<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Notificacion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seguridad (21/10/2026, RF-M01-029 a 031, RN-USR-23/24): contraseña segura en toda cuenta nueva o
 * contraseña nueva, y bloqueo temporal del inicio de sesión tras varios intentos fallidos.
 */
class SeguridadClavesTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 15:00:00', 'America/Bogota'));
        $this->super = User::create(['role' => 'superadmin', 'nombre' => 'Sara Super', 'email' => 'super@test.co', 'password' => Hash::make('Super2026x'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => Hash::make('Admin2026x'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'username' => 'mario', 'password' => Hash::make('Mesero2026x'), 'activo' => true]);
    }

    private function registro(string $clave)
    {
        return $this->postJson('/api/auth/register', ['nombre' => 'Carla', 'email' => 'carla@test.co', 'documento' => '1020304050', 'password' => $clave, 'password_confirmation' => $clave]);
    }

    private function entrar(string $login, string $clave)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['login' => $login, 'password' => $clave]);
    }

    public function test_el_cliente_se_registra_solo_con_una_contrasena_segura(): void
    {
        $this->getJson('/api/configuracion')->assertJsonPath('clave.minimo', 8)->assertJsonPath('clave.mezcla', true);

        $this->registro('Corta1')->assertStatus(422)->assertJsonPath('errors.password.0', 'El campo contraseña debe tener al menos 8 caracteres.');
        $this->registro('todominusculas1')->assertStatus(422)->assertJsonPath('errors.password.0', 'El campo contraseña debe contener al menos una mayúscula y una minúscula.');
        $this->registro('SinNumerosAqui')->assertStatus(422)->assertJsonPath('errors.password.0', 'El campo contraseña debe contener al menos un número.');
        $this->registro('Segura2026')->assertCreated();
    }

    public function test_las_cuentas_que_crea_el_personal_y_las_contrasenas_nuevas_tambien(): void
    {
        $nueva = fn (string $clave, string $rol = 'mesero') => ['role' => $rol, 'nombre' => 'Pepe', 'email' => "pepe-{$rol}@test.co", 'password' => $clave, 'password_confirmation' => $clave];

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/usuarios', $nueva('password'))->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson('/api/usuarios', $nueva('Mesero2026'))->assertCreated();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->super);
        $this->postJson('/api/usuarios', $nueva('administrador1', 'administrador'))->assertStatus(422);
        $this->postJson('/api/usuarios', $nueva('Administra2026', 'administrador'))->assertCreated();

        // Cambiar la propia contraseña y recuperarla.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/perfil/password', ['password_actual' => 'Mesero2026x', 'password' => 'nueva1234', 'password_confirmation' => 'nueva1234'])->assertStatus(422);
        $this->postJson('/api/perfil/password', ['password_actual' => 'Mesero2026x', 'password' => 'Nueva1234', 'password_confirmation' => 'Nueva1234'])->assertOk();

        DB::table('password_reset_tokens')->insert(['email' => 'mesero@test.co', 'token' => Hash::make('tok'), 'created_at' => now()]);
        $this->postJson('/api/auth/restablecer', ['email' => 'mesero@test.co', 'token' => 'tok', 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])->assertStatus(422);

        // Los comandos del servidor usan la misma regla.
        $this->artisan('fragua:crear-soporte', ['correo' => 'dev@test.co'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con mayúscula, minúscula y número)', 'debil')
            ->expectsQuestion('Repite la contraseña', 'debil')
            ->assertExitCode(1);
        $this->assertNull(User::where('email', 'dev@test.co')->first());
    }

    public function test_en_produccion_rechaza_las_contrasenas_filtradas(): void
    {
        config(['fragua_real.seguridad.clave_filtradas' => true]);
        $hash = strtoupper(sha1('Password1'));
        Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":52579\r\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:1")]);

        $this->registro('Password1')->assertStatus(422)->assertJsonPath('errors.password.0', 'Esa contraseña aparece en una filtración de datos conocida: elige otra.');
        Http::assertSent(fn ($pedido) => ! str_contains($pedido->url(), 'Password1') && str_contains($pedido->url(), substr($hash, 0, 5)));
    }

    public function test_cinco_intentos_fallidos_bloquean_la_cuenta_quince_minutos(): void
    {
        // Un acierto borra el conteo.
        foreach (range(1, 4) as $i) {
            $this->entrar('mario', 'mala')->assertStatus(422);
        }
        $this->entrar('mario', 'Mesero2026x')->assertOk();
        foreach (range(1, 4) as $i) {
            $this->entrar('mario', 'mala')->assertStatus(422);
        }
        $this->travel(61)->seconds(); // aparte, la ruta deja 10 intentos por minuto por cuenta e IP

        // El quinto seguido bloquea; ni la contraseña correcta entra.
        $this->entrar('mario', 'mala')->assertStatus(429)
            ->assertJsonPath('message', 'Hiciste demasiados intentos fallidos. Por seguridad, espera 15 minutos o usa «¿Olvidaste tu contraseña?».');
        $this->entrar('mario', 'Mesero2026x')->assertStatus(429)->assertJsonPath('bloqueado_minutos', 15);
        $this->assertSame(1, Auditoria::where('accion', 'bloqueo_inicio_sesion')->where('entidad_id', $this->mesero->id)->count());
        $this->assertSame(1, Auditoria::where('accion', 'inicio_sesion_bloqueado')->count());

        // El Administrador y el SuperAdmin se enteran en la campana (M11).
        $this->assertEqualsCanonicalizing([$this->admin->id, $this->super->id],
            Notificacion::where('tipo', 'cuenta_bloqueada')->pluck('user_id')->all());

        // Pasados los 15 minutos, vuelve a entrar.
        $this->travel(16)->minutes();
        $this->entrar('mario', 'Mesero2026x')->assertOk();
    }

    public function test_recuperar_la_contrasena_quita_el_bloqueo(): void
    {
        foreach (range(1, 5) as $i) {
            $this->entrar('mesero@test.co', 'mala');
        }
        $this->entrar('mesero@test.co', 'Mesero2026x')->assertStatus(429);

        DB::table('password_reset_tokens')->insert(['email' => 'mesero@test.co', 'token' => Hash::make('tok'), 'created_at' => now()]);
        $this->postJson('/api/auth/restablecer', ['email' => 'mesero@test.co', 'token' => 'tok', 'password' => 'Recuperada2026', 'password_confirmation' => 'Recuperada2026'])->assertOk();
        $this->entrar('mesero@test.co', 'Recuperada2026')->assertOk();
    }

    public function test_una_ip_que_prueba_muchas_cuentas_tambien_se_bloquea(): void
    {
        // 20 fallos desde la misma IP con cuentas distintas (de a 9 por minuto, por el límite de la ruta).
        foreach (range(1, 20) as $i) {
            $this->entrar("nadie{$i}@test.co", 'mala')->assertStatus(422);
            if ($i % 9 === 0) {
                $this->travel(61)->seconds();
            }
        }
        $this->travel(61)->seconds();

        // Ahora ni una cuenta real con su contraseña entra desde esa IP; la cuenta inexistente no avisa a nadie.
        $this->entrar('admin@test.co', 'Admin2026x')->assertStatus(429);
        $this->assertSame(0, Notificacion::count());
    }
}
