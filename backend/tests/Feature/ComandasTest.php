<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Comanda;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\ComandaService;
use App\Services\PedidoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 6, tarea 2 (13/10/2026, decisión D5: comandas impresas desde el navegador del Mesero).
 * RF-M02-L-011, RF-M02-T-004 a 007, RN-PED-06, 09, 17 a 19, RNF-M02-001, 004, 005, 011, 014, 016,
 * HU-M02-016, 017, RF-M07-020, 022.
 */
class ComandasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private Mesa $mesa;
    private Producto $hamburguesa;
    private Producto $papas;
    private Combo $combo;
    private array $dibujado = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 19:00:00', 'America/Bogota'));

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Menú']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->papas = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Papas', 'precio_base' => 6000, 'tipo_venta' => 'alimento']);
        $gaseosa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Gaseosa', 'precio_base' => 4000, 'tipo_venta' => 'bebida']);
        $this->combo = Combo::create(['nombre' => 'Combo Fragua', 'precio' => 28000, 'tipo_venta' => 'alimento', 'activo' => true]);
        $this->combo->productos()->attach([$this->hamburguesa->id => ['cantidad' => 1], $gaseosa->id => ['cantidad' => 1]]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 4', 'qr_code' => 'qr-4']);

        View::composer('comandas.ticket', function ($vista) {
            $this->dibujado = $vista->getData();
        });
    }

    private function pedidoConfirmado(array $lineas = null): Pedido
    {
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => $lineas ?? [
                ['producto_id' => $this->hamburguesa->id, 'cantidad' => 2, 'observaciones' => 'Sin cebolla'],
                ['combo_id' => $this->combo->id, 'cantidad' => 1],
            ],
        ]);
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/confirmar")->assertOk();

        return $pedido->fresh();
    }

    private function imprimir(Comanda $comanda)
    {
        return $this->get("/api/comandas/{$comanda->id}/ticket")->assertOk();
    }

    public function test_RN_PED_06_confirmar_manda_la_comanda_inicial_a_la_cocina(): void
    {
        $pedido = $this->pedidoConfirmado();

        $comanda = Comanda::sole();
        $this->assertSame([$pedido->id, 1, 'inicial', 'pendiente', 0], [$comanda->pedido_id, $comanda->numero, $comanda->tipo, $comanda->estado_impresion, $comanda->impresiones]);
        $lineas = $comanda->contenido['lineas'];
        $this->assertSame([2, 'Clásica', 'Sin cebolla'], [$lineas[0]['cantidad'], $lineas[0]['nombre'], $lineas[0]['observaciones']]);
        $this->assertSame(['1 Clásica', '1 Gaseosa'], $lineas[1]['componentes'], 'la cocina ve qué lleva el combo');
        $this->assertSame(['EN EL LOCAL', 'Mesa 4'], [$comanda->contenido['canal'], $comanda->contenido['mesa']]);

        // RNF-M02-011: aparece en las pendientes, y el pedido trae su estado de impresión.
        $this->getJson('/api/comandas/pendientes')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.pedido.mesa', 'Mesa 4')
            ->assertJsonPath('0.tipo', 'inicial')
            ->assertJsonMissingPath('0.clave');
        $this->getJson("/api/pedidos/{$pedido->id}")->assertJsonPath('comandas.0.estado_impresion', 'pendiente')
            ->assertJsonMissingPath('comandas.0.contenido');
    }

    public function test_RF_M02_T_007_la_misma_confirmacion_nunca_saca_dos_comandas(): void
    {
        $pedido = $this->pedidoConfirmado();
        $servicio = app(ComandaService::class);

        $servicio->inicial($pedido);
        $servicio->alConfirmar($pedido);
        $this->getJson('/api/comandas/pendientes')->assertJsonCount(1);
        $this->postJson("/api/pedidos/{$pedido->id}/confirmar")->assertStatus(422); // ya no está pendiente

        $this->assertSame(1, Comanda::count());
    }

    public function test_RN_PED_17_si_la_comanda_falla_el_pedido_queda_confirmado_y_la_comanda_sale_despues(): void
    {
        Comanda::creating(fn () => throw new RuntimeException('Base de datos ocupada'));
        $pedido = $this->pedidoConfirmado();

        $this->assertSame('confirmado', $pedido->estado, 'RNF-M02-001: el pedido no se pierde ni se deshace');
        $this->assertSame(0, Comanda::count());

        Comanda::flushEventListeners();
        $this->getJson('/api/comandas/pendientes')->assertOk()->assertJsonCount(1)->assertJsonPath('0.pedido.id', $pedido->id);
    }

    public function test_HU_M02_016_imprimir_reimprimir_y_avisar_que_no_salio(): void
    {
        $pedido = $this->pedidoConfirmado();
        $comanda = Comanda::sole();

        $respuesta = $this->imprimir($comanda);
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertNull($this->dibujado['reimpresion'], 'la primera es el original');
        $comanda->refresh();
        $this->assertSame(['impresa', 1, 1, $this->mesero->id], [$comanda->estado_impresion, $comanda->impresiones, $comanda->intentos, $comanda->impresa_por]);
        $this->getJson('/api/comandas/pendientes')->assertJsonCount(0);
        $this->assertSame('confirmado', $pedido->fresh()->estado, 'RN-PED-19: imprimir no cambia el pedido');

        // No salió (sin papel): vuelve a pendientes y la próxima vez sale como original.
        $this->postJson("/api/comandas/{$comanda->id}/no-impresa", ['motivo' => 'Sin papel'])->assertOk()
            ->assertJsonPath('estado_impresion', 'fallida');
        $this->getJson('/api/comandas/pendientes')->assertJsonCount(1)->assertJsonPath('0.ultimo_error', 'Sin papel');
        $this->imprimir($comanda);
        $this->assertNull($this->dibujado['reimpresion']);

        // Una que sí salió se reimprime marcada (regla de reimpresión).
        $this->imprimir($comanda);
        $this->assertSame(2, $this->dibujado['reimpresion']['vez']);
        $html = view('comandas.ticket', $this->dibujado)->render();
        $this->assertStringContainsString('REIMPRESIÓN · COPIA 2', $html);
        $this->assertStringContainsString('Sin cebolla', $html);
        $this->assertStringNotContainsString('$', $html, 'la comanda no lleva precios');

        $this->assertSame([3, 2], [$comanda->fresh()->intentos, $comanda->fresh()->impresiones]);
        $this->assertSame(3, Auditoria::where('accion', 'imprimir_comanda')->count());
        $this->assertSame(1, Auditoria::where('accion', 'comanda_no_impresa')->count());
    }

    public function test_RN_PED_09_los_cambios_con_el_pedido_confirmado_van_en_una_comanda_de_actualizacion(): void
    {
        $pedido = $this->pedidoConfirmado([['producto_id' => $this->hamburguesa->id, 'cantidad' => 1], ['producto_id' => $this->papas->id, 'cantidad' => 1]]);
        $servicio = app(PedidoService::class);

        // Antes de imprimir la inicial: se rehace, no sale otra hoja.
        $pedido = $servicio->agregarLinea($pedido, ['producto_id' => $this->papas->id, 'cantidad' => 2], $this->mesero);
        $this->assertSame(1, Comanda::count());
        $this->assertCount(3, Comanda::sole()->contenido['lineas']);

        $this->imprimir(Comanda::sole());

        // Ya en la cocina: el cambio va en la hoja 2, solo con lo que cambió.
        $papasNuevas = $pedido->detalles()->orderByDesc('id')->first();
        $hamburguesa = $pedido->detalles()->where('producto_id', $this->hamburguesa->id)->first();
        $servicio->actualizarLinea($pedido, $hamburguesa, ['cantidad' => 3], $this->mesero);
        $servicio->retirarLinea($pedido->fresh(), $papasNuevas, $this->mesero);

        $segunda = Comanda::where('numero', 2)->sole();
        $this->assertSame('actualizacion', $segunda->tipo);
        $this->assertSame(
            [['cambiar', 3, 1, 'Clásica'], ['quitar', 2, null, 'Papas']],
            array_map(fn ($l) => [$l['accion'], $l['cantidad'], $l['cantidad_antes'], $l['nombre']], $segunda->contenido['lineas']),
            'los dos cambios van en la misma hoja mientras no se imprima',
        );

        $this->imprimir($segunda);
        $html = view('comandas.ticket', $this->dibujado)->render();
        $this->assertStringContainsString('CAMBIOS AL PEDIDO', $html);
        // Diseño de tickets (29/10/2026): la acción va en su etiqueta y la cantidad en un recuadro.
        $this->assertMatchesRegularExpression('/<span>3<\/span><\/td>\s*<td>\s*<span class="accion">CAMBIAR<\/span>/u', $html);
        $this->assertStringContainsString('<div class="nombre">Clásica</div>', $html);
        $this->assertStringContainsString('Antes: 1', $html);

        // Otro cambio después de imprimir la hoja 2: sale la hoja 3.
        $servicio->agregarLinea($pedido->fresh(), ['producto_id' => $this->papas->id, 'cantidad' => 1], $this->mesero);
        $this->assertSame([1, 2, 3], Comanda::orderBy('numero')->pluck('numero')->all());
        $this->assertSame('agregar', Comanda::where('numero', 3)->sole()->contenido['lineas'][0]['accion']);
    }

    public function test_cancelar_avisa_a_la_cocina_solo_si_ya_tenia_el_pedido(): void
    {
        $servicio = app(PedidoService::class);

        $enCocina = $this->pedidoConfirmado();
        $this->imprimir($enCocina->comandas()->first());
        $servicio->cancelar($enCocina->fresh(), $this->admin, 'El cliente se fue');
        $cancelacion = $enCocina->comandas()->where('tipo', 'cancelacion')->sole();
        $this->assertSame('pendiente', $cancelacion->estado_impresion);
        $this->imprimir($cancelacion);
        $html = view('comandas.ticket', $this->dibujado)->render();
        $this->assertStringContainsString('PEDIDO CANCELADO', $html);
        $this->assertStringContainsString('El cliente se fue', $html);

        $sinImprimir = $this->pedidoConfirmado();
        $servicio->cancelar($sinImprimir->fresh(), $this->admin, 'Error al tomarlo');
        $this->assertSame(['descartada'], $sinImprimir->comandas()->pluck('estado_impresion')->all());
        $this->getJson('/api/comandas/pendientes')->assertJsonCount(0);
    }

    public function test_un_domicilio_tambien_tiene_comanda_y_solo_el_personal_las_ve(): void
    {
        $id = $this->postJson('/api/pedidos', [
            'tipo' => 'domicilio', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Ana Pérez', 'cliente_invitado_telefono' => '3001234567',
            'direccion_entrega' => 'Calle 1 # 2-3', 'barrio' => 'Centro', 'referencia' => 'Casa azul',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->assertCreated()->json('id');
        $pedido = Pedido::findOrFail($id);
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pedidos/{$pedido->id}/confirmar")->assertOk();

        $comanda = Comanda::sole();
        $this->assertSame(['DOMICILIO', 'Ana Pérez', null], [$comanda->contenido['canal'], $comanda->contenido['cliente'], $comanda->contenido['mesa']]);

        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Carla', 'email' => 'c@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($cliente);
        $this->getJson('/api/comandas/pendientes')->assertForbidden();
        $this->getJson("/api/comandas/{$comanda->id}/ticket")->assertForbidden();
        $this->postJson("/api/comandas/{$comanda->id}/no-impresa")->assertForbidden();
    }
}
