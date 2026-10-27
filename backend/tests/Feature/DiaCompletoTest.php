<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Comanda;
use App\Models\Comprobante;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\User;
use App\Notifications\ListoParaRecogerNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prueba de un día completo de operación (cierre del plan, 13/10/2026). Un solo recorrido por la API,
 * en orden, con los tres roles y los cuatro canales, para comprobar que las piezas de todas las fases
 * encajan y que las cifras cuadran de punta a punta:
 *
 *  09:00 el Administrador abre la caja con $100.000.
 *  10:00 una clienta reserva la Mesa 2 por la web y paga el depósito en línea ($20.000).
 *  11:00 pedido local en la Mesa 1 (Mesero): $44.000 en efectivo.
 *  11:30 pedido por QR en la Mesa 3: $20.000 con tarjeta.
 *  12:00 domicilio de un invitado, contra entrega: $22.000 en efectivo (con el domicilio).
 *  12:15 recogida de un cliente con cuenta, pagada en línea: $8.000.
 *  14:00 compras y gastos en efectivo: $30.000 y $5.000.
 *  15:00 un pedido confirmado se cancela (con la clave del Administrador).
 *  16:00 llega la reserva: pedido de $40.000 en la Mesa 2; el depósito se abona y el resto ($20.000) en efectivo.
 *  21:00 cierre de caja (sin descuadre), cierre del día y reportes.
 *
 * Ventas del día: 44.000 + 20.000 + 22.000 + 8.000 + 40.000 = $134.000.
 * Efectivo esperado: 100.000 + 44.000 + 22.000 + 20.000 − 30.000 − 5.000 = $151.000.
 * Resultado operativo: 134.000 − 30.000 − 5.000 = $99.000.
 */
class DiaCompletoTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\PagoDirectoDePrueba;

    private const HOY = '2026-10-13';

    private User $admin;
    private User $mesero;
    private User $cliente;
    private Producto $clasica;
    private Producto $gaseosa;
    private Mesa $mesa1;
    private Mesa $mesa2;
    private Mesa $mesa3;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('Clave-Admin-2026'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('Clave-Mesero-2026'), 'activo' => true]);
        $this->cliente = User::create(['role' => 'cliente', 'nombre' => 'Carla Cliente', 'email' => 'carla@correo.co', 'telefono' => '3109998877', 'password' => bcrypt('Clave-Cliente-2026'), 'activo' => true]);

        $hamburguesas = Categoria::create(['nombre' => 'Hamburguesas']);
        $bebidas = Categoria::create(['nombre' => 'Bebidas']);
        $this->clasica = Producto::create(['categoria_id' => $hamburguesas->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->gaseosa = Producto::create(['categoria_id' => $bebidas->id, 'nombre' => 'Gaseosa', 'precio_base' => 4000, 'tipo_venta' => 'bebida']);
        $this->mesa1 = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-mesa-1']);
        $this->mesa2 = Mesa::create(['nombre' => 'Mesa 2', 'qr_code' => 'qr-mesa-2']);
        $this->mesa3 = Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-mesa-3']);
    }

    // ───────────── ayudas ─────────────

    private function a(string $hora): void
    {
        $this->travelTo(CarbonImmutable::parse(self::HOY.' '.$hora, 'America/Bogota'));
    }

    private function como(?User $usuario): void
    {
        $this->app['auth']->forgetGuards();
        if ($usuario) {
            Sanctum::actingAs($usuario);
        }
    }

    /**
     * El Mesero confirma (si aún está pendiente), imprime la comanda y pasa a preparación. Desde el
     * 27/10/2026 (RF-M02-L-036) el pedido que toma el Mesero ya nace confirmado; el del QR no.
     */
    private function aCocina(int $pedidoId): void
    {
        $this->como($this->mesero);
        if (\App\Models\Pedido::findOrFail($pedidoId)->estado === 'pendiente') {
            $this->postJson("/api/pedidos/{$pedidoId}/confirmar")->assertOk()->assertJsonPath('estado', 'confirmado');
        }
        $this->assertSame('confirmado', \App\Models\Pedido::findOrFail($pedidoId)->estado);
        $comanda = Comanda::where('pedido_id', $pedidoId)->where('tipo', 'inicial')->sole();
        $this->get("/api/comandas/{$comanda->id}/ticket")->assertOk();
        $this->postJson("/api/pedidos/{$pedidoId}/preparando")->assertOk()->assertJsonPath('estado', 'preparando');
    }

    private function lineas(array $cantidades): array
    {
        $lineas = [];
        foreach ($cantidades as [$producto, $cantidad]) {
            $lineas[] = ['producto_id' => $producto->id, 'cantidad' => $cantidad];
        }

        return $lineas;
    }

    // ───────────── el día ─────────────

    public function test_un_dia_completo_de_operacion_cuadra_de_punta_a_punta(): void
    {
        // 09:00 — Apertura de caja (RN-M06-01/03).
        $this->a('09:00');
        $this->como($this->admin);
        $turnoId = $this->postJson('/api/caja/abrir', ['base_inicial' => 100000])->assertSuccessful()->json('id');
        $this->postJson('/api/caja/abrir', ['base_inicial' => 50000])->assertStatus(422); // un solo turno por día

        // 10:00 — Reserva web de la Mesa 2 para las 4:00 p. m. (primera hora del horario de reservas y
        // con más de 3 horas de anticipación), depósito en línea (modo manual).
        $this->a('10:00');
        $this->como(null);
        $creada = $this->postJson('/api/reservas', [
            'mesa_id' => $this->mesa2->id, 'cliente_nombre' => 'Laura Pérez', 'cliente_telefono' => '3105551234',
            'cliente_correo' => 'laura@correo.co', 'fecha_hora' => self::HOY.' 16:00',
        ])->assertCreated()->assertJsonPath('estado', 'pendiente_pago');
        $reservaId = $creada->json('id');
        $this->pagoDirecto("/api/reservas/{$reservaId}/deposito/en-linea", ['pasarela' => 'nequi', 'token' => $creada->json('token_publico')])
            ->assertOk()->assertJsonPath('checkout', null);
        $this->como($this->mesero);
        $this->postJson("/api/reservas/{$reservaId}/deposito/en-linea/confirmar")->assertOk()->assertJsonPath('estado', 'confirmada');

        // 11:00 — Pedido local en la Mesa 1: 2 Clásicas + 1 Gaseosa = $44.000, efectivo.
        $this->a('11:00');
        $local = $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa1->id,
            'lineas' => $this->lineas([[$this->clasica, 2], [$this->gaseosa, 1]]),
        ])->assertCreated()->json('id');
        $this->assertSame('ocupada', $this->mesa1->fresh()->estado);
        $this->aCocina($local);
        $this->postJson("/api/pedidos/{$local}/cobrar", ['metodo' => 'efectivo', 'monto' => 44000])->assertOk()->assertJsonPath('estado', 'finalizado');

        // 11:30 — Pedido por QR en la Mesa 3 (sin cuenta): 1 Clásica, con tarjeta.
        $this->a('11:30');
        $this->como(null);
        $this->getJson('/api/mesas/qr/qr-mesa-3')->assertOk();
        $qr = $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa3->id,
            'lineas' => $this->lineas([[$this->clasica, 1]]),
        ])->assertCreated()->assertJsonPath('estado', 'pendiente')->json('id');
        $this->aCocina($qr);
        $this->postJson("/api/pedidos/{$qr}/cobrar", ['metodo' => 'tarjeta', 'monto' => 20000])->assertOk()->assertJsonPath('estado', 'finalizado');

        // 12:00 — Domicilio de un invitado, contra entrega: 1 Clásica + $2.000 de domicilio.
        $this->a('12:00');
        $this->como(null);
        $domicilio = $this->postJson('/api/pedidos', [
            'tipo' => 'domicilio', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Pedro Gómez', 'cliente_invitado_telefono' => '3001112233', 'cliente_invitado_correo' => 'pedro@correo.co',
            'direccion_entrega' => 'Calle 5 # 3-20', 'barrio' => 'Centro', 'referencia' => 'Portón negro',
            'lineas' => $this->lineas([[$this->clasica, 1]]),
        ])->assertCreated()->assertJsonPath('total', fn ($t) => (float) $t === 22000.0);
        $domicilioId = $domicilio->json('id');
        $this->getJson('/api/pedidos/seguimiento/'.$domicilio->json('token_publico'))->assertOk()->assertJsonPath('estado', 'pendiente');
        $this->aCocina($domicilioId);
        $this->postJson("/api/pedidos/{$domicilioId}/cobrar", ['metodo' => 'efectivo', 'monto' => 22000])->assertStatus(422); // primero la entrega (RN-M06-06)
        $this->postJson("/api/pedidos/{$domicilioId}/entregar")->assertOk();
        $this->postJson("/api/pedidos/{$domicilioId}/cobrar", ['metodo' => 'efectivo', 'monto' => 22000])->assertOk()->assertJsonPath('estado', 'finalizado');

        // 12:15 — Recogida de una clienta con cuenta, pagada en línea (modo manual: lo aprueba el Mesero).
        $this->a('12:15');
        $this->como($this->cliente);
        $recogida = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'metodo_pago_preferido' => 'en_linea', 'pasarela_preferida' => 'nequi',
            'lineas' => $this->lineas([[$this->gaseosa, 2]]),
        ])->assertCreated()->json('id');
        $pagoEnLinea = $this->pagoDirecto("/api/pedidos/{$recogida}/pagar-en-linea", ['pasarela' => 'nequi', 'monto' => 8000])->assertCreated()->json('id');
        $this->como($this->mesero);
        $this->postJson("/api/pedidos/{$recogida}/confirmar")->assertStatus(422); // RN-M06-05: el pago aún no está aprobado
        $this->postJson("/api/pagos/{$pagoEnLinea}/confirmar-en-linea")->assertOk();
        $this->aCocina($recogida);
        $this->postJson("/api/pedidos/{$recogida}/listo")->assertOk();
        Notification::assertSentTo(new AnonymousNotifiable, ListoParaRecogerNotification::class,
            fn ($aviso, $canales, $destino) => array_key_exists('carla@correo.co', $destino->routes['mail']));
        $this->postJson("/api/pedidos/{$recogida}/recoger")->assertOk()->assertJsonPath('estado', 'finalizado');

        // 14:00 — Compras y gastos del día, en efectivo.
        $this->a('14:00');
        $this->como($this->admin);
        $this->postJson('/api/compras-gastos', ['tipo' => 'compra', 'categoria' => 'Alimentos', 'descripcion' => 'Carne y pan', 'valor' => 30000, 'metodo_pago' => 'efectivo', 'fecha' => self::HOY])->assertCreated();
        $this->postJson('/api/compras-gastos', ['tipo' => 'gasto', 'categoria' => 'Transporte', 'descripcion' => 'Domiciliario', 'valor' => 5000, 'metodo_pago' => 'efectivo', 'fecha' => self::HOY])->assertCreated();

        // 15:00 — Un pedido confirmado se cancela: la cocina recibe la comanda de cancelación.
        $this->a('15:00');
        $this->como($this->mesero);
        $cancelado = $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa1->id,
            'lineas' => $this->lineas([[$this->gaseosa, 1]]),
        ])->assertCreated()->json('id');
        $this->aCocina($cancelado);
        $this->postJson("/api/pedidos/{$cancelado}/cancelar", ['motivo' => 'El cliente se fue'])->assertStatus(422); // ya en preparación: falta la clave
        $this->postJson("/api/pedidos/{$cancelado}/cancelar", ['motivo' => 'El cliente se fue', 'password_administrador' => 'Clave-Admin-2026'])->assertOk();
        $this->assertSame('pendiente', Comanda::where('pedido_id', $cancelado)->where('tipo', 'cancelacion')->sole()->estado_impresion);
        $this->getJson('/api/comandas/pendientes')->assertOk()->assertJsonCount(1);

        // 16:00 — Llega la reserva: Mesa 2 ocupada, pedido de $40.000; el depósito se abona.
        $this->a('16:00');
        $this->como($this->mesero);
        $this->postJson("/api/reservas/{$reservaId}/completar")->assertOk()->assertJsonPath('estado', 'completada');
        $visita = $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa2->id,
            'lineas' => $this->lineas([[$this->clasica, 2]]),
        ])->assertCreated()->json('id');
        $this->aCocina($visita);
        $this->getJson("/api/mesas/{$this->mesa2->id}/cuenta")->assertOk()->assertJsonPath('saldo', fn ($s) => (float) $s === 20000.0);
        $this->postJson("/api/mesas/{$this->mesa2->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 20000])->assertOk();
        $this->assertSame('finalizado', Pedido::find($visita)->estado);

        // Todas las mesas quedan libres.
        $this->assertSame(['libre', 'libre', 'libre'], [$this->mesa1->fresh()->estado, $this->mesa2->fresh()->estado, $this->mesa3->fresh()->estado]);

        // 21:00 — Cierre de caja: el efectivo esperado sale de las cuentas del día, sin descuadre.
        $this->a('21:00');
        $this->como($this->admin);
        $turno = $this->getJson("/api/caja/turnos/{$turnoId}")->assertOk();
        $this->assertEquals(151000, (float) $turno->json('efectivo_esperado'), '100.000 + 44.000 + 22.000 + 20.000 − 30.000 − 5.000');
        $this->postJson("/api/caja/turnos/{$turnoId}/cerrar", ['efectivo_contado' => 151000])->assertOk()->assertJsonPath('estado', 'cerrada');

        // Cierre del día (M03): ventas, compras, gastos y resultado, con su desglose.
        $previa = $this->getJson('/api/cierres-diarios/previa?fecha='.self::HOY)->assertOk();
        $this->assertEquals(134000, $previa->json('ventas.total'));
        $porMetodo = collect($previa->json('ventas.por_metodo'))->pluck('monto', 'metodo')->map(fn ($v) => (float) $v);
        $this->assertEquals(
            collect(['efectivo' => 86000.0, 'tarjeta' => 20000.0, 'en_linea' => 8000.0, 'deposito_reserva' => 20000.0])->sortKeys()->all(),
            $porMetodo->sortKeys()->all(),
            'por forma de pago: el depósito de la reserva se ve aparte (no entra dos veces a la caja)',
        );
        $porTipo = collect($previa->json('ventas.por_tipo'))->pluck('monto', 'tipo_venta')->map(fn ($v) => (float) $v);
        $this->assertEquals(['alimento' => 120000.0, 'bebida' => 12000.0], $porTipo->except('otro')->sortKeys()->all(), '6 Clásicas y 3 Gaseosas; el domicilio no es un producto');
        $cierre = $this->postJson('/api/cierres-diarios', ['fecha' => self::HOY])->assertCreated()->json('cierre');
        $this->assertSame(['134000.00', '30000.00', '5000.00', '99000.00'], [$cierre['total_ventas'], $cierre['total_compras'], $cierre['total_gastos'], $cierre['resultado_operativo']]);
        $this->get("/api/cierres-diarios/{$cierre['id']}/pdf")->assertOk();

        // Los reportes dicen lo mismo que el cierre (RNF-M08-002).
        $rango = 'periodo=personalizado&desde='.self::HOY.'&hasta='.self::HOY;
        $this->assertEquals(134000, (float) $this->getJson("/api/reportes/ventas-totales?{$rango}")->assertOk()->json('reporte.resumen.monto_total'));
        // Por producto suman 132.000: los $2.000 del domicilio no son un producto.
        $this->assertEquals(132000, (float) $this->getJson("/api/reportes/ventas-por-producto?{$rango}")->json('reporte.resumen.monto_total'));
        $uso = $this->getJson("/api/reportes/uso-de-mesas?{$rango}")->assertOk()->json('reporte.resumen');
        // El resultado operativo del periodo sale de los cierres guardados (usa AT TIME ZONE: PostgreSQL).
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            $resultado = $this->getJson("/api/reportes/resultado-operativo?{$rango}")->assertOk()->json('reporte.resumen');
            $this->assertEquals([134000, 30000, 5000, 99000], array_map('floatval', [$resultado['total_ventas'], $resultado['total_compras'], $resultado['total_gastos'], $resultado['resultado_operativo']]));
        }
        // Tres visitas (Mesas 1, 2 y 3; el pedido cancelado no cuenta) y las tres mesas usadas.
        $this->assertSame([3, 3, 3], [$uso['visitas'], $uso['pedidos_locales'], $uso['mesas_con_uso']]);

        // Cada pedido pagado tiene su comprobante, con números seguidos (RN-M06-07).
        $this->assertSame(['FR-000001', 'FR-000002', 'FR-000003', 'FR-000004', 'FR-000005'],
            Comprobante::whereNull('anulado_at')->orderBy('consecutivo')->pluck('numero')->all());
        $this->assertSame(['impreso', 'impreso', 'digital', 'digital', 'impreso'],
            Comprobante::whereNull('anulado_at')->orderBy('consecutivo')->pluck('tipo')->all());

        // Seis pedidos confirmados, seis comandas iniciales; ninguna se perdió.
        $this->assertSame(6, Comanda::where('tipo', 'inicial')->count());
        $this->assertSame(6, Comanda::where('tipo', 'inicial')->where('estado_impresion', 'impresa')->count());

        // La auditoría cuenta el día.
        $acciones = Auditoria::pluck('accion')->unique()->values()->all();
        foreach (['abrir_caja', 'confirmar_deposito_reserva', 'confirmar_pago_en_linea', 'imprimir_comanda', 'cancelar_pedido', 'cerrar_caja'] as $accion) {
            $this->assertContains($accion, $acciones, "falta {$accion} en la auditoría");
        }

        // La reserva quedó completada, con el depósito abonado y nada pendiente.
        $reserva = Reserva::find($reservaId);
        $this->assertSame(['completada', true], [$reserva->estado, (bool) $reserva->deposito_aplicado_abono]);
        $this->assertEquals(20000, (float) $reserva->deposito_abonado);
    }
}
