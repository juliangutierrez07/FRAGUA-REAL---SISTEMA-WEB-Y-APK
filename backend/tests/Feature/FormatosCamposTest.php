<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Formatos de los campos (22/10/2026): el servidor aplica las mismas reglas que la pantalla
 * (App\Support\Validacion\Formatos): teléfonos de 10 dígitos guardados solo con números, nombres de
 * personas sin números, cédula o NIT, cuenta de 11 dígitos, llave Bre-B y un tope a los valores en pesos.
 */
class FormatosCamposTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::create(['role' => 'superadmin', 'nombre' => 'Sara Super', 'email' => 'super@test.co', 'password' => 'Clave12345', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'Clave12345', 'activo' => true]);
    }

    public function test_el_telefono_se_guarda_solo_con_numeros_y_debe_tener_10_digitos(): void
    {
        $base = ['nombre' => 'Carla Pérez', 'email' => 'carla@test.co', 'documento' => '1020304050', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345'];

        foreach (['31125339', '311253394162', '3112533941j6p', 'abc', '1234567890'] as $malo) {
            $this->postJson('/api/auth/register', $base + ['telefono' => $malo])->assertStatus(422)->assertJsonValidationErrors('telefono');
        }
        $this->postJson('/api/auth/register', $base + ['telefono' => '+57 311 253-3941'])->assertCreated();
        $this->assertSame('3112533941', User::where('email', 'carla@test.co')->value('telefono'));
    }

    public function test_el_nombre_de_una_persona_no_lleva_numeros(): void
    {
        $base = ['email' => 'luis@test.co', 'documento' => '1020304050', 'password' => 'Clave12345', 'password_confirmation' => 'Clave12345'];
        $this->postJson('/api/auth/register', $base + ['nombre' => '888787'])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->postJson('/api/auth/register', $base + ['nombre' => 'Luis 2'])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->postJson('/api/auth/register', $base + ['nombre' => "  María   José O'Neill-Gómez "])->assertCreated();
        $this->assertSame("María José O'Neill-Gómez", User::where('email', 'luis@test.co')->value('nombre'));
    }

    public function test_pedido_y_reserva_validan_nombre_y_telefono_del_invitado(): void
    {
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $pedido = fn (array $extra) => $this->postJson('/api/pedidos', $extra + [
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'cliente_invitado_nombre' => 'Ana Ruiz', 'cliente_invitado_telefono' => '3001234567',
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        $pedido(['cliente_invitado_nombre' => 'Ana 123'])->assertStatus(422)->assertJsonValidationErrors('cliente_invitado_nombre');
        $pedido(['cliente_invitado_telefono' => '300123'])->assertStatus(422)->assertJsonValidationErrors('cliente_invitado_telefono');
        $id = $pedido(['cliente_invitado_telefono' => '300 123 4567'])->assertCreated()->json('id');
        $this->assertSame('3001234567', Pedido::findOrFail($id)->cliente_invitado_telefono);

        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        $reserva = fn (array $extra) => $this->postJson('/api/reservas', $extra + [
            'mesa_id' => $mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234', 'cliente_correo' => 'laura@correo.co',
            'fecha_hora' => CarbonImmutable::now('America/Bogota')->addDay()->setTime(20, 0)->format('Y-m-d H:i'),
        ]);
        $reserva(['cliente_nombre' => '12345'])->assertStatus(422)->assertJsonValidationErrors('cliente_nombre');
        $reserva(['cliente_telefono' => '310555123x'])->assertStatus(422)->assertJsonValidationErrors('cliente_telefono');
    }

    public function test_datos_del_negocio_telefono_y_nit(): void
    {
        Sanctum::actingAs($this->super);
        $base = ['direccion' => 'Calle 1', 'telefono' => '3105551234'];

        $this->putJson('/api/negocio', $base + ['nit' => 'rwerqwerqwerqwer'])->assertStatus(422)->assertJsonValidationErrors('nit');
        $this->putJson('/api/negocio', $base + ['nit' => '900123456-78'])->assertStatus(422)->assertJsonValidationErrors('nit');
        $this->putJson('/api/negocio', ['telefono' => '311 253 3941', 'direccion' => 'Calle 1', 'nit' => '1.075.320.008-5'])
            ->assertOk()->assertJsonPath('telefono', '3112533941')->assertJsonPath('nit', '1075320008-5');
    }

    public function test_cuentas_de_pago_con_su_formato(): void
    {
        Sanctum::actingAs($this->super);
        $malos = [
            ['nequi' => ['numero' => '3112533941j6p', 'titular' => 'Fragua Real']],
            ['nequi' => ['numero' => '6012345678', 'titular' => 'Fragua Real']],
            ['bancolombia' => ['tipo' => 'ahorros', 'numero' => '9494949498498498494', 'titular' => 'Fragua Real']],
            ['bancolombia' => ['tipo' => 'ahorros', 'numero' => '12345678901', 'titular' => '888787']],
            ['bancolombia' => ['tipo' => 'ahorros', 'numero' => '12345678901', 'titular' => 'Fragua Real', 'documento' => '64-94']],
            ['breb' => ['llave' => 'mi llave', 'titular' => 'Fragua Real']],
        ];
        foreach ($malos as $datos) {
            $this->putJson('/api/negocio/pagos-directos', $datos)->assertStatus(422);
        }

        $this->putJson('/api/negocio/pagos-directos', [
            'nequi' => ['numero' => '311 253 3941', 'titular' => 'Fragua Real'],
            'bancolombia' => ['tipo' => 'ahorros', 'numero' => '123-456789-01', 'titular' => 'Fragua Real', 'documento' => '900.123.456-7'],
            'breb' => ['llave' => '@fraguareal', 'titular' => 'Fragua Real'],
        ])->assertOk()
            ->assertJsonPath('datos.nequi.numero', '3112533941')
            ->assertJsonPath('datos.bancolombia.numero', '12345678901')
            ->assertJsonPath('datos.bancolombia.documento', '900123456-7');

        foreach (['3112533941', 'pagos@fraguareal.com', '900123456-7'] as $llave) {
            $this->putJson('/api/negocio/pagos-directos', ['breb' => ['llave' => $llave, 'titular' => 'Fragua Real']])->assertOk();
        }
    }

    public function test_los_valores_en_pesos_tienen_tope_y_deben_ser_numeros(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/configuraciones', ['valor_domicilio' => '2000wrfqwrf', 'deposito_reserva' => 20000])
            ->assertStatus(422)->assertJsonValidationErrors('valor_domicilio');
        $this->putJson('/api/configuraciones', ['valor_domicilio' => 2000, 'deposito_reserva' => 200003222222])
            ->assertStatus(422)->assertJsonValidationErrors('deposito_reserva');
        $this->putJson('/api/configuraciones', ['valor_domicilio' => 2000, 'deposito_reserva' => 20000])->assertOk();
    }
}
