<?php

namespace Tests\Feature;

use App\Models\Comanda;
use App\Models\Comprobante;
use App\Services\ComandaService;
use App\Services\ComprobanteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Diseño de tickets (29/10/2026): el comprobante y la comanda con el diseño nuevo, y el alto del papel medido
 * (App\Support\Documentos\AltoTicket) en vez de contado por renglones: una sola hoja que crece con el pedido.
 */
class DisenoTicketsTest extends TestCase
{
    use RefreshDatabase;

    private function comprobante(int $lineas): Comprobante
    {
        $comprobante = new Comprobante(['numero' => 'FR-000007']);
        $comprobante->forceFill(['detalle' => [
            'negocio' => ['nombre' => 'Fragua Real Burger', 'nit' => '', 'direccion' => 'Malecón', 'telefono' => '3227259965'],
            'numero' => 'FR-000007',
            'fecha' => now()->toIso8601String(),
            'pedido' => ['id' => 7, 'tipo' => 'domicilio', 'canal' => 'Domicilio', 'mesa' => null, 'cliente' => 'Jaiber', 'telefono' => '3150886257', 'direccion' => 'Calle 5 Sur', 'creado' => now()->toIso8601String()],
            'lineas' => array_fill(0, $lineas, ['cantidad' => 1, 'nombre' => 'Hamburguesa BBQ', 'extras' => ['Extra queso'], 'valor' => 26000]),
            'subtotal' => 26000 * $lineas, 'descuentos' => [], 'valor_domicilio' => 2000, 'total' => 26000 * $lineas + 2000,
            'pagos' => [['texto' => 'Transferencia', 'valor' => 26000 * $lineas + 2000, 'fecha' => now()->toIso8601String()]],
        ]]);

        return $comprobante;
    }

    /** @return array{0: int, 1: float} páginas y alto de la primera, leídos del PDF */
    private function medidas(string $pdf): array
    {
        preg_match('#/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+[\d.]+\s+([\d.]+)\s*\]#', $pdf, $m);

        return [preg_match_all('#/Type\s*/Page(?!s)#', $pdf), (float) ($m[1] ?? 0)];
    }

    public function test_el_ticket_del_comprobante_es_una_sola_hoja_que_crece_con_el_pedido(): void
    {
        $servicio = app(ComprobanteService::class);
        [$paginasCorto, $altoCorto] = $this->medidas($servicio->ticketPdf($this->comprobante(1), null)->output());
        [$paginasLargo, $altoLargo] = $this->medidas($servicio->ticketPdf($this->comprobante(12), null)->output());

        $this->assertSame(1, $paginasCorto);
        $this->assertSame(1, $paginasLargo);
        $this->assertGreaterThan($altoCorto + 200, $altoLargo, 'once líneas más alargan el papel');
        $this->assertLessThan(900, $altoCorto, 'sin papel en blanco de sobra');
    }

    public function test_el_pdf_digital_y_la_comanda_tambien_caben_en_una_hoja(): void
    {
        [$paginas] = $this->medidas(app(ComprobanteService::class)->contenido($this->comprobante(5), 'pdf'));
        $this->assertSame(1, $paginas);

        $comanda = new Comanda(['numero' => 1, 'tipo' => 'inicial']);
        $comanda->forceFill(['contenido' => ['pedido' => 7, 'canal' => 'En el local', 'mesa' => 'Mesa 4', 'cliente' => null, 'atiende' => 'Mario',
            'generada_at' => now()->toIso8601String(), 'lineas' => [
                ['cantidad' => 2, 'nombre' => 'Hamburguesa BBQ', 'componentes' => [], 'extras' => ['Extra queso'], 'observaciones' => 'Sin cebolla'],
                ['cantidad' => 1, 'nombre' => 'Combo Clásico', 'componentes' => ['Clásica', 'Gaseosa'], 'extras' => [], 'observaciones' => null],
            ]]]);
        $comanda->created_at = now();
        $html = view('comandas.ticket', ['comanda' => $comanda, 'c' => $comanda->contenido])->render();
        $this->assertStringContainsString('<b>#7</b>', $html);
        $this->assertStringContainsString('<span>NOTA</span> Sin cebolla', $html);

        [$paginas, $alto] = $this->medidas(app(ComandaService::class)->ticketPdf($comanda, null)->output());
        $this->assertSame(1, $paginas);
        $this->assertLessThan(600, $alto);
    }
}
