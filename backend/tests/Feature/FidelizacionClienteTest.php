<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\PedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fidelización (27/10/2026): el Cliente se registra con su número de documento, único entre clientes, igual
 * que su celular si lo da (M01: RF-M01-035, RN-USR-26), y al cobrar el Mesero lo busca por documento, correo
 * o celular y le deja el pedido —del QR o del Mesero— en su historial (M06: RF-M06-040, RN-M06-23, HU-M06-021).
 */
class FidelizacionClienteTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private User $cliente;
    private Mesa $mesa;
    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'telefono' => '3001112233', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->cliente = User::create(['role' => 'cliente', 'nombre' => 'Juan Pérez', 'email' => 'Juan@Test.co', 'telefono' => '3105556677', 'documento' => '1020304050', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-3', 'estado' => 'libre']);
    }

    private function pedido(string $origen = 'qr', ?int $cuenta = null): Pedido
    {
        return app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => $origen, 'mesa_id' => $this->mesa->id, 'cuenta' => $cuenta,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ]);
    }

    private function registro(array $datos): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/register', $datos + [
            'nombre' => 'Ana Gómez', 'email' => 'ana@test.co', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345',
        ]);
    }

    // ---------- Registro y perfil (RF-M01-035, RN-USR-26) ----------

    public function test_RF_M01_035_el_registro_pide_el_documento_y_no_se_repite(): void
    {
        $this->registro([])->assertStatus(422)->assertJsonPath('errors.documento.0', 'Escribe tu número de documento.');
        $this->registro(['documento' => '12'])->assertStatus(422)->assertJsonValidationErrors('documento');
        $this->registro(['documento' => '1.020.304.050'])->assertStatus(422)
            ->assertJsonPath('errors.documento.0', 'Ese número de documento ya está registrado en otra cuenta.');

        $this->registro(['documento' => 'pa 123-456'])->assertCreated()->assertJsonPath('user.documento', 'PA123456');
    }

    public function test_RN_USR_26_el_celular_no_se_repite_entre_clientes_pero_si_con_el_personal(): void
    {
        $this->registro(['documento' => '55667788', 'telefono' => '+57 310 555 6677'])->assertStatus(422)
            ->assertJsonPath('errors.telefono.0', 'Ese celular ya está registrado en otra cuenta.');
        // El mismo celular de un Mesero sí se puede (su cuenta de personal no cuenta).
        $this->registro(['documento' => '55667788', 'telefono' => '3001112233'])->assertCreated();
    }

    public function test_el_cliente_agrega_su_documento_en_el_perfil_y_luego_no_lo_deja_vacio(): void
    {
        $antiguo = User::create(['role' => 'cliente', 'nombre' => 'Rosa Díaz', 'email' => 'rosa@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        Sanctum::actingAs($antiguo);

        $this->putJson('/api/perfil', ['nombre' => 'Rosa Díaz'])->assertOk()->assertJsonPath('documento', null);
        $this->putJson('/api/perfil', ['nombre' => 'Rosa Díaz', 'documento' => '1020304050'])->assertStatus(422)->assertJsonValidationErrors('documento');
        $this->putJson('/api/perfil', ['nombre' => 'Rosa Díaz', 'documento' => '52.123.456'])->assertOk()->assertJsonPath('documento', '52123456');
        $this->putJson('/api/perfil', ['nombre' => 'Rosa Díaz', 'documento' => ''])->assertStatus(422)->assertJsonValidationErrors('documento');
    }

    public function test_el_administrador_corrige_el_documento_de_un_cliente(): void
    {
        $otro = User::create(['role' => 'cliente', 'nombre' => 'Rosa Díaz', 'email' => 'rosa@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        Sanctum::actingAs($this->admin);
        $datos = ['nombre' => 'Rosa Díaz', 'email' => 'rosa@test.co', 'role' => 'cliente'];

        $this->putJson("/api/usuarios/{$otro->id}", $datos + ['documento' => '1020304050'])->assertStatus(422)
            ->assertJsonPath('errors.documento.0', 'Ese número de documento ya lo tiene otro cliente.');
        $this->putJson("/api/usuarios/{$otro->id}", $datos + ['documento' => '52123456'])->assertOk();
        $this->assertSame('52123456', $otro->fresh()->documento);
    }

    // ---------- Al cobrar (RF-M06-040, RN-M06-23) ----------

    public function test_RF_M06_040_el_mesero_encuentra_al_cliente_por_documento_correo_o_celular(): void
    {
        Sanctum::actingAs($this->mesero);

        foreach (['1.020.304.050' => 'documento', 'juan@test.co' => 'correo', '+57 310 555 6677' => 'celular'] as $dato => $por) {
            $this->getJson('/api/clientes/buscar?'.http_build_query(['dato' => $dato]))->assertOk()
                ->assertExactJson(['id' => $this->cliente->id, 'nombre' => 'Juan Pérez', 'coincide' => $por]);
        }
        $this->getJson('/api/clientes/buscar?dato=999999')->assertNotFound()
            ->assertJsonPath('message', 'No hay un cliente registrado con ese documento, correo o celular.');
        // Al personal no se le busca, ni a un cliente desactivado.
        $this->getJson('/api/clientes/buscar?dato=3001112233')->assertNotFound();
        $this->cliente->update(['activo' => false]);
        $this->getJson('/api/clientes/buscar?dato=1020304050')->assertNotFound();
    }

    public function test_RF_M06_040_el_pedido_del_qr_queda_en_el_historial_del_cliente(): void
    {
        $pedido = $this->pedido('qr');
        Sanctum::actingAs($this->mesero);

        $this->postJson('/api/pedidos/asignar-cliente', ['pedido_ids' => [$pedido->id], 'cliente_id' => $this->cliente->id])
            ->assertOk()->assertJsonPath('pedido_ids', [$pedido->id])->assertJsonPath('cliente.nombre', 'Juan Pérez');
        $this->assertDatabaseHas('auditorias', ['accion' => 'asignar_cliente_pedido', 'entidad_id' => $pedido->id]);

        Sanctum::actingAs($this->cliente);
        $this->getJson('/api/pedidos')->assertOk()->assertJsonPath('data.0.id', $pedido->id);
    }

    public function test_se_asigna_toda_la_cuenta_de_la_mesa_y_no_se_le_quita_a_otro_cliente(): void
    {
        $uno = $this->pedido('mesero', 1);
        $dos = $this->pedido('qr', 1);
        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/pedidos/asignar-cliente', ['pedido_ids' => [$uno->id, $dos->id], 'cliente_id' => $this->cliente->id])
            ->assertOk()->assertJsonPath('pedido_ids', [$uno->id, $dos->id]);

        $otro = User::create(['role' => 'cliente', 'nombre' => 'Rosa Díaz', 'email' => 'rosa@test.co', 'documento' => '52123456', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->postJson('/api/pedidos/asignar-cliente', ['pedido_ids' => [$uno->id], 'cliente_id' => $otro->id])
            ->assertStatus(422)->assertJsonPath('message', "El pedido #{$uno->id} ya está a nombre de otro cliente.");
        $this->assertSame($this->cliente->id, $uno->fresh()->cliente_id);
    }

    public function test_un_pedido_cancelado_no_se_asigna_y_solo_el_mesero_asigna(): void
    {
        $pedido = $this->pedido('qr');
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/clientes/buscar?dato=1020304050')->assertForbidden();
        $this->postJson('/api/pedidos/asignar-cliente', ['pedido_ids' => [$pedido->id], 'cliente_id' => $this->cliente->id])->assertForbidden();

        app(PedidoService::class)->cancelar($pedido, $this->mesero, 'El cliente se fue');
        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/pedidos/asignar-cliente', ['pedido_ids' => [$pedido->id], 'cliente_id' => $this->cliente->id])
            ->assertStatus(422)->assertJsonPath('message', "El pedido #{$pedido->id} está cancelado: no se asigna a un cliente.");
        // Ni a una cuenta que no es de cliente.
        $otro = $this->pedido('qr');
        $this->postJson('/api/pedidos/asignar-cliente', ['pedido_ids' => [$otro->id], 'cliente_id' => $this->mesero->id])
            ->assertStatus(422)->assertJsonPath('message', 'Ese cliente ya no está disponible.');
    }
}
