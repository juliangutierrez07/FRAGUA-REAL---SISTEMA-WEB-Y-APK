<?php

namespace App\Services;

use App\Models\CajaTurno;
use App\Models\CompraGasto;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Reserva;
use App\Models\User;
use RuntimeException;

/**
 * M06 — Caja y Ventas. RN-M06-03: un solo turno de caja por día
 * (reforzado además a nivel de base de datos con `caja_turnos.fecha` único).
 */
class CajaService
{
    // BUG REAL encontrado en vivo al probar esta vista: config/app.php tiene
    // 'timezone' => 'UTC' (toda la app corre en UTC), pero el restaurante opera en hora
    // de Colombia (UTC-5). Si "hoy" se calculara con now() crudo, el "día" de caja
    // cambiaría a las 7pm hora Colombia (medianoche UTC) en vez de a medianoche local —
    // es decir, el turno de la noche se marcaría como "de mañana" y RN-M06-03 bloquearía
    // abrir uno nuevo aunque el día de negocio no haya terminado. Por eso "hoy" para
    // efectos de caja se calcula explícitamente en esta zona horaria, sin tocar el
    // 'timezone' global de la app (eso afectaría casts/logs/etc. de todo el sistema).
    private const ZONA_HORARIA_NEGOCIO = 'America/Bogota';

    public function turnoAbierto(?\DateTimeInterface $fecha = null): ?CajaTurno
    {
        $fecha ??= now(self::ZONA_HORARIA_NEGOCIO);

        return CajaTurno::whereDate('fecha', $fecha)->where('estado', 'abierta')->first();
    }

    public function abrir(User $actor, float $baseInicial, ?\DateTimeInterface $fecha = null): CajaTurno
    {
        $fecha ??= now(self::ZONA_HORARIA_NEGOCIO);

        if (CajaTurno::whereDate('fecha', $fecha)->exists()) {
            throw new RuntimeException('Ya existe un turno de caja para hoy (RN-M06-03).');
        }

        $turno = new CajaTurno();
        $turno->fecha = $fecha;
        $turno->base_inicial = $baseInicial;
        $turno->efectivo_esperado = $baseInicial;
        $turno->abierto_por = $actor->id;
        $turno->abierto_at = now();
        $turno->estado = 'abierta';
        $turno->save();

        // RF-M06-020: apertura auditada.
        app(AuditoriaService::class)->registrar('abrir_caja', 'CajaTurno', $turno->id, ['base_inicial' => $baseInicial], $actor);

        return $turno;
    }

    /**
     * Recalcula el efectivo esperado = base inicial + pagos en efectivo, aprobados y
     * no anulados, registrados en este turno.
     */
    /**
     * RN-M06-08: efectivo esperado = base inicial + pagos en efectivo del día − compras y
     * gastos en efectivo del día (M03) − anulaciones de pagos en efectivo. Las anulaciones
     * restan porque un pago anulado ya no se suma.
     *
     * CORREGIDO (Fase 0, tarea 3, 09/10/2026): antes no restaba las compras ni los gastos en
     * efectivo de M03 (RF-M06-013), así que el arqueo mostraba un descuadre falso cada vez
     * que se pagaba algo de la caja.
     */
    public function recalcularEfectivoEsperado(CajaTurno $turno): float
    {
        $totalEfectivo = $turno->pagos()
            ->where('metodo', 'efectivo')
            ->where('estado', 'aprobado')
            ->where('anulado', false)
            ->sum('monto');

        // Fase 2, tarea 2: los abonos de depósito (pagos tipo "deposito") no llevan turno, así que
        // no entran aquí; lo que sí entra es el depósito en efectivo recibido en este turno, y sale
        // el que se devolvió en este turno.
        $esperado = (float) $turno->base_inicial + (float) $totalEfectivo - $this->gastosEnEfectivo($turno)
            + $this->depositosEnEfectivo($turno) - $this->reembolsosDeDepositoEnEfectivo($turno);
        $turno->efectivo_esperado = $esperado;
        $turno->save();

        return $esperado;
    }

    // Fase 2, tarea 2 (RN-M05-16, RN-M06-08): depósitos de reserva recibidos en efectivo en este turno.
    public function depositosEnEfectivo(CajaTurno $turno): float
    {
        return (float) Reserva::where('deposito_caja_turno_id', $turno->id)
            ->where('deposito_metodo', 'efectivo')
            ->where('deposito_pagado', true)
            ->sum('deposito_valor');
    }

    // Fase 2, tarea 2 (RN-M05-17): depósitos en efectivo devueltos en este turno.
    public function reembolsosDeDepositoEnEfectivo(CajaTurno $turno): float
    {
        return (float) Reserva::where('deposito_reembolso_caja_turno_id', $turno->id)
            ->where('deposito_metodo', 'efectivo')
            ->where('deposito_reembolsado', true)
            ->sum('deposito_valor');
    }

    /**
     * Mejora (16/10/2026): resumen del turno para el Administrador. Antes la Caja solo mostraba el
     * efectivo esperado; para saber cuánto entró por tarjeta o transferencia había que sumar los
     * pagos uno a uno. Ahora (RN-M06-08, RN-M06-11):
     *  - ingresos: los cobros del turno por método, más los pagos en línea del día;
     *  - efectivo: de dónde sale el efectivo esperado (base + cobros + depósitos − gastos − devoluciones);
     *  - depósitos de reserva recibidos en el día por método, incluidos los pagados en línea (antes no
     *    se veían en Caja), y los devueltos;
     *  - anulados: aparte, para no confundirlos con lo cobrado.
     * Solo lee: no cambia el turno.
     */
    public function resumen(CajaTurno $turno): array
    {
        [$desde, $hasta] = $this->rangoDelTurno($turno);
        $metodos = ['efectivo', 'tarjeta', 'transferencia', 'en_linea'];
        $agrupar = fn ($consulta, string $columnaMetodo, string $columnaMonto) => $consulta
            ->selectRaw("{$columnaMetodo} as metodo, COUNT(*) as cantidad, COALESCE(SUM({$columnaMonto}), 0) as monto")
            ->groupBy($columnaMetodo)->get()->keyBy('metodo');
        $fila = fn ($grupos, string $m) => [
            'metodo' => $m,
            'monto' => round((float) ($grupos[$m]->monto ?? 0), 2),
            'cantidad' => (int) ($grupos[$m]->cantidad ?? 0),
        ];

        // Cobros: los presenciales llevan el turno; los en línea, por el día (ADR-004).
        $presenciales = $agrupar($turno->pagos()->where('tipo', 'cobro')->where('estado', 'aprobado')->where('anulado', false), 'metodo', 'monto');
        $enLinea = $agrupar(Pago::where('metodo', 'en_linea')->where('tipo', 'cobro')->where('estado', 'aprobado')
            ->where('anulado', false)->whereBetween('created_at', [$desde, $hasta]), 'metodo', 'monto');
        $ingresos = array_map(fn ($m) => $fila($m === 'en_linea' ? $enLinea : $presenciales, $m), $metodos);

        // Depósitos de reserva: los del local llevan el turno; los en línea, por el día en que se pagaron.
        $depositosLocal = $agrupar(Reserva::where('deposito_caja_turno_id', $turno->id)->where('deposito_pagado', true), 'deposito_metodo', 'deposito_valor');
        $depositosEnLinea = $agrupar(Reserva::whereNull('deposito_caja_turno_id')->where('deposito_metodo', 'en_linea')
            ->where('deposito_pagado', true)->whereBetween('deposito_pagado_at', [$desde, $hasta]), 'deposito_metodo', 'deposito_valor');
        $depositos = array_values(array_filter(
            array_map(fn ($m) => $fila($m === 'en_linea' ? $depositosEnLinea : $depositosLocal, $m), $metodos),
            fn ($d) => $d['cantidad'] > 0,
        ));
        $devueltos = Reserva::where('deposito_reembolso_caja_turno_id', $turno->id)->where('deposito_reembolsado', true);

        $anulados = Pago::where('tipo', 'cobro')->where('anulado', true)
            ->where(fn ($q) => $q->where('caja_turno_id', $turno->id)
                ->orWhere(fn ($q) => $q->where('metodo', 'en_linea')->whereBetween('created_at', [$desde, $hasta])));
        $anuladosPorMetodo = $agrupar(clone $anulados, 'metodo', 'monto');

        $cobrosEfectivo = $ingresos[0]['monto'];
        $depositosEfectivo = $this->depositosEnEfectivo($turno);
        $gastosEfectivo = $this->gastosEnEfectivo($turno);
        $devolucionesEfectivo = $this->reembolsosDeDepositoEnEfectivo($turno);

        return [
            'ingresos' => [
                'por_metodo' => $ingresos,
                'total' => round(array_sum(array_column($ingresos, 'monto')), 2),
                'cantidad' => array_sum(array_column($ingresos, 'cantidad')),
            ],
            'efectivo' => [
                'base_inicial' => (float) $turno->base_inicial,
                'cobros' => $cobrosEfectivo,
                'depositos' => $depositosEfectivo,
                'gastos' => $gastosEfectivo,
                'devoluciones' => $devolucionesEfectivo,
                // Con la caja cerrada se muestra el esperado del arqueo; lo que cambie después (un
                // ajuste extraordinario) se ve en sus partes y en «Ajustes extraordinarios».
                'esperado' => $turno->estaAbierta()
                    ? round((float) $turno->base_inicial + $cobrosEfectivo + $depositosEfectivo - $gastosEfectivo - $devolucionesEfectivo, 2)
                    : (float) $turno->efectivo_esperado,
            ],
            'depositos' => [
                'por_metodo' => $depositos,
                'total' => round(array_sum(array_column($depositos, 'monto')), 2),
                'cantidad' => array_sum(array_column($depositos, 'cantidad')),
                'devueltos' => ['monto' => round((float) (clone $devueltos)->sum('deposito_valor'), 2), 'cantidad' => (clone $devueltos)->count()],
            ],
            'anulados' => [
                'por_metodo' => array_values(array_filter(array_map(fn ($m) => $fila($anuladosPorMetodo, $m), $metodos), fn ($a) => $a['cantidad'] > 0)),
                'total' => round((float) (clone $anulados)->sum('monto'), 2),
                'cantidad' => (clone $anulados)->count(),
            ],
            'actualizado_at' => now()->toIso8601String(),
        ];
    }

    // RF-M06-013: compras y gastos activos pagados en efectivo con la fecha del turno.
    public function gastosEnEfectivo(CajaTurno $turno): float
    {
        return (float) CompraGasto::where('metodo_pago', 'efectivo')
            ->where('estado', 'activo')
            ->whereDate('fecha', $turno->fecha)
            ->sum('valor');
    }

    // Turno (abierto o cerrado) del día de negocio. Recibe una fecha 'Y-m-d' (ya es un día
    // de negocio, como compras_gastos.fecha) o un instante (como el created_at de un pago,
    // en UTC), que se pasa a la hora de Bogotá antes de tomar el día.
    public function turnoDeFecha(\DateTimeInterface|string $fecha): ?CajaTurno
    {
        $dia = is_string($fecha) ? $fecha : \Illuminate\Support\Carbon::instance($fecha)->setTimezone(self::ZONA_HORARIA_NEGOCIO)->toDateString();

        return CajaTurno::whereDate('fecha', $dia)->first();
    }

    // Lo llama M03 al registrar, editar o anular una compra o gasto: si la caja de ese día
    // sigue abierta, su efectivo esperado se actualiza. Una caja cerrada no se toca.
    public function recalcularSiEstaAbierto(\DateTimeInterface|string $fecha): void
    {
        $turno = $this->turnoDeFecha($fecha);
        if ($turno && $turno->estaAbierta()) {
            $this->recalcularEfectivoEsperado($turno);
        }
    }

    // Inicio y fin (en UTC) del día de negocio de un turno, para buscar lo que no tiene
    // caja_turno_id, como los pagos en línea.
    public function rangoDelTurno(CajaTurno $turno): array
    {
        $inicio = \Illuminate\Support\Carbon::parse($turno->fecha->toDateString(), self::ZONA_HORARIA_NEGOCIO)->startOfDay();

        return [$inicio->copy()->utc(), $inicio->copy()->endOfDay()->utc()];
    }

    /**
     * Mejora (26/10/2026): la caja solo cierra con todos los pedidos finalizados o cancelados. Un pedido
     * pendiente, confirmado o en preparación (con o sin saldo) queda en esta lista, de cualquier día.
     *
     * @return array<int, array{id: int, tipo: string, estado: string, destino: string, saldo: float}>
     */
    public function pedidosSinFinalizar(): array
    {
        return Pedido::with(['mesa:id,nombre', 'pagos'])
            ->whereIn('estado', Pedido::ESTADOS_ACTIVOS)
            ->orderBy('id')
            ->get()
            ->map(fn (Pedido $p) => [
                'id' => $p->id,
                'tipo' => $p->tipo,
                'estado' => $p->estado,
                'destino' => $p->esLocal()
                    ? trim(($p->mesa?->nombre ?? 'Mesa').($p->cuenta > 1 ? " · Cuenta {$p->cuenta}" : ''))
                    : ($p->esDomicilio() ? 'Domicilio' : 'Recogida'),
                'saldo' => round(max(0, (float) $p->total - $p->totalPagado()), 2),
            ])
            ->all();
    }

    public function cerrar(CajaTurno $turno, User $actor, float $efectivoContado, ?string $justificacion = null): CajaTurno
    {
        if (! $turno->estaAbierta()) {
            throw new RuntimeException('Este turno ya está cerrado.');
        }

        $abiertos = $this->pedidosSinFinalizar();
        if ($abiertos) {
            $lista = implode(', ', array_map(fn ($p) => "#{$p['id']} ({$p['destino']})", array_slice($abiertos, 0, 5)));
            $mas = count($abiertos) > 5 ? ' y '.(count($abiertos) - 5).' más' : '';
            throw new RuntimeException(count($abiertos) === 1
                ? "No puedes cerrar la caja: el pedido {$lista} no está finalizado. Cóbralo y finalízalo, o cancélalo con su motivo."
                : 'No puedes cerrar la caja: hay '.count($abiertos)." pedidos sin finalizar: {$lista}{$mas}. Cóbralos y finalízalos, o cancélalos con su motivo.");
        }

        $esperado = $this->recalcularEfectivoEsperado($turno);
        $diferencia = round($efectivoContado - $esperado, 2);

        if ($diferencia !== 0.0 && empty($justificacion)) {
            throw new RuntimeException('Hay un descuadre de caja: se requiere justificación para cerrar (RF-M06-015/016).');
        }

        $turno->efectivo_contado = $efectivoContado;
        $turno->diferencia = $diferencia;
        $turno->justificacion_descuadre = $diferencia !== 0.0 ? $justificacion : null;
        $turno->cerrado_por = $actor->id;
        $turno->cerrado_at = now();
        $turno->estado = 'cerrada';
        $turno->save();

        // RF-M06-020: cierre auditado, con el descuadre si lo hubo.
        app(AuditoriaService::class)->registrar('cerrar_caja', 'CajaTurno', $turno->id, [
            'efectivo_esperado' => $esperado,
            'efectivo_contado' => $efectivoContado,
            'diferencia' => $diferencia,
            'justificacion' => $turno->justificacion_descuadre,
        ], $actor);

        return $turno;
    }
}
