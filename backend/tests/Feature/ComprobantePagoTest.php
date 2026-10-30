<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Comprobante;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\PedidoAviso;
use App\Models\Producto;
use App\Models\User;
use App\Notifications\ComprobantePagoNotification;
use App\Services\CajaService;
use App\Services\PagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 5, tarea 1 (13/10/2026): comprobante de pago. RF-M06-009, 010, RN-M06-07, HU-M06-006,
 * CA-M06-006, RF-M02-L-023, RF-M02-T-013, HU-M02-019.
 */
class ComprobantePagoTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\PagoDirectoDePrueba;

    private User $mesero;
    private User $admin;
    private Producto $hamburguesa;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-3', 'estado' => 'libre']);
        app(CajaService::class)->abrir($this->admin, 0);
    }

    private function pedidoLocal(int $cantidad = 2): Pedido
    {
        Sanctum::actingAs($this->mesero);
        $id = $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => $cantidad]],
        ])->assertCreated()->json('id');

        return Pedido::findOrFail($id);
    }

    private function cobrar(Pedido $pedido, float $monto, string $metodo = 'efectivo')
    {
        Sanctum::actingAs($this->mesero);

        return $this->postJson("/api/pedidos/{$pedido->id}/cobrar", ['metodo' => $metodo, 'monto' => $monto])->assertOk();
    }

    public function test_RN_M06_07_el_pago_completo_genera_un_comprobante_impreso_con_todos_los_pagos(): void
    {
        $pedido = $this->pedidoLocal();

        $this->cobrar($pedido, 15000)->assertJsonPath('comprobante', null);
        $this->assertSame(0, Comprobante::count(), 'con el pago a medias todavía no hay comprobante');
        $this->getJson("/api/pedidos/{$pedido->id}/comprobante")->assertNotFound()
            ->assertJsonPath('message', 'Este pedido todavía no tiene comprobante: se genera cuando el pago queda completo.');

        $respuesta = $this->cobrar($pedido, 25000, 'tarjeta')
            ->assertJsonPath('comprobante.numero', 'FR-000001')
            ->assertJsonPath('comprobante.tipo', 'impreso');
        $this->assertArrayNotHasKey('detalle', $respuesta->json('comprobante'));

        $comprobante = Comprobante::sole();
        $this->assertSame(1, $comprobante->consecutivo);
        $this->assertEquals(40000, $comprobante->detalle['total']);
        $this->assertSame(['Efectivo', 'Tarjeta'], array_column($comprobante->detalle['pagos'], 'texto'));
        $this->assertSame('Mesa 3', $comprobante->detalle['pedido']['mesa']);
        $this->assertSame('2', (string) $comprobante->detalle['lineas'][0]['cantidad']);

        // Ticket de 80 mm para imprimir desde el navegador.
        $ticket = $this->get("/api/pedidos/{$pedido->id}/comprobante")->assertOk();
        $this->assertSame('application/pdf', $ticket->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $ticket->getContent());
        $this->assertStringContainsString('comprobante-FR-000001.pdf', $ticket->headers->get('Content-Disposition'));

        // El siguiente pedido toma el número siguiente.
        $otro = $this->pedidoLocal(1);
        $this->cobrar($otro, 20000)->assertJsonPath('comprobante.numero', 'FR-000002');
    }

    public function test_el_ticket_y_el_pdf_dicen_lo_que_se_pago(): void
    {
        $pedido = $this->pedidoLocal();
        $this->cobrar($pedido, 40000);
        $comprobante = Comprobante::sole();
        $datos = ['c' => $comprobante->detalle, 'comprobante' => $comprobante];

        $ticket = view('comprobantes.ticket', $datos)->render();
        // Diseño de tickets (29/10/2026): la etiqueta y el número van en renglones aparte, y la cantidad en su columna.
        $this->assertStringContainsString('COMPROBANTE DE PAGO', $ticket);
        $this->assertStringContainsString('<span>FR-000001</span>', $ticket);
        $this->assertMatchesRegularExpression('/<td class="cant">2<\/td>\s*<td>Clásica/u', $ticket);
        $this->assertStringContainsString('$40.000', $ticket);
        $this->assertStringContainsString('no es una factura electrónica.', $ticket);
        $this->assertStringContainsString('Fragua Real Burger', $ticket);
        $this->assertStringNotContainsString('NIT', $ticket, 'sin NIT configurado no se imprime');

        config(['fragua_real.negocio.nit' => '900.123.456-7']);
        $otro = $this->pedidoLocal(1);
        $this->cobrar($otro, 20000);
        $conNit = Comprobante::latest('id')->first();
        $pdf = view('comprobantes.pdf', ['c' => $conNit->detalle, 'comprobante' => $conNit])->render();
        $this->assertStringContainsString('NIT 900.123.456-7', $pdf);
        $this->assertStringContainsString('COMPROBANTE DE PAGO', $pdf);
    }

    public function test_al_anular_el_pago_se_anula_el_comprobante_y_el_nuevo_pago_saca_otro_numero(): void
    {
        $pedido = $this->pedidoLocal(1);
        $this->cobrar($pedido, 20000);
        $pago = Pago::sole();

        Sanctum::actingAs($this->admin);
        app(PagoService::class)->anular($pago, $this->admin, 'Se cobró a la mesa equivocada');

        $primero = Comprobante::sole();
        $this->assertNotNull($primero->anulado_at);
        $this->assertSame('El pedido ya no está pagado por completo.', $primero->anulado_motivo);
        $this->assertSame('FR-000001', Auditoria::where('accion', 'anular_comprobante')->sole()->detalles['comprobante']);
        $this->getJson("/api/pedidos/{$pedido->id}")->assertJsonPath('comprobante', null);

        $this->cobrar($pedido, 20000)->assertJsonPath('comprobante.numero', 'FR-000002');
        $this->assertSame(2, Comprobante::count(), 'el anulado se conserva');

        $historial = collect($this->getJson("/api/pedidos/{$pedido->id}/historial")->json('data'))->pluck('descripcion');
        $this->assertContains('Comprobante FR-000001 generado', $historial);
        $this->assertContains('Comprobante FR-000001 anulado: El pedido ya no está pagado por completo.', $historial);
        $this->assertContains('Comprobante FR-000002 generado', $historial);
    }

    public function test_si_el_pedido_pagado_cambia_el_comprobante_se_anula_hasta_completar_el_pago(): void
    {
        $pedido = $this->pedidoLocal(1);
        $this->cobrar($pedido, 20000);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/detalles", ['producto_id' => $this->hamburguesa->id, 'cantidad' => 1])->assertOk();
        $this->assertNotNull(Comprobante::sole()->anulado_at);

        $this->cobrar($pedido, 20000)->assertJsonPath('comprobante.numero', 'FR-000002');
        $this->assertEquals(40000, Comprobante::latest('id')->first()->detalle['total']);
    }

    public function test_RF_M06_010_el_domicilio_pagado_en_linea_recibe_el_pdf_por_correo_y_lo_descarga_con_su_enlace(): void
    {
        Notification::fake();
        $respuesta = $this->postJson('/api/pedidos', [
            'tipo' => 'domicilio', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Laura Pérez', 'cliente_invitado_telefono' => '3105551234', 'cliente_invitado_correo' => 'laura@correo.co',
            'direccion_entrega' => 'Calle 5 # 3-20', 'barrio' => 'Centro', 'referencia' => 'Portón negro',
            'metodo_pago_preferido' => 'en_linea', 'pasarela_preferida' => 'nequi',
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->assertCreated();
        $token = $respuesta->json('token_publico');
        $pedido = Pedido::findOrFail($respuesta->json('id'));
        $total = (float) $pedido->total;

        $this->pagoDirecto("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'nequi', 'monto' => $total])->assertSuccessful();
        $this->assertSame(0, Comprobante::count(), 'un pago en revisión todavía no da comprobante');

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/pagos/'.Pago::sole()->id.'/confirmar-en-linea')->assertOk();

        $comprobante = Comprobante::sole();
        $this->assertSame('digital', $comprobante->tipo);
        $this->assertSame('Nequi', substr($comprobante->detalle['pagos'][0]['texto'], strpos($comprobante->detalle['pagos'][0]['texto'], '(') + 1, 5));
        $this->assertSame('Calle 5 # 3-20, Centro', $comprobante->detalle['pedido']['direccion']);

        Notification::assertSentTo(new AnonymousNotifiable, ComprobantePagoNotification::class, fn ($n, $c, $destino) => array_key_exists('laura@correo.co', $destino->routes['mail']));
        $this->assertSame('enviado', PedidoAviso::where('tipo', 'comprobante')->sole()->estado);
        $correo = (new ComprobantePagoNotification($pedido->fresh()))->toMail(new AnonymousNotifiable);
        $this->assertStringContainsString('FR-000001', $correo->subject);
        $this->assertSame('comprobante-FR-000001.pdf', $correo->rawAttachments[0]['name']);
        $this->assertStringStartsWith('%PDF', $correo->rawAttachments[0]['data']);

        // El invitado lo descarga con su enlace; el seguimiento dice que existe.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/pedidos/seguimiento/{$token}")->assertJsonPath('comprobante.numero', 'FR-000001');
        $pdf = $this->get("/api/pedidos/seguimiento/{$token}/comprobante")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get('/api/pedidos/seguimiento/'.str_repeat('x', 48).'/comprobante')->assertNotFound();
    }

    public function test_el_cliente_descarga_el_suyo_y_no_el_de_otro(): void
    {
        Notification::fake();
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Pedro', 'email' => 'pedro@correo.co', 'telefono' => '3001112233', 'password' => 'secreto123', 'activo' => true]);
        $otro = User::create(['role' => 'cliente', 'nombre' => 'Otro', 'email' => 'otro@correo.co', 'password' => 'secreto123', 'activo' => true]);
        Sanctum::actingAs($cliente);
        $id = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ])->assertCreated()->json('id');
        $pedido = Pedido::findOrFail($id);
        $this->app['auth']->forgetGuards();
        $this->cobrar($pedido, 20000);
        Notification::assertSentTo(new AnonymousNotifiable, ComprobantePagoNotification::class, fn ($n, $c, $destino) => array_key_exists('pedro@correo.co', $destino->routes['mail']));

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($cliente);
        $this->get("/api/pedidos/{$id}/comprobante")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($otro);
        $this->get("/api/pedidos/{$id}/comprobante")->assertForbidden();
    }

    public function test_un_pedido_local_no_manda_correo(): void
    {
        Notification::fake();
        $pedido = $this->pedidoLocal(1);
        $this->cobrar($pedido, 20000);

        $this->assertSame('impreso', Comprobante::sole()->tipo);
        Notification::assertNothingSent();
        // El personal también puede pedir la versión PDF de un local.
        $this->get("/api/pedidos/{$pedido->id}/comprobante?formato=pdf")->assertOk();
    }
}
