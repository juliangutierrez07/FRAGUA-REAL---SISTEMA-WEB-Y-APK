<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 0, tarea 2 del plan de implementación: POST /pedidos es una ruta pública (para el
 * invitado y el QR), así que el servidor no puede creerle al cuerpo de la petición quién
 * crea el pedido, para qué cliente, ni qué precio aplica.
 *
 * RNF-M02-006, RNF-TRANS-010 · RF-M02-L-002, RN-PED-02 · RF-M04-003, RF-M04-018, RF-M04-031,
 * RN-M04-05, RN-M04-08, RN-M04-16, RNF-M04-004.
 */
class CrearPedidoSeguridadTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $cliente;
    private User $otroCliente;
    private Categoria $categoria;
    private Producto $producto;
    private Producto $otroProducto;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->cliente = User::create(['role' => 'cliente', 'nombre' => 'Ana', 'email' => 'ana@test.co', 'telefono' => '3001234567', 'password' => 'secreto123', 'activo' => true]);
        $this->otroCliente = User::create(['role' => 'cliente', 'nombre' => 'Luis', 'email' => 'luis@test.co', 'telefono' => '3007654321', 'password' => 'secreto123', 'activo' => true]);

        $this->categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $this->categoria->id, 'nombre' => 'Doble', 'precio_base' => 30000, 'tipo_venta' => 'alimento']);
        $this->otroProducto = Producto::create(['categoria_id' => $this->categoria->id, 'nombre' => 'Sencilla', 'precio_base' => 15000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
    }

    private function linea(array $extra = []): array
    {
        return array_merge(['producto_id' => $this->producto->id, 'cantidad' => 1], $extra);
    }

    private function recogidaInvitado(array $lineas): array
    {
        return [
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Invitado', 'cliente_invitado_telefono' => '3001234567',
            'lineas' => $lineas,
        ];
    }

    // ---- Quién crea el pedido ----

    public function test_RNF_M02_006_sin_sesion_no_se_puede_crear_un_pedido_como_mesero(): void
    {
        $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id, 'lineas' => [$this->linea()],
        ])->assertForbidden();

        $this->assertSame(0, Pedido::count());
    }

    public function test_un_cliente_tampoco_puede_crear_pedidos_como_mesero(): void
    {
        Sanctum::actingAs($this->cliente);

        $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id, 'lineas' => [$this->linea()],
        ])->assertForbidden();
    }

    public function test_el_mesero_con_sesion_si_crea_un_pedido_local(): void
    {
        Sanctum::actingAs($this->mesero);

        $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id, 'lineas' => [$this->linea()],
        ])->assertCreated();
    }

    public function test_el_qr_de_mesa_sigue_funcionando_sin_sesion(): void
    {
        $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id, 'lineas' => [$this->linea()],
        ])->assertCreated();
    }

    public function test_el_canal_web_no_crea_pedidos_locales_y_el_qr_no_crea_domicilios(): void
    {
        $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'cliente_web', 'mesa_id' => $this->mesa->id, 'lineas' => [$this->linea()],
        ])->assertStatus(422)->assertJsonValidationErrors('origen');

        $this->postJson('/api/pedidos', array_merge($this->recogidaInvitado([$this->linea()]), ['origen' => 'qr']))
            ->assertStatus(422)->assertJsonValidationErrors('origen');
    }

    // ---- Para qué cliente ----

    public function test_un_invitado_no_puede_asignar_el_pedido_a_un_cliente_registrado(): void
    {
        $this->postJson('/api/pedidos', array_merge($this->recogidaInvitado([$this->linea()]), ['cliente_id' => $this->cliente->id]))
            ->assertStatus(422)->assertJsonValidationErrors('cliente_id');
    }

    public function test_un_cliente_con_sesion_siempre_crea_el_pedido_a_su_nombre(): void
    {
        Sanctum::actingAs($this->cliente);

        $respuesta = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'cliente_id' => $this->otroCliente->id, 'lineas' => [$this->linea()],
        ])->assertCreated();

        $this->assertSame($this->cliente->id, Pedido::find($respuesta->json('id'))->cliente_id);
    }

    // ---- Qué se pide y a qué precio ----

    public function test_RNF_M04_004_no_se_acepta_la_variante_de_otro_producto(): void
    {
        $varianteBarata = Variante::create(['producto_id' => $this->otroProducto->id, 'nombre' => 'Mini', 'precio' => 1000, 'activa' => true]);

        $this->postJson('/api/pedidos', $this->recogidaInvitado([$this->linea(['variante_id' => $varianteBarata->id])]))
            ->assertStatus(422);

        $this->assertSame(0, Pedido::count());
    }

    public function test_RNF_M04_004_no_se_acepta_una_variante_inactiva(): void
    {
        $inactiva = Variante::create(['producto_id' => $this->producto->id, 'nombre' => 'XL', 'precio' => 40000, 'activa' => false]);

        $this->postJson('/api/pedidos', $this->recogidaInvitado([$this->linea(['variante_id' => $inactiva->id])]))
            ->assertStatus(422);
    }

    public function test_la_variante_valida_fija_su_precio(): void
    {
        $grande = Variante::create(['producto_id' => $this->producto->id, 'nombre' => 'Grande', 'precio' => 36000, 'activa' => true]);

        $respuesta = $this->postJson('/api/pedidos', $this->recogidaInvitado([$this->linea(['variante_id' => $grande->id])]))
            ->assertCreated();

        $this->assertEquals(36000, Pedido::find($respuesta->json('id'))->total);
    }

    public function test_RN_M04_05_no_se_acepta_un_extra_especifico_de_otro_producto_ni_uno_inactivo(): void
    {
        $ajeno = Extra::create(['nombre' => 'Salsa de la casa', 'precio' => 2000, 'alcance' => 'especifico', 'producto_id' => $this->otroProducto->id, 'activo' => true]);
        $inactivo = Extra::create(['nombre' => 'Queso', 'precio' => 3000, 'alcance' => 'general', 'activo' => false]);

        $this->postJson('/api/pedidos', $this->recogidaInvitado([$this->linea(['extras_ids' => [$ajeno->id]])]))->assertStatus(422);
        $this->postJson('/api/pedidos', $this->recogidaInvitado([$this->linea(['extras_ids' => [$inactivo->id]])]))->assertStatus(422);
    }

    public function test_un_extra_general_activo_se_acepta_en_cualquier_producto(): void
    {
        $tocineta = Extra::create(['nombre' => 'Tocineta', 'precio' => 4000, 'alcance' => 'general', 'activo' => true]);
        $tocineta->productos()->attach($this->producto->id);

        $respuesta = $this->postJson('/api/pedidos', $this->recogidaInvitado([$this->linea(['extras_ids' => [$tocineta->id]])]))
            ->assertCreated();

        $this->assertEquals(34000, Pedido::find($respuesta->json('id'))->total);
    }

    public function test_RF_M02_L_002_no_se_crea_un_pedido_en_una_mesa_desactivada(): void
    {
        $this->mesa->update(['activa' => false]);

        $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id, 'lineas' => [$this->linea()],
        ])->assertStatus(422);
    }

    public function test_RF_M04_003_no_se_pide_un_producto_de_una_categoria_desactivada(): void
    {
        $this->categoria->update(['activo' => false]);

        $this->postJson('/api/pedidos', $this->recogidaInvitado([$this->linea()]))->assertStatus(422);
    }

    public function test_RF_M04_031_no_se_pide_un_combo_fuera_de_su_vigencia(): void
    {
        $combo = Combo::create([
            'nombre' => 'Combo de ayer', 'precio' => 25000, 'tipo_venta' => 'alimento', 'activo' => true,
            // Fechas del negocio (hora de Colombia), igual que la vigencia que evalúa ComboService.
            'vigencia_inicio' => now('America/Bogota')->subDays(10)->toDateString(), 'vigencia_fin' => now('America/Bogota')->subDay()->toDateString(),
        ]);
        $combo->productos()->attach($this->producto->id, ['cantidad' => 1]);

        $this->postJson('/api/pedidos', $this->recogidaInvitado([['combo_id' => $combo->id, 'cantidad' => 1]]))->assertStatus(422);
    }
}
