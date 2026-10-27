<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Extra;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Eliminar categorías, combos, extras y promociones (27/10/2026, RF-M04-057 a 060, RN-M04-34). Es
 * definitivo: lo que nunca se usó se borra; lo que se vendió o se aplicó sale de todas las listas y deja
 * de ofrecerse (sin «Eliminados» ni restaurar), pero los pedidos de antes lo siguen mostrando. Una
 * categoría con productos vigentes, o que usa una promoción vigente, no se elimina. Con contraseña, sin motivo.
 */
class EliminarCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private Categoria $hamburguesas;
    private Producto $clasica;

    protected function setUp(): void
    {
        parent::setUp();
        $crear = fn (string $rol, string $correo) => User::create(['role' => $rol, 'nombre' => ucfirst($rol), 'email' => $correo, 'password' => bcrypt('Clave12345'), 'activo' => true]);
        $this->admin = $crear('administrador', 'admin@test.co');
        $this->mesero = $crear('mesero', 'mesero@test.co');
        $this->hamburguesas = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->clasica = Producto::create(['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
    }

    private function eliminar(string $uri)
    {
        return $this->postJson($uri, ['password' => 'Clave12345']);
    }

    private function pedido(array $linea)
    {
        Sanctum::actingAs($this->mesero);
        $respuesta = $this->postJson('/api/pedidos', ['tipo' => 'recogida', 'origen' => 'mesero', 'cliente_invitado_nombre' => 'Ana',
            'cliente_invitado_telefono' => '3001234567', 'lineas' => [$linea]]);
        Sanctum::actingAs($this->admin);

        return $respuesta;
    }

    public function test_RF_M04_057_RN_M04_34_categoria_con_productos_o_promocion_no_se_elimina_y_una_vacia_se_borra(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/categorias/{$this->hamburguesas->id}/eliminacion")
            ->assertOk()->assertJsonPath('bloqueo', 'La categoría tiene 1 producto: muévelos a otra categoría o elimínalos antes.');
        $this->eliminar("/api/categorias/{$this->hamburguesas->id}/eliminar")->assertStatus(422);

        $postres = Categoria::create(['nombre' => 'Postres']);
        $promo = Promocion::create(['nombre' => 'Postres 10%', 'tipo' => 'automatica', 'alcance' => 'categoria', 'tipo_valor' => 'porcentaje', 'valor' => 10, 'activo' => true]);
        $promo->categorias()->attach($postres->id);
        $this->getJson("/api/categorias/{$postres->id}/eliminacion")->assertOk()
            ->assertJsonPath('bloqueo', 'La promoción «Postres 10%» aplica a esta categoría: quítala de la promoción o elimina la promoción antes.');

        $promo->categorias()->detach();
        $this->getJson("/api/categorias/{$postres->id}/eliminacion")->assertOk()->assertJsonPath('se_borra', true)->assertJsonPath('bloqueo', null);
        $this->eliminar("/api/categorias/{$postres->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'borrado');
        $this->assertDatabaseMissing('categorias', ['id' => $postres->id]);
        $this->assertTrue(Auditoria::where('accion', 'eliminar_categoria')->where('entidad_id', $postres->id)->exists());
    }

    public function test_RF_M04_057_categoria_con_productos_eliminados_sale_de_todo_y_su_nombre_queda_libre(): void
    {
        Sanctum::actingAs($this->admin);
        $this->clasica->forceFill(['eliminado_at' => now(), 'activo' => false])->save(); // el producto ya está archivado

        $this->getJson("/api/categorias/{$this->hamburguesas->id}/eliminacion")->assertOk()->assertJsonPath('se_borra', false);
        $this->eliminar("/api/categorias/{$this->hamburguesas->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'archivado');

        $this->assertSame([], $this->getJson('/api/categorias')->assertOk()->json());
        $this->assertSame([], $this->getJson('/api/categorias?eliminados=1')->assertOk()->json());
        $this->putJson("/api/categorias/{$this->hamburguesas->id}", ['nombre' => 'Burgers'])->assertNotFound();
        $this->postJson("/api/categorias/{$this->hamburguesas->id}/restaurar")->assertNotFound();
        // No se puede usar en un producto, y su nombre queda libre.
        $this->postJson('/api/productos', ['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Doble', 'precio_base' => 25000, 'tipo_venta' => 'alimento'])
            ->assertStatus(422)->assertJsonValidationErrors('categoria_id');
        $this->postJson('/api/categorias', ['nombre' => 'Hamburguesas'])->assertCreated();
    }

    public function test_RF_M04_058_combo_nuevo_se_borra_y_uno_vendido_sale_de_todo_y_no_se_vende(): void
    {
        $nuevo = Combo::create(['nombre' => 'Combo prueba', 'precio' => 35000, 'tipo_venta' => 'alimento', 'activo' => true]);
        $nuevo->productos()->attach($this->clasica->id, ['cantidad' => 2]);
        $vendido = Combo::create(['nombre' => 'Combo pareja', 'precio' => 36000, 'tipo_venta' => 'alimento', 'activo' => true]);
        $vendido->productos()->attach($this->clasica->id, ['cantidad' => 2]);
        $this->pedido(['combo_id' => $vendido->id, 'cantidad' => 1])->assertCreated();

        $this->eliminar("/api/combos/{$nuevo->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'borrado');
        $this->assertDatabaseMissing('combo_producto', ['combo_id' => $nuevo->id]);

        $this->getJson("/api/combos/{$vendido->id}/eliminacion")->assertOk()->assertJsonPath('se_borra', false)
            ->assertJsonPath('detalle', fn ($d) => str_contains($d, 'Se vendió en 1 línea de pedido'));
        $this->eliminar("/api/combos/{$vendido->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'archivado');
        $this->assertSame([], $this->getJson('/api/combos-admin')->assertOk()->json());
        $this->assertSame([], $this->getJson('/api/combos-admin?eliminados=1')->assertOk()->json());
        $this->assertSame([], $this->getJson('/api/combos')->assertOk()->json());
        $this->pedido(['combo_id' => $vendido->id, 'cantidad' => 1])->assertStatus(422);
        $this->assertSame(1, Pedido::count(), 'El pedido viejo sigue con su combo.');
        $this->assertSame('Combo pareja', Pedido::firstOrFail()->detalles()->firstOrFail()->combo->nombre);
    }

    public function test_RF_M04_059_extra_nuevo_se_borra_y_uno_vendido_sale_de_todo_y_no_se_ofrece(): void
    {
        $nuevo = Extra::create(['nombre' => 'Salsa', 'precio' => 1000, 'alcance' => 'general', 'activo' => true]);
        $nuevo->productos()->attach($this->clasica->id);
        $queso = Extra::create(['nombre' => 'Queso', 'precio' => 3000, 'alcance' => 'general', 'activo' => true]);
        $queso->productos()->attach($this->clasica->id);
        $this->pedido(['producto_id' => $this->clasica->id, 'cantidad' => 1, 'extras_ids' => [$queso->id]])->assertCreated();

        $this->eliminar("/api/extras/{$nuevo->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'borrado');
        $this->assertDatabaseMissing('producto_extra', ['extra_id' => $nuevo->id]);

        $this->eliminar("/api/extras/{$queso->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'archivado');
        $this->assertSame([], $this->getJson('/api/extras')->assertOk()->json());
        $this->assertSame([], $this->getJson('/api/extras?eliminados=1')->assertOk()->json());
        $this->putJson("/api/extras/{$queso->id}", ['nombre' => 'Queso', 'precio' => 3000, 'alcance' => 'general', 'activo' => true])->assertNotFound();
        $this->pedido(['producto_id' => $this->clasica->id, 'cantidad' => 1, 'extras_ids' => [$queso->id]])->assertStatus(422);
        $this->assertSame([], collect($this->getJson('/api/productos')->json())->firstWhere('nombre', 'Clásica')['extras_generales']);
    }

    public function test_RF_M04_060_promocion_nunca_aplicada_se_borra_y_una_aplicada_sale_de_todo_y_deja_de_aplicarse(): void
    {
        $nueva = Promocion::create(['nombre' => 'Navidad', 'tipo' => 'manual', 'alcance' => 'total', 'tipo_valor' => 'porcentaje', 'valor' => 5, 'activo' => true]);
        $martes = Promocion::create(['nombre' => 'Martes 10%', 'tipo' => 'automatica', 'alcance' => 'total', 'tipo_valor' => 'porcentaje', 'valor' => 10, 'activo' => true]);
        $this->pedido(['producto_id' => $this->clasica->id, 'cantidad' => 1])->assertCreated()->assertJsonPath('total', '18000.00');

        $this->eliminar("/api/promociones/{$nueva->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'borrado');

        $this->getJson("/api/promociones/{$martes->id}/eliminacion")->assertOk()->assertJsonPath('se_borra', false)
            ->assertJsonPath('detalle', fn ($d) => str_contains($d, 'Se aplicó en 1 pedido'));
        $this->eliminar("/api/promociones/{$martes->id}/eliminar")->assertOk()->assertJsonPath('resultado', 'archivado');
        $this->assertSame([], $this->getJson('/api/promociones')->assertOk()->json());
        $this->assertSame([], $this->getJson('/api/promociones?eliminados=1')->assertOk()->json());
        $this->pedido(['producto_id' => $this->clasica->id, 'cantidad' => 1])->assertCreated()->assertJsonPath('total', '20000.00');
        $this->assertTrue(Auditoria::where('accion', 'archivar_promocion')->where('entidad_id', $martes->id)->exists());
    }

    public function test_el_mesero_no_elimina_del_catalogo(): void
    {
        $combo = Combo::create(['nombre' => 'Combo prueba', 'precio' => 35000, 'tipo_venta' => 'alimento', 'activo' => true]);
        Sanctum::actingAs($this->mesero);
        $this->eliminar("/api/combos/{$combo->id}/eliminar")->assertForbidden();
        $this->eliminar("/api/categorias/{$this->hamburguesas->id}/eliminar")->assertForbidden();
        $this->assertDatabaseHas('combos', ['id' => $combo->id]);
    }
}
