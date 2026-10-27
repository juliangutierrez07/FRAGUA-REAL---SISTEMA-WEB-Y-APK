<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Eliminar mesas, productos y usuarios (24/10/2026, RF-M05-043, RF-M04-055, RF-M01-033). Desde el
 * 27/10/2026 es definitivo: lo que nunca se usó se borra; lo que tiene historial sale de todas las listas
 * (sin «Eliminados» ni restaurar) y su nombre o correo queda libre. Administrador y SuperAdmin, con
 * contraseña; el motivo solo al eliminar una cuenta. Todo queda en la auditoría.
 */
class EliminarRegistrosTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;
    private User $mesero;
    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $crear = fn (string $rol, string $correo, string $nombre) => User::create(['role' => $rol, 'nombre' => $nombre, 'email' => $correo, 'password' => bcrypt('Clave12345'), 'activo' => true]);
        $this->super = $crear('superadmin', 'super@test.co', 'Sara Super');
        $this->admin = $crear('administrador', 'admin@test.co', 'Ana Admin');
        $this->mesero = $crear('mesero', 'mesero@test.co', 'Mario Mesero');
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
    }

    private function eliminar(string $uri, array $datos = [])
    {
        return $this->postJson($uri, $datos + ['password' => 'Clave12345']);
    }

    private function eliminarCuenta(User $cuenta, array $datos = [])
    {
        return $this->eliminar("/api/usuarios/{$cuenta->id}/eliminar", $datos + ['motivo' => 'Ya no trabaja aquí']);
    }

    private function venderEnMesa(Mesa $mesa): void
    {
        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $mesa->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertCreated();
    }

    public function test_una_mesa_sin_historial_se_borra_de_verdad(): void
    {
        $mesa = Mesa::create(['nombre' => 'Mesa 9', 'qr_code' => 'qr-9', 'estado' => 'libre']);
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/mesas/{$mesa->id}/eliminacion")->assertOk()->assertJsonPath('se_borra', true)->assertJsonPath('bloqueo', null);
        $this->eliminar("/api/mesas/{$mesa->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'borrado');

        $this->assertDatabaseMissing('mesas', ['id' => $mesa->id]);
        $this->assertTrue(Auditoria::where('accion', 'eliminar_mesa')->where('entidad_id', $mesa->id)->exists());
    }

    public function test_una_mesa_con_pedidos_sale_de_todo_y_su_nombre_queda_libre(): void
    {
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        $this->venderEnMesa($mesa);

        Sanctum::actingAs($this->admin);
        $this->eliminar("/api/mesas/{$mesa->id}/eliminar")->assertStatus(422); // ocupada: primero cobrar o liberar
        $mesa->pedidos()->update(['estado' => 'cancelado']);
        Mesa::whereKey($mesa->id)->update(['estado' => 'libre']); // el Mesero la liberó

        $this->getJson("/api/mesas/{$mesa->id}/eliminacion")->assertOk()->assertJsonPath('se_borra', false);
        $this->eliminar("/api/mesas/{$mesa->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'archivado');

        $mesa->refresh();
        $this->assertNotNull($mesa->eliminado_at);
        $this->assertFalse($mesa->activa);
        $this->assertNull($mesa->eliminado_motivo, 'Una mesa se elimina sin motivo.');
        $this->assertNotContains($mesa->id, collect($this->getJson('/api/mesas')->json())->pluck('id'));
        $this->assertNotContains($mesa->id, collect($this->getJson('/api/mesas?eliminadas=1')->json())->pluck('id'), 'Ya no hay lista de eliminadas.');
        $this->postJson("/api/mesas/{$mesa->id}/activar")->assertNotFound();
        $this->postJson("/api/mesas/{$mesa->id}/restaurar")->assertNotFound();
        // El nombre queda libre para una mesa nueva.
        $this->postJson('/api/mesas', ['nombre' => 'Mesa 1'])->assertCreated();
        $this->assertTrue(Auditoria::where('accion', 'archivar_mesa')->exists());
    }

    public function test_no_se_elimina_una_mesa_con_reservas_proximas(): void
    {
        $mesa = Mesa::create(['nombre' => 'Mesa 2', 'qr_code' => 'qr-2', 'estado' => 'libre']);
        Reserva::create([
            'mesa_id' => $mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234', 'cliente_correo' => 'l@c.co',
            'fecha_hora' => CarbonImmutable::now()->addDay(), 'estado' => 'confirmada', 'deposito_valor' => 20000, 'creado_por' => 'cliente_web',
        ]);
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/mesas/{$mesa->id}/eliminacion")->assertOk()->assertJsonPath('bloqueo', fn ($b) => str_contains((string) $b, 'reserva próxima'));
        $this->eliminar("/api/mesas/{$mesa->id}/eliminar")->assertStatus(422);
    }

    public function test_pide_contrasena_el_motivo_solo_en_cuentas_y_no_lo_hace_el_mesero(): void
    {
        $mesa = Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-3', 'estado' => 'libre']);

        Sanctum::actingAs($this->mesero);
        $this->eliminar("/api/mesas/{$mesa->id}/eliminar")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/mesas/{$mesa->id}/eliminacion")->assertOk()->assertJsonPath('pide_motivo', false);
        $this->eliminar("/api/mesas/{$mesa->id}/eliminar", ['password' => 'otra-clave'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->eliminar("/api/mesas/{$mesa->id}/eliminar", ['password' => ''])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertDatabaseHas('mesas', ['id' => $mesa->id, 'eliminado_at' => null]);
        $this->eliminar("/api/mesas/{$mesa->id}/eliminar")->assertOk(); // sin motivo

        $this->getJson("/api/usuarios/{$this->mesero->id}/eliminacion")->assertOk()->assertJsonPath('pide_motivo', true);
        $this->eliminar("/api/usuarios/{$this->mesero->id}/eliminar")->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->assertNull($this->mesero->fresh()->eliminado_at);
    }

    public function test_un_producto_vendido_sale_de_todo_y_uno_nuevo_se_borra(): void
    {
        $mesa = Mesa::create(['nombre' => 'Mesa 4', 'qr_code' => 'qr-4', 'estado' => 'libre']);
        $this->venderEnMesa($mesa);
        $combo = Combo::create(['nombre' => 'Combo Clásico', 'precio' => 25000, 'activo' => true, 'tipo_venta' => 'alimento']);
        $combo->productos()->attach($this->producto->id, ['cantidad' => 1]);
        $nuevo = Producto::create(['categoria_id' => $this->producto->categoria_id, 'nombre' => 'Doble', 'precio_base' => 30000, 'tipo_venta' => 'alimento']);

        Sanctum::actingAs($this->admin);
        $this->eliminar("/api/productos/{$nuevo->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'borrado');
        $this->assertDatabaseMissing('productos', ['id' => $nuevo->id]);

        $this->eliminar("/api/productos/{$this->producto->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'archivado');
        $this->assertFalse($this->producto->fresh()->activo);
        $this->assertFalse($combo->fresh()->activo, 'El combo que lo trae deja de venderse.');
        $this->assertNotContains($this->producto->id, collect($this->getJson('/api/productos?eliminados=1')->json())->pluck('id'));
        $this->putJson("/api/productos/{$this->producto->id}", ['nombre' => 'Clásica', 'precio_base' => 20000, 'categoria_id' => $this->producto->categoria_id, 'tipo_venta' => 'alimento'])
            ->assertNotFound();
        // El nombre queda libre; el pedido viejo sigue nombrando al producto eliminado.
        $this->postJson('/api/productos', ['nombre' => 'Clásica', 'precio_base' => 21000, 'categoria_id' => $this->producto->categoria_id, 'tipo_venta' => 'alimento'])
            ->assertCreated();
        $this->assertSame('Clásica', \App\Models\PedidoDetalle::firstOrFail()->producto->nombre);

        Sanctum::actingAs($this->mesero);
        $this->assertNotContains($this->producto->id, collect($this->getJson('/api/productos')->json())->pluck('id'));
    }

    public function test_una_cuenta_con_historial_sale_de_todo_no_entra_y_su_correo_queda_libre(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'mesero@test.co', 'password' => 'Clave12345'])->assertOk();
        $sinUso = User::create(['role' => 'mesero', 'nombre' => 'Nuevo Mesero', 'email' => 'nuevo@test.co', 'password' => bcrypt('Clave12345'), 'activo' => true]);

        Sanctum::actingAs($this->admin);
        $this->eliminarCuenta($sinUso)->assertOk()->assertJsonPath('resultado', 'borrado');
        $this->assertDatabaseMissing('users', ['id' => $sinUso->id]);

        $this->eliminarCuenta($this->mesero)->assertOk()->assertJsonPath('resultado', 'archivado');
        $this->assertFalse($this->mesero->fresh()->activo);
        $this->assertSame('Ya no trabaja aquí', $this->mesero->fresh()->eliminado_motivo);
        $this->assertSame(0, $this->mesero->tokens()->count());
        $this->assertNotContains($this->mesero->id, collect($this->getJson('/api/usuarios?eliminados=1')->json('data'))->pluck('id'));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'mesero@test.co', 'password' => 'Clave12345'])->assertStatus(422); // eliminada: no entra

        // El correo queda libre: una cuenta nueva con él entra normalmente, y no la confunde con la eliminada.
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/usuarios', ['nombre' => 'Mario Regresa', 'email' => 'mesero@test.co', 'role' => 'mesero', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345'])
            ->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'mesero@test.co', 'password' => 'Clave12345'])->assertOk()->assertJsonPath('user.nombre', 'Mario Regresa');
    }

    public function test_reglas_de_quien_elimina_a_quien(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/usuarios/{$this->admin->id}/eliminacion")->assertJsonPath('bloqueo', 'No puedes eliminar tu propia cuenta.');
        $this->eliminarCuenta($this->admin)->assertStatus(422);
        $otroAdmin = User::create(['role' => 'administrador', 'nombre' => 'Beto Admin', 'email' => 'beto@test.co', 'password' => bcrypt('Clave12345'), 'activo' => true]);
        $this->eliminarCuenta($otroAdmin)->assertStatus(422);
        $this->eliminarCuenta($this->super)->assertStatus(422);

        Sanctum::actingAs($this->super);
        $this->eliminarCuenta($otroAdmin)->assertOk();
    }
}
