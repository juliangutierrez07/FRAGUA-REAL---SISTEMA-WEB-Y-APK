<?php

namespace Tests\Feature;

use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\CajaService;
use App\Services\PedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mejoras de operación (26/10/2026): la caja cierra solo con todo finalizado, cuentas separadas en la
 * mesa, foto del comprobante al cobrar por transferencia, billete y ubicación del domicilio, y las
 * categorías que no preguntan qué ingrediente quitar.
 */
class MejorasOperacionTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private Producto $hamburguesa;
    private Mesa $mesa;
    private CajaTurno $turno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        $this->turno = CajaTurno::create([
            'fecha' => now('America/Bogota')->toDateString(), 'base_inicial' => 0, 'efectivo_esperado' => 0,
            'abierto_por' => $this->admin->id, 'abierto_at' => now(), 'estado' => 'abierta',
        ]);
    }

    private function pedidoLocal(int $cantidad = 1, ?int $cuenta = null): Pedido
    {
        return app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id, 'cuenta' => $cuenta,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => $cantidad]],
        ]);
    }

    private function preparando(Pedido $pedido): void
    {
        app(PedidoService::class)->confirmar($pedido, $this->mesero);
        app(PedidoService::class)->marcarPreparando($pedido->fresh());
    }

    // ---------- Cerrar caja ----------

    public function test_la_caja_no_cierra_con_pedidos_sin_finalizar(): void
    {
        $pedido = $this->pedidoLocal();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/caja/turno-actual')
            ->assertOk()
            ->assertJsonCount(1, 'pedidos_sin_finalizar')
            ->assertJsonPath('pedidos_sin_finalizar.0.id', $pedido->id)
            ->assertJsonPath('pedidos_sin_finalizar.0.destino', 'Mesa 1')
            ->assertJsonPath('pedidos_sin_finalizar.0.saldo', 20000);

        $this->postJson("/api/caja/turnos/{$this->turno->id}/cerrar", ['efectivo_contado' => 0])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "No puedes cerrar la caja: el pedido #{$pedido->id} (Mesa 1) no está finalizado. Cóbralo y finalízalo, o cancélalo con su motivo."]);
        $this->assertSame('abierta', $this->turno->fresh()->estado);

        app(PedidoService::class)->cancelar($pedido->fresh(), $this->mesero, 'El cliente se fue');
        $this->postJson("/api/caja/turnos/{$this->turno->id}/cerrar", ['efectivo_contado' => 0])->assertOk();
        $this->assertSame('cerrada', $this->turno->fresh()->estado);
    }

    public function test_un_pedido_pagado_pero_sin_finalizar_tambien_impide_cerrar(): void
    {
        $pedido = $this->pedidoLocal();
        app(PedidoService::class)->confirmar($pedido, $this->mesero);
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 20000])->assertOk();
        $this->assertSame('confirmado', $pedido->fresh()->estado, 'Pagado, pero la cocina aún no lo prepara.');

        $this->expectExceptionMessage('no está finalizado');
        app(CajaService::class)->cerrar($this->turno->fresh(), $this->admin, 20000);
    }

    // ---------- Cuentas separadas ----------

    public function test_cuentas_separadas_en_una_mesa_ocupada(): void
    {
        $uno = $this->pedidoLocal(1);
        $this->assertSame(1, $uno->cuenta);
        $dos = $this->pedidoLocal(2, 2);
        $this->assertSame(2, $dos->cuenta);
        $otroDeLaUno = $this->pedidoLocal(1, 1);
        $this->assertSame(1, $otroDeLaUno->cuenta);

        try {
            $this->pedidoLocal(1, 5);
            $this->fail('La cuenta 5 no existe: solo hay 1 y 2 (la siguiente sería la 3).');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('abre la cuenta 3', $e->getMessage());
        }

        Sanctum::actingAs($this->mesero);
        $this->getJson("/api/mesas/{$this->mesa->id}/cuenta")
            ->assertOk()
            ->assertJsonCount(2, 'cuentas')
            ->assertJsonPath('cuentas.0.numero', 1)
            ->assertJsonPath('cuentas.0.total', 40000)
            ->assertJsonPath('cuentas.1.numero', 2)
            ->assertJsonPath('cuentas.1.total', 40000)
            ->assertJsonPath('total', 80000);
        $this->getJson('/api/mesas')->assertOk()->assertJsonPath('0.cuentas.1.numero', 2);
    }

    public function test_cobrar_una_sola_cuenta_no_toca_la_otra(): void
    {
        $uno = $this->pedidoLocal(1);
        $dos = $this->pedidoLocal(2, 2);
        $this->preparando($uno);
        $this->preparando($dos);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 40001, 'cuenta' => 2])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'El monto supera lo que debe la cuenta 2 ($40.000) (RF-M06-011).']);
        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 40000, 'cuenta' => 2])
            ->assertOk()
            ->assertJsonPath('cuentas.1.saldo', 0)
            ->assertJsonPath('cuentas.0.saldo', 20000);

        $this->assertSame('finalizado', $dos->fresh()->estado);
        $this->assertSame('preparando', $uno->fresh()->estado);
        $this->assertSame('ocupada', $this->mesa->fresh()->estado, 'La cuenta 1 sigue abierta.');
    }

    public function test_la_comanda_dice_a_que_cuenta_va(): void
    {
        $this->pedidoLocal(1);
        $dos = $this->pedidoLocal(1, 2);
        app(PedidoService::class)->confirmar($dos, $this->mesero);

        Sanctum::actingAs($this->mesero);
        $comanda = $dos->fresh()->comandas()->firstOrFail();
        $this->assertSame(2, $comanda->contenido['cuenta'] ?? null);
    }

    // ---------- Foto del comprobante ----------

    public function test_cobro_por_transferencia_exige_la_foto_y_la_guarda(): void
    {
        Storage::fake('local');
        $pedido = $this->pedidoLocal();
        $this->preparando($pedido);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/cobrar", ['metodo' => 'transferencia', 'monto' => 20000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comprobante' => 'Toma la foto del comprobante de la transferencia.']);

        $this->post("/api/pedidos/{$pedido->id}/cobrar", [
            'metodo' => 'transferencia', 'monto' => 20000,
            'comprobante' => UploadedFile::fake()->image('comprobante.jpg', 600, 1000),
        ], ['Accept' => 'application/json'])->assertOk();

        $pago = Pago::where('pedido_id', $pedido->id)->firstOrFail();
        $this->assertNotNull($pago->comprobante_path);
        $this->assertTrue($pago->tiene_comprobante);
        $this->get("/api/pagos/{$pago->id}/comprobante")->assertOk();
    }

    public function test_cobro_con_tarjeta_no_exige_foto_y_la_mesa_la_reparte(): void
    {
        Storage::fake('local');
        $uno = $this->pedidoLocal(1);
        $otro = $this->pedidoLocal(1);
        $this->preparando($uno);
        $this->preparando($otro);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'tarjeta', 'monto' => 10000])->assertOk();
        $this->post("/api/mesas/{$this->mesa->id}/cobrar", [
            'metodo' => 'transferencia', 'monto' => 30000,
            'comprobante' => UploadedFile::fake()->image('foto.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $transferencias = Pago::where('metodo', 'transferencia')->get();
        $this->assertCount(2, $transferencias, 'El cobro de la mesa se reparte entre sus dos pedidos.');
        $this->assertTrue($transferencias->every(fn (Pago $p) => $p->comprobante_path !== null));
        $this->assertNull(Pago::where('metodo', 'tarjeta')->value('comprobante_path'));
    }

    // ---------- Domicilio: billete y ubicación ----------

    private function domicilio(array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/pedidos', [
            'tipo' => 'domicilio', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Laura Gómez', 'cliente_invitado_telefono' => '3001234567',
            'direccion_entrega' => 'Calle 5 #3-15', 'barrio' => 'Centro', 'referencia' => 'Frente al parque',
            'metodo_pago_preferido' => 'contra_entrega',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 2]],
            ...$extra,
        ]);
    }

    public function test_domicilio_guarda_el_billete_y_la_ubicacion(): void
    {
        $respuesta = $this->domicilio(['paga_con' => 50000, 'ubicacion_lat' => 4.6097102, 'ubicacion_lng' => -74.0817501])->assertCreated();
        $pedido = Pedido::findOrFail($respuesta->json('id'));

        $this->assertEquals(50000, (float) $pedido->paga_con);
        $this->assertEqualsWithDelta(4.6097102, $pedido->ubicacion_lat, 0.0000001);
        $this->assertEqualsWithDelta(-74.0817501, $pedido->ubicacion_lng, 0.0000001);
    }

    public function test_el_billete_debe_alcanzar_para_el_total(): void
    {
        $this->domicilio(['paga_con' => 20000])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'El billete con el que pagas ($20.000) no alcanza para el total ($'.number_format(40000 + (float) config('fragua_real.valor_domicilio_default'), 0, ',', '.').'). Elige uno mayor o «Pago exacto».']);
        $this->assertSame(0, Pedido::count(), 'No queda un pedido a medias.');
    }

    public function test_en_linea_no_guarda_billete_y_la_ubicacion_va_completa(): void
    {
        $this->domicilio(['metodo_pago_preferido' => 'en_linea', 'pasarela_preferida' => 'nequi', 'paga_con' => 100000]);
        $this->assertNull(Pedido::latest('id')->value('paga_con'));

        $this->domicilio(['ubicacion_lat' => 4.6])->assertStatus(422)->assertJsonValidationErrors('ubicacion_lng');
    }

    // ---------- Categorías sin «quitar ingredientes» ----------

    public function test_la_categoria_dice_si_se_pueden_quitar_ingredientes(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/categorias', ['nombre' => 'Bebidas frías', 'permite_quitar_ingredientes' => false])
            ->assertCreated()->json('id');
        $this->assertFalse(Categoria::findOrFail($id)->permite_quitar_ingredientes);

        $this->putJson("/api/categorias/{$id}", ['nombre' => 'Bebidas frías', 'permite_quitar_ingredientes' => true])->assertOk();
        $this->assertTrue(Categoria::findOrFail($id)->permite_quitar_ingredientes);
        $this->assertTrue(Categoria::create(['nombre' => 'Otra'])->fresh()->permite_quitar_ingredientes, 'Por defecto sí se puede.');
    }
}
