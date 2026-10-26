<?php

namespace App\Services;

use App\Models\Comprobante;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\User;
use App\Support\Documentos\RegistroImpresiones;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * M06 — Fase 5, tarea 1 (13/10/2026): comprobante de pago. RF-M06-009 (impreso para pedidos
 * locales), RF-M06-010 (digital/PDF para domicilio y recogida), RN-M06-07 ("todo pago genera un
 * comprobante"), HU-M06-006 / CA-M06-006 ("de forma inmediata"), RF-M02-L-023, RF-M02-T-013,
 * HU-M02-019 (el Mesero lo genera o lo solicita).
 *
 * Un pedido tiene comprobante cuando su pago está completo (pagos aprobados y no anulados que
 * suman el total), aunque se haya pagado en varias partes (pago mixto, RF-M06-011): un solo
 * comprobante con todos los pagos. sincronizar() lo mantiene al día y lo llaman los modelos
 * Pago y Pedido cada vez que cambia un pago o el total o el estado del pedido:
 *  - pago completo y sin comprobante vigente → se genera, con el siguiente consecutivo;
 *  - deja de estar completo, se cancela el pedido o cambian sus cifras → el vigente se anula
 *    (nunca se borra ni se reescribe) y, si vuelve a estar pagado, sale uno nuevo.
 *
 * Formato simple y propio, que no depende de la facturación electrónica futura (M06 §16): el
 * documento lo dice. El PDF y el ticket se dibujan con dompdf (barryvdh/laravel-dompdf) desde la
 * copia guardada en `detalle`.
 */
class ComprobanteService
{
    public const ANCHO_TICKET_PUNTOS = 226.77; // 80 mm

    // Solo las letras usadas de la fuente (el PDF va por correo) y nada remoto al dibujar.
    private const OPCIONES = RegistroImpresiones::OPCIONES_PDF;

    public function sincronizar(?Pedido $pedido): ?Comprobante
    {
        $pedido = $pedido?->fresh();
        if (! $pedido) {
            return null;
        }

        $pagos = $this->pagosVigentes($pedido);
        $total = round((float) $pedido->total, 2);
        $completo = $pedido->estado !== 'cancelado' && $total > 0 && round((float) $pagos->sum('monto'), 2) >= $total;
        $vigente = $pedido->comprobante()->first();

        if ($vigente) {
            if ($completo && ($vigente->detalle['huella'] ?? null) === $this->huella($pedido, $pagos)) {
                return $vigente;
            }
            $this->anular($vigente, match (true) {
                $pedido->estado === 'cancelado' => 'Se canceló el pedido.',
                ! $completo => 'El pedido ya no está pagado por completo.',
                default => 'El pedido o sus pagos cambiaron después de generado.',
            });
        }

        return $completo ? $this->generar($pedido, $pagos) : null;
    }

    public function anular(Comprobante $comprobante, string $motivo): void
    {
        $comprobante->forceFill(['anulado_at' => now(), 'anulado_motivo' => $motivo])->save();

        app(AuditoriaService::class)->registrar('anular_comprobante', 'Pedido', $comprobante->pedido_id, [
            'comprobante' => $comprobante->numero, 'motivo' => $motivo,
        ]);
    }

    /**
     * El PDF (digital, tamaño A5) o el ticket de 80 mm de un comprobante, tal cual (lo que ve el
     * cliente en Mis pedidos o con su enlace, y lo que va por correo: nunca dice REIMPRESIÓN).
     */
    public function documento(Comprobante $comprobante, string $formato): Response
    {
        return $this->pdf($comprobante, $formato)->stream($this->nombreArchivo($comprobante));
    }

    /**
     * Decisión del usuario (13/10/2026): el personal puede reimprimir un comprobante cuando quiera,
     * pero la reimpresión no se confunde con el original. Cada impresión del personal suma una vez
     * y queda en la auditoría (quién, cuál, qué vez, en qué formato); desde la segunda el documento
     * dice "REIMPRESIÓN" con la fecha y la hora. Un anulado sale además con su sello ANULADO.
     */
    public function imprimir(Comprobante $comprobante, string $formato, User $actor): Response
    {
        $marca = RegistroImpresiones::registrar($comprobante, 'imprimir_comprobante', 'Pedido', $comprobante->pedido_id, [
            'comprobante' => $comprobante->numero,
            'formato' => $formato,
            'anulado' => $comprobante->anulado_at !== null,
        ], $actor);

        return $this->pdf($comprobante, $formato, $marca)->stream($this->nombreArchivo($comprobante));
    }

    /** El PDF ya dibujado, en bytes (para adjuntarlo a un correo). */
    public function contenido(Comprobante $comprobante, string $formato = 'pdf'): string
    {
        return $this->pdf($comprobante, $formato)->output();
    }

    public function nombreArchivo(Comprobante $comprobante): string
    {
        return "comprobante-{$comprobante->numero}.pdf";
    }

    private function pdf(Comprobante $comprobante, string $formato, ?array $reimpresion = null): DomPdf
    {
        if ($formato === 'ticket') {
            return $this->ticketPdf($comprobante, $reimpresion);
        }
        $datos = ['c' => $comprobante->detalle, 'comprobante' => $comprobante, 'reimpresion' => $reimpresion,
            'logo' => app(NegocioService::class)->logoParaPdf()];

        return Pdf::loadView('comprobantes.pdf', $datos)->setOption(self::OPCIONES)->setPaper('a5', 'portrait');
    }

    /**
     * El ticket de 80 mm. M12 (24/10/2026): con el formato de Impresoras (logo, letra, mensaje al pie y
     * copias); `$formatoTicket` sirve para la vista previa sin guardar.
     */
    public function ticketPdf(Comprobante $comprobante, ?array $reimpresion, ?array $formatoTicket = null): DomPdf
    {
        $f = $formatoTicket ?? app(ImpresionService::class)->formato();
        $logo = $f['ticket_logo'] ? app(NegocioService::class)->logoParaPdf() : null;
        $grande = $f['ticket_letra'] === 'grande';
        $d = $comprobante->detalle;
        // dompdf no ajusta el alto solo: se calcula con lo que lleva el ticket.
        // Un renglón por línea (dos si el nombre es largo o lleva extras), pagos, descuentos y lo fijo.
        $renglones = collect($d['lineas'] ?? [])->sum(fn ($l) => 1 + (mb_strlen($l['nombre']) > ($grande ? 19 : 24) ? 1 : 0) + (empty($l['extras']) ? 0 : 1))
            + count($d['pagos'] ?? []) + count($d['descuentos'] ?? []) + ($comprobante->anulado_at ? 3 : 0) + ($reimpresion ? 2 : 0) + 17
            + ($logo ? 5 : 0) + (int) ceil(mb_strlen((string) $f['ticket_mensaje_pie']) / ($grande ? 30 : 38)) - 1
            + ((int) $f['copias_comprobante'] > 1 ? 1 : 0);
        $datos = ['c' => $d, 'comprobante' => $comprobante, 'reimpresion' => $reimpresion, 'logo' => $logo, 'f' => $f];

        return Pdf::loadView('comprobantes.ticket', $datos)->setOption(self::OPCIONES)
            ->setPaper([0, 0, self::ANCHO_TICKET_PUNTOS, 30 + $renglones * ($grande ? 12.6 : 11.5)]);
    }

    // ───────────── internos ─────────────

    /** @return Collection<int, Pago> */
    private function pagosVigentes(Pedido $pedido): Collection
    {
        return $pedido->pagos()->where('anulado', false)->where('estado', 'aprobado')->orderBy('id')->get();
    }

    // Lo que hace que un comprobante deje de valer: el total, las líneas y los pagos.
    private function huella(Pedido $pedido, Collection $pagos): string
    {
        $lineas = $pedido->detalles()->orderBy('id')->get(['id', 'cantidad', 'precio_unitario_congelado', 'descuento'])
            ->map(fn ($d) => [$d->id, (int) $d->cantidad, (float) $d->precio_unitario_congelado, (float) $d->descuento]);

        return hash('sha256', json_encode([
            (float) $pedido->total, (float) $pedido->descuento, (float) ($pedido->valor_domicilio ?? 0),
            $lineas->all(), $pagos->map(fn (Pago $p) => [$p->id, (float) $p->monto])->all(),
        ]));
    }

    private function generar(Pedido $pedido, Collection $pagos): Comprobante
    {
        $detalle = $this->armarDetalle($pedido, $pagos);

        // Consecutivo sin huecos: el siguiente al mayor. Si dos cajas generan a la vez, el índice
        // único lo detecta y se toma el siguiente (cada intento en su propio punto de guardado).
        for ($intento = 1; ; $intento++) {
            try {
                $comprobante = DB::transaction(function () use ($pedido, $detalle) {
                    $consecutivo = (int) Comprobante::max('consecutivo') + 1;
                    $numero = 'FR-'.str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT);

                    return Comprobante::create([
                        'pedido_id' => $pedido->id,
                        'consecutivo' => $consecutivo,
                        'numero' => $numero,
                        'tipo' => $pedido->esLocal() ? 'impreso' : 'digital',
                        'generado_at' => now(),
                        'detalle' => ['numero' => $numero] + $detalle,
                    ]);
                });

                // RF-M06-010, M06 §3 ("el Cliente recibe el comprobante digital"): el de domicilio
                // y recogida también le llega por correo, si hay a dónde.
                if ($comprobante->tipo === 'digital') {
                    app(AvisoPedidoService::class)->programar($pedido, 'comprobante');
                }

                return $comprobante;
            } catch (UniqueConstraintViolationException $e) {
                if ($intento >= 3) {
                    throw $e;
                }
            }
        }
    }

    private function armarDetalle(Pedido $pedido, Collection $pagos): array
    {
        $pedido->loadMissing(['detalles.producto', 'detalles.variante', 'detalles.combo', 'detalles.extras', 'mesa', 'cliente']);
        // El logo no va en la copia guardada del comprobante: se pone al generar el PDF.
        $negocio = array_diff_key(app(NegocioService::class)->datos(), ['logo' => true]);
        $metodos = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'en_linea' => 'En línea'];
        $pasarelas = ['nequi' => 'Nequi', 'bancolombia' => 'Bancolombia', 'breb' => 'Bre-B'];

        $descuentos = [];
        if ($pedido->descuento_manual) {
            $descuentos[] = ['texto' => 'Descuento'.($pedido->descuento_manual_motivo ? ": {$pedido->descuento_manual_motivo}" : ''), 'valor' => (float) $pedido->descuento];
        } elseif ((float) $pedido->descuento > 0) {
            foreach ($pedido->promociones_aplicadas ?? [] as $promo) {
                $descuentos[] = ['texto' => $promo['nombre'] ?? 'Promoción', 'valor' => (float) ($promo['monto'] ?? 0)];
            }
            if (! $descuentos) {
                $descuentos[] = ['texto' => 'Promociones', 'valor' => (float) $pedido->descuento];
            }
        }

        return [
            'huella' => $this->huella($pedido, $pagos),
            'negocio' => $negocio,
            'fecha' => now()->toIso8601String(),
            'pedido' => [
                'id' => $pedido->id,
                'tipo' => $pedido->tipo,
                'canal' => ['local' => 'En el local', 'domicilio' => 'Domicilio', 'recogida' => 'Para recoger'][$pedido->tipo],
                'mesa' => $pedido->mesa?->nombre,
                'cliente' => $pedido->cliente?->nombre ?? $pedido->cliente_invitado_nombre,
                'telefono' => $pedido->esLocal() ? null : $pedido->telefono_contacto,
                'direccion' => $pedido->esDomicilio()
                    ? trim($pedido->direccion_entrega.($pedido->barrio ? ", {$pedido->barrio}" : ''))
                    : null,
                'creado' => $pedido->created_at?->toIso8601String(),
            ],
            'lineas' => $pedido->detalles->sortBy('id')->values()->map(fn (PedidoDetalle $d) => [
                'cantidad' => (int) $d->cantidad,
                'nombre' => trim(($d->producto?->nombre ?? $d->combo?->nombre ?? 'Producto').($d->variante ? " ({$d->variante->nombre})" : '')),
                'extras' => $d->extras->pluck('nombre')->all(),
                'valor' => round($d->subtotalLinea(), 2),
            ])->all(),
            'subtotal' => (float) $pedido->subtotal,
            'descuentos' => $descuentos,
            'descuento' => (float) $pedido->descuento,
            'valor_domicilio' => (float) ($pedido->valor_domicilio ?? 0),
            'total' => (float) $pedido->total,
            'pagos' => $pagos->map(fn (Pago $p) => [
                'texto' => $p->esAbonoDeDeposito()
                    ? 'Depósito de la reserva'
                    : ($metodos[$p->metodo] ?? $p->metodo).($p->pasarela ? ' ('.($pasarelas[$p->pasarela] ?? $p->pasarela).')' : ''),
                'valor' => (float) $p->monto,
                'fecha' => $p->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
