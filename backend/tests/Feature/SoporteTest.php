<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Comanda;
use App\Models\Comprobante;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\CajaService;
use App\Services\RevisionProduccionService;
use App\Support\Usuarios\MenusPorRol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M10 (20/10/2026): rol Soporte, la cuenta técnica del desarrollador. Ve todo el panel del
 * Administrador pero no cambia nada del negocio; se crea en el servidor
 * (php artisan fragua:crear-soporte) y el SuperAdmin la desactiva, la reactiva y le cierra sesiones.
 */
class SoporteTest extends TestCase
{
    use RefreshDatabase;

    private const SOLO_CONSULTA = 'La cuenta de Soporte solo puede consultar.';

    private User $soporte;
    private User $super;
    private User $admin;
    private User $mesero;
    private Producto $hamburguesa;
    private Mesa $mesa;
    private array $dibujado = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->soporte = User::create(['role' => 'soporte', 'nombre' => 'Dev Soporte', 'email' => 'soporte@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->super = User::create(['role' => 'superadmin', 'nombre' => 'Sara Super', 'email' => 'super@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-3', 'estado' => 'libre']);

        View::composer(['comprobantes.ticket', 'comprobantes.pdf'], function ($vista) {
            $this->dibujado = $vista->getData();
        });
    }

    private function como(User $usuario): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($usuario);
    }

    private function pedidoPagado(): Pedido
    {
        app(CajaService::class)->abrir($this->admin, 0);
        $this->como($this->mesero);
        $id = $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->json('id'); // nace confirmado (RF-M02-L-036)
        $this->postJson("/api/pedidos/{$id}/cobrar", ['metodo' => 'efectivo', 'monto' => 20000])->assertOk();

        return Pedido::findOrFail($id);
    }

    public function test_soporte_ve_todo_el_panel_del_administrador(): void
    {
        $this->como($this->soporte);

        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('role', 'soporte')
            ->assertJsonPath('menus', MenusPorRol::claves('administrador'));

        foreach (['/api/dashboard/resumen', '/api/usuarios', '/api/pedidos', '/api/caja/turnos', '/api/compras-gastos',
            '/api/cierres-diarios', '/api/combos-admin', '/api/promociones', '/api/reservas', '/api/auditorias',
            '/api/configuraciones', '/api/sistema/estado', '/api/sistema/revision-produccion', '/api/pasarela/transacciones',
            '/api/reportes/ventas-totales?periodo=semana', '/api/reportes/exportaciones'] as $ruta) {
            $this->getJson($ruta)->assertOk();
        }

        // Exportar un reporte es un POST, pero solo arma el archivo.
        $this->postJson('/api/reportes/exportar', ['reporte' => 'ventas-totales', 'formato' => 'excel', 'periodo' => 'semana'])->assertOk();
    }

    public function test_soporte_no_cambia_nada_aunque_llame_la_api(): void
    {
        $this->como($this->soporte);

        $intentos = [
            ['post', '/api/categorias', ['nombre' => 'Nueva']],
            ['put', "/api/productos/{$this->hamburguesa->id}", ['nombre' => 'Otra']],
            ['post', '/api/mesas', ['nombre' => 'Mesa 9']],
            ['post', "/api/mesas/{$this->mesa->id}/desactivar", []],
            ['post', '/api/usuarios', ['role' => 'mesero', 'nombre' => 'X', 'email' => 'x@test.co', 'password' => 'secreto123']],
            ['post', "/api/usuarios/{$this->mesero->id}/desactivar", []],
            ['put', '/api/configuraciones', ['valor_domicilio' => 1]],
            ['put', '/api/negocio', ['direccion' => 'Otra', 'telefono' => '3000000000']],
            ['post', '/api/caja/abrir', ['monto_inicial' => 0]],
            // Rutas públicas que reconocen la sesión: tampoco.
            ['post', '/api/pedidos', ['tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id, 'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]]]],
            ['post', '/api/reservas', ['nombre_contacto' => 'X']],
        ];
        foreach ($intentos as [$metodo, $ruta, $datos]) {
            $this->{$metodo.'Json'}($ruta, $datos)->assertForbidden()->assertJsonPath('message', self::SOLO_CONSULTA);
        }

        $this->assertSame(1, Categoria::count());
        $this->assertSame('Clásica', $this->hamburguesa->fresh()->nombre);
        $this->assertTrue($this->mesero->fresh()->activo);
        $this->assertSame(0, Pedido::count());
    }

    public function test_soporte_si_maneja_su_propia_cuenta(): void
    {
        $this->como($this->soporte);
        $this->putJson('/api/perfil', ['nombre' => 'Dev de Soporte', 'email' => 'soporte@test.co'])->assertOk();
        $this->postJson('/api/perfil/password', ['password_actual' => 'secreto123', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026'])->assertOk();
        $this->assertSame('Dev de Soporte', $this->soporte->fresh()->nombre);
        $this->assertTrue(Hash::check('Nueva-Clave-2026', $this->soporte->fresh()->password));
    }

    public function test_solo_el_superadmin_ve_y_controla_la_cuenta_de_soporte(): void
    {
        // El Administrador no la ve ni la toca.
        $this->como($this->admin);
        $this->assertNotContains('soporte@test.co', collect($this->getJson('/api/usuarios')->json('data'))->pluck('email'));
        $this->postJson("/api/usuarios/{$this->soporte->id}/desactivar")->assertForbidden();

        // El SuperAdmin la ve; la desactiva (se cierran sus sesiones), la reactiva y le cierra sesiones…
        $this->soporte->createToken('portatil');
        $this->como($this->super);
        $fila = collect($this->getJson('/api/usuarios')->json('data'))->firstWhere('email', 'soporte@test.co');
        $this->assertSame('soporte', $fila['role']);
        $this->postJson("/api/usuarios/{$this->soporte->id}/desactivar")->assertOk();
        $this->assertFalse($this->soporte->fresh()->activo);
        $this->assertSame(0, $this->soporte->tokens()->count());
        $this->postJson("/api/usuarios/{$this->soporte->id}/activar")->assertOk();
        $this->postJson("/api/usuarios/{$this->soporte->id}/revocar-sesiones")->assertOk();
        $this->assertSame(1, Auditoria::where('accion', 'desactivar_usuario')->where('entidad_id', $this->soporte->id)->count());

        // …pero no la edita ni crea otra desde la aplicación.
        $this->putJson("/api/usuarios/{$this->soporte->id}", ['nombre' => 'Otro', 'email' => 'soporte@test.co', 'role' => 'administrador'])
            ->assertForbidden()->assertJsonPath('message', \App\Support\Usuarios\GestionCuentas::CUENTA_SOPORTE);
        $this->postJson('/api/usuarios', ['role' => 'soporte', 'nombre' => 'Otro', 'email' => 'otro@test.co', 'password' => 'secreto123'])
            ->assertStatus(422);
        $this->assertSame('soporte', $this->soporte->fresh()->role);
    }

    public function test_la_cuenta_se_crea_solo_en_el_servidor_y_no_convierte_cuentas_del_negocio(): void
    {
        $this->artisan('fragua:crear-soporte', ['correo' => 'dev@test.co', '--nombre' => 'Dev'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con mayúscula, minúscula y número)', 'Clave-Dev-2026')
            ->expectsQuestion('Repite la contraseña', 'Clave-Dev-2026')
            ->expectsOutput('Cuenta de Soporte creada: dev@test.co')
            ->assertExitCode(0);
        $dev = User::where('email', 'dev@test.co')->sole();
        $this->assertSame(['soporte', true], [$dev->role, $dev->activo]);
        $this->assertSame(1, Auditoria::where('accion', 'crear_soporte')->where('entidad_id', $dev->id)->count());

        // Con una cuenta del negocio no hace nada.
        $this->artisan('fragua:crear-soporte', ['correo' => 'admin@test.co'])->assertExitCode(1);
        $this->assertSame('administrador', $this->admin->fresh()->role);

        // A una que ya es de Soporte le cambia la contraseña y le cierra las sesiones; no la reactiva.
        $dev->createToken('portatil');
        $dev->forceFill(['activo' => false])->save();
        $this->artisan('fragua:crear-soporte', ['correo' => 'dev@test.co', '--cambiar-clave' => true])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con mayúscula, minúscula y número)', 'Clave-Nueva-2026')
            ->expectsQuestion('Repite la contraseña', 'Clave-Nueva-2026')
            ->expectsOutput('La cuenta está desactivada: solo el SuperAdmin puede reactivarla, en Usuarios.')
            ->assertExitCode(0);
        $this->assertTrue(Hash::check('Clave-Nueva-2026', $dev->fresh()->password));
        $this->assertFalse($dev->fresh()->activo);
        $this->assertSame(0, $dev->tokens()->count());
    }

    public function test_abrir_un_comprobante_no_cuenta_como_impresion_y_la_comanda_no_se_imprime(): void
    {
        $pedido = $this->pedidoPagado();

        $this->como($this->soporte);
        $this->get("/api/pedidos/{$pedido->id}/comprobante")->assertOk();
        $this->assertTrue($this->dibujado['reimpresion']['consulta']);
        $this->assertSame(0, (int) Comprobante::sole()->impresiones, 'no cuenta como impresión');
        $this->assertSame(1, Auditoria::where('accion', 'consultar_documento')->where('user_id', $this->soporte->id)->count());
        $this->assertStringContainsString('COPIA DE CONSULTA · SOPORTE', view('comprobantes.ticket', $this->dibujado)->render());

        // Imprimir una comanda la marcaría como recibida en la cocina.
        $comanda = Comanda::where('pedido_id', $pedido->id)->firstOrFail();
        $antes = $comanda->estado_impresion;
        $this->get("/api/comandas/{$comanda->id}/ticket")->assertForbidden();
        $this->assertSame($antes, $comanda->fresh()->estado_impresion);

        // El primero que imprime del negocio sigue sacando el original.
        $this->como($this->mesero);
        $this->get("/api/pedidos/{$pedido->id}/comprobante")->assertOk();
        $this->assertNull($this->dibujado['reimpresion']);
    }

    public function test_su_contrasena_no_autoriza_al_mesero_y_la_revision_la_menciona(): void
    {
        $this->assertNotContains('soporte', User::ROLES_ADMINISTRACION, 'no recibe avisos del negocio ni autoriza acciones del Mesero');

        $items = collect(app(RevisionProduccionService::class)->revisar())->keyBy('clave');
        $this->assertSame('Hay una cuenta de Soporte activa (solo consulta).', $items['soporte']['detalle']);

        $this->soporte->forceFill(['password' => Hash::make('password')])->save();
        $items = collect(app(RevisionProduccionService::class)->revisar())->keyBy('clave');
        $this->assertStringContainsString('soporte@test.co', $items['cuentas_prueba']['detalle']);
    }
}
