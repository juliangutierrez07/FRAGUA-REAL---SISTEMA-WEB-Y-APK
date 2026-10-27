<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reserva\ConfirmarDepositoReservaRequest;
use App\Http\Requests\Api\Reserva\RegistrarDepositoManualRequest;
use App\Http\Requests\Api\Reserva\StoreReservaRequest;
use App\Models\Configuracion;
use App\Models\Reserva;
use App\Models\ReservaAviso;
use App\Services\AvisoReservaService;
use App\Services\ReservaService;
use Illuminate\Http\Request;

class ReservaController extends Controller
{
    public function __construct(private readonly ReservaService $reservaService)
    {
    }

    // M05 (Admin): listado con filtro opcional por estado y/o por mesa (?mesa_id=) — este
    // último es el historial de reservas de una mesa concreta, enlazado desde "Ver
    // reservas" en MesasView.vue. Ver EstadoReserva en frontend/src/types/models.ts para
    // los valores válidos de estado.
    public function index(Request $request)
    {
        $query = Reserva::with('mesa')->latest('fecha_hora');

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        if ($request->filled('mesa_id')) {
            $query->where('mesa_id', $request->integer('mesa_id'));
        }

        // Fase 1, tarea 4: ?proximas=1 → las que el salón tiene que atender, de hoy en adelante
        // (hora de Bogotá), pendientes o confirmadas, de la más cercana a la más lejana.
        if ($request->boolean('proximas')) {
            $query->reorder()
                ->whereIn('estado', ['pendiente_pago', 'confirmada'])
                ->where('fecha_hora', '>=', now('America/Bogota')->startOfDay()->utc())
                ->orderBy('fecha_hora');
        }

        // Fase 4, tarea 4 (RF-M05-027): con los avisos enviados al cliente (o los que fallaron).
        return response()->json($query->with(['registradaPorUsuario:id,nombre', 'avisos', 'transaccionDeposito'])->paginate(20));
    }

    // Cliente web, Mesero o Administrador (RN-M05-06): creado_por distingue el origen.
    // CORREGIDO (Fase 1, tarea 4, 11/10/2026): POST /reservas es una ruta pública, así que
    // $request->user() siempre era null y toda reserva quedaba como "cliente_web", también las
    // que registraba el personal por teléfono. Ahora se pide la sesión a Sanctum (igual que en
    // StorePedidoRequest) y se guarda quién la registró.
    public function store(StoreReservaRequest $request)
    {
        $data = $request->validated();
        $usuario = $request->user('sanctum');
        $esPersonal = $usuario && ($usuario->esMesero() || $usuario->esAdministrador());
        // M09 (20/10/2026): POST /reservas es pública; el personal sin el menú de Mesas ni el de
        // Reservas no registra reservas por teléfono.
        abort_if($esPersonal && ! \App\Support\Usuarios\MenusPorRol::permite($usuario, ['mesas', 'reservas']), 403, \App\Support\Usuarios\MenusPorRol::SIN_PERMISO);
        // M09: la reserva del SuperAdmin cuenta como del Administrador (creado_por no tiene otro valor).
        $data['creado_por'] = $esPersonal ? ($usuario->esMesero() ? 'mesero' : 'administrador') : 'cliente_web';

        // Valor vigente fijado por el servidor, no por el Cliente (mismo criterio que
        // valor_domicilio en PedidoController) — tabla `configuraciones`.
        $depositoValor = Configuracion::obtenerNumerico(
            'deposito_reserva',
            (float) config('fragua_real.deposito_reserva_default')
        );
        $reserva = $this->reservaService->crear($data, $depositoValor, $esPersonal ? $usuario : null);
        $reserva->load(['mesa', 'avisos', 'transaccionDeposito']);

        // Fase 6, tarea 1: quien crea la reserva web recibe su enlace secreto una sola vez (como el
        // token del pedido de invitado); con él paga el depósito. Nadie más puede iniciar ese pago.
        return response()->json($esPersonal ? $reserva : $reserva->makeVisible('token_publico'), 201);
    }

    // Fase 1, tarea 4 (RN-M05-07): mesas activas que no tienen otra reserva a esa hora. Pública:
    // la usan la reserva web del Cliente y la del personal. Solo devuelve mesas, sin datos de nadie.
    public function mesasDisponibles(Request $request)
    {
        $request->validate(['fecha_hora' => ['required', 'date']]);
        $fechaHora = \Illuminate\Support\Carbon::parse($request->string('fecha_hora'), 'America/Bogota')->utc();

        return response()->json($this->reservaService->mesasDisponibles($fechaHora));
    }

    // RN-M05-16, RF-M05-030: el Cliente inicia el pago del depósito por Nequi/Bancolombia (público).
    // Fase 6, tarea 1: exige el enlace secreto de la reserva (antes bastaba el número, que es
    // consecutivo) y, con la pasarela activa, devuelve el enlace del checkout en "checkout".
    public function registrarDepositoEnLinea(ConfirmarDepositoReservaRequest $request, Reserva $reserva)
    {
        abort_unless(hash_equals((string) $reserva->token_publico, (string) $request->input('token')), 404);

        [$reserva, $checkout] = $this->reservaService->iniciarDepositoEnLinea($reserva, $request->pasarela, $request->file('comprobante'), $request->input('referencia'));

        return response()->json($reserva->load(['mesa', 'avisos', 'transaccionDeposito'])->toArray() + ['checkout' => $checkout]);
    }

    // Aprobación manual del depósito en línea, por el Administrador o el Mesero. Fase 6, tarea 1:
    // con la pasarela activa el depósito se confirma solo; esto queda como respaldo (decisión D4).
    // ->load(['mesa', 'avisos', 'transaccionDeposito']) en todas las acciones de abajo: la vista de Administrador reemplaza
    // la reserva en su lista con esta misma respuesta y necesita mesa.nombre, igual que
    // index() (sin esto, tras cualquier acción la mesa se vería como "Mesa #id").
    public function confirmarDepositoEnLinea(Request $request, Reserva $reserva)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);

        return response()->json($this->reservaService->confirmarDepositoEnLinea($reserva, $request->user())->load(['mesa', 'avisos', 'transaccionDeposito']));
    }

    public function rechazarDepositoEnLinea(Request $request, Reserva $reserva)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->reservaService->rechazarDepositoEnLinea($reserva, $request->user(), 'manual', $datos['motivo'] ?? null)->load(['mesa', 'avisos', 'transaccionDeposito']));
    }

    // GET /api/reservas/{reserva}/deposito/comprobante — Pago directo (RF-M06-032): la captura del
    // depósito, solo para el personal.
    public function comprobanteDeposito(Request $request, Reserva $reserva, \App\Services\PagoDirectoService $pagosDirectos)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);
        abort_if(! $pagosDirectos->existe($reserva->deposito_comprobante_path), 404, 'Este depósito no tiene comprobante.');

        return $pagosDirectos->respuesta($reserva->deposito_comprobante_path, $reserva->deposito_comprobante_mime, "comprobante-reserva-{$reserva->id}");
    }

    // RN-M05-16: depósito manual registrado por el Mesero o el Administrador.
    // Mejora (26/10/2026, RF-M05-045 y 046): con lo que entregó el cliente en efectivo y la foto del comprobante.
    public function registrarDepositoManual(RegistrarDepositoManualRequest $request, Reserva $reserva)
    {
        $recibido = $request->filled('recibido') ? (float) $request->input('recibido') : null;

        return response()->json(
            $this->reservaService->registrarDepositoManual($reserva, $request->metodo, $request->user(), $recibido, $request->file('comprobante'))
                ->load(['mesa', 'avisos', 'transaccionDeposito'])
        );
    }

    public function marcarNoShow(Request $request, Reserva $reserva)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);

        return response()->json($this->reservaService->marcarNoShow($reserva, $request->user())->load(['mesa', 'avisos', 'transaccionDeposito']));
    }

    // Fase 4, tarea 4 (RF-M05-027): reintentar un aviso por correo que falló.
    public function reintentarAviso(Request $request, Reserva $reserva, ReservaAviso $aviso, AvisoReservaService $avisos)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);
        abort_if($aviso->reserva_id !== $reserva->id, 404);

        $avisos->reintentar($aviso);

        return response()->json($reserva->fresh(['mesa', 'avisos']));
    }

    public function completar(Request $request, Reserva $reserva)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);

        return response()->json($this->reservaService->completar($reserva, $request->user())->load(['mesa', 'avisos', 'transaccionDeposito']));
    }

    public function cancelar(Request $request, Reserva $reserva)
    {
        // CORREGIDO: antes esta acción no tenía ninguna autorización fina — cualquier
        // Cliente autenticado podía cancelar la reserva de otro con solo conocer su id.
        // RN-M05-09 ("por quien la creó, o por el Mesero/Administrador") vive en
        // ReservaPolicy::cancelar() (ver ahí por qué se identifica por correo).
        $this->authorize('cancelar', $reserva);

        return response()->json($this->reservaService->cancelar($reserva, $request->user())->load(['mesa', 'avisos', 'transaccionDeposito']));
    }

    // "Mis reservas" del Cliente (HU-M05, pantalla "Mis pedidos y reservas"): Reserva no
    // tiene cliente_id (RN-M05-12), así que se identifican por el correo de contacto —
    // mismo criterio que ReservaPolicy::cancelar().
    public function misReservas(Request $request)
    {
        $reservas = Reserva::with('mesa')
            ->where('cliente_correo', $request->user()->email)
            ->latest('fecha_hora')
            ->paginate(20);

        // Mejora (26/10/2026, RF-M05-044): si el depósito sigue por pagar, el Cliente lo paga desde aquí.
        $reservas->getCollection()->each(fn (Reserva $r) => $r->setAttribute('puede_pagar', $this->reservaService->puedePagarDeposito($r)));

        return response()->json($reservas);
    }

    // POST /api/mis-reservas/{reserva}/deposito — Mejora (26/10/2026, RF-M05-044): el Cliente con sesión paga el
    // depósito de su reserva desde «Mis pedidos y reservas», igual que con el enlace del correo.
    // Solo sus reservas (mismo correo); las de otros responden 404.
    public function pagarMiDeposito(Request $request, Reserva $reserva, \App\Services\PagoDirectoService $pagosDirectos)
    {
        abort_unless($reserva->cliente_correo === $request->user()->email, 404, 'No encontramos esa reserva.');

        $datos = $request->validate(['pasarela' => ['required', 'in:nequi,bancolombia,breb'], ...$pagosDirectos::reglasComprobante()],
            $pagosDirectos::mensajesComprobante());
        if ($error = $pagosDirectos->errorDeMedio($datos['pasarela'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['pasarela' => [$error]]);
        }
        if (! $this->reservaService->puedePagarDeposito($reserva)) {
            throw new \RuntimeException('Esta reserva ya no espera el pago del depósito.');
        }

        [$reserva, $checkout] = $this->reservaService->iniciarDepositoEnLinea($reserva, $datos['pasarela'], $request->file('comprobante'), $datos['referencia'] ?? null);
        $reserva = $reserva->fresh('mesa');
        $reserva->setAttribute('puede_pagar', $this->reservaService->puedePagarDeposito($reserva));

        return response()->json($reserva->toArray() + ['checkout' => $checkout]);
    }

    // RF-M05-035, HU-M05-015: depósitos retenidos por no-show (RN-M05-18) — un depósito
    // pagado que nunca se reembolsó (cancelar()) ni se aplicó como abono (completar()),
    // porque el cliente no llegó. Exclusivo Administrador (ver routes/api.php).
    public function depositosRetenidos(Request $request)
    {
        $reservas = Reserva::with('mesa')
            ->where('estado', 'no_show')
            ->where('deposito_pagado', true)
            ->where('deposito_reembolsado', false)
            ->where('deposito_aplicado_abono', false)
            ->latest('fecha_hora')
            ->get();

        return response()->json([
            'total_retenido' => $reservas->sum('deposito_valor'),
            'reservas' => $reservas,
        ]);
    }
}
