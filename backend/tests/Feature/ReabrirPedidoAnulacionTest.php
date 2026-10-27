<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decisión del negocio (22/10/2026, RN-M06-15): si se anula un pago y el pedido finalizado queda sin
 * pagar completo, vuelve a «en preparación» para cobrar el saldo, con aviso al personal. Un
 * reembolso no lo reabre.
 */
class ReabrirPedidoAnulacionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private Producto $hamburguesa;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-3', 'estado' => 'libre']);
        app(CajaService::class)->abrir($this->admin, 0);
    }

    private function como(User $usuario): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($usuario);
    }

    /** Un pedido local cobrado en dos pagos, ya finalizado. */
    private function pedidoFinalizado(): Pedido
    {
        $this->como($this->mesero);
        $id = $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 2]]])->json('id'); // nace confirmado (RF-M02-L-036)
        $this->postJson("/api/pedidos/{$id}/preparando")->assertOk();
        $this->postJson("/api/pedidos/{$id}/cobrar", ['metodo' => 'efectivo', 'monto' => 25000])->assertOk();
        $this->postJson("/api/pedidos/{$id}/cobrar", ['metodo' => 'tarjeta', 'monto' => 15000])->assertOk();

        $pedido = Pedido::findOrFail($id);
        $this->assertSame('finalizado', $pedido->estado);

        return $pedido;
    }

    public function test_anular_un_pago_reabre_el_pedido_para_cobrar_el_saldo(): void
    {
        $pedido = $this->pedidoFinalizado();
        $pago = Pago::where('pedido_id', $pedido->id)->where('metodo', 'tarjeta')->sole();

        $this->como($this->admin);
        $this->postJson("/api/pagos/{$pago->id}/anular", ['motivo' => 'Se registró en tarjeta y era efectivo', 'tipo' => 'anulacion'])->assertOk()
            ->assertJsonPath('pedido_reabierto.pedido_id', $pedido->id)
            ->assertJsonPath('pedido_reabierto.saldo', 15000);

        $pedido->refresh();
        $this->assertSame('preparando', $pedido->estado);
        $this->assertNull($pedido->finalizado_at);
        $this->assertSame(1, Auditoria::where('accion', 'reabrir_pedido')->where('entidad_id', $pedido->id)->count());
        // Aviso al personal (el Mesero lo cobra); no a quien anuló.
        $this->assertSame([$this->mesero->id], Notificacion::where('tipo', 'pedido_reabierto')->pluck('user_id')->all());

        // El Mesero cobra el saldo y el pedido se vuelve a finalizar solo.
        $this->como($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 15000])->assertOk();
        $this->assertSame('finalizado', $pedido->fresh()->estado);
    }

    public function test_un_reembolso_no_reabre_el_pedido(): void
    {
        $pedido = $this->pedidoFinalizado();
        $pago = Pago::where('pedido_id', $pedido->id)->where('metodo', 'tarjeta')->sole();

        $this->como($this->admin);
        $this->postJson("/api/pagos/{$pago->id}/anular", ['motivo' => 'El cliente se quejó del plato', 'tipo' => 'reembolso'])->assertOk()
            ->assertJsonPath('pedido_reabierto', null);

        $this->assertSame('finalizado', $pedido->fresh()->estado);
        $this->assertSame(0, Auditoria::where('accion', 'reabrir_pedido')->count());
    }
}
