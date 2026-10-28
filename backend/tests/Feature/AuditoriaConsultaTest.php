<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\User;
use App\Notifications\RecuperarPasswordNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 3, tarea 1 (12/10/2026): eventos de seguridad auditados (RF-M01-017/024, RN-USR-12),
 * consulta de la auditoría solo para el Administrador (RF-M01-025, HU-M01-020, RNF-TRANS-023)
 * y un historial que la base de datos no deja cambiar ni borrar (RNF-TRANS-022, RN-USR-13).
 */
class AuditoriaConsultaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    public function test_RF_M01_017_inicio_de_sesion_exitoso_y_fallido_quedan_auditados_sin_la_contrasena(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'mesero@test.co', 'password' => 'secreto123'], ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/129.0 Mobile Safari/537.36'])
            ->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'mesero@test.co', 'password' => 'equivocada'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => 'nadie@test.co', 'password' => 'loquesea'])->assertStatus(422);

        $exito = Auditoria::where('accion', 'inicio_sesion')->sole();
        $this->assertSame($this->mesero->id, $exito->user_id);
        $this->assertSame($this->mesero->id, $exito->entidad_id);
        $this->assertNotNull($exito->ip);

        $fallidos = Auditoria::where('accion', 'inicio_sesion_fallido')->orderBy('id')->get();
        $this->assertCount(2, $fallidos);
        $this->assertSame('contrasena_incorrecta', $fallidos[0]->detalles['motivo']);
        $this->assertSame($this->mesero->id, $fallidos[0]->entidad_id);
        $this->assertNull($fallidos[0]->user_id);
        $this->assertSame('cuenta_inexistente', $fallidos[1]->detalles['motivo']);
        $this->assertSame('nadie@test.co', $fallidos[1]->detalles['correo']);
        $this->assertStringNotContainsString('equivocada', json_encode(Auditoria::all()->toArray()));

        // Cuenta desactivada con la contraseña correcta: también se registra, con su motivo.
        $this->mesero->update(['activo' => false]);
        $this->postJson('/api/auth/login', ['email' => 'mesero@test.co', 'password' => 'secreto123'])->assertStatus(422);
        $this->assertSame('cuenta_inactiva', Auditoria::where('accion', 'inicio_sesion_fallido')->latest('id')->first()->detalles['motivo']);
    }

    public function test_RN_USR_12_registro_cierre_de_sesion_y_cambios_de_contrasena_quedan_auditados(): void
    {
        Notification::fake();

        $registro = $this->postJson('/api/auth/register', [
            'nombre' => 'Carla Cliente', 'email' => 'carla@test.co', 'documento' => '1020304050',
            'password' => 'Clave12345', 'password_confirmation' => 'Clave12345',
        ])->assertCreated();
        $cliente = User::where('email', 'carla@test.co')->sole();
        $token = $registro->json('token');

        $this->withToken($token)->postJson('/api/perfil/password', [
            'password_actual' => 'Clave12345', 'password' => 'Nueva12345', 'password_confirmation' => 'Nueva12345',
        ])->assertOk();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // Recuperación: solicitud y restablecimiento (fallido con token malo, luego exitoso).
        $this->postJson('/api/auth/recuperar', ['email' => 'carla@test.co'])->assertOk();
        Notification::assertSentTo($cliente, RecuperarPasswordNotification::class);
        // El token real solo viaja en el correo; para la prueba se fija uno conocido.
        $tokenPlano = 'token-de-prueba';
        DB::table('password_reset_tokens')->where('email', 'carla@test.co')->update(['token' => Hash::make($tokenPlano)]);
        $this->postJson('/api/auth/restablecer', ['email' => 'carla@test.co', 'token' => 'malo', 'password' => 'Otra12345', 'password_confirmation' => 'Otra12345'])
            ->assertStatus(422);
        $this->postJson('/api/auth/restablecer', ['email' => 'carla@test.co', 'token' => $tokenPlano, 'password' => 'Otra12345', 'password_confirmation' => 'Otra12345'])
            ->assertOk();

        $acciones = Auditoria::where('entidad', 'User')->where('entidad_id', $cliente->id)->orderBy('id')->pluck('accion')->all();
        $this->assertSame([
            'registro_cliente', 'cambiar_contrasena', 'cierre_sesion', 'solicitar_recuperacion',
            'restablecer_contrasena_fallido', 'restablecer_contrasena',
        ], $acciones);
        $this->assertSame('token_invalido', Auditoria::where('accion', 'restablecer_contrasena_fallido')->sole()->detalles['motivo']);

        // Pedir recuperación para un correo que no existe no deja rastro de esa cuenta ni cambia la respuesta.
        $this->postJson('/api/auth/recuperar', ['email' => 'nadie@test.co'])->assertOk();
        $this->assertSame(1, Auditoria::where('accion', 'solicitar_recuperacion')->count());
    }

    public function test_RF_M01_017_alta_de_usuario_y_cambio_de_configuracion_quedan_auditados(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/usuarios', [
            'role' => 'mesero', 'nombre' => 'Nuevo Mesero', 'email' => 'nuevo@test.co',
            'password' => 'Clave12345', 'password_confirmation' => 'Clave12345',
        ])->assertCreated();
        $alta = Auditoria::where('accion', 'crear_usuario')->sole();
        $this->assertSame($this->admin->id, $alta->user_id);
        $this->assertSame('mesero', $alta->detalles['rol']);
        $this->assertArrayNotHasKey('password', $alta->detalles);

        $this->putJson('/api/configuraciones', ['valor_domicilio' => 6000, 'deposito_reserva' => 20000])->assertOk();
        $this->putJson('/api/configuraciones', ['valor_domicilio' => 6000, 'deposito_reserva' => 25000])->assertOk();

        $cambios = Auditoria::where('accion', 'editar_configuracion')->orderBy('id')->get();
        $this->assertCount(2, $cambios);
        $this->assertSame(['antes' => '20000', 'despues' => '25000'], $cambios[1]->detalles['cambios']['deposito_reserva']);
        $this->assertArrayNotHasKey('valor_domicilio', $cambios[1]->detalles['cambios']); // no cambió
    }

    public function test_RNF_TRANS_023_solo_el_administrador_consulta_la_auditoria(): void
    {
        $this->getJson('/api/auditorias')->assertUnauthorized();

        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/auditorias')->assertForbidden();
        $this->getJson('/api/auditorias/opciones')->assertForbidden();

        $cliente = User::create(['role' => 'cliente', 'nombre' => 'C', 'email' => 'c@test.co', 'password' => bcrypt('x12345678'), 'activo' => true]);
        Sanctum::actingAs($cliente);
        $this->getJson('/api/auditorias')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/auditorias')->assertOk();
    }

    public function test_RF_M01_025_filtra_por_dia_del_negocio_usuario_grupo_y_texto(): void
    {
        // 23:30 del 10/10 en Colombia = 04:30 UTC del 11/10: debe contar como día 10.
        $this->registro('inicio_sesion', $this->mesero, '2026-10-11 04:30:00');
        $this->registro('anular_pago', $this->admin, '2026-10-11 15:00:00', ['motivo' => 'Cobro duplicado en anulación']);
        $this->registro('cerrar_caja', $this->admin, '2026-10-12 01:00:00'); // 20:00 del 11/10 en Colombia

        Sanctum::actingAs($this->admin);

        $dia10 = $this->getJson('/api/auditorias?desde=2026-10-10&hasta=2026-10-10')->assertOk();
        $this->assertSame(['inicio_sesion'], array_column($dia10->json('data'), 'accion'));
        $this->assertSame('Inicio de sesión', $dia10->json('data.0.accion_texto'));
        $this->assertSame('seguridad', $dia10->json('data.0.categoria'));
        $this->assertSame('Chrome en Android', $dia10->json('data.0.navegador'));
        $this->assertSame('Mario Mesero', $dia10->json('data.0.usuario.nombre'));

        $dia11 = $this->getJson('/api/auditorias?desde=2026-10-11&hasta=2026-10-11')->assertOk();
        $this->assertSame(['cerrar_caja', 'anular_pago'], array_column($dia11->json('data'), 'accion')); // más reciente primero

        $this->assertSame(1, $this->getJson('/api/auditorias?categoria=seguridad')->json('total'), 'seguridad');
        $this->assertSame(2, $this->getJson("/api/auditorias?user_id={$this->admin->id}")->json('total'));
        $this->assertSame(1, $this->getJson('/api/auditorias?categoria=caja&accion=anular_pago')->json('total'));
        $this->assertSame(1, $this->getJson('/api/auditorias?busqueda=anulación')->json('total'));
        $this->assertSame(1, $this->getJson('/api/auditorias?busqueda=DUPLICADO')->json('total'));
        $this->assertSame(0, $this->getJson('/api/auditorias?busqueda=100%')->json('total'));

        $this->getJson('/api/auditorias?desde=2026-10-12&hasta=2026-10-10')->assertStatus(422)->assertJsonValidationErrors('hasta');
        $this->getJson('/api/auditorias?categoria=inventada')->assertStatus(422);
    }

    public function test_RF_M01_024_opciones_y_resumen_de_eventos_de_seguridad(): void
    {
        foreach (range(1, 3) as $_) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
                ->postJson('/api/auth/login', ['email' => 'admin@test.co', 'password' => 'mala']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->postJson('/api/auth/login', ['email' => 'admin@test.co', 'password' => 'secreto123'])->assertOk();

        Sanctum::actingAs($this->admin);
        $opciones = $this->getJson('/api/auditorias/opciones')->assertOk();

        $this->assertSame(3, $opciones->json('seguridad.inicios_fallidos_24h'));
        $this->assertSame(1, $opciones->json('seguridad.inicios_exitosos_24h'));
        $this->assertSame([['ip' => '203.0.113.7', 'intentos' => 3]], $opciones->json('seguridad.ips_con_intentos_repetidos'));
        $this->assertContains('seguridad', array_column($opciones->json('categorias'), 'valor'));
        $this->assertContains('Anulación de pago', array_column($opciones->json('acciones'), 'texto'));
        $this->assertSame([$this->admin->id], array_column($opciones->json('usuarios'), 'id'));
    }

    public function test_RN_USR_13_la_base_de_datos_no_deja_modificar_ni_borrar_el_historial(): void
    {
        $registro = $this->registro('anular_pago', $this->admin, '2026-10-11 15:00:00', ['motivo' => 'Original']);

        try {
            // Dentro de su propia transacción (savepoint): en PostgreSQL un error aborta la transacción de la prueba.
            DB::transaction(fn () => DB::table('auditorias')->where('id', $registro->id)->update(['accion' => 'otra']));
            $this->fail('La actualización directa debió fallar.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('auditoria', $e->getMessage());
        }

        try {
            // Dentro de su propia transacción (savepoint): en PostgreSQL un error aborta la transacción de la prueba.
            DB::transaction(fn () => DB::table('auditorias')->where('id', $registro->id)->delete());
            $this->fail('El borrado directo debió fallar.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('auditoria', $e->getMessage());
        }

        // CA-M01-020.2: consultar no altera ni agrega registros.
        Sanctum::actingAs($this->admin);
        $antes = Auditoria::count();
        $this->getJson('/api/auditorias?busqueda=Original')->assertOk();
        $this->getJson('/api/auditorias/opciones')->assertOk();
        $this->assertSame($antes, Auditoria::count());
        $this->assertSame('anular_pago', $registro->fresh()->accion);
    }

    private function registro(string $accion, User $usuario, string $utc, array $detalles = []): Auditoria
    {
        return Auditoria::forceCreate([
            'user_id' => $usuario->id,
            'accion' => $accion,
            'entidad' => 'User',
            'entidad_id' => $usuario->id,
            'detalles' => $detalles ?: null,
            'ip' => '198.51.100.20',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36',
            'created_at' => Carbon::parse($utc, 'UTC'),
        ]);
    }
}
