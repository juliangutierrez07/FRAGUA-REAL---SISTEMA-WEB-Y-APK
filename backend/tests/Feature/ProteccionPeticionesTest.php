<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seguridad (28/10/2026): límites de peticiones con su propio contador (RNF-TRANS-045), equipo de confianza en
 * el inicio de sesión (RF-M01-036, RN-USR-27) y topes de los pedidos de la web y del QR (RN-PED-36).
 */
class ProteccionPeticionesTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private Producto $producto;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 15:00:00', 'America/Bogota'));
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'username' => 'mario', 'password' => Hash::make('Mesero2026x'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => Hash::make('Admin2026x'), 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Doble', 'precio_base' => 30000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
    }

    private function entrar(string $login, string $clave, array $llaves = [], string $ip = '10.0.0.1')
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/auth/login', ['login' => $login, 'password' => $clave, 'equipos_confianza' => $llaves]);
    }

    private function recogida(string $telefono, int $cantidad = 1, string $ip = '10.0.0.9')
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Invitado', 'cliente_invitado_telefono' => $telefono,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => $cantidad]],
        ]);
    }

    // ---- Inicio de sesión ----

    public function test_los_intentos_contra_una_cuenta_no_bloquean_a_otra_desde_la_misma_red(): void
    {
        // Antes: 10 peticiones por minuto por IP para todo el formulario; la undécima de cualquiera era 429.
        foreach (range(1, 4) as $i) {
            $this->entrar('admin@test.co', 'mala')->assertStatus(422);
        }
        foreach (range(1, 8) as $i) {
            $this->entrar("nadie{$i}@test.co", 'mala')->assertStatus(422);
        }
        $this->entrar('mario', 'Mesero2026x')->assertOk();
    }

    public function test_los_demas_formularios_publicos_no_gastan_el_contador_del_inicio_de_sesion(): void
    {
        foreach (range(1, 12) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson('/api/pedidos/seguimiento/no-existe')->assertNotFound();
        }
        $this->entrar('mario', 'Mesero2026x')->assertOk();
    }

    public function test_RF_M01_036_desde_su_equipo_de_confianza_entra_aunque_un_intruso_bloquee_la_cuenta(): void
    {
        $llave = $this->entrar('mario', 'Mesero2026x')->assertOk()->json('equipo_confianza');
        $this->assertSame(64, strlen($llave));

        // Un intruso (otra red, sin la llave) bloquea la cuenta con 5 intentos.
        foreach (range(1, 5) as $i) {
            $this->entrar('mario', 'mala', [], '203.0.113.7');
        }
        $this->entrar('mario', 'Mesero2026x', [], '203.0.113.7')->assertStatus(429);
        $this->entrar('mario', 'Mesero2026x')->assertStatus(429); // sin la llave, tampoco desde el local

        // Con la llave de su equipo entra, y conserva la misma llave.
        $this->entrar('mario', 'Mesero2026x', [$llave])->assertOk()->assertJsonPath('equipo_confianza', $llave);
    }

    public function test_RN_USR_27_la_llave_solo_sirve_para_su_cuenta_y_sus_fallos_bloquean_solo_ese_equipo(): void
    {
        $llave = $this->entrar('mario', 'Mesero2026x')->assertOk()->json('equipo_confianza');

        // La llave de Mario no salva la cuenta del Administrador.
        foreach (range(1, 5) as $i) {
            $this->entrar('admin@test.co', 'mala', [], '203.0.113.7');
        }
        $this->entrar('admin@test.co', 'Admin2026x', [$llave])->assertStatus(429);

        // Cinco fallos desde el equipo de confianza bloquean ese equipo, no la cuenta en otros.
        foreach (range(1, 5) as $i) {
            $this->entrar('mario', 'mala', [$llave]);
        }
        $this->entrar('mario', 'Mesero2026x', [$llave])->assertStatus(429);
        $this->entrar('mario', 'Mesero2026x', [], '198.51.100.4')->assertOk();
    }

    public function test_una_ip_bloqueada_no_detiene_al_personal_que_ya_entraba_desde_ese_equipo(): void
    {
        $llave = $this->entrar('admin@test.co', 'Admin2026x')->assertOk()->json('equipo_confianza');

        // Alguien en el mismo wifi prueba 20 cuentas: la IP queda bloqueada para equipos desconocidos.
        foreach (range(1, 20) as $i) {
            $this->entrar("nadie{$i}@test.co", 'mala')->assertStatus(422);
        }
        $this->entrar('mario', 'Mesero2026x')->assertStatus(429);
        $this->entrar('admin@test.co', 'Admin2026x', [$llave])->assertOk();
    }

    // ---- Pedidos de la web y del QR ----

    public function test_RN_PED_36_un_comprador_tiene_hasta_tres_pedidos_esperando_confirmacion(): void
    {
        foreach (range(1, 3) as $i) {
            $this->recogida('3001112233')->assertCreated();
        }
        $this->recogida('3001112233')->assertStatus(422)->assertJsonValidationErrors('lineas');

        // Otro celular sí puede; y al confirmar uno, el primero vuelve a tener cupo.
        $this->recogida('3009998877')->assertCreated();
        Pedido::where('cliente_invitado_telefono', '3001112233')->first()->forceFill(['estado' => 'confirmado'])->save();
        $this->recogida('3001112233')->assertCreated();
    }

    public function test_RN_PED_36_una_mesa_tiene_hasta_cuatro_pedidos_del_qr_esperando(): void
    {
        $pedidoQr = fn () => $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ]);
        foreach (range(1, 4) as $i) {
            $pedidoQr()->assertCreated();
        }
        $pedidoQr()->assertStatus(422)->assertJsonValidationErrors('mesa_id');

        // El Mesero sí toma pedidos en esa mesa.
        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertCreated();
    }

    public function test_RN_PED_36_tope_de_unidades_por_linea_en_la_web_y_no_para_el_personal(): void
    {
        $this->recogida('3001112233', 21)->assertStatus(422)->assertJsonValidationErrors('lineas.0.cantidad');
        $this->recogida('3001112233', 20)->assertCreated();

        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 60]],
        ])->assertCreated();
    }

    public function test_RNF_TRANS_045_muchos_pedidos_seguidos_desde_una_ip_se_frenan(): void
    {
        // Celulares distintos (para no chocar con el tope por comprador): el undécimo en un minuto es 429.
        foreach (range(1, 10) as $i) {
            $this->recogida('30011122'.str_pad((string) $i, 2, '0', STR_PAD_LEFT))->assertCreated();
        }
        $this->recogida('3001112299')->assertStatus(429);
        // Desde otra IP sigue funcionando.
        $this->recogida('3001112299', 1, '10.0.0.77')->assertCreated();
    }

    // ---- Pedidos por día ----

    public function test_RF_M02_T_018_la_lista_de_pedidos_se_filtra_por_dia_en_hora_de_colombia(): void
    {
        // 27/10 a las 11:30 p. m. en Colombia = 28/10 04:30 UTC: es de ayer, no de hoy.
        $this->travelTo(CarbonImmutable::parse('2026-10-27 23:30:00', 'America/Bogota'));
        $ayer = Pedido::findOrFail($this->recogida('3001112233')->assertCreated()->json('id'));
        $this->travelTo(CarbonImmutable::parse('2026-10-28 08:00:00', 'America/Bogota'));
        $hoy = Pedido::findOrFail($this->recogida('3009998877')->assertCreated()->json('id'));

        Sanctum::actingAs($this->admin);
        $ids = fn (string $q) => collect($this->getJson('/api/pedidos?'.$q)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$hoy->id], $ids('desde=2026-10-28&hasta=2026-10-28'));
        $this->assertSame([$ayer->id], $ids('desde=2026-10-27&hasta=2026-10-27'));
        $this->assertEqualsCanonicalizing([$ayer->id, $hoy->id], $ids('desde=2026-10-22&hasta=2026-10-28'));
        $this->getJson('/api/pedidos?desde=2026-10-28&hasta=2026-10-20')->assertStatus(422);
    }
}
