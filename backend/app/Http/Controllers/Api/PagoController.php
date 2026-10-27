<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pago\AnularPagoRequest;
use App\Http\Requests\Api\Pago\RegistrarCobroRequest;
use App\Http\Requests\Api\Pago\RegistrarPagoEnLineaRequest;
use App\Models\Pago;
use App\Models\Pedido;
use App\Services\PagoService;
use Illuminate\Http\Request;

/**
 * Implementa el flujo de cobro de M06:
 * routes/api.php (middleware role:xxx) → Controller → Form Request → Policy → Service → JSON.
 * El ejemplo original del documento de arquitectura ("Administrador cobra un pedido Local
 * en caja") no reflejaba la regla real de M06 — ver PedidoPolicy::cobrar() y
 * RF-M06-003/004, RNF-M06-004.
 */
class PagoController extends Controller
{
    public function __construct(private readonly PagoService $pagoService)
    {
    }

    // POST /api/pedidos/{pedido}/cobrar — cobro presencial, siempre por el Mesero (local,
    // domicilio contra entrega o recogida). El Administrador nunca cobra.
    public function cobrar(RegistrarCobroRequest $request, Pedido $pedido)
    {
        $this->authorize('cobrar', $pedido); // PedidoPolicy::cobrar() — RF-M06-003/004, RNF-M06-004

        // Mejora (26/10/2026, RF-M06-037): la foto del comprobante (obligatoria en transferencia) queda con el pago.
        $data = $request->safe()->only(['metodo', 'monto']);
        if ($request->hasFile('comprobante')) {
            $archivo = app(\App\Services\PagoDirectoService::class)->guardarComprobante($request->file('comprobante'), "cobro-pedido-{$pedido->id}");
            $data['comprobante_path'] = $archivo['path'];
            $data['comprobante_mime'] = $archivo['mime'];
        }
        $pago = $this->pagoService->registrarCobro($pedido, $request->user(), $data);

        // Pedido completo (no solo los pagos): la pantalla Cobrar reemplaza la tarjeta con
        // esta respuesta, y el cobro puede haberlo finalizado (RN-PED-32).
        return response()->json($pedido->fresh(PedidoController::CON_DETALLES));
    }

    // POST /api/pedidos/{pedido}/pagar-en-linea — autoservicio del Cliente (Nequi/Bancolombia).
    public function pagarEnLinea(RegistrarPagoEnLineaRequest $request, Pedido $pedido)
    {
        $this->authorize('pagarEnLinea', $pedido);

        return $this->respuestaPagoEnLinea(...$this->pagoService->iniciarPagoEnLinea($pedido, $request->validated()));
    }

    // POST /api/pedidos/seguimiento/{token}/pagar-en-linea — el invitado paga con el token
    // de su pedido (RF-M06-005, Fase 0 tarea 4). El token reemplaza a la sesión.
    public function pagarEnLineaConToken(RegistrarPagoEnLineaRequest $request, string $token)
    {
        $pedido = Pedido::where('token_publico', $token)->firstOrFail();

        return $this->respuestaPagoEnLinea(...$this->pagoService->iniciarPagoEnLinea($pedido, $request->validated()));
    }

    // Fase 6, tarea 1 (RF-M06-006): el pago, y en "checkout" el enlace de la pasarela a donde el
    // navegador manda al Cliente (null en modo manual: el pago queda en revisión, como antes).
    private function respuestaPagoEnLinea(Pago $pago, ?array $checkout)
    {
        return response()->json($pago->toArray() + ['checkout' => $checkout], 201);
    }

    public function anular(AnularPagoRequest $request, Pago $pago)
    {
        $this->authorize('anular', $pago);

        // Fase 6: con la transacción de la pasarela, para mostrar cómo quedó el reembolso.
        return response()->json($this->pagoService->anular(
            $pago,
            $request->user(),
            $request->motivo,
            $request->input('tipo') ?? 'anulacion',
            $request->input('password_confirmacion'),
        )->load('transaccionPasarela'));
    }

    // POST /api/pagos/{pago}/confirmar-en-linea — Mesero/Administrador aprueban manualmente
    // un pago en línea (mismo patrón que ReservaController::confirmarDepositoEnLinea). Fase 6,
    // tarea 1: con la pasarela activa el pago se confirma solo; esto queda como respaldo (D4) y
    // en la auditoría con quién lo hizo.
    public function confirmarEnLinea(Request $request, Pago $pago)
    {
        $this->authorize('confirmarEnLinea', $pago);

        return response()->json($this->pagoService->confirmarPagoEnLinea($pago, $request->user(), 'manual')->load('transaccionPasarela'));
    }

    public function rechazarEnLinea(Request $request, Pago $pago)
    {
        $this->authorize('rechazarEnLinea', $pago);
        // Pago directo (22/10/2026, RF-M06-033): el motivo lo ve el Cliente en su seguimiento.
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->pagoService->rechazarPagoEnLinea($pago, $request->user(), 'manual', $datos['motivo'] ?? null)->load('transaccionPasarela'));
    }

    // GET /api/pagos/{pago}/comprobante — Pago directo (RF-M06-032): la captura que subió el Cliente,
    // solo para el personal.
    public function comprobante(Request $request, Pago $pago, \App\Services\PagoDirectoService $pagosDirectos)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);
        abort_if(! $pagosDirectos->existe($pago->comprobante_path), 404, 'Este pago no tiene comprobante.');

        return $pagosDirectos->respuesta($pago->comprobante_path, $pago->comprobante_mime, "comprobante-pedido-{$pago->pedido_id}");
    }
}
