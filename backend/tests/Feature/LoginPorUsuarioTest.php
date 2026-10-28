<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 3, tarea 3 (12/10/2026): iniciar sesión con el correo o con el nombre de usuario.
 * RF-M01-007, 011, 016, RNF-M01-004, RN-USR-04, HU-M01-006, 011.
 */
class LoginPorUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'username' => 'ana', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'username' => 'mario.m', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    public function test_RF_M01_007_entra_con_correo_o_con_nombre_de_usuario(): void
    {
        $this->postJson('/api/auth/login', ['login' => 'mario.m', 'password' => 'secreto123'])
            ->assertOk()->assertJsonPath('user.id', $this->mesero->id)->assertJsonStructure(['token']);
        $this->postJson('/api/auth/login', ['login' => '  Mario.M ', 'password' => 'secreto123'])
            ->assertOk()->assertJsonPath('user.id', $this->mesero->id);
        $this->postJson('/api/auth/login', ['login' => 'mesero@test.co', 'password' => 'secreto123'])
            ->assertOk()->assertJsonPath('user.username', 'mario.m');

        // El campo anterior ("email") sigue funcionando, con correo o con usuario.
        $this->postJson('/api/auth/login', ['email' => 'admin@test.co', 'password' => 'secreto123'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'ana', 'password' => 'secreto123'])->assertOk();

        $vias = Auditoria::where('accion', 'inicio_sesion')->orderBy('id')->get()->map(fn ($a) => $a->detalles['via'])->all();
        $this->assertSame(['usuario', 'usuario', 'correo', 'correo', 'usuario'], $vias);
    }

    public function test_el_error_es_el_mismo_y_queda_en_el_campo_que_se_envio(): void
    {
        foreach ([['login' => 'mario.m', 'password' => 'mala'], ['login' => 'nadie', 'password' => 'secreto123']] as $datos) {
            $this->postJson('/api/auth/login', $datos)
                ->assertStatus(422)
                ->assertJsonPath('errors.login.0', 'Las credenciales no son válidas o el usuario está inactivo.');
        }
        $this->postJson('/api/auth/login', ['email' => 'nadie@test.co', 'password' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/auth/login', ['password' => 'secreto123'])
            ->assertStatus(422)->assertJsonValidationErrors(['login', 'email']);

        $fallidos = Auditoria::where('accion', 'inicio_sesion_fallido')->orderBy('id')->get();
        $this->assertSame('mario.m', $fallidos[0]->detalles['usuario']);
        $this->assertSame('contrasena_incorrecta', $fallidos[0]->detalles['motivo']);
        $this->assertSame('cuenta_inexistente', $fallidos[1]->detalles['motivo']);

        // Una cuenta desactivada tampoco entra por su nombre de usuario.
        $this->mesero->update(['activo' => false]);
        $this->postJson('/api/auth/login', ['login' => 'mario.m', 'password' => 'secreto123'])->assertStatus(422);
    }

    public function test_RF_M01_011_formato_y_unicidad_sin_importar_mayusculas_al_registrarse(): void
    {
        $base = ['nombre' => 'Carla', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345'];

        $this->postJson('/api/auth/register', $base + ['email' => 'carla@test.co', 'username' => 'MARIO.M'])
            ->assertStatus(422)->assertJsonPath('errors.username.0', 'Ese nombre de usuario ya lo usa otra cuenta.');

        foreach (['ab', '1carla', 'carla@casa', 'carla perez', 'carlá', str_repeat('c', 31)] as $malo) {
            $this->postJson('/api/auth/register', $base + ['email' => 'carla@test.co', 'username' => $malo])
                ->assertStatus(422)->assertJsonValidationErrors('username');
        }

        $this->postJson('/api/auth/register', $base + ['email' => 'carla@test.co', 'documento' => '1000000001', 'username' => ' Carla_P '])
            ->assertCreated()->assertJsonPath('user.username', 'carla_p');
        // Es opcional.
        $this->postJson('/api/auth/register', $base + ['email' => 'otra@test.co', 'documento' => '1000000002', 'username' => ''])
            ->assertCreated()->assertJsonPath('user.username', null);
        $this->postJson('/api/auth/register', $base + ['email' => 'tercera@test.co', 'documento' => '1000000003'])->assertCreated();
    }

    public function test_el_administrador_pone_cambia_o_quita_el_nombre_de_usuario(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/usuarios', [
            'role' => 'mesero', 'nombre' => 'Nuevo', 'email' => 'nuevo@test.co', 'username' => 'Nuevo1',
            'password' => 'Clave12345', 'password_confirmation' => 'Clave12345',
        ])->assertCreated()->assertJsonPath('username', 'nuevo1');
        $this->assertSame('nuevo1', Auditoria::where('accion', 'crear_usuario')->sole()->detalles['usuario']);

        $datos = ['nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'role' => 'mesero'];
        $this->putJson("/api/usuarios/{$this->mesero->id}", $datos + ['username' => 'ana'])
            ->assertStatus(422)->assertJsonValidationErrors('username');
        $this->putJson("/api/usuarios/{$this->mesero->id}", $datos + ['username' => 'mario.m'])->assertOk(); // el suyo no choca
        $this->putJson("/api/usuarios/{$this->mesero->id}", $datos + ['username' => 'mperez'])
            ->assertOk()->assertJsonPath('usuario.username', 'mperez');
        $this->assertSame(['antes' => 'mario.m', 'despues' => 'mperez'], Auditoria::where('accion', 'editar_usuario')->sole()->detalles['cambios']['username']);

        // Sin enviarlo no se toca; vacío lo quita.
        $this->putJson("/api/usuarios/{$this->mesero->id}", $datos)->assertOk()->assertJsonPath('usuario.username', 'mperez');
        $this->putJson("/api/usuarios/{$this->mesero->id}", $datos + ['username' => ''])->assertOk()->assertJsonPath('usuario.username', null);

        // Búsqueda en Usuarios también por nombre de usuario.
        $this->assertSame([$this->admin->id], array_column($this->getJson('/api/usuarios?busqueda=ANA')->json('data'), 'id'));
    }

    public function test_RF_M01_016_cada_quien_elige_su_nombre_de_usuario_en_su_perfil(): void
    {
        Sanctum::actingAs($this->mesero);

        $this->putJson('/api/perfil', ['nombre' => 'Mario Mesero', 'username' => 'ana'])
            ->assertStatus(422)->assertJsonValidationErrors('username');
        $this->putJson('/api/perfil', ['nombre' => 'Mario Mesero', 'username' => 'MarioM'])
            ->assertOk()->assertJsonPath('username', 'mariom');
        // Sin el campo, el perfil no lo cambia.
        $this->putJson('/api/perfil', ['nombre' => 'Mario P.'])->assertOk()->assertJsonPath('username', 'mariom');

        $this->postJson('/api/auth/login', ['login' => 'mariom', 'password' => 'secreto123'])->assertOk();
    }
}
