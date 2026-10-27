<?php

namespace Tests\Feature;

use App\Models\AjusteCaja;
use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\CajaService;
use App\Services\PagoService;
use App\Services\PedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 0, tarea 3 del plan de implementación: la caja cuadra con lo que dice M06.
 * RF-M06-013, RN-M06-08 (fórmula del efectivo esperado) · RF-M06-017/018/019, RN-M06-10
 * (anular o reembolsar; con la caja cerrada, autorización adicional y ajuste extraordinario).
 */
class CajaCuadreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private CajaTurno $turno;
    private Producto $producto;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => \Illuminate\Support\Facades\Hash::make('clave-admin'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);

        $this->turno = app(CajaService::class)->abrir($this->admin, 100000);
    }

    private function hoy(): string
    {
        return now('America/Bogota')->toDateString();
    }

    // $preparado: el pedido pasa por la cocina antes del cobro y queda finalizado (26/10/2026, RF-M06-038: la caja
    // solo cierra con todos los pedidos finalizados).
    private function cobrarEnEfectivo(float $monto, bool $preparado = false): Pago
    {
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ]);
        if ($preparado) {
            app(PedidoService::class)->confirmar($pedido, $this->mesero);
            $pedido = app(PedidoService::class)->marcarPreparando($pedido->fresh());
        }

        return app(PagoService::class)->registrarCobro($pedido, $this->mesero, ['metodo' => 'efectivo', 'monto' => $monto]);
    }

    private function registrarGasto(float $valor, string $metodo = 'efectivo')
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/compras-gastos', [
            'tipo' => 'gasto', 'categoria' => 'Servicios', 'descripcion' => 'Gas', 'valor' => $valor,
            'metodo_pago' => $metodo, 'fecha' => $this->hoy(),
        ])->assertCreated();
    }

    // ---- RN-M06-08: base + pagos en efectivo − gastos en efectivo − anulaciones ----

    public function test_RF_M06_013_un_gasto_en_efectivo_resta_del_efectivo_esperado(): void
    {
        $this->cobrarEnEfectivo(20000);
        $this->assertEquals(120000, $this->turno->fresh()->efectivo_esperado);

        $this->registrarGasto(15000);

        $this->assertEquals(105000, $this->turno->fresh()->efectivo_esperado);
    }

    public function test_RF_M06_013_un_gasto_por_transferencia_no_toca_la_caja(): void
    {
        $this->registrarGasto(15000, 'transferencia');

        $this->assertEquals(100000, $this->turno->fresh()->efectivo_esperado);
    }

    public function test_RF_M06_013_anular_el_gasto_devuelve_el_efectivo_esperado(): void
    {
        $id = $this->registrarGasto(15000)->json('id');
        $this->assertEquals(85000, $this->turno->fresh()->efectivo_esperado);

        $this->postJson("/api/compras-gastos/{$id}/anular", ['motivo' => 'Registrado dos veces'])->assertOk();

        $this->assertEquals(100000, $this->turno->fresh()->efectivo_esperado);
    }

    public function test_el_cierre_usa_el_esperado_con_gastos_y_no_marca_descuadre_falso(): void
    {
        $this->cobrarEnEfectivo(20000, preparado: true);
        $this->registrarGasto(15000);

        $this->postJson("/api/caja/turnos/{$this->turno->id}/cerrar", ['efectivo_contado' => 105000])
            ->assertOk()
            ->assertJsonPath('estado', 'cerrada');

        $this->assertEquals(0, $this->turno->fresh()->diferencia);
    }

    // ---- RF-M06-017/018: anular o reembolsar con la caja abierta ----

    public function test_RF_M06_018_anular_un_pago_en_efectivo_con_la_caja_abierta_ajusta_el_esperado(): void
    {
        $pago = $this->cobrarEnEfectivo(20000);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pagos/{$pago->id}/anular", ['motivo' => 'Cobro equivocado'])
            ->assertOk()
            ->assertJsonPath('reverso_tipo', 'anulacion');

        $this->assertEquals(100000, $this->turno->fresh()->efectivo_esperado);
        $this->assertSame(0, AjusteCaja::count());
    }

    public function test_RF_M06_017_se_puede_registrar_un_reembolso(): void
    {
        $pago = $this->cobrarEnEfectivo(20000);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pagos/{$pago->id}/anular", ['motivo' => 'El cliente devolvió el pedido', 'tipo' => 'reembolso'])
            ->assertOk()
            ->assertJsonPath('reverso_tipo', 'reembolso');
    }

    // ---- RF-M06-019: con la caja cerrada ----

    public function test_RF_M06_019_con_la_caja_cerrada_anular_exige_la_contrasena_del_administrador(): void
    {
        $pago = $this->cobrarEnEfectivo(20000, preparado: true);
        app(CajaService::class)->cerrar($this->turno->fresh(), $this->admin, 120000);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pagos/{$pago->id}/anular", ['motivo' => 'Error'])->assertStatus(422);
        $this->postJson("/api/pagos/{$pago->id}/anular", ['motivo' => 'Error', 'password_confirmacion' => 'otra'])->assertStatus(422);

        $this->assertFalse($pago->fresh()->anulado);
    }

    public function test_RF_M06_019_con_autorizacion_queda_un_ajuste_extraordinario_y_el_turno_cerrado_no_cambia(): void
    {
        $pago = $this->cobrarEnEfectivo(20000, preparado: true);
        app(CajaService::class)->cerrar($this->turno->fresh(), $this->admin, 120000);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pagos/{$pago->id}/anular", ['motivo' => 'Cobro duplicado', 'password_confirmacion' => 'clave-admin'])
            ->assertOk();

        $turno = $this->turno->fresh();
        $this->assertEquals(120000, $turno->efectivo_esperado, 'El arqueo de un día cerrado no se reescribe.');
        $this->assertEquals(0, $turno->diferencia);

        $ajuste = AjusteCaja::sole();
        $this->assertSame($turno->id, $ajuste->caja_turno_id);
        $this->assertEquals(20000, $ajuste->monto);
        $this->assertSame($this->admin->id, $ajuste->autorizado_por);
    }

    public function test_el_mesero_no_anula_pagos(): void
    {
        $pago = $this->cobrarEnEfectivo(20000);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pagos/{$pago->id}/anular", ['motivo' => 'x'])->assertForbidden();
    }

    // ---- Pagos en línea en el detalle del turno ----

    public function test_RF_M06_017_los_pagos_en_linea_del_dia_aparecen_en_el_turno_y_se_pueden_anular(): void
    {
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Ana', 'cliente_invitado_telefono' => '3000000000',
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ]);
        $pagos = app(PagoService::class);
        $pago = $pagos->registrarPagoEnLinea($pedido, ['pasarela' => 'nequi', 'monto' => 20000]);
        $pagos->confirmarPagoEnLinea($pago);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/caja/turnos/{$this->turno->id}")
            ->assertOk()
            ->assertJsonPath('pagos_en_linea.0.id', $pago->id)
            ->assertJsonPath('gastos_efectivo', 0);

        $this->postJson("/api/pagos/{$pago->id}/anular", ['motivo' => 'Pago repetido', 'tipo' => 'reembolso'])->assertOk();
        $this->assertEquals(100000, $this->turno->fresh()->efectivo_esperado, 'Un pago en línea no mueve el efectivo.');
    }
}
