<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1, tarea 2 del plan de implementación: el Mesero registra domicilios y recogidas que
 * llegan por teléfono o WhatsApp, y todo pedido remoto lleva sus datos de entrega completos.
 *
 * RF-M07-001, RF-M07-003 a 007, RF-M07-010, RF-M07-R-001, RF-M07-R-003, RN-M07-01,
 * RN-M07-R-01, HU-M07-001, HU-M07-003.
 */
class PedidoTelefonicoTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\PagoDirectoDePrueba;

    private User $mesero;
    private Producto $hamburguesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
    }

    private function domicilio(array $cambios = []): array
    {
        return array_merge([
            'tipo' => 'domicilio', 'origen' => 'mesero',
            'cliente_invitado_nombre' => 'Marta Gómez', 'cliente_invitado_telefono' => '300 123 4567',
            'direccion_entrega' => 'Carrera 10 # 20-30', 'barrio' => 'El Prado', 'referencia' => 'Casa esquinera azul',
            'observaciones_entrega' => 'Llamar al llegar, el timbre no sirve',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 2]],
        ], $cambios);
    }

    public function test_RF_M07_001_el_mesero_registra_un_domicilio_telefonico_con_todos_sus_datos(): void
    {
        Sanctum::actingAs($this->mesero);

        $id = $this->postJson('/api/pedidos', $this->domicilio())->assertCreated()->json('id');

        $pedido = Pedido::findOrFail($id);
        $this->assertSame('mesero', $pedido->origen);
        $this->assertSame('confirmado', $pedido->estado); // RF-M02-L-036: lo que toma el Mesero sale a la cocina de una vez
        $this->assertSame('3001234567', $pedido->telefono_contacto); // se guarda solo con números
        $this->assertSame('El Prado', $pedido->barrio);
        $this->assertSame('Casa esquinera azul', $pedido->referencia);
        $this->assertSame('Llamar al llegar, el timbre no sirve', $pedido->observaciones_entrega);
        // RF-M07-011/012: el valor de domicilio lo fija el servidor y se suma aparte.
        $valor = (float) config('fragua_real.valor_domicilio_default');
        $this->assertEquals($valor, $pedido->valor_domicilio);
        $this->assertEquals(40000 + $valor, $pedido->total);
    }

    public function test_RF_M07_R_001_el_mesero_registra_una_recogida_sin_direccion_ni_costo(): void
    {
        Sanctum::actingAs($this->mesero);

        // Aunque el formulario mande dirección, una recogida no la guarda (RN-M07-R-01).
        $id = $this->postJson('/api/pedidos', $this->domicilio(['tipo' => 'recogida']))->assertCreated()->json('id');

        $pedido = Pedido::findOrFail($id);
        $this->assertNull($pedido->direccion_entrega);
        $this->assertNull($pedido->barrio);
        $this->assertNull($pedido->valor_domicilio);
        $this->assertEquals(40000, $pedido->total);
        $this->assertSame('3001234567', $pedido->telefono_contacto); // se guarda solo con números
    }

    public function test_RN_M07_01_un_domicilio_exige_barrio_y_referencia(): void
    {
        Sanctum::actingAs($this->mesero);

        $this->postJson('/api/pedidos', $this->domicilio(['barrio' => '', 'referencia' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['barrio', 'referencia']);
        $this->assertSame(0, Pedido::count());
    }

    public function test_RF_M07_004_el_telefono_debe_ser_valido(): void
    {
        Sanctum::actingAs($this->mesero);

        $this->postJson('/api/pedidos', $this->domicilio(['cliente_invitado_telefono' => 'llamar a Marta']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cliente_invitado_telefono']);
    }

    public function test_un_cliente_con_cuenta_sin_telefono_en_su_perfil_lo_escribe_en_el_pedido(): void
    {
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Ana', 'email' => 'ana@test.co', 'password' => 'secreto123', 'activo' => true]);
        Sanctum::actingAs($cliente);
        $datos = ['tipo' => 'recogida', 'origen' => 'cliente_web', 'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]]];

        $this->postJson('/api/pedidos', $datos)->assertStatus(422)->assertJsonValidationErrors(['telefono_contacto']);

        $id = $this->postJson('/api/pedidos', $datos + ['telefono_contacto' => '3109876543'])->assertCreated()->json('id');
        $this->assertSame('3109876543', Pedido::findOrFail($id)->telefono_contacto);
    }

    public function test_si_el_perfil_tiene_telefono_se_usa_cuando_el_pedido_no_trae_otro(): void
    {
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Ana', 'email' => 'ana@test.co', 'telefono' => '3205550000', 'password' => 'secreto123', 'activo' => true]);
        Sanctum::actingAs($cliente);

        $id = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->assertCreated()->json('id');

        $this->assertSame('3205550000', Pedido::findOrFail($id)->telefono_contacto);
    }

    public function test_un_pedido_local_no_guarda_datos_de_entrega(): void
    {
        Sanctum::actingAs($this->mesero);
        $mesa = \App\Models\Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);

        $id = $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $mesa->id,
            'observaciones_entrega' => 'no aplica',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->assertCreated()->json('id');

        $pedido = Pedido::findOrFail($id);
        $this->assertNull($pedido->telefono_contacto);
        $this->assertNull($pedido->observaciones_entrega);
    }

    // ---- Método de pago elegido (RF-M07-013/015, RF-M07-R-007/008, HU-M07-004) ----

    public function test_RF_M07_015_el_pedido_guarda_el_metodo_y_la_pasarela_elegidos(): void
    {
        $datos = $this->domicilio(['origen' => 'cliente_web', 'metodo_pago_preferido' => 'en_linea', 'pasarela_preferida' => 'bancolombia']);

        $id = $this->postJson('/api/pedidos', $datos)->assertCreated()->json('id');

        $pedido = Pedido::findOrFail($id);
        $this->assertSame('en_linea', $pedido->metodo_pago_preferido);
        $this->assertSame('bancolombia', $pedido->pasarela_preferida);
    }

    public function test_sin_metodo_elegido_un_pedido_por_telefono_queda_contra_entrega(): void
    {
        Sanctum::actingAs($this->mesero);

        $id = $this->postJson('/api/pedidos', $this->domicilio())->assertCreated()->json('id');

        $this->assertSame('contra_entrega', Pedido::findOrFail($id)->metodo_pago_preferido);
        $this->assertNull(Pedido::findOrFail($id)->pasarela_preferida);
    }

    public function test_pago_en_linea_exige_pasarela_y_al_pagar_queda_la_aplicada(): void
    {
        $this->postJson('/api/pedidos', $this->domicilio(['origen' => 'cliente_web', 'metodo_pago_preferido' => 'en_linea']))
            ->assertStatus(422)->assertJsonValidationErrors(['pasarela_preferida']);

        $respuesta = $this->postJson('/api/pedidos', $this->domicilio(['origen' => 'cliente_web']))->assertCreated();
        $this->pagoDirecto("/api/pedidos/seguimiento/{$respuesta->json('token_publico')}/pagar-en-linea", [
            'pasarela' => 'nequi', 'monto' => $respuesta->json('total'),
        ])->assertSuccessful();

        $pedido = Pedido::findOrFail($respuesta->json('id'));
        $this->assertSame('en_linea', $pedido->metodo_pago_preferido);
        $this->assertSame('nequi', $pedido->pasarela_preferida);
    }
}
