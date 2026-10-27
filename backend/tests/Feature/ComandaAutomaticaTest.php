<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Comanda;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comanda automática (27/10/2026, RF-M02-L-036, RN-PED-35): el pedido que toma el personal (Mesero o
 * Administrador) se confirma al crearlo, así la comanda sale de una vez a la impresora de la cocina. Los
 * pedidos por QR y por la web siguen esperando que el personal los confirme.
 */
class ComandaAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private Producto $clasica;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();
        $crear = fn (string $rol, string $correo) => User::create(['role' => $rol, 'nombre' => ucfirst($rol), 'email' => $correo, 'password' => bcrypt('Clave12345'), 'activo' => true]);
        $this->mesero = $crear('mesero', 'mesero@test.co');
        $this->admin = $crear('administrador', 'admin@test.co');
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->clasica = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
    }

    private function linea(): array
    {
        return [['producto_id' => $this->clasica->id, 'cantidad' => 1]];
    }

    public function test_RF_M02_L_036_el_pedido_del_mesero_nace_confirmado_y_con_su_comanda(): void
    {
        Sanctum::actingAs($this->mesero);
        $id = $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id, 'lineas' => $this->linea()])
            ->assertCreated()->assertJsonPath('estado', 'confirmado')->json('id');

        $pedido = Pedido::findOrFail($id);
        $this->assertSame($this->mesero->id, $pedido->confirmado_por);
        $this->assertNotNull($pedido->confirmado_at);
        $comanda = Comanda::where('pedido_id', $id)->sole();
        $this->assertSame('inicial', $comanda->tipo);
        $this->assertSame('pendiente', $comanda->estado_impresion, 'Queda lista para que la imprima el equipo de la cocina.');
        $this->getJson('/api/comandas/pendientes')->assertOk()->assertJsonCount(1);

        // Ya está confirmado: confirmarlo otra vez no hace falta.
        $this->postJson("/api/pedidos/{$id}/confirmar")->assertStatus(422);
    }

    public function test_RF_M02_L_036_tambien_el_del_administrador_y_el_domicilio_por_telefono(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id, 'lineas' => $this->linea()])
            ->assertCreated()->assertJsonPath('estado', 'confirmado');

        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'mesero', 'cliente_invitado_nombre' => 'Marta Gómez', 'cliente_invitado_telefono' => '3001234567',
            'lineas' => $this->linea(),
        ])->assertCreated()->assertJsonPath('estado', 'confirmado');
        $this->assertSame(2, Comanda::count());
    }

    public function test_RN_PED_35_el_qr_y_la_web_siguen_esperando_confirmacion(): void
    {
        $qr = $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id, 'lineas' => $this->linea()])
            ->assertCreated()->assertJsonPath('estado', 'pendiente')->json('id');
        $web = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'cliente_invitado_nombre' => 'Ana', 'cliente_invitado_telefono' => '3001234567',
            'lineas' => $this->linea(),
        ])->assertCreated()->assertJsonPath('estado', 'pendiente')->json('id');
        $this->assertSame(0, Comanda::count());

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$qr}/confirmar")->assertOk()->assertJsonPath('estado', 'confirmado');
        $this->postJson("/api/pedidos/{$web}/confirmar")->assertOk();
        $this->assertSame(2, Comanda::count());
    }
}
