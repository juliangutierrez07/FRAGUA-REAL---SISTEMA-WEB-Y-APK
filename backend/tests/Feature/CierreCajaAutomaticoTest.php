<?php

namespace Tests\Feature;

use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\Configuracion;
use App\Models\Mesa;
use App\Models\Notificacion;
use App\Models\Producto;
use App\Models\User;
use App\Services\CajaService;
use App\Services\PedidoService;
use App\Support\Caja\PoliticaCaja;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cierre automático de la caja (27/10/2026, M06: RF-M06-039, RN-M06-22): el turno que nadie cerró se
 * cierra solo a la hora de cierre más la tolerancia (Configuración), sin contar el efectivo, aunque queden
 * pedidos sin finalizar (se avisan en la campana). El Administrador registra el conteo después.
 */
class CierreCajaAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private CajaTurno $turno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-27 20:00', 'America/Bogota'));
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->turno = CajaTurno::create([
            'fecha' => '2026-10-27', 'base_inicial' => 50000, 'efectivo_esperado' => 50000,
            'abierto_por' => $this->admin->id, 'abierto_at' => now(), 'estado' => 'abierta',
        ]);
    }

    private function a(string $hora): void
    {
        $this->travelTo(Carbon::parse($hora, 'America/Bogota'));
    }

    public function test_RN_M06_22_el_limite_es_la_hora_de_cierre_mas_la_tolerancia(): void
    {
        $this->assertSame('2026-10-28 00:30', PoliticaCaja::limite('2026-10-27')->setTimezone('America/Bogota')->format('Y-m-d H:i'));

        Configuracion::fijar(PoliticaCaja::HORA_CIERRE, '22:00');
        Configuracion::fijar(PoliticaCaja::TOLERANCIA, 30);
        $this->assertSame('2026-10-27 22:30', PoliticaCaja::limite('2026-10-27')->setTimezone('America/Bogota')->format('Y-m-d H:i'));

        // Una hora de cierre de madrugada es la del día siguiente.
        Configuracion::fijar(PoliticaCaja::HORA_CIERRE, '02:00');
        $this->assertSame('2026-10-28 02:30', PoliticaCaja::limite('2026-10-27')->setTimezone('America/Bogota')->format('Y-m-d H:i'));
    }

    public function test_RF_M06_039_antes_del_limite_la_caja_sigue_abierta(): void
    {
        $this->a('2026-10-28 00:29');
        $this->assertSame(0, app(CajaService::class)->cerrarVencidos());
        $this->assertSame('abierta', $this->turno->fresh()->estado);
    }

    public function test_RF_M06_039_pasado_el_limite_se_cierra_sola_sin_contar_aunque_haya_pedidos_abiertos(): void
    {
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $mesa->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        $this->a('2026-10-28 00:31');
        $this->artisan('caja:cerrar-vencidas')->expectsOutput('Turnos cerrados automáticamente: 1.')->assertSuccessful();

        $turno = $this->turno->fresh();
        $this->assertSame('cerrada', $turno->estado);
        $this->assertTrue($turno->cierre_automatico);
        $this->assertNull($turno->efectivo_contado);
        $this->assertNull($turno->cerrado_por);
        $this->assertNotNull($turno->cerrado_at);
        $this->assertSame('pendiente', $pedido->fresh()->estado, 'El pedido abierto no se toca.');

        $aviso = Notificacion::where('tipo', 'caja_cerrada_sola')->where('user_id', $this->admin->id)->first();
        $this->assertNotNull($aviso);
        $this->assertStringContainsString("#{$pedido->id}", $aviso->texto);

        $this->assertDatabaseHas('auditorias', ['accion' => 'cerrar_caja_automatico', 'entidad_id' => $turno->id]);

        // Ya cerrada, no se vuelve a cerrar.
        $this->assertSame(0, app(CajaService::class)->cerrarVencidos());
    }

    public function test_RF_M06_039_el_administrador_registra_el_conteo_despues(): void
    {
        $this->a('2026-10-28 01:00');
        app(CajaService::class)->cerrarVencidos();
        Sanctum::actingAs($this->admin);

        // Con descuadre, sin justificación: no.
        $this->postJson("/api/caja/turnos/{$this->turno->id}/conteo", ['efectivo_contado' => 48000])->assertStatus(422);

        $this->postJson("/api/caja/turnos/{$this->turno->id}/conteo", [
            'efectivo_contado' => 48000, 'justificacion_descuadre' => 'Faltó un billete de 2.000',
        ])->assertOk()->assertJsonPath('diferencia', '-2000.00');

        $turno = $this->turno->fresh();
        $this->assertSame('48000.00', (string) $turno->efectivo_contado);
        $this->assertDatabaseHas('auditorias', ['accion' => 'registrar_conteo_caja', 'entidad_id' => $turno->id]);

        // Se registra una sola vez.
        $this->postJson("/api/caja/turnos/{$this->turno->id}/conteo", ['efectivo_contado' => 50000])->assertStatus(422);
    }

    public function test_un_turno_cerrado_a_mano_no_recibe_conteo(): void
    {
        app(CajaService::class)->cerrar($this->turno, $this->admin, 50000);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/caja/turnos/{$this->turno->id}/conteo", ['efectivo_contado' => 50000])->assertStatus(422);
    }

    public function test_el_aviso_de_caja_abierta_sale_a_la_hora_de_cierre_y_dice_cuando_se_cierra_sola(): void
    {
        $this->a('2026-10-27 23:29');
        app(\App\Services\NotificacionService::class)->revisar();
        $this->assertSame(0, Notificacion::where('tipo', 'caja_abierta')->count());

        $this->a('2026-10-27 23:30');
        app(\App\Services\NotificacionService::class)->revisar();
        $aviso = Notificacion::where('tipo', 'caja_abierta')->first();
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('12:30 a. m.', $aviso->texto);
    }

    public function test_la_hora_y_la_tolerancia_se_cambian_en_configuracion(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/configuraciones', ['valor_domicilio' => 2000, 'deposito_reserva' => 20000, 'caja_hora_cierre' => '25:00', 'caja_tolerancia_minutos' => 400])
            ->assertStatus(422)->assertJsonValidationErrors(['caja_hora_cierre', 'caja_tolerancia_minutos']);

        $this->putJson('/api/configuraciones', ['valor_domicilio' => 2000, 'deposito_reserva' => 20000, 'caja_hora_cierre' => '22:00', 'caja_tolerancia_minutos' => 15])->assertOk();
        $this->getJson('/api/configuracion')
            ->assertJsonPath('caja_hora_cierre', '22:00')
            ->assertJsonPath('caja_tolerancia_minutos', 15);

        $this->getJson('/api/caja/turno-actual')->assertJsonPath('se_cierra_sola_at', Carbon::parse('2026-10-27 22:15', 'America/Bogota')->toIso8601String());
    }
}
