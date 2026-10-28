<?php

namespace Tests\Feature;

use App\Models\PushSuscripcion;
use App\Models\User;
use App\Services\NotificacionService;
use App\Support\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M11 — Avisos push del personal (28/10/2026, RF-M11-013, RN-M11-09, HU-M11-008): los avisos de la campana
 * también llegan por Web Push al equipo donde cada quien los activó; la suscripción va unida a la sesión.
 */
class AvisosPushTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/equipo-1';

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();

        $llaves = WebPush::generarLlaves();
        config(['fragua_real.push.publica' => $llaves['publica'], 'fragua_real.push.privada' => $llaves['privada'],
            'fragua_real.push.asunto' => 'mailto:soporte@fraguareal.com']);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    /** Las llaves que daría el navegador del equipo. */
    private function suscripcion(string $endpoint = self::ENDPOINT): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => WebPush::generarLlaves()['publica'], 'auth' => WebPush::b64(random_bytes(16))]];
    }

    /** Inicia sesión con un token real (la suscripción se une a él). */
    private function conSesion(User $usuario): string
    {
        // El cliente de pruebas recuerda al primer usuario autenticado: se olvida al cambiar de cuenta.
        $this->app['auth']->forgetGuards();

        return $usuario->createToken('prueba')->plainTextToken;
    }

    // ---------- El protocolo ----------

    public function test_el_cifrado_coincide_con_el_ejemplo_del_rfc_8291(): void
    {
        $b = fn (string $t) => WebPush::desdeB64($t);
        $cuerpo = WebPush::cifrar(
            'When I grow up, I want to be a watermelon',
            $b('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            $b('BTBZMqHH6r4Tts7J_aSIgg'),
            [$b('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), $b('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8')],
            $b('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', WebPush::b64($cuerpo));
    }

    public function test_la_firma_vapid_es_valida_y_vale_para_el_servicio_push(): void
    {
        $cabecera = WebPush::cabeceraVapid(self::ENDPOINT, config('fragua_real.push.publica'), config('fragua_real.push.privada'), 'mailto:soporte@fraguareal.com', time() + 3600);

        $this->assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]+$/', $cabecera);
        preg_match('/t=([^,]+), k=(.+)$/', $cabecera, $m);
        $this->assertTrue(WebPush::verificarJwt($m[1], $m[2]));
        $carga = json_decode(WebPush::desdeB64(explode('.', $m[1])[1]), true);
        $this->assertSame('https://fcm.googleapis.com', $carga['aud']);
        $this->assertSame('mailto:soporte@fraguareal.com', $carga['sub']);
        // Otra llave no la valida.
        $this->assertFalse(WebPush::verificarJwt($m[1], WebPush::generarLlaves()['publica']));
    }

    // ---------- Activar y apagar (RF-M11-013, RN-M11-09) ----------

    public function test_RF_M11_013_el_personal_activa_y_apaga_los_avisos_en_su_equipo(): void
    {
        $token = $this->conSesion($this->mesero);
        $this->withToken($token)->postJson('/api/push/estado', ['endpoint' => self::ENDPOINT])->assertOk()
            ->assertJsonPath('disponible', true)->assertJsonPath('activa', false)
            ->assertJsonPath('llave_publica', config('fragua_real.push.publica'));

        $this->withToken($token)->postJson('/api/push/suscripciones', $this->suscripcion())->assertCreated();
        $this->assertSame($this->mesero->tokens()->first()->id, PushSuscripcion::first()->token_id);
        $this->withToken($token)->postJson('/api/push/estado', ['endpoint' => self::ENDPOINT])->assertJsonPath('activa', true);

        $this->withToken($token)->deleteJson('/api/push/suscripciones', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, PushSuscripcion::count());
    }

    public function test_solo_se_aceptan_los_servicios_push_de_los_navegadores_y_llaves_validas(): void
    {
        Sanctum::actingAs($this->mesero);

        $this->postJson('/api/push/suscripciones', $this->suscripcion('https://atacante.example.com/recibir'))->assertStatus(422)
            ->assertJsonPath('message', 'Ese navegador usa un servicio de avisos que el sistema no reconoce.');
        $this->postJson('/api/push/suscripciones', $this->suscripcion('http://fcm.googleapis.com/fcm/send/x'))->assertStatus(422);
        $this->postJson('/api/push/suscripciones', ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => 'abc', 'auth' => 'abc']])->assertStatus(422);

        foreach (['https://updates.push.services.mozilla.com/wpush/v2/a', 'https://web.push.apple.com/Q', 'https://wns2-bn3p.notify.windows.com/w/?token=a'] as $i => $endpoint) {
            $this->postJson('/api/push/suscripciones', $this->suscripcion($endpoint))->assertCreated();
        }
        $this->assertSame(3, PushSuscripcion::count());
    }

    public function test_el_cliente_no_tiene_avisos_push_y_sin_llaves_no_se_activan(): void
    {
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Ana', 'email' => 'ana@test.co', 'documento' => '1020304050', 'password' => bcrypt('secreto123'), 'activo' => true]);
        Sanctum::actingAs($cliente);
        $this->postJson('/api/push/estado')->assertForbidden();
        $this->postJson('/api/push/suscripciones', $this->suscripcion())->assertForbidden();

        config(['fragua_real.push.publica' => '', 'fragua_real.push.privada' => '']);
        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/push/estado')->assertJsonPath('disponible', false)->assertJsonPath('llave_publica', null);
        $this->postJson('/api/push/suscripciones', $this->suscripcion())->assertStatus(422);
    }

    public function test_RN_M11_09_al_cerrar_sesion_el_equipo_deja_de_recibir_avisos(): void
    {
        $token = $this->conSesion($this->mesero);
        $this->withToken($token)->postJson('/api/push/suscripciones', $this->suscripcion())->assertCreated();

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(0, PushSuscripcion::count());
    }

    public function test_un_equipo_compartido_recibe_solo_los_avisos_de_la_cuenta_con_que_se_activo_por_ultima_vez(): void
    {
        $datos = $this->suscripcion();
        $this->withToken($this->conSesion($this->mesero))->postJson('/api/push/suscripciones', $datos)->assertCreated();
        $this->withToken($this->conSesion($this->admin))->postJson('/api/push/suscripciones', $datos)->assertCreated();

        $this->assertSame(1, PushSuscripcion::count());
        $this->assertSame($this->admin->id, PushSuscripcion::first()->user_id);
    }

    // ---------- Envío ----------

    public function test_RF_M11_013_el_aviso_de_la_campana_llega_cifrado_al_equipo_y_no_se_repite(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $this->withToken($this->conSesion($this->admin))->postJson('/api/push/suscripciones', $this->suscripcion())->assertCreated();

        $notificaciones = app(NotificacionService::class);
        $notificaciones->notificar('caja_cerrada_sola', 'La caja del 27/10 se cerró sola', 'Registra el conteo.', 'caja_cerrada_sola:1', ['administrador' => '/admin/caja']);
        $notificaciones->notificar('caja_cerrada_sola', 'La caja del 27/10 se cerró sola', 'Registra el conteo.', 'caja_cerrada_sola:1', ['administrador' => '/admin/caja']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === self::ENDPOINT
            && $r->hasHeader('Content-Encoding', 'aes128gcm')
            && $r->hasHeader('TTL', '3600')
            && str_starts_with($r->header('Authorization')[0], 'vapid t=')
            // Cifrado: el texto no viaja a la vista.
            && ! str_contains($r->body(), 'se cerró sola'));
        $this->assertNotNull(PushSuscripcion::first()->ultimo_envio_at);
    }

    public function test_un_equipo_que_el_servicio_ya_no_reconoce_se_borra(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 410)]);
        $this->withToken($this->conSesion($this->admin))->postJson('/api/push/suscripciones', $this->suscripcion())->assertCreated();

        app(NotificacionService::class)->notificar('caja_cerrada_sola', 'Caja', 'Texto', 'caja_cerrada_sola:2', ['administrador' => '/admin/caja']);

        $this->assertSame(0, PushSuscripcion::count());
    }

    public function test_la_prueba_llega_solo_al_equipo_propio(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $token = $this->conSesion($this->mesero);
        $this->withToken($token)->postJson('/api/push/suscripciones', $this->suscripcion())->assertCreated();

        $this->withToken($token)->postJson('/api/push/prueba', ['endpoint' => self::ENDPOINT])->assertOk()->assertJsonPath('enviado', true);
        Http::assertSentCount(1);

        $this->withToken($this->conSesion($this->admin))->postJson('/api/push/prueba', ['endpoint' => self::ENDPOINT])->assertNotFound();
    }

    public function test_la_cuenta_de_soporte_puede_activarlos_en_su_equipo(): void
    {
        $soporte = User::create(['role' => 'soporte', 'nombre' => 'Soporte', 'email' => 'soporte@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->withToken($this->conSesion($soporte))->postJson('/api/push/suscripciones', $this->suscripcion())->assertCreated();
    }
}
