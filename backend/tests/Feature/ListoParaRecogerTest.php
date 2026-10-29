<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\PedidoAviso;
use App\Models\Producto;
use App\Models\User;
use App\Notifications\ListoParaRecogerNotification;
use App\Services\AvisoPedidoService;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 4, tarea 5 (13/10/2026): aviso «listo para recoger» por correo (D3: correo en V1, SMS
 * después). RF-M07-R-012, RN-M07-R-03, HU-M07-012.
 */
class ListoParaRecogerTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private Producto $hamburguesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
    }

    /** Recogida web de invitado (con o sin correo), confirmada y en preparación. */
    private function recogidaEnPreparacion(array $datos = []): array
    {
        $respuesta = $this->postJson('/api/pedidos', array_merge([
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Laura Pérez', 'cliente_invitado_telefono' => '3105551234',
            'cliente_invitado_correo' => ' Laura@Correo.co ',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 2]],
        ], $datos))->assertCreated();

        $pedido = Pedido::findOrFail($respuesta->json('id'));
        $pedido->forceFill(['estado' => 'preparando', 'confirmado_at' => now(), 'preparando_at' => now()])->save();

        return [$pedido, $respuesta->json('token_publico')];
    }

    public function test_RF_M07_R_012_al_marcarlo_listo_el_invitado_recibe_el_correo_con_su_enlace(): void
    {
        Notification::fake();
        [$pedido, $token] = $this->recogidaEnPreparacion();
        $this->assertSame('laura@correo.co', $pedido->cliente_invitado_correo, 'se guarda limpio y en minúsculas');

        Sanctum::actingAs($this->mesero);
        $respuesta = $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertOk()
            ->assertJsonPath('estado', 'preparando') // no hay estado LISTO
            ->assertJsonPath('listo_por', $this->mesero->id)
            ->assertJsonPath('avisos.0.tipo', 'listo_para_recoger')
            ->assertJsonPath('avisos.0.estado', 'enviado');
        $this->assertNotNull($respuesta->json('listo_at'));
        $this->assertTrue($pedido->detalles()->get()->every->preparado, 'todas las líneas quedan preparadas');

        Notification::assertSentTo(new AnonymousNotifiable, ListoParaRecogerNotification::class, function ($aviso, $canales, $destino) use ($pedido) {
            return $aviso->pedido->is($pedido) && array_key_exists('laura@correo.co', $destino->routes['mail']);
        });
        $this->assertTrue(Auditoria::where('accion', 'enviar_aviso_pedido')->where('entidad_id', $pedido->id)->exists());

        $correo = (new ListoParaRecogerNotification($pedido->fresh()))->toMail(new AnonymousNotifiable);
        $this->assertStringContainsString("#{$pedido->id} está listo para recoger", $correo->subject);
        $this->assertStringContainsString('/seguimiento/'.$token, $correo->actionUrl);
        // 28/10/2026: el saldo va en la tarjeta de resumen del correo con la marca.
        $this->assertSame('Pagas $40.000 al recoger', $correo->viewData['resumen']['Pago']);
        $html = (string) $correo->render();
        $this->assertStringContainsString('Pagas $40.000 al recoger', $html);
        $this->assertStringContainsString('El equipo de Fragua Real Burger', $html);

        // El seguimiento del invitado lo muestra.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/pedidos/seguimiento/{$token}")->assertOk()->assertJsonPath('estado', 'preparando')
            ->assertJsonPath('listo_at', fn ($valor) => $valor !== null);
    }

    public function test_el_cliente_con_cuenta_recibe_el_aviso_en_su_correo_y_lo_ve_en_mis_pedidos(): void
    {
        Notification::fake();
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Pedro Cliente', 'email' => 'pedro@correo.co', 'telefono' => '3001112233', 'password' => 'secreto123', 'activo' => true]);
        Sanctum::actingAs($cliente);
        [$pedido] = $this->recogidaEnPreparacion(['cliente_invitado_nombre' => null, 'cliente_invitado_telefono' => null, 'cliente_invitado_correo' => null]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertOk();
        Notification::assertSentTo(new AnonymousNotifiable, ListoParaRecogerNotification::class, fn ($a, $c, $destino) => array_key_exists('pedro@correo.co', $destino->routes['mail']));

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($cliente);
        $this->getJson('/api/pedidos')->assertOk()->assertJsonPath('data.0.listo_at', fn ($valor) => $valor !== null);
    }

    public function test_sin_correo_se_marca_listo_pero_no_hay_aviso(): void
    {
        Notification::fake();
        [$pedido] = $this->recogidaEnPreparacion(['cliente_invitado_correo' => null]);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertOk()->assertJsonPath('avisos', []);

        $this->assertNotNull($pedido->fresh()->listo_at);
        Notification::assertNothingSent();
    }

    public function test_solo_una_recogida_en_preparacion_y_una_sola_vez(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->mesero);

        $domicilio = $this->postJson('/api/pedidos', [
            'tipo' => 'domicilio', 'origen' => 'mesero', 'cliente_invitado_nombre' => 'Marta', 'cliente_invitado_telefono' => '3001234567',
            'direccion_entrega' => 'Calle 1', 'barrio' => 'Centro', 'referencia' => 'Casa azul',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->json('id');
        Pedido::whereKey($domicilio)->update(['estado' => 'preparando']);
        $this->postJson("/api/pedidos/{$domicilio}/listo")->assertStatus(422)
            ->assertJsonPath('message', 'Solo un pedido para recoger se marca como listo para recoger.');

        [$pedido] = $this->recogidaEnPreparacion();
        $pedido->forceFill(['estado' => 'confirmado'])->save();
        $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertStatus(422)
            ->assertJsonPath('message', 'Solo se marca listo un pedido en preparación.');

        $pedido->forceFill(['estado' => 'preparando'])->save();
        $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertOk();
        $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertStatus(422);
        Notification::assertSentTimes(ListoParaRecogerNotification::class, 1);
    }

    public function test_el_administrador_tambien_lo_marca_y_el_cliente_no(): void
    {
        Notification::fake();
        [$pedido] = $this->recogidaEnPreparacion();
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Otro', 'email' => 'otro@correo.co', 'password' => 'secreto123', 'activo' => true]);

        Sanctum::actingAs($cliente);
        $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertForbidden();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertOk()->assertJsonPath('listo_por', $this->admin->id);
    }

    public function test_si_el_correo_falla_queda_fallido_y_se_reintenta(): void
    {
        $this->mock(AvisoPedidoService::class, function ($mock) {
            $mock->makePartial();
            $mock->shouldReceive('entregar')->once()->andThrow(new RuntimeException('Conexión rechazada por el servidor SMTP'));
            $mock->shouldReceive('entregar')->andReturnNull();
        });
        [$pedido] = $this->recogidaEnPreparacion();

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertOk()
            ->assertJsonPath('avisos.0.estado', 'fallido');
        $aviso = PedidoAviso::sole();
        $this->assertStringContainsString('SMTP', $aviso->error);
        $this->assertTrue(Auditoria::where('accion', 'falla_aviso_pedido')->exists());

        $this->postJson("/api/pedidos/{$pedido->id}/avisos/{$aviso->id}/reintentar")->assertOk()
            ->assertJsonPath('avisos.0.estado', 'enviado')
            ->assertJsonPath('avisos.0.intentos', 2);
        $this->postJson("/api/pedidos/{$pedido->id}/avisos/{$aviso->id}/reintentar")->assertStatus(422);
    }

    public function test_el_pedido_listo_se_recoge_y_finaliza_como_siempre(): void
    {
        Notification::fake();
        app(CajaService::class)->abrir($this->admin, 0);
        [$pedido] = $this->recogidaEnPreparacion();
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/listo")->assertOk();

        $this->postJson("/api/pedidos/{$pedido->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 40000])->assertSuccessful();
        $this->postJson("/api/pedidos/{$pedido->id}/recoger")->assertOk()->assertJsonPath('estado', 'finalizado');

        $historial = collect($this->getJson("/api/pedidos/{$pedido->id}/historial")->json('data'));
        $listo = $historial->firstWhere('descripcion', 'Listo para recoger');
        $this->assertSame('Mario Mesero', $listo['quien']);
    }

    public function test_el_correo_del_invitado_debe_ser_valido_y_no_se_guarda_en_pedidos_locales_ni_de_clientes(): void
    {
        $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Laura', 'cliente_invitado_telefono' => '3105551234',
            'cliente_invitado_correo' => 'no-es-un-correo',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['cliente_invitado_correo' => 'Escribe un correo válido o déjalo vacío.']);

        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Pedro', 'email' => 'pedro@correo.co', 'telefono' => '3001112233', 'password' => 'secreto123', 'activo' => true]);
        Sanctum::actingAs($cliente);
        $id = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'cliente_invitado_correo' => 'otro@correo.co',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->assertCreated()->json('id');
        $this->assertNull(Pedido::find($id)->cliente_invitado_correo);
        $this->assertSame('pedro@correo.co', Pedido::find($id)->correoDeContacto());
    }
}
