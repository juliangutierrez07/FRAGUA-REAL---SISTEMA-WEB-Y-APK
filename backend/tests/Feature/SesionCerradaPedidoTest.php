<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\SesionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Corrección (28/10/2026, RF-M01-038): un Cliente al que el Administrador le cerró la sesión seguía viendo su
 * cuenta en la app, y el pedido que hacía llegaba como de invitado (sin quedar en su historial). Ahora crear un
 * pedido con un token que ya no sirve responde 401, y al volver a entrar la sesión nueva se ve en Usuarios.
 */
class SesionCerradaPedidoTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;
    private User $admin;
    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = User::create(['role' => 'cliente', 'nombre' => 'Laura', 'email' => 'laura@test.co', 'telefono' => '3105551234', 'documento' => '1020304050', 'password' => bcrypt('Clave12345'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana', 'email' => 'admin@test.co', 'password' => 'Clave12345', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Doble', 'precio_base' => 30000, 'tipo_venta' => 'alimento']);
    }

    private function token(User $usuario): string
    {
        return app(SesionService::class)->abrir($usuario, Request::create('/'))['token'];
    }

    private function pedir(?string $token, array $extra = [])
    {
        $this->app['auth']->forgetGuards();
        $peticion = $token ? $this->withToken($token) : $this->withHeaders([]);

        return $peticion->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'telefono_contacto' => '3105551234',
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ] + $extra);
    }

    public function test_RF_M01_038_con_la_sesion_cerrada_el_pedido_responde_401_y_no_queda_como_invitado(): void
    {
        $token = $this->token($this->cliente);
        $this->pedir($token)->assertCreated()->assertJsonPath('cliente_id', $this->cliente->id);

        // El Administrador le cierra todas las sesiones.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($this->admin))->postJson("/api/usuarios/{$this->cliente->id}/revocar-sesiones")->assertOk();

        $this->pedir($token, ['cliente_invitado_nombre' => 'Laura', 'cliente_invitado_telefono' => '3105551234'])
            ->assertUnauthorized()->assertJsonPath('sesion_cerrada', true);
        $this->assertSame(1, Pedido::count());

        // Sin token, el invitado sigue pidiendo como siempre.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->pedir(null, ['cliente_invitado_nombre' => 'Invitado', 'cliente_invitado_telefono' => '3001112233'])
            ->assertCreated()->assertJsonPath('cliente_id', null);
    }

    public function test_al_volver_a_entrar_la_sesion_nueva_aparece_en_usuarios(): void
    {
        $this->app['auth']->forgetGuards();
        $tokenAdmin = $this->token($this->admin);
        $this->withToken($tokenAdmin)->postJson("/api/usuarios/{$this->cliente->id}/revocar-sesiones")->assertOk();
        $this->withToken($tokenAdmin)->getJson("/api/usuarios/{$this->cliente->id}/sesiones")->assertJsonCount(0, 'sesiones');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['login' => 'laura@test.co', 'password' => 'Clave12345'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenAdmin)->getJson("/api/usuarios/{$this->cliente->id}/sesiones")->assertJsonCount(1, 'sesiones');
        $this->withToken($tokenAdmin)->getJson('/api/usuarios?rol=cliente')->assertJsonPath('data.0.sesiones_activas', 1);
    }
}
