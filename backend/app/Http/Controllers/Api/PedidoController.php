<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pedido\ActualizarLineaRequest;
use App\Http\Requests\Api\Pedido\AgregarLineaRequest;
use App\Http\Requests\Api\Pedido\AplicarDescuentoRequest;
use App\Http\Requests\Api\Pedido\CancelarPedidoRequest;
use App\Http\Requests\Api\Pedido\CotizarPedidoRequest;
use App\Http\Requests\Api\Pedido\StorePedidoRequest;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\PedidoAviso;
use App\Models\PedidoDetalle;
use App\Services\AvisoPedidoService;
use App\Services\PedidoService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class PedidoController extends Controller
{
    public function __construct(
        private readonly PedidoService $pedidoService,
        private readonly AvisoPedidoService $avisos,
    ) {
    }

    // Relaciones que necesita el frontend para mostrar cada línea sin pedir el detalle
    // aparte (nombre de producto/variante/combo, extras). CORREGIDO (28/09/2026): index()
    // y show() cargaban 'detalles.extras' pero no 'detalles.producto'/'variante'/'combo' —
    // Eloquent no serializa una relación que no fue cargada, así que el frontend recibía
    // detalle.producto === undefined y no podía mostrar el nombre del producto.
    // 'pagos' se agrega también en index() (no solo en show()) porque la vista Mesero >
    // Cobrar necesita saber cuánto lleva pagado cada pedido de la lista (pago mixto,
    // RF-M06-011) sin pedir el detalle de cada uno por separado.
    // CORREGIDO (29/09/2026): faltaba 'cliente' — sin esto, el Mesero no tenía forma de ver
    // el nombre de un Cliente CON SESIÓN en Domicilio/Recogida (pedido.cliente_id sin null,
    // pero pedido.cliente_invitado_nombre si null porque no aplica) — la vista solo mostraba
    // el nombre para invitados. Ver PedidosView.vue/CajaView.vue del Mesero (fallback a
    // pedido.cliente?.nombre).
    // Fase 2, tarea 1: 'descuentoManualPor' para mostrar quién aplicó el descuento manual.
    // Fase 4, tarea 5: 'avisos' (correo «listo para recoger» enviado, en cola o fallido).
    // Fase 5, tarea 1: 'comprobante' (el vigente: número y tipo; el documento se pide aparte).
    public const CON_DETALLES = ['detalles.producto', 'detalles.variante', 'detalles.combo', 'detalles.extras', 'mesa', 'pagos.transaccionPasarela', 'cliente', 'descuentoManualPor:id,nombre', 'avisos', 'comprobante', 'comandas'];

    // Panel de pedidos del Administrador (RF-M02-T-017): filtra por tipo/estado.
    public function index(Request $request)
    {
        // Mejora (28/10/2026, RF-M02-T-018): pedidos de un día o de un rango de días (`desde`/`hasta`,
        // fechas de calendario en hora de Colombia). Las pantallas del personal muestran hoy por defecto;
        // antes salían todos los pedidos de todas las semanas.
        $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);
        $zona = 'America/Bogota';
        $desde = $request->filled('desde') ? CarbonImmutable::parse($request->desde, $zona)->startOfDay()->utc() : null;
        $hasta = $request->filled('hasta') ? CarbonImmutable::parse($request->hasta, $zona)->endOfDay()->utc() : null;

        $pedidos = Pedido::with(self::CON_DETALLES)
            ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('created_at', '<=', $hasta))
            ->when($request->tipo, fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->estado, fn ($q) => $q->where('estado', $request->estado))
            // Fase 1, tarea 3: pedidos de una mesa (RF-M02-L-020).
            ->when($request->mesa_id, fn ($q) => $q->where('mesa_id', (int) $request->mesa_id))
            // ?activos=1 → pendiente/confirmado/preparando (lo que el Mesero atiende y cobra);
            // sin esto, un listado paginado de 20 podía dejar pedidos activos fuera de la lista.
            ->when($request->boolean('activos'), fn ($q) => $q->whereIn('estado', ['pendiente', 'confirmado', 'preparando']))
            ->when($request->user()->esCliente(), fn ($q) => $q->where('cliente_id', $request->user()->id))
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        return response()->json($pedidos);
    }

    public function show(Request $request, Pedido $pedido)
    {
        $this->authorize('view', $pedido);

        return response()->json($pedido->load(self::CON_DETALLES));
    }

    // Crear pedido: mesero (QR/local), o cliente_web (domicilio/recogida, con o sin cuenta).
    public function store(StorePedidoRequest $request)
    {
        $data = $request->validated();
        if ($request->user() && $request->user()->esCliente()) {
            $data['cliente_id'] = $request->user()->id;
        }
        if ($data['tipo'] === 'domicilio') {
            // Valor vigente fijado por el servidor (tabla `configuraciones`), nunca por el Cliente.
            $data['valor_domicilio'] = Configuracion::obtenerNumerico(
                'valor_domicilio',
                (float) config('fragua_real.valor_domicilio_default')
            );
        }

        // Seguridad (28/10/2026, RN-PED-36): topes de pedidos esperando confirmación (web y QR).
        app(\App\Support\Seguridad\LimitePedidosPublicos::class)->revisar($data);

        $pedido = $this->pedidoService->crear($data);

        // Mejora (27/10/2026, RF-M02-L-036, RN-PED-35): el pedido que toma el personal (Mesero o
        // Administrador) se confirma al crearlo, así la comanda sale de una vez a la impresora de la
        // cocina. Los del QR y la web siguen esperando que el personal los confirme. Uno que se paga en
        // línea tampoco: se confirma cuando el pago esté aprobado (RN-M06-05).
        $usuario = $request->user('sanctum');
        if ($data['origen'] === 'mesero' && $usuario && Gate::forUser($usuario)->allows('confirmar', $pedido)
            && $pedido->metodo_pago_preferido !== 'en_linea') {
            try {
                // Con su comanda, para que el equipo que la imprime al confirmar la abra de una vez.
                $pedido = $this->pedidoService->confirmar($pedido, $usuario)->load('comandas');
            } catch (RuntimeException) {
                // Si algo lo impide, queda pendiente y se confirma a mano como siempre.
            }
        }

        // RF-M06-005: única vez que se entrega el token del pedido (ver Pedido::$hidden).
        return response()->json($pedido->makeVisible('token_publico'), 201);
    }

    // RF-M04-035 (Fase 2, tarea 1): lo que costaría el carrito, con las promociones de este
    // momento, antes de crear el pedido. No guarda nada.
    public function cotizar(CotizarPedidoRequest $request)
    {
        $data = $request->validated();
        $valorDomicilio = $data['tipo'] === 'domicilio'
            ? Configuracion::obtenerNumerico('valor_domicilio', (float) config('fragua_real.valor_domicilio_default'))
            : 0.0;

        // Un producto agotado o un combo fuera de horario responde 422 con el mensaje (bootstrap/app.php).
        return response()->json($this->pedidoService->cotizar($data['lineas'], (float) $valorDomicilio));
    }

    // RF-M06-005, HU-M07-002/011 (Fase 0, tarea 4): el invitado consulta su pedido con el
    // token que recibió al crearlo. Sin token válido, 404 (no revela qué pedidos existen).
    public function seguimiento(string $token)
    {
        $pedido = Pedido::where('token_publico', $token)->firstOrFail();

        return response()->json($this->paraEnlacePublico($pedido));
    }

    /**
     * Lo que ve quien tiene el enlace de seguimiento (el invitado). Fase 6: con el intento en la
     * pasarela de cada pago en línea (estado y si se puede reintentar).
     *
     * Revisión por rol (13/10/2026): el enlace se puede reenviar (a quien pasa a recoger, por
     * ejemplo), así que ya no lleva el teléfono ni el correo del cliente, ni qué persona del
     * personal hizo cada paso, ni las notas internas de pagos y revisiones. La pantalla de
     * seguimiento no usaba nada de eso.
     */
    private function paraEnlacePublico(Pedido $pedido): Pedido
    {
        $pedido->loadMissing(['detalles.producto', 'detalles.variante', 'detalles.combo', 'detalles.extras', 'pagos.transaccionPasarela', 'comprobante']);

        $pedido->makeHidden([
            'cliente_id', 'cliente_invitado_telefono', 'cliente_invitado_correo', 'telefono_contacto',
            'descuento_manual_por', 'confirmado_por', 'listo_por', 'entregado_por', 'recogido_por',
            'finalizado_por', 'cancelado_por',
        ]);
        foreach ($pedido->pagos as $pago) {
            $pago->makeHidden(['caja_turno_id', 'registrado_por', 'confirmado_por', 'anulado_por', 'anulado_motivo', 'reserva_id']);
            $pago->transaccionPasarela?->makeHidden([
                'pagable_type', 'pagable_id', 'requiere_revision', 'motivo_revision', 'revisado_por', 'revisado_at',
                'reembolso_mensaje',
            ]);
        }
        $pedido->comprobante?->makeHidden(['anulado_motivo', 'impresiones']);

        return $pedido;
    }

    public function confirmar(Request $request, Pedido $pedido)
    {
        $this->authorize('confirmar', $pedido);

        // Fase 6, tarea 2: con sus comandas, para que la pantalla abra la comanda recién generada.
        return response()->json($this->pedidoService->confirmar($pedido, $request->user())->load('comandas'));
    }

    public function marcarPreparando(Request $request, Pedido $pedido)
    {
        $this->authorize('marcarPreparando', $pedido);

        return response()->json($this->pedidoService->marcarPreparando($pedido));
    }

    // Fase 4, tarea 5 (RF-M07-R-012, HU-M07-012): la recogida está lista; se avisa al cliente.
    public function marcarListo(Request $request, Pedido $pedido)
    {
        $this->authorize('marcarPreparando', $pedido);

        return response()->json($this->pedidoService->marcarListo($pedido, $request->user())->load(self::CON_DETALLES));
    }

    // Fase 4, tarea 5: vuelve a enviar un aviso que falló.
    public function reintentarAviso(Request $request, Pedido $pedido, PedidoAviso $aviso)
    {
        $this->authorize('marcarPreparando', $pedido);
        abort_if($aviso->pedido_id !== $pedido->id, 404);

        $this->avisos->reintentar($aviso);

        return response()->json($pedido->fresh()->load(self::CON_DETALLES));
    }

    // RF-M02-T-017: el panel de cocina marca cada línea como preparada.
    public function marcarLineaPreparada(Request $request, Pedido $pedido, PedidoDetalle $detalle)
    {
        $this->authorize('marcarPreparando', $pedido);
        abort_if($detalle->pedido_id !== $pedido->id, 404);

        return response()->json($this->pedidoService->marcarLineaPreparada($detalle));
    }

    // RF-M02-T-016: el Mesero finaliza a mano un pedido que ya cumple todo (por ejemplo, un
    // local pagado antes de pasar a preparación). 422 con lo que falta si no cumple.
    public function finalizar(Request $request, Pedido $pedido)
    {
        $this->authorize('finalizar', $pedido);

        return response()->json($this->pedidoService->finalizar($pedido, $request->user())->load(self::CON_DETALLES));
    }

    // RF-M07-025: el Mesero confirma la entrega de un domicilio.
    public function confirmarEntrega(Request $request, Pedido $pedido)
    {
        $this->authorize('confirmarEntrega', $pedido);

        return response()->json($this->pedidoService->confirmarEntrega($pedido, $request->user())->load(self::CON_DETALLES));
    }

    // RF-M07-R-013: el Mesero confirma que el Cliente recogió su pedido.
    public function confirmarRecogida(Request $request, Pedido $pedido)
    {
        $this->authorize('confirmarRecogida', $pedido);

        return response()->json($this->pedidoService->confirmarRecogida($pedido, $request->user())->load(self::CON_DETALLES));
    }

    // ---- Edición (Fase 1, tarea 1): RN-PED-07/08/10/12, RF-M02-L-004/005/012/014/017 ----
    public function agregarLinea(AgregarLineaRequest $request, Pedido $pedido)
    {
        $this->authorize('modificar', $pedido);

        return response()->json($this->pedidoService->agregarLinea($pedido, $request->validated(), $request->user()));
    }

    public function actualizarLinea(ActualizarLineaRequest $request, Pedido $pedido, PedidoDetalle $detalle)
    {
        $this->authorize('modificar', $pedido);
        abort_if($detalle->pedido_id !== $pedido->id, 404);

        return response()->json($this->pedidoService->actualizarLinea($pedido, $detalle, $request->validated(), $request->user()));
    }

    public function retirarLinea(Request $request, Pedido $pedido, PedidoDetalle $detalle)
    {
        $this->authorize('modificar', $pedido);
        abort_if($detalle->pedido_id !== $pedido->id, 404);

        return response()->json($this->pedidoService->retirarLinea($pedido, $detalle, $request->user()));
    }

    // HU-M02-020: historia del pedido (estados, cambios y pagos), con actor y fecha/hora.
    public function historial(Request $request, Pedido $pedido)
    {
        $this->authorize('verHistorial', $pedido);

        return response()->json(['data' => $this->pedidoService->historial($pedido)]);
    }

    // ---- Descuento manual (Fase 2, tarea 1): RF-M04-036/037/039/041, HU-M04-015 ----
    public function aplicarDescuento(AplicarDescuentoRequest $request, Pedido $pedido)
    {
        $this->authorize('aplicarDescuento', $pedido);

        return response()->json($this->pedidoService->aplicarDescuentoManual($pedido, $request->validated(), $request->user()));
    }

    public function quitarDescuento(Request $request, Pedido $pedido)
    {
        $this->authorize('aplicarDescuento', $pedido);

        return response()->json($this->pedidoService->quitarDescuentoManual($pedido, $request->user()));
    }

    public function cancelar(CancelarPedidoRequest $request, Pedido $pedido)
    {
        $this->authorize('cancelar', $pedido);

        return response()->json($this->pedidoService->cancelar(
            $pedido,
            $request->user(),
            $request->motivo,
            $request->input('password_administrador'),
        )->load(self::CON_DETALLES));
    }

    // RF-M07-016, RF-M07-R-009 (Fase 1, tarea 6): el invitado cancela su domicilio o su recogida
    // con el enlace de su pedido, mientras esté PENDIENTE. Sin token válido, 404.
    public function cancelarConToken(CancelarPedidoRequest $request, string $token)
    {
        $pedido = Pedido::where('token_publico', $token)->firstOrFail();
        abort_if(! in_array($pedido->tipo, ['domicilio', 'recogida'], true), 404);
        abort_if($pedido->estado !== 'pendiente', 422, 'El pedido ya fue confirmado por el restaurante; para cancelarlo comunícate con el local.');

        return response()->json($this->paraEnlacePublico($this->pedidoService->cancelar($pedido, null, $request->motivo)));
    }

    // ---- Fidelización (27/10/2026, RF-M06-040, RN-M06-23, HU-M06-021) ----

    // GET /clientes/buscar?dato=… — al cobrar, el Mesero busca al Cliente por documento, correo o celular.
    // Solo devuelve el nombre: lo justo para confirmarlo con el cliente en la mesa.
    public function buscarCliente(Request $request)
    {
        abort_unless($request->user()->esMesero(), 403, 'Solo el Mesero asigna el pedido a un cliente al cobrar.');
        $dato = (string) $request->validate(['dato' => ['required', 'string', 'max:120']])['dato'];
        $hallado = \App\Support\Usuarios\IdentidadCliente::buscar($dato);
        abort_if($hallado === null, 404, 'No hay un cliente registrado con ese documento, correo o celular.');

        return response()->json([
            'id' => $hallado['cliente']->id,
            'nombre' => $hallado['cliente']->nombre,
            'coincide' => $hallado['coincide'],
        ]);
    }

    // POST /pedidos/asignar-cliente — deja los pedidos que se cobran (uno, una cuenta o toda la mesa) en el
    // historial del Cliente.
    public function asignarCliente(Request $request)
    {
        $datos = $request->validate([
            'pedido_ids' => ['required', 'array', 'min:1', 'max:30'],
            'pedido_ids.*' => ['integer', 'distinct', 'exists:pedidos,id'],
            'cliente_id' => ['required', 'integer'],
        ], ['pedido_ids.*.exists' => 'Uno de los pedidos no existe.']);
        $pedidos = Pedido::whereIn('id', $datos['pedido_ids'])->get();
        foreach ($pedidos as $pedido) {
            $this->authorize('cobrar', $pedido);
        }
        $cliente = \App\Models\User::where('role', 'cliente')->where('activo', true)->whereNull('eliminado_at')->find($datos['cliente_id']);
        abort_if($cliente === null, 422, 'Ese cliente ya no está disponible.');

        $asignados = $this->pedidoService->asignarCliente($pedidos, $cliente, $request->user());

        return response()->json(['cliente' => $cliente->only(['id', 'nombre']), 'pedido_ids' => $asignados]);
    }
}
