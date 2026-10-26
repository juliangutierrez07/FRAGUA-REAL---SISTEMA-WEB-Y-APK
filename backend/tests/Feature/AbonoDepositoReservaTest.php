<?php

namespace Tests\Feature;

use App\Models\AjusteCaja;
use App\Models\Auditoria;
use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\User;
use App\Services\CajaService;
use App\Services\PedidoService;
use App\Services\ReporteService;
use App\Services\ReservaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 2, tarea 2 del plan de implementación: el depósito de la reserva se abona a los pedidos de
 * la visita (decisión D7: un pago de tipo "deposito", sin turno de caja) y el depósito manual
 * entra y sale de la caja del día.
 *
 * RF-M05-034, RN-M05-19 · RN-M05-16/17 · RN-M06-02/08 · RF-M06-011/017.
 */
class AbonoDepositoReservaTest extends TestCase
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

        // Martes 13/10/2026, 12:00 m. en Colombia.
        $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', 'America/Bogota'));

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 10000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        $this->turno = app(CajaService::class)->abrir($this->admin, 100000);
    }

    /** Reserva de hoy a la 1:00 p. m. con depósito de $20.000 ya pagado. */
    private function reservaConDeposito(string $metodo = 'efectivo'): Reserva
    {
        $reserva = Reserva::create([
            'mesa_id' => $this->mesa->id, 'cliente_nombre' => 'Laura Pérez', 'cliente_telefono' => '3105551234',
            'cliente_correo' => 'laura@correo.co', 'fecha_hora' => CarbonImmutable::parse('2026-10-13 13:00:00', 'America/Bogota')->utc(),
            'estado' => 'pendiente_pago', 'deposito_valor' => 20000, 'creado_por' => $metodo === 'en_linea' ? 'cliente_web' : 'mesero',
        ]);

        $servicio = app(ReservaService::class);
        if ($metodo === 'en_linea') {
            $servicio->registrarDepositoEnLinea($reserva, 'nequi');
            $servicio->confirmarDepositoEnLinea($reserva->fresh(), $this->admin);
        } else {
            $servicio->registrarDepositoManual($reserva, $metodo, $this->mesero);
        }

        return $reserva->fresh();
    }

    private function pedido(int $cantidad): Pedido
    {
        return app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => $cantidad]],
        ]);
    }

    private function abonosDe(Pedido $pedido)
    {
        return Pago::where('pedido_id', $pedido->id)->where('tipo', 'deposito')->where('anulado', false)->get();
    }

    // ---- RF-M05-034, RN-M05-19: el depósito se abona a la visita ----

    public function test_RF_M05_034_al_confirmar_la_llegada_el_deposito_se_abona_al_primer_pedido(): void
    {
        $reserva = $this->reservaConDeposito();
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/reservas/{$reserva->id}/completar")->assertOk()->assertJsonPath('estado', 'completada');

        $pedido = $this->pedido(5); // $50.000

        $abono = $this->abonosDe($pedido)->sole();
        $this->assertEquals(20000, $abono->monto);
        $this->assertSame('efectivo', $abono->metodo);
        $this->assertSame('aprobado', $abono->estado);
        $this->assertNull($abono->caja_turno_id, 'El abono no es dinero de la caja del día.');
        $this->assertSame($reserva->id, $abono->reserva_id);

        $reserva->refresh();
        $this->assertEquals(20000, $reserva->deposito_abonado);
        $this->assertSame($pedido->id, $reserva->pedido_id);
        $this->assertTrue($reserva->deposito_aplicado_abono);
        $this->assertNotNull($reserva->llegada_at);

        // La cuenta de la mesa ya descuenta el depósito.
        $this->getJson("/api/mesas/{$this->mesa->id}/cuenta")
            ->assertOk()->assertJsonPath('total', 50000)->assertJsonPath('pagado', 20000)->assertJsonPath('saldo', 30000);
    }

    public function test_el_primer_pedido_en_una_mesa_reservada_es_la_llegada_del_cliente(): void
    {
        $reserva = $this->reservaConDeposito();
        $this->assertSame('reservada', $this->mesa->fresh()->estado);

        $pedido = $this->pedido(3);

        $reserva->refresh();
        $this->assertSame('completada', $reserva->estado);
        $this->assertNotNull($reserva->llegada_at);
        $this->assertEquals(20000, $this->abonosDe($pedido)->sum('monto'));
        $this->assertSame('ocupada', $this->mesa->fresh()->estado);
        $this->assertTrue(Auditoria::where('accion', 'abonar_deposito_reserva')->where('entidad_id', $reserva->id)->exists());
    }

    public function test_si_la_visita_consume_menos_que_el_deposito_el_resto_pasa_a_los_pedidos_nuevos(): void
    {
        $reserva = $this->reservaConDeposito();
        $primero = $this->pedido(1); // $10.000: se abonan $10.000
        $this->assertEquals(10000, $this->abonosDe($primero)->sum('monto'));
        $this->assertEquals(10000, $reserva->fresh()->depositoPorAbonar());

        $segundo = $this->pedido(3); // $30.000: se abonan los $10.000 que quedaban
        $this->assertEquals(10000, $this->abonosDe($segundo)->sum('monto'));
        $this->assertEquals(0, $reserva->fresh()->depositoPorAbonar());

        $tercero = $this->pedido(1);
        $this->assertCount(0, $this->abonosDe($tercero));
    }

    public function test_un_deposito_en_linea_se_abona_con_su_metodo_y_pasarela(): void
    {
        $this->reservaConDeposito('en_linea');
        $pedido = $this->pedido(4);

        $abono = $this->abonosDe($pedido)->sole();
        $this->assertSame('en_linea', $abono->metodo);
        $this->assertSame('nequi', $abono->pasarela);
    }

    // ---- El abono se ajusta solo cuando el pedido baja o se cancela ----

    public function test_si_el_pedido_baja_de_valor_el_abono_se_ajusta_y_pasa_a_otro_pedido(): void
    {
        $reserva = $this->reservaConDeposito();
        $primero = $this->pedido(2); // $20.000, cubierto por el depósito
        $segundo = $this->pedido(3); // $30.000, sin abono

        Sanctum::actingAs($this->mesero);
        $linea = $primero->detalles->first();
        $this->patchJson("/api/pedidos/{$primero->id}/detalles/{$linea->id}", ['cantidad' => 1])->assertOk();

        // El primero queda en $10.000 con $10.000 de abono; los otros $10.000 pasan al segundo.
        $this->assertEquals(10000, $this->abonosDe($primero)->sum('monto'));
        $this->assertEquals(10000, $this->abonosDe($segundo)->sum('monto'));
        $this->assertEquals(20000, $reserva->fresh()->deposito_abonado);
        $this->assertTrue(Pago::where('pedido_id', $primero->id)->where('reverso_tipo', 'ajuste_abono')->exists());
        $this->assertTrue(Auditoria::where('accion', 'ajustar_abono_deposito')->exists());
    }

    public function test_un_descuento_tambien_ajusta_el_abono(): void
    {
        $this->reservaConDeposito();
        $pedido = $this->pedido(2); // $20.000, cubierto por el depósito

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'porcentaje', 'valor' => 50, 'motivo' => 'Demora'])->assertOk();

        $this->assertEquals(10000, $this->abonosDe($pedido)->sum('monto'));
        $this->assertEquals(10000, $pedido->fresh()->total);
    }

    public function test_un_pedido_cancelado_devuelve_su_abono_a_los_demas_pedidos_de_la_visita(): void
    {
        $reserva = $this->reservaConDeposito();
        $primero = $this->pedido(2);
        $segundo = $this->pedido(2);
        $this->assertCount(0, $this->abonosDe($segundo));

        app(PedidoService::class)->cancelar($primero->fresh(), $this->admin, 'Se equivocó de mesa');

        $this->assertCount(0, $this->abonosDe($primero));
        $this->assertEquals(20000, $this->abonosDe($segundo)->sum('monto'));
        $this->assertEquals(20000, $reserva->fresh()->deposito_abonado);
    }

    public function test_el_administrador_anula_un_abono_sin_tocar_la_caja_y_vuelve_por_abonar(): void
    {
        $reserva = $this->reservaConDeposito();
        $pedido = $this->pedido(3);
        $abono = $this->abonosDe($pedido)->sole();

        // Aunque la caja del día cierre, anular un abono no es un ajuste extraordinario. (Se marca cerrada
        // directamente: desde el 26/10/2026 cerrar() exige el pedido finalizado, y aquí sigue abierto.)
        $this->turno->forceFill(['estado' => 'cerrada', 'efectivo_contado' => 120000, 'diferencia' => 0, 'cerrado_por' => $this->admin->id, 'cerrado_at' => now()])->save();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pagos/{$abono->id}/anular", ['motivo' => 'El cliente pidió que no se usara'])->assertOk();

        $this->assertEquals(0, AjusteCaja::count());
        $this->assertEquals(20000, $reserva->fresh()->depositoPorAbonar());
    }

    // ---- Caja: el depósito entra el día que se recibe, no el día de la visita ----

    public function test_RN_M06_08_el_deposito_en_efectivo_entra_a_la_caja_y_el_abono_no_se_cuenta_dos_veces(): void
    {
        $this->reservaConDeposito(); // +20.000 en efectivo
        $this->assertEquals(120000, $this->turno->fresh()->efectivo_esperado);

        $this->pedido(5); // $50.000: abono de $20.000, sin turno
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 30000])->assertOk();

        // Base 100.000 + depósito 20.000 + cobro 30.000 = lo que se vendió más la base.
        $this->assertEquals(150000, $this->turno->fresh()->efectivo_esperado);

        $detalle = $this->actingAs($this->admin)->getJson("/api/caja/turnos/{$this->turno->id}")->assertOk();
        $this->assertEquals(20000, $detalle->json('depositos_efectivo'));
        $this->assertCount(1, $detalle->json('depositos_reserva'));
    }

    public function test_RN_M06_02_sin_caja_abierta_no_se_registra_un_deposito_manual(): void
    {
        $this->turno->update(['estado' => 'cerrada', 'cerrado_at' => now()]);
        $reserva = Reserva::create([
            'mesa_id' => $this->mesa->id, 'cliente_nombre' => 'Ana', 'cliente_telefono' => '3001112233', 'cliente_correo' => 'ana@correo.co',
            'fecha_hora' => now()->addDay(), 'estado' => 'pendiente_pago', 'deposito_valor' => 20000, 'creado_por' => 'mesero',
        ]);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/reservas/{$reserva->id}/deposito/manual", ['metodo' => 'efectivo'])->assertStatus(422);
        $this->assertFalse($reserva->fresh()->deposito_pagado);
    }

    public function test_RN_M05_17_devolver_un_deposito_en_efectivo_sale_de_la_caja(): void
    {
        $reserva = $this->reservaConDeposito();
        $this->assertEquals(120000, $this->turno->fresh()->efectivo_esperado);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/reservas/{$reserva->id}/cancelar")->assertOk();

        $this->assertTrue($reserva->fresh()->deposito_reembolsado);
        $this->assertEquals(100000, $this->turno->fresh()->efectivo_esperado);
    }

    public function test_sin_caja_abierta_no_se_devuelve_un_deposito_en_efectivo(): void
    {
        $reserva = $this->reservaConDeposito();
        $this->turno->update(['estado' => 'cerrada', 'cerrado_at' => now()]);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/reservas/{$reserva->id}/cancelar")->assertStatus(422);
        $this->assertSame('confirmada', $reserva->fresh()->estado);
    }

    public function test_el_reporte_por_metodo_muestra_el_abono_aparte(): void
    {
        $this->reservaConDeposito();
        $this->pedido(5);

        $reporte = app(ReporteService::class)->ventasPorTipoYMetodo(now()->subDay()->toImmutable(), now()->addDay()->toImmutable());
        $metodos = collect($reporte['detalle']['por_metodo'])->pluck('monto_total', 'metodo');

        $this->assertEquals(20000, $metodos['deposito_reserva']);
        $this->assertArrayNotHasKey('efectivo', $metodos->all());
    }
}
