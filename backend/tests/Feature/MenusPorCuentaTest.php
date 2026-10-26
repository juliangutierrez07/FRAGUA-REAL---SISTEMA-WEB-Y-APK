<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M09 (20/10/2026): el SuperAdmin decide, cuenta por cuenta, qué menús ve cada Administrador y cada
 * Mesero. Quitar un menú lo esconde y el servidor rechaza sus acciones. Dashboard (Administrador) y
 * Pedidos (Mesero) no se pueden quitar.
 */
class MenusPorCuentaTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::create(['role' => 'superadmin', 'nombre' => 'Sara Super', 'email' => 'super@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    private function fijarMenus(User $cuenta, array $menus)
    {
        Sanctum::actingAs($this->super);

        return $this->putJson("/api/usuarios/{$cuenta->id}/menus", ['menus' => $menus]);
    }

    public function test_por_defecto_cada_cuenta_ve_todos_los_menus_de_su_rol(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('menus', [
            'dashboard', 'usuarios', 'pedidos', 'compras_gastos', 'catalogo', 'mesas', 'caja', 'reportes', 'auditoria', 'configuracion',
            'impresoras', // M12 (24/10/2026)
        ]);
        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('menus', ['pedidos', 'mesas', 'reservas', 'menu', 'cobrar', 'gastos']);
    }

    public function test_el_superadmin_quita_menus_y_el_servidor_bloquea_sus_acciones(): void
    {
        $this->fijarMenus($this->admin, ['caja'])->assertOk()
            ->assertJsonPath('menus', ['dashboard', 'caja'])
            ->assertJsonPath('todos', false);

        Sanctum::actingAs($this->admin->fresh());
        $this->getJson('/api/auth/me')->assertJsonPath('menus', ['dashboard', 'caja']);
        $this->getJson('/api/caja/turnos')->assertOk();
        $this->getJson('/api/dashboard/resumen')->assertOk();
        $this->getJson('/api/reportes/ventas-totales')->assertForbidden()
            ->assertJsonPath('message', 'Esta opción no está habilitada para tu cuenta. Pídele al SuperAdmin que te la active.');
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/configuraciones')->assertForbidden();
        $this->postJson('/api/categorias', ['nombre' => 'Nueva'])->assertForbidden();

        // El SuperAdmin nunca se filtra.
        Sanctum::actingAs($this->super);
        $this->getJson('/api/reportes/ventas-totales')->assertOk();
    }

    public function test_al_mesero_se_le_quitan_reservas_menu_y_cobrar(): void
    {
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        $producto = Producto::create(['categoria_id' => Categoria::create(['nombre' => 'Bebidas'])->id, 'nombre' => 'Limonada', 'precio_base' => 6000, 'tipo_venta' => 'bebida']);

        $this->fijarMenus($this->mesero, ['mesas'])->assertOk()->assertJsonPath('menus', ['pedidos', 'mesas']);
        Sanctum::actingAs($this->mesero->fresh());

        $this->postJson("/api/productos/{$producto->id}/disponibilidad", ['disponible' => false])->assertForbidden();
        $this->postJson("/api/mesas/{$mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 1000])->assertForbidden();
        $this->postJson('/api/compras-gastos', [])->assertForbidden();
        // Con Mesas sigue viendo las reservas que llegan a sus mesas.
        $this->getJson('/api/reservas')->assertOk();

        $this->fijarMenus($this->mesero, ['menu']);
        Sanctum::actingAs($this->mesero->fresh());
        $this->getJson('/api/reservas')->assertForbidden();
        $this->postJson("/api/mesas/{$mesa->id}/abrir")->assertForbidden();
        $this->postJson("/api/productos/{$producto->id}/disponibilidad", ['disponible' => false])->assertOk();
    }

    public function test_los_menus_fijos_no_se_quitan_y_marcar_todos_vuelve_a_sin_restriccion(): void
    {
        $this->fijarMenus($this->mesero, [])->assertOk()->assertJsonPath('menus', ['pedidos']);
        $this->fijarMenus($this->mesero, ['pedidos', 'mesas', 'reservas', 'menu', 'cobrar', 'gastos'])->assertOk()
            ->assertJsonPath('todos', true);
        $this->assertNull($this->mesero->fresh()->menus_permitidos);

        $this->fijarMenus($this->mesero, ['reportes'])->assertStatus(422)->assertJsonValidationErrors(['menus.0']);
        $this->assertTrue(Auditoria::where('accion', 'cambiar_menus')->where('entidad_id', $this->mesero->id)->exists());
    }

    public function test_solo_el_superadmin_cambia_menus_y_solo_de_administradores_y_meseros(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/usuarios/{$this->mesero->id}/menus", ['menus' => ['mesas']])->assertForbidden()
            ->assertJsonPath('message', 'Solo el SuperAdmin cambia los menús de una cuenta.');
        $this->getJson("/api/usuarios/{$this->mesero->id}/menus")->assertForbidden();

        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Carla', 'email' => 'carla@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->fijarMenus($cliente, [])->assertStatus(422);
        $this->getJson("/api/usuarios/{$this->admin->id}/menus")->assertOk()
            ->assertJsonPath('catalogo.0', ['clave' => 'dashboard', 'nombre' => 'Dashboard', 'fijo' => true]);
    }

    public function test_cambiar_de_rol_devuelve_todos_los_menus_del_rol_nuevo(): void
    {
        $this->fijarMenus($this->mesero, ['mesas']);
        Sanctum::actingAs($this->super);
        $this->putJson("/api/usuarios/{$this->mesero->id}", ['nombre' => 'Mario', 'email' => 'mesero@test.co', 'role' => 'administrador'])->assertOk();
        $this->assertNull($this->mesero->fresh()->menus_permitidos);
        $this->assertContains('reportes', $this->mesero->fresh()->menus);
    }
}
