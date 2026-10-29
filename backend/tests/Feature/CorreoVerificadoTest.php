<?php

namespace Tests\Feature;

use App\Models\Mesa;
use App\Models\User;
use App\Notifications\CodigoCorreoNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seguridad (28/10/2026, RF-M01-037, RN-USR-28, HU-M01-028): el Cliente confirma su correo con un código antes
 * de ver lo que se busca por ese correo («Mis reservas») y de que el Mesero lo encuentre por correo al cobrar.
 * Antes cualquiera podía registrarse con el correo de otra persona y ver sus reservas.
 */
class CorreoVerificadoTest extends TestCase
{
    use RefreshDatabase;

    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'America/Bogota'));
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        Notification::fake();
    }

    private function registrar(string $correo = 'laura@correo.co'): User
    {
        $this->postJson('/api/auth/register', [
            'nombre' => 'Laura Gómez', 'email' => $correo, 'documento' => '1020304050',
            'password' => 'Clave12345', 'password_confirmation' => 'Clave12345',
        ])->assertCreated()->assertJsonPath('user.correo_verificado_at', null);

        return User::where('email', $correo)->firstOrFail();
    }

    private function ultimoCodigo(User $usuario): string
    {
        $codigo = null;
        Notification::assertSentTo($usuario, CodigoCorreoNotification::class, function ($n) use ($usuario, &$codigo) {
            preg_match('/\d{6}/', $n->toMail($usuario)->subject, $m);
            $codigo = $m[0];

            return true;
        });

        return $codigo;
    }

    private function reservaDeInvitado(string $correo): void
    {
        $this->postJson('/api/reservas', [
            'mesa_id' => $this->mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234', 'cliente_correo' => $correo,
            'fecha_hora' => CarbonImmutable::now('America/Bogota')->addDay()->setTime(20, 0)->format('Y-m-d H:i'),
        ])->assertCreated();
    }

    public function test_RF_M01_037_al_registrarse_llega_el_codigo_y_con_el_ve_sus_reservas(): void
    {
        $this->reservaDeInvitado('laura@correo.co');
        $cliente = $this->registrar();
        $codigo = $this->ultimoCodigo($cliente);

        Sanctum::actingAs($cliente);
        $this->getJson('/api/mis-reservas')->assertForbidden()->assertJsonPath('correo_sin_confirmar', true);

        $this->postJson('/api/auth/correo/verificar', ['codigo' => '000000' === $codigo ? '111111' : '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->postJson('/api/auth/correo/verificar', ['codigo' => $codigo])->assertOk();
        $this->assertNotNull($cliente->fresh()->correo_verificado_at);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($cliente->fresh());
        $this->assertCount(1, $this->getJson('/api/mis-reservas')->assertOk()->json('data'));
    }

    public function test_RN_USR_28_quien_se_registra_con_el_correo_de_otro_no_ve_sus_reservas_ni_lo_encuentran_por_correo(): void
    {
        $this->reservaDeInvitado('victima@correo.co');
        $impostor = $this->registrar('victima@correo.co');

        Sanctum::actingAs($impostor);
        $this->getJson('/api/mis-reservas')->assertForbidden();

        $mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario', 'email' => 'mesero@test.co', 'password' => 'Clave12345', 'activo' => true]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($mesero);
        $this->getJson('/api/clientes/buscar?dato=victima@correo.co')->assertNotFound();
        // Por documento sí (se lo da la persona en la caja).
        $this->getJson('/api/clientes/buscar?dato=1020304050')->assertOk();
    }

    public function test_cinco_intentos_fallidos_anulan_el_codigo_y_uno_nuevo_reemplaza_al_anterior(): void
    {
        $cliente = $this->registrar();
        $primero = $this->ultimoCodigo($cliente);
        Sanctum::actingAs($cliente);

        $malo = $primero === '123456' ? '654321' : '123456';
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/auth/correo/verificar', ['codigo' => $malo])->assertJsonPath('errors.codigo.0', 'El código no es correcto.');
        }
        $this->postJson('/api/auth/correo/verificar', ['codigo' => $malo])->assertJsonPath('errors.codigo.0', 'Hiciste demasiados intentos. Pide un código nuevo.');
        $this->postJson('/api/auth/correo/verificar', ['codigo' => $primero])->assertStatus(422); // ya no sirve

        Notification::fake();
        $this->postJson('/api/auth/correo/codigo')->assertOk();
        $this->postJson('/api/auth/correo/verificar', ['codigo' => $this->ultimoCodigo($cliente)])->assertOk();
    }

    public function test_recuperar_la_contrasena_tambien_confirma_el_correo_y_cambiarlo_lo_desconfirma(): void
    {
        $cliente = $this->registrar();
        DB::table('password_reset_tokens')->insert(['email' => 'laura@correo.co', 'token' => Hash::make('tok'), 'created_at' => now()]);
        $this->postJson('/api/auth/restablecer', ['email' => 'laura@correo.co', 'token' => 'tok', 'password' => 'Nueva12345', 'password_confirmation' => 'Nueva12345'])->assertOk();
        $this->assertNotNull($cliente->fresh()->correo_verificado_at);

        // El Administrador le cambia el correo: el nuevo queda sin confirmar.
        $admin = User::create(['role' => 'administrador', 'nombre' => 'Ana', 'email' => 'admin@test.co', 'password' => 'Clave12345', 'activo' => true]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin);
        $this->putJson("/api/usuarios/{$cliente->id}", [
            'nombre' => 'Laura Gómez', 'email' => 'laura.nueva@correo.co', 'role' => 'cliente', 'documento' => '1020304050',
        ])->assertOk();
        $this->assertNull($cliente->fresh()->correo_verificado_at);
    }

    public function test_el_personal_no_usa_estas_rutas(): void
    {
        Sanctum::actingAs(User::create(['role' => 'mesero', 'nombre' => 'Mario', 'email' => 'mesero@test.co', 'password' => 'Clave12345', 'activo' => true]));
        $this->postJson('/api/auth/correo/codigo')->assertForbidden();
    }
}
