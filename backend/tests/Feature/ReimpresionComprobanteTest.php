<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Comprobante;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\CajaService;
use App\Services\PagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 5, tarea 1b (13/10/2026, decisión del usuario): reimpresión controlada del comprobante.
 * El personal reimprime cuando quiera; desde la segunda vez sale "REIMPRESIÓN" y cada impresión
 * queda en la auditoría. El Administrador ve y abre los comprobantes anulados.
 */
class ReimpresionComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private Producto $hamburguesa;
    private Mesa $mesa;
    /** Datos con los que se dibujó el último comprobante. */
    private array $dibujado = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-3', 'estado' => 'libre']);
        app(CajaService::class)->abrir($this->admin, 0);

        View::composer(['comprobantes.ticket', 'comprobantes.pdf'], function ($vista) {
            $this->dibujado = $vista->getData();
        });
    }

    private function como(User $usuario): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($usuario);
    }

    private function pedidoPagado(): Pedido
    {
        $this->como($this->mesero);
        $id = $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->json('id');
        $this->postJson("/api/pedidos/{$id}/cobrar", ['metodo' => 'efectivo', 'monto' => 20000])->assertOk();

        return Pedido::findOrFail($id);
    }

    public function test_la_segunda_impresion_sale_como_reimpresion_y_cada_una_queda_auditada(): void
    {
        $pedido = $this->pedidoPagado();

        $this->get("/api/pedidos/{$pedido->id}/comprobante")->assertOk();
        $this->assertNull($this->dibujado['reimpresion'], 'la primera impresión es el original');
        $this->assertSame(1, Comprobante::sole()->impresiones);

        $this->como($this->admin);
        $this->get("/api/pedidos/{$pedido->id}/comprobante?formato=pdf")->assertOk();
        $this->assertSame(2, $this->dibujado['reimpresion']['vez']);
        $this->assertSame(2, Comprobante::sole()->impresiones);

        $registros = Auditoria::where('accion', 'imprimir_comprobante')->orderBy('id')->get();
        $this->assertCount(2, $registros);
        $this->assertSame([$this->mesero->id, $this->admin->id], $registros->pluck('user_id')->all());
        $this->assertFalse($registros[0]->detalles['reimpresion']);
        $this->assertTrue($registros[1]->detalles['reimpresion']);
        $this->assertSame(['FR-000001', 2, 'pdf'], [$registros[1]->detalles['comprobante'], $registros[1]->detalles['vez'], $registros[1]->detalles['formato']]);

        // El pedido dice cuántas veces se imprimió, para avisar antes de reimprimir.
        $this->getJson("/api/pedidos/{$pedido->id}")->assertJsonPath('comprobante.impresiones', 2);
    }

    public function test_el_documento_dice_reimpresion_y_la_copia(): void
    {
        $pedido = $this->pedidoPagado();
        $comprobante = Comprobante::sole();
        $datos = ['c' => $comprobante->detalle, 'comprobante' => $comprobante];

        $original = view('comprobantes.ticket', $datos)->render();
        $this->assertStringNotContainsString('REIMPRESIÓN', $original);

        $copia = view('comprobantes.ticket', $datos + ['reimpresion' => ['vez' => 3, 'fecha' => now()->toIso8601String()]])->render();
        $this->assertStringContainsString('*** REIMPRESIÓN · COPIA 3 ***', $copia);
        $this->assertStringContainsString('Impresa el', $copia);

        $pdf = view('comprobantes.pdf', $datos + ['reimpresion' => ['vez' => 2, 'fecha' => now()->toIso8601String()]])->render();
        $this->assertStringContainsString('*** REIMPRESIÓN · COPIA 2 ***', $pdf); // 29/10/2026: el mismo diseño del ticket
    }

    public function test_lo_que_abre_el_cliente_o_le_llega_por_correo_no_cuenta_ni_se_marca(): void
    {
        Notification::fake();
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Pedro', 'email' => 'pedro@correo.co', 'telefono' => '3001112233', 'password' => 'secreto123', 'activo' => true]);
        $this->como($cliente);
        $respuesta = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->assertCreated();
        $id = $respuesta->json('id');
        $this->como($this->mesero);
        $this->postJson("/api/pedidos/{$id}/cobrar", ['metodo' => 'efectivo', 'monto' => 20000])->assertOk();

        $this->como($cliente);
        $this->get("/api/pedidos/{$id}/comprobante")->assertOk();
        $this->get("/api/pedidos/{$id}/comprobante")->assertOk();
        $this->assertNull($this->dibujado['reimpresion']);

        $this->app['auth']->forgetGuards();
        $token = Pedido::find($id)->token_publico;
        $this->get("/api/pedidos/seguimiento/{$token}/comprobante")->assertOk();

        $this->assertSame(0, Comprobante::sole()->impresiones);
        $this->assertFalse(Auditoria::where('accion', 'imprimir_comprobante')->exists());
    }

    public function test_el_administrador_ve_los_anulados_y_los_abre_con_su_sello(): void
    {
        $pedido = $this->pedidoPagado();
        app(PagoService::class)->anular(Pago::sole(), $this->admin, 'Se cobró a la mesa equivocada');
        $this->como($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/cobrar", ['metodo' => 'tarjeta', 'monto' => 20000])->assertOk();

        $this->como($this->admin);
        $lista = $this->getJson("/api/pedidos/{$pedido->id}/comprobantes")->assertOk()->json('data');
        $this->assertSame(['FR-000002', 'FR-000001'], array_column($lista, 'numero'));
        $this->assertNull($lista[0]['anulado_at']);
        $this->assertSame('El pedido ya no está pagado por completo.', $lista[1]['anulado_motivo']);
        $this->assertArrayNotHasKey('detalle', $lista[1]);

        $anulado = Comprobante::where('numero', 'FR-000001')->sole();
        $this->get("/api/pedidos/{$pedido->id}/comprobantes/{$anulado->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertTrue($this->dibujado['comprobante']->anulado_at !== null, 'sale con el sello ANULADO');
        $this->assertTrue(Auditoria::where('accion', 'imprimir_comprobante')->get()->contains(fn ($a) => $a->detalles['comprobante'] === 'FR-000001' && $a->detalles['anulado'] === true));

        // Otro pedido: su comprobante no se abre por este pedido.
        $otro = $this->pedidoPagado();
        $deOtro = $otro->comprobante()->first();
        $this->como($this->admin);
        $this->get("/api/pedidos/{$pedido->id}/comprobantes/{$deOtro->id}")->assertNotFound();
    }

    public function test_el_mesero_reimprime_el_vigente_pero_no_ve_los_anulados(): void
    {
        $pedido = $this->pedidoPagado();
        $this->como($this->mesero);

        $this->getJson("/api/pedidos/{$pedido->id}/comprobantes")->assertForbidden();
        $this->get("/api/pedidos/{$pedido->id}/comprobantes/".Comprobante::sole()->id)->assertForbidden();
        $this->get("/api/pedidos/{$pedido->id}/comprobante")->assertOk();
    }
}
