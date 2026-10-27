<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Comanda;
use App\Models\EquipoImpresion;
use App\Models\Mesa;
use App\Models\Notificacion;
use App\Models\Producto;
use App\Models\User;
use App\Services\CajaService;
use App\Services\ImpresionService;
use App\Services\NotificacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M12 — Impresoras (24/10/2026, RF-M12-001 a 014): equipos que imprimen (registrar aquí o vincular con
 * código), señal, impresión de prueba con su resultado, formato del ticket con vista previa, estado de
 * impresión y aviso de equipo desconectado con la caja abierta.
 */
class ImpresorasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('Clave12345'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('Clave12345'), 'activo' => true]);
    }

    private function conEquipo(string $clave): array
    {
        return [ImpresionService::CABECERA_EQUIPO => $clave];
    }

    public function test_registrar_este_equipo_y_su_senal(): void
    {
        Sanctum::actingAs($this->admin);
        $respuesta = $this->postJson('/api/impresion/equipos', ['nombre' => 'Caja', 'aqui' => true])->assertCreated();
        $clave = $respuesta->json('clave');
        $this->assertNotEmpty($clave);
        $respuesta->assertJsonMissingPath('equipo.clave')->assertJsonPath('equipo.en_linea', true);

        $this->postJson('/api/impresion/equipos/senal', [], $this->conEquipo($clave))->assertOk()->assertJsonPath('nombre', 'Caja')->assertJsonPath('al_confirmar', true);
        $this->postJson('/api/impresion/equipos/senal', [], $this->conEquipo('otra-clave'))->assertNotFound();
        $this->getJson('/api/impresion/equipos')->assertOk()->assertJsonPath('0.nombre', 'Caja')->assertJsonMissingPath('0.clave');
        $this->postJson('/api/impresion/equipos', ['nombre' => 'caja', 'aqui' => true])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->assertTrue(Auditoria::where('accion', 'registrar_equipo_impresion')->exists());
    }

    public function test_vincular_la_tablet_de_la_cocina_con_un_codigo(): void
    {
        Sanctum::actingAs($this->admin);
        $codigo = $this->postJson('/api/impresion/equipos', ['nombre' => 'Cocina', 'aqui' => false])->assertCreated()->json('codigo');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $codigo);
        $this->getJson('/api/impresion/equipos')->assertJsonPath('0.codigo', $codigo)->assertJsonPath('0.vinculado', false);

        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/impresion/equipos/vincular', ['codigo' => '000000'])->assertStatus(422);
        $clave = $this->postJson('/api/impresion/equipos/vincular', ['codigo' => $codigo])->assertOk()->assertJsonPath('equipo.nombre', 'Cocina')->json('clave');
        $this->postJson('/api/impresion/equipos/vincular', ['codigo' => $codigo])->assertStatus(422); // ya se usó

        $this->postJson('/api/impresion/equipos/senal', [], $this->conEquipo($clave))->assertOk();
        $equipo = EquipoImpresion::sole();
        $this->assertSame($this->mesero->id, $equipo->ultima_senal_por);
        $this->assertTrue($equipo->vinculado);

        // El Mesero no administra Impresoras.
        $this->getJson('/api/impresion/equipos')->assertForbidden();
        $this->putJson("/api/impresion/equipos/{$equipo->id}", ['automatico' => true])->assertForbidden();
    }

    public function test_el_administrador_cambia_los_ajustes_del_equipo_y_le_llegan_con_la_senal(): void
    {
        Sanctum::actingAs($this->admin);
        $creado = $this->postJson('/api/impresion/equipos', ['nombre' => 'Cocina', 'aqui' => true])->json();
        $id = $creado['equipo']['id'];

        $this->putJson("/api/impresion/equipos/{$id}", ['automatico' => true, 'imprime_comprobantes' => false])->assertOk()->assertJsonPath('automatico', true);
        $this->postJson('/api/impresion/equipos/senal', [], $this->conEquipo($creado['clave']))
            ->assertJsonPath('automatico', true)->assertJsonPath('imprime_comprobantes', false);
        $this->assertTrue(Auditoria::where('accion', 'editar_equipo_impresion')->exists());

        $this->deleteJson("/api/impresion/equipos/{$id}")->assertOk();
        $this->postJson('/api/impresion/equipos/senal', [], $this->conEquipo($creado['clave']))->assertNotFound();
    }

    public function test_impresion_de_prueba_y_su_resultado(): void
    {
        Sanctum::actingAs($this->admin);
        $clave = $this->postJson('/api/impresion/equipos', ['nombre' => 'Caja', 'aqui' => true])->json('clave');

        Sanctum::actingAs($this->mesero);
        $pdf = $this->get('/api/impresion/prueba', $this->conEquipo($clave))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->postJson('/api/impresion/equipos/prueba', ['resultado' => 'raro'], $this->conEquipo($clave))->assertStatus(422);
        $this->postJson('/api/impresion/equipos/prueba', ['resultado' => 'cortado'], $this->conEquipo($clave))
            ->assertOk()->assertJsonPath('ultima_prueba_resultado', 'cortado');

        $this->assertSame('Salió cortado', Auditoria::where('accion', 'prueba_impresion')->sole()->detalles['resultado']);
    }

    public function test_formato_del_ticket_con_vista_previa(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/impresion/formato')->assertOk()->assertJsonPath('copias_comanda', 1)->assertJsonPath('ticket_mensaje_pie', '¡Gracias por tu visita!');

        $this->putJson('/api/impresion/formato', ['copias_comanda' => 5])->assertStatus(422);
        $this->putJson('/api/impresion/formato', [
            'ticket_letra' => 'grande', 'ticket_mensaje_pie' => 'WiFi: fragua2026', 'comanda_letra_grande' => true, 'copias_comanda' => 2,
        ])->assertOk()->assertJsonPath('ticket_letra', 'grande')->assertJsonPath('copias_comanda', 2);
        $this->assertSame(2, app(ImpresionService::class)->formato()['copias_comanda']);
        $this->assertTrue(Auditoria::where('accion', 'editar_formato_impresion')->exists());

        foreach (['comprobante', 'comanda'] as $tipo) {
            $r = $this->post('/api/impresion/vista-previa', ['tipo' => $tipo, 'ticket_logo' => false, 'comanda_mostrar_mesero' => true], ['Accept' => 'application/json'])->assertOk();
            $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        }
        $this->assertSame(2, app(ImpresionService::class)->formato()['copias_comanda'], 'La vista previa no guarda.');
    }

    public function test_estado_historial_con_equipo_y_descartar_comanda(): void
    {
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        Sanctum::actingAs($this->mesero);
        // RF-M02-L-036: el pedido del Mesero se confirma al crearlo y su comanda queda lista.
        $pedido = $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $mesa->id, 'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]]])
            ->assertJsonPath('estado', 'confirmado')->json('id');
        $comanda = Comanda::where('pedido_id', $pedido)->sole();

        Sanctum::actingAs($this->admin);
        $clave = $this->postJson('/api/impresion/equipos', ['nombre' => 'Cocina', 'aqui' => true])->json('clave');
        $this->getJson('/api/impresion/estado')->assertOk()->assertJsonPath('pendientes.0.id', $comanda->id);

        // Imprimir desde el equipo deja su nombre en el historial.
        $this->get("/api/comandas/{$comanda->id}/ticket", $this->conEquipo($clave))->assertOk();
        $this->getJson('/api/impresion/estado')->assertJsonPath('pendientes', [])
            ->assertJsonPath('historial.0.accion', 'imprimir_comanda')->assertJsonPath('historial.0.detalles.equipo', 'Cocina');

        // Una que no salió se puede descartar con motivo.
        $this->postJson("/api/comandas/{$comanda->id}/no-impresa")->assertOk();
        $this->postJson("/api/comandas/{$comanda->id}/descartar", ['motivo' => ''])->assertStatus(422);
        $this->postJson("/api/comandas/{$comanda->id}/descartar", ['motivo' => 'La cocina ya lo tiene'])->assertOk()->assertJsonPath('estado_impresion', 'descartada');
        $this->postJson("/api/comandas/{$comanda->id}/descartar", ['motivo' => 'Otra vez'])->assertStatus(422);
    }

    public function test_avisa_si_el_equipo_de_la_cocina_se_desconecta_con_la_caja_abierta(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/impresion/equipos', ['nombre' => 'Cocina', 'aqui' => true])->assertCreated();
        $equipo = EquipoImpresion::sole();
        $equipo->forceFill(['ultima_senal_at' => now()->subMinutes(20)])->save();

        // Sin caja abierta no avisa (el local puede estar cerrado).
        $this->assertSame(0, app(ImpresionService::class)->revisarDesconectados());

        app(CajaService::class)->abrir($this->admin, 0);
        app(NotificacionService::class)->revisar();
        app(NotificacionService::class)->revisar(); // no se repite
        $avisos = Notificacion::where('tipo', 'equipo_desconectado')->get();
        $this->assertCount(1, $avisos);
        $this->assertSame($this->admin->id, $avisos->first()->user_id);
        $this->assertFalse(Notificacion::where('tipo', 'equipo_desconectado')->where('user_id', $this->mesero->id)->exists());
    }
}
