<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Caja\AbrirCajaRequest;
use App\Http\Requests\Api\Caja\CerrarCajaRequest;
use App\Models\CajaTurno;
use App\Models\Pago;
use App\Models\Reserva;
use App\Services\CajaService;
use Illuminate\Http\Request;

class CajaController extends Controller
{
    public function __construct(private readonly CajaService $cajaService)
    {
    }

    // Historial de turnos (RF-M06-014 y ss.): quién lo abrió/cerró se necesita en la lista
    // para no tener que entrar al detalle solo para saber eso — los pagos en cambio son
    // potencialmente muchos por turno, así que se cargan aparte en show().
    public function index(Request $request)
    {
        $this->authorize('viewAny', CajaTurno::class);

        return response()->json(CajaTurno::with(['abiertoPorUsuario', 'cerradoPorUsuario'])->latest('fecha')->paginate(20));
    }

    // Detalle de un turno: sus pagos (con el pedido y quién cobró cada uno) — para anular
    // uno desde aquí (PagoController::anular). Un pago en_linea no tiene registrado_por
    // (ADR-004, ver PagoService::registrarPagoEnLinea), por eso esa relación es opcional.
    public function show(Request $request, CajaTurno $turno)
    {
        $this->authorize('view', $turno);

        $turno->load([
            'abiertoPorUsuario', 'cerradoPorUsuario', 'pagos.pedido', 'pagos.registradoPorUsuario', 'pagos.anuladoPorUsuario',
            'ajustes.autorizadoPorUsuario',
        ]);

        // Fase 0, tarea 3: los pagos en línea no llevan caja_turno_id (ADR-004) y por eso no
        // salían aquí, así que no había desde dónde anularlos (RF-M06-017). Se agregan los
        // del día del turno, junto con lo que restan las compras y gastos en efectivo
        // (RF-M06-013), para que el Administrador vea de dónde sale el efectivo esperado.
        [$desde, $hasta] = $this->cajaService->rangoDelTurno($turno);
        $pagosEnLinea = Pago::with(['pedido', 'anuladoPorUsuario', 'transaccionPasarela', 'confirmadoPorUsuario:id,nombre'])
            ->where('metodo', 'en_linea')
            ->where('tipo', 'cobro') // el abono de un depósito no es un cobro del día (Fase 2, tarea 2)
            ->whereBetween('created_at', [$desde, $hasta])
            ->latest()
            ->get();

        // Fase 2, tarea 2 (RN-M05-16/17, RN-M06-08): depósitos de reserva recibidos y devueltos en
        // este turno, que también mueven el efectivo esperado cuando son en efectivo.
        $depositos = Reserva::with(['mesa', 'depositoRegistradoPor'])
            ->where('deposito_caja_turno_id', $turno->id)
            ->latest('deposito_pagado_at')
            ->get(['id', 'mesa_id', 'cliente_nombre', 'fecha_hora', 'estado', 'deposito_valor', 'deposito_metodo', 'deposito_pagado_at', 'deposito_registrado_por', 'deposito_reembolsado']);
        $depositosDevueltos = Reserva::with('mesa')
            ->where('deposito_reembolso_caja_turno_id', $turno->id)
            ->get(['id', 'mesa_id', 'cliente_nombre', 'fecha_hora', 'deposito_valor', 'deposito_metodo', 'updated_at']);
        // Mejora (16/10/2026): los depósitos pagados en línea ese día no llevan turno y no se veían aquí.
        $depositosEnLinea = Reserva::with('mesa')
            ->whereNull('deposito_caja_turno_id')->where('deposito_metodo', 'en_linea')->where('deposito_pagado', true)
            ->whereBetween('deposito_pagado_at', [$desde, $hasta])
            ->latest('deposito_pagado_at')
            ->get(['id', 'mesa_id', 'cliente_nombre', 'fecha_hora', 'estado', 'deposito_valor', 'deposito_metodo', 'deposito_pasarela', 'deposito_confirmado_via', 'deposito_pagado_at', 'deposito_reembolsado']);

        return response()->json(array_merge($turno->toArray(), [
            'resumen' => $this->cajaService->resumen($turno),
            'depositos_en_linea' => $depositosEnLinea,
            'pagos_en_linea' => $pagosEnLinea,
            'gastos_efectivo' => $this->cajaService->gastosEnEfectivo($turno),
            'depositos_reserva' => $depositos,
            'depositos_devueltos' => $depositosDevueltos,
            'depositos_efectivo' => $this->cajaService->depositosEnEfectivo($turno),
            'reembolsos_deposito_efectivo' => $this->cajaService->reembolsosDeDepositoEnEfectivo($turno),
        ]));
    }

    // Fase 7, tarea 4 (RF-M06-023, HU-M06-009): «quiero saber si la caja está abierta y cuánto
    // efectivo se espera». Para el Mesero y el Administrador: el estado de la caja de hoy (sin
    // abrir, abierta o cerrada), quién la abrió y, si está abierta, el efectivo esperado. Antes el
    // Mesero solo se enteraba de que estaba cerrada cuando el cobro le fallaba. Solo lectura; el
    // detalle del turno (pagos, gastos, arqueo) sigue siendo del Administrador.
    public function estado()
    {
        $hoy = now('America/Bogota')->toDateString();
        $turno = $this->cajaService->turnoDeFecha($hoy)?->load(['abiertoPorUsuario:id,nombre', 'cerradoPorUsuario:id,nombre']);
        $abierta = $turno?->estado === 'abierta';

        return response()->json([
            'fecha' => $hoy,
            'estado' => $turno === null ? 'sin_abrir' : ($abierta ? 'abierta' : 'cerrada'),
            'abierta_at' => $turno?->abierto_at,
            'abierta_por' => $turno?->abiertoPorUsuario?->nombre,
            'cerrada_at' => $abierta ? null : $turno?->cerrado_at,
            'cerrada_por' => $abierta ? null : $turno?->cerradoPorUsuario?->nombre,
            'efectivo_esperado' => $abierta ? (float) $turno->efectivo_esperado : null,
        ]);
    }

    public function turnoActual(Request $request)
    {
        $this->authorize('viewAny', CajaTurno::class);

        $turno = $this->cajaService->turnoAbierto()?->load(['abiertoPorUsuario', 'cerradoPorUsuario']);

        // BUG REAL encontrado en vivo en el QA final: en esta instalación concreta de
        // Laravel 13 + PHP 8.3, response()->json(null) no serializa a "null" — serializa al
        // literal "{}" (confirmado aislando el problema en una ruta de prueba sin Controller
        // ni middleware de por medio: el mismo response()->json(null) a secas ya daba "{}").
        // Como "{}" es truthy en JS, admin/CajaView.vue (turnoActual.value = data; v-else-if
        //="!turnoActual") interpretaba "sin turno abierto" como "turno abierto" y mostraba
        // "Abierta / Base inicial $0 · abierto por —" sin que existiera ningún turno para hoy.
        // Se construye la Response a mano para los dos casos, sin pasar por el setData() de
        // JsonResponse con un valor null — con un turno real, response()->json() sí serializa
        // bien (se confirmó en vivo), así que solo el caso null necesita este rodeo.
        if ($turno === null) {
            return response('null', 200)->header('Content-Type', 'application/json');
        }

        // Mejora (16/10/2026): el turno abierto trae su resumen (ingresos por método, de dónde sale el
        // efectivo esperado, depósitos y anulados), para la tarjeta «Turno de hoy».
        $this->cajaService->recalcularEfectivoEsperado($turno);

        // Mejora (26/10/2026, RF-M06-038): lo que impide cerrar la caja (pedidos sin finalizar).
        return response()->json(array_merge($turno->toArray(), [
            'resumen' => $this->cajaService->resumen($turno),
            'pedidos_sin_finalizar' => $this->cajaService->pedidosSinFinalizar(),
            // 27/10/2026 (RF-M06-039): cuándo se cierra sola si nadie la cierra (hora de cierre + tolerancia).
            'se_cierra_sola_at' => \App\Support\Caja\PoliticaCaja::limite($turno->fecha)->toIso8601String(),
        ]));
    }

    public function abrir(AbrirCajaRequest $request)
    {
        $this->authorize('abrir', CajaTurno::class);

        $turno = $this->cajaService->abrir($request->user(), (float) $request->base_inicial);

        return response()->json($turno, 201);
    }

    public function cerrar(CerrarCajaRequest $request, CajaTurno $turno)
    {
        $this->authorize('cerrar', $turno);

        $turno = $this->cajaService->cerrar(
            $turno,
            $request->user(),
            (float) $request->efectivo_contado,
            $request->justificacion_descuadre,
        );

        return response()->json($turno);
    }

    // POST /api/caja/turnos/{turno}/conteo — 27/10/2026 (RF-M06-039): el efectivo contado de un turno que
    // se cerró solo; con justificación si no cuadra, como al cerrar a mano.
    public function registrarConteo(CerrarCajaRequest $request, CajaTurno $turno)
    {
        $this->authorize('cerrar', $turno);

        return response()->json($this->cajaService->registrarConteo(
            $turno,
            $request->user(),
            (float) $request->efectivo_contado,
            $request->justificacion_descuadre,
        ));
    }
}
