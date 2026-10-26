<?php

namespace App\Services;

use App\Models\Comanda;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\PedidoModificacion;
use App\Models\User;
use App\Support\Documentos\RegistroImpresiones;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * M02/M07 — Fase 6, tarea 2 (13/10/2026, decisión D5: comandas impresas desde el navegador del
 * Mesero en V1). RF-M02-L-011 y RF-M07-020 (comanda al confirmar), RN-PED-06, RN-PED-09 y
 * RF-M07-022 (comanda de actualización), RF-M02-T-004 a 007, RN-PED-17 a 19, RNF-M02-001, 004, 005,
 * 011, 014, 016, HU-M02-016, 017.
 *
 * Cómo funciona:
 *  - Confirmar un pedido genera su comanda inicial (alConfirmar). Si algo falla al generarla, el
 *    pedido queda confirmado igual (RN-PED-17, RNF-M02-005) y la comanda se crea en la siguiente
 *    consulta de pendientes (asegurarIniciales): ningún pedido confirmado se queda sin comanda.
 *  - Un cambio en un pedido ya confirmado (RN-PED-09) va en una comanda de actualización con solo
 *    lo que cambió. Si la cocina todavía no recibió la comanda anterior (no se ha impreso), el cambio
 *    se suma a esa en vez de sacar otra hoja.
 *  - Cancelar un pedido que la cocina ya tenía saca una comanda de cancelación; si la cocina no lo
 *    había recibido, la comanda pendiente se descarta.
 *  - Cada comanda tiene una clave única (idempotencia, RF-M02-T-007): la misma confirmación o el mismo
 *    cambio nunca generan dos comandas.
 *  - Impresión (D5): el Mesero la abre en el navegador (ticket de 80 mm) y la imprime. El estado de
 *    impresión es independiente del pedido (RN-PED-19): pendiente → impresa; si no salió, el Mesero
 *    lo dice (fallida) y vuelve a la lista de pendientes. Las pendientes se ven en todas las pantallas
 *    del Mesero (RNF-M02-011) y la pantalla puede abrirlas sola (RF-M02-T-006).
 *  - Regla de reimpresión del usuario: una comanda que ya salió se puede volver a imprimir, marcada
 *    "REIMPRESIÓN · copia n" y auditada; una que no salió (fallida) se imprime otra vez como original.
 */
class ComandaService
{
    public const ANCHO_TICKET_PUNTOS = 226.77; // 80 mm

    private const CANALES = ['local' => 'EN EL LOCAL', 'domicilio' => 'DOMICILIO', 'recogida' => 'PARA RECOGER'];

    /** RN-PED-06: comanda inicial al confirmar. Nunca lanza: un fallo no deshace la confirmación. */
    public function alConfirmar(Pedido $pedido): ?Comanda
    {
        try {
            return $this->inicial($pedido);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** La comanda inicial del pedido (la crea si no existe; si existe, la devuelve tal cual). */
    public function inicial(Pedido $pedido): Comanda
    {
        $existente = $pedido->comandas()->where('tipo', 'inicial')->first();
        if ($existente) {
            return $existente;
        }

        return $this->crear($pedido, 'inicial', "pedido:{$pedido->id}:inicial", [
            'lineas' => $this->lineasActuales($pedido),
        ], $this->ultimaModificacion($pedido));
    }

    /**
     * RN-PED-09, RF-M07-022: un cambio hecho con el pedido confirmado. Lo llama PedidoService después
     * de guardar el cambio, dentro de la misma transacción; un fallo aquí se reporta y no deshace el
     * cambio (la cocina lo verá en la siguiente comanda o al reimprimir).
     */
    public function alModificar(Pedido $pedido, PedidoModificacion $modificacion): ?Comanda
    {
        try {
            return DB::transaction(fn () => $this->actualizar($pedido, $modificacion));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Cancelar un pedido: la cocina se entera si ya lo tenía; si no, lo pendiente se descarta. */
    public function alCancelar(Pedido $pedido, ?string $motivo): ?Comanda
    {
        try {
            return DB::transaction(function () use ($pedido, $motivo) {
                $comandas = $pedido->comandas()->lockForUpdate()->get();
                $recibidas = $comandas->filter(fn (Comanda $c) => $c->impresiones > 0);

                foreach ($comandas->filter(fn (Comanda $c) => $c->porImprimir()) as $c) {
                    $c->forceFill(['estado_impresion' => 'descartada'])->save();
                }
                if ($recibidas->isEmpty()) {
                    return null;
                }

                return $this->crear($pedido, 'cancelacion', "pedido:{$pedido->id}:cancelacion", [
                    'lineas' => $this->lineasActuales($pedido),
                    'motivo' => $motivo,
                ], $this->ultimaModificacion($pedido));
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * RNF-M02-011: las comandas por imprimir (pendientes o que no salieron), de la más vieja a la más
     * nueva. Antes se asegura de que todo pedido confirmado tenga su comanda inicial.
     *
     * @return Collection<int, Comanda>
     */
    public function porImprimir(): Collection
    {
        $this->asegurarIniciales();

        return Comanda::with(['pedido.mesa', 'pedido.cliente:id,nombre'])
            ->whereIn('estado_impresion', Comanda::POR_IMPRIMIR)
            ->orderBy('created_at')->orderBy('id')
            ->limit(100)
            ->get();
    }

    /**
     * El ticket de 80 mm de una comanda, para imprimir desde el navegador. La primera impresión que
     * sale es el original; desde la segunda dice "REIMPRESIÓN · copia n". Cada una queda en la
     * auditoría (regla de reimpresión del 13/10/2026).
     */
    public function imprimir(Comanda $comanda, User $actor): Response
    {
        $comanda = DB::transaction(function () use ($comanda, $actor) {
            $fila = Comanda::whereKey($comanda->id)->lockForUpdate()->firstOrFail();
            $fila->intentos++;
            if ($fila->porImprimir()) {
                $fila->estado_impresion = 'impresa';
                $fila->impresa_at = now();
                $fila->impresa_por = $actor->id;
                $fila->ultimo_error = null;
            }
            $fila->save();

            return $fila;
        });

        $marca = RegistroImpresiones::registrar($comanda, 'imprimir_comanda', 'Pedido', $comanda->pedido_id, [
            'comanda' => $comanda->numero,
            'tipo' => $comanda->tipo,
        ], $actor);

        return $this->ticketPdf($comanda, $marca)->stream("comanda-{$comanda->pedido_id}-{$comanda->numero}.pdf");
    }

    /**
     * HU-M02-016, RF-M02-T-004/006: la comanda no salió (impresora apagada, sin papel, se cerró el
     * cuadro de impresión). Vuelve a la lista de pendientes y la próxima vez sale como original. El
     * pedido no cambia (RN-PED-17).
     */
    public function marcarNoImpresa(Comanda $comanda, User $actor, ?string $motivo): Comanda
    {
        return DB::transaction(function () use ($comanda, $actor, $motivo) {
            $fila = Comanda::whereKey($comanda->id)->lockForUpdate()->firstOrFail();
            if ($fila->estado_impresion !== 'impresa') {
                throw new RuntimeException('Esta comanda no figura como impresa.');
            }
            $fila->forceFill([
                'estado_impresion' => 'fallida',
                'impresiones' => max(0, $fila->impresiones - 1),
                'ultimo_error' => $motivo ? mb_substr($motivo, 0, 255) : 'No salió de la impresora.',
            ])->save();

            app(AuditoriaService::class)->registrar('comanda_no_impresa', 'Pedido', $fila->pedido_id, [
                'comanda' => $fila->numero, 'tipo' => $fila->tipo, 'motivo' => $fila->ultimo_error,
            ], $actor);
            app(NotificacionService::class)->comandaNoSalio($fila, $actor); // M11

            return $fila;
        });
    }

    /**
     * M12 (24/10/2026): el Administrador descarta una comanda que ya no hace falta imprimir (la cocina
     * ya lo tiene a mano, o era de prueba). Sale de las pendientes; el pedido no cambia.
     */
    public function descartar(Comanda $comanda, User $actor, string $motivo): Comanda
    {
        return DB::transaction(function () use ($comanda, $actor, $motivo) {
            $fila = Comanda::whereKey($comanda->id)->lockForUpdate()->firstOrFail();
            if (! $fila->porImprimir()) {
                throw new RuntimeException('Esta comanda ya no está por imprimir.');
            }
            $fila->forceFill(['estado_impresion' => 'descartada', 'ultimo_error' => mb_substr($motivo, 0, 255)])->save();
            app(AuditoriaService::class)->registrar('descartar_comanda', 'Pedido', $fila->pedido_id, [
                'comanda' => $fila->numero, 'tipo' => $fila->tipo, 'motivo' => $fila->ultimo_error,
            ], $actor);

            return $fila;
        });
    }

    /** Lo que se muestra de una comanda en las pantallas (sin la clave interna). */
    public static function resumen(Comanda $c): array
    {
        return [
            'id' => $c->id,
            'pedido_id' => $c->pedido_id,
            'numero' => $c->numero,
            'tipo' => $c->tipo,
            'estado_impresion' => $c->estado_impresion,
            'intentos' => $c->intentos,
            'impresiones' => $c->impresiones,
            'impresa_at' => $c->impresa_at?->toIso8601String(),
            'ultimo_error' => $c->ultimo_error,
            'created_at' => $c->created_at?->toIso8601String(),
            'lineas' => count($c->contenido['lineas'] ?? []),
        ];
    }

    // ───────────── internos ─────────────

    private function actualizar(Pedido $pedido, PedidoModificacion $modificacion): Comanda
    {
        $comandas = $pedido->comandas()->lockForUpdate()->get();
        $inicial = $comandas->firstWhere('tipo', 'inicial');

        // La cocina todavía no recibió nada: la comanda inicial se rehace con el pedido como quedó.
        if (! $inicial) {
            return $this->inicial($pedido);
        }
        if ($inicial->porImprimir() && $inicial->impresiones === 0) {
            $inicial->forceFill([
                'contenido' => ['lineas' => $this->lineasActuales($pedido)] + $inicial->contenido,
                'modificacion_hasta' => $modificacion->id,
            ])->save();

            return $inicial;
        }

        $cambio = $this->lineaDeCambio($modificacion);

        // La última actualización todavía no salió: el cambio se suma a esa hoja.
        $ultima = $comandas->where('tipo', 'actualizacion')->sortByDesc('numero')->first();
        if ($ultima && $ultima->porImprimir() && $ultima->impresiones === 0) {
            $contenido = $ultima->contenido;
            $contenido['lineas'][] = $cambio;
            $ultima->forceFill(['contenido' => $contenido, 'modificacion_hasta' => $modificacion->id])->save();

            return $ultima;
        }

        return $this->crear($pedido, 'actualizacion', "pedido:{$pedido->id}:modificacion:{$modificacion->id}", [
            'lineas' => [$cambio],
        ], $modificacion->id);
    }

    private function crear(Pedido $pedido, string $tipo, string $clave, array $contenido, ?int $modificacionHasta): Comanda
    {
        // Idempotencia: si ya existe con esa clave, es la misma comanda.
        if ($existente = Comanda::where('clave', $clave)->first()) {
            return $existente;
        }

        try {
            return DB::transaction(function () use ($pedido, $tipo, $clave, $contenido, $modificacionHasta) {
                $numero = (int) Comanda::where('pedido_id', $pedido->id)->max('numero') + 1;

                return Comanda::create([
                    'pedido_id' => $pedido->id,
                    'numero' => $numero,
                    'tipo' => $tipo,
                    'clave' => $clave,
                    'contenido' => $this->encabezado($pedido) + $contenido,
                    'estado_impresion' => 'pendiente',
                    'modificacion_hasta' => $modificacionHasta,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Otra petición la creó al mismo tiempo (doble clic, dos pantallas): es la misma comanda.
            return Comanda::where('clave', $clave)->first() ?? throw $e;
        }
    }

    /** Pedidos confirmados sin comanda inicial (si algo falló al confirmar), de las últimas 24 h. */
    private function asegurarIniciales(): void
    {
        Pedido::whereIn('estado', ['confirmado', 'preparando'])
            ->where('confirmado_at', '>=', now()->subDay())
            ->whereDoesntHave('comandas', fn ($q) => $q->where('tipo', 'inicial'))
            ->limit(50)
            ->get()
            ->each(fn (Pedido $p) => $this->alConfirmar($p));
    }

    private function ultimaModificacion(Pedido $pedido): ?int
    {
        $id = $pedido->modificaciones()->max('id');

        return $id ? (int) $id : null;
    }

    private function encabezado(Pedido $pedido): array
    {
        $pedido->loadMissing(['mesa', 'cliente']);

        return [
            'pedido' => $pedido->id,
            'tipo_pedido' => $pedido->tipo,
            'canal' => self::CANALES[$pedido->tipo] ?? strtoupper($pedido->tipo),
            'origen' => $pedido->origen,
            'mesa' => $pedido->mesa?->nombre,
            // Mejora (26/10/2026): con cuentas separadas en la mesa, la comanda dice a cuál va.
            'cuenta' => $pedido->esLocal() && $pedido->mesa && ($pedido->cuenta > 1 || count($pedido->mesa->cuentasDeLaVisita()) > 1)
                ? (int) ($pedido->cuenta ?? 1)
                : null,
            'cliente' => $pedido->esLocal() ? null : ($pedido->cliente?->nombre ?? $pedido->cliente_invitado_nombre),
            // M12 (24/10/2026): quién confirmó el pedido, por si el formato pide mostrarlo.
            'atiende' => $pedido->confirmadoPor?->nombre,
            'generada_at' => now()->toIso8601String(),
        ];
    }

    private function lineasActuales(Pedido $pedido): array
    {
        return $pedido->detalles()->with(['producto', 'variante', 'combo.productos', 'extras'])->orderBy('id')->get()
            ->map(fn (PedidoDetalle $d) => $this->linea($d))
            ->all();
    }

    private function linea(PedidoDetalle $d): array
    {
        $nombreCombo = $d->combo?->nombre ?? "#{$d->combo_id}";
        $nombre = $d->combo_id
            ? (str_starts_with(mb_strtolower($nombreCombo), 'combo') ? $nombreCombo : "Combo {$nombreCombo}")
            : ($d->producto?->nombre ?? "Producto #{$d->producto_id}");
        if ($d->variante) {
            $nombre .= " ({$d->variante->nombre})";
        }

        return [
            'accion' => null,
            'cantidad' => (int) $d->cantidad,
            'nombre' => $nombre,
            // La cocina necesita saber qué lleva el combo.
            'componentes' => $d->combo
                ? $d->combo->productos->map(fn ($p) => ((int) ($p->pivot->cantidad ?? 1)).' '.$p->nombre)->values()->all()
                : [],
            'extras' => $d->extras->pluck('nombre')->values()->all(),
            'observaciones' => $d->observaciones ?: null,
            'preparado' => (bool) $d->preparado,
        ];
    }

    // Una línea de la comanda de actualización, a partir del historial del pedido.
    private function lineaDeCambio(PedidoModificacion $m): array
    {
        $antes = $m->antes ?? [];
        $despues = $m->despues ?? [];
        $base = $despues ?: $antes;

        return [
            'accion' => match ($m->accion) {
                'agregar_linea' => 'agregar',
                'retirar_linea' => 'quitar',
                default => 'cambiar',
            },
            'cantidad' => (int) ($base['cantidad'] ?? 0),
            'cantidad_antes' => $m->accion === 'cambiar_linea' ? (int) ($antes['cantidad'] ?? 0) : null,
            'nombre' => $base['nombre'] ?? 'Producto',
            'componentes' => [],
            'extras' => $base['extras'] ?? [],
            'observaciones' => $base['observaciones'] ?? null,
            'preparado' => false,
        ];
    }

    /**
     * El ticket de 80 mm de una comanda. M12 (24/10/2026): con el formato de Impresoras (letra grande,
     * hora, quién atiende y copias); `$formato` sirve para la vista previa sin guardar.
     */
    public function ticketPdf(Comanda $comanda, ?array $reimpresion, ?array $formato = null)
    {
        $f = $formato ?? app(ImpresionService::class)->formato();
        $c = $comanda->contenido;
        $c['atiende'] ??= $comanda->pedido?->confirmadoPor?->nombre;
        $lineas = $c['lineas'] ?? [];
        $grande = (bool) $f['comanda_letra_grande'];
        // dompdf no ajusta el alto solo: un renglón por línea, más extras, componentes y notas.
        $renglones = collect($lineas)->sum(fn ($l) => 1 + (mb_strlen($l['nombre']) > ($grande ? 15 : 20) ? 1 : 0)
            + (empty($l['extras']) ? 0 : 1) + count($l['componentes'] ?? []) + (empty($l['observaciones']) ? 0 : 2))
            + ($reimpresion ? 2 : 0) + (empty($c['motivo']) ? 0 : 2) + 9
            + ($f['comanda_mostrar_mesero'] && ! empty($c['atiende']) ? 1 : 0) - ($f['comanda_mostrar_hora'] ? 0 : 1)
            + ((int) $f['copias_comanda'] > 1 ? 1 : 0);

        return Pdf::loadView('comandas.ticket', ['comanda' => $comanda, 'c' => $c, 'reimpresion' => $reimpresion, 'f' => $f])
            ->setOption(RegistroImpresiones::OPCIONES_PDF)
            ->setPaper([0, 0, self::ANCHO_TICKET_PUNTOS, 40 + $renglones * ($grande ? 16.5 : 13)]);
    }
}
