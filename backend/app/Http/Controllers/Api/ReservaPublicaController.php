<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reserva;
use App\Services\ReservaService;
use App\Support\Reservas\PoliticaReservas;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * M05 — RF-M05-018, RN-M05-09, HU-M05-011 (Fase 4, tarea 4, 13/10/2026): quien hizo la reserva
 * (también el invitado sin cuenta) la ve y la cancela desde el enlace secreto que llega en los
 * correos. Sin sesión: el enlace es la credencial (48 caracteres al azar, no adivinable).
 *
 * Reglas para cancelar por enlace: la reserva debe estar pendiente de pago o confirmada, y aún no
 * debe haber llegado su hora (RN-M05-17: "antes de su hora"). Si el depósito se pagó en efectivo
 * en el local, se devuelve en el local: el cliente llama o pasa por el restaurante.
 */
class ReservaPublicaController extends Controller
{
    public function __construct(private readonly ReservaService $reservas)
    {
    }

    public function ver(string $token)
    {
        return response()->json($this->presentar($this->buscar($token)));
    }

    public function cancelar(string $token)
    {
        $reserva = $this->buscar($token);
        $motivo = $this->motivoNoCancela($reserva);
        if ($motivo) {
            throw new RuntimeException($motivo);
        }

        $this->reservas->cancelar($reserva, null, true);

        return response()->json($this->presentar($reserva->fresh('mesa')));
    }

    // Fase 6, tarea 1 (RF-M05-030, RF-M06-008): pagar (o volver a intentar) el depósito desde el
    // enlace, por ejemplo después de un rechazo en la pasarela. Mismo camino que la reserva web.
    public function pagarDeposito(Request $request, string $token)
    {
        $pagosDirectos = app(\App\Services\PagoDirectoService::class);
        // Pago directo (22/10/2026, RF-M06-031): la captura del comprobante y la referencia.
        $datos = $request->validate(['pasarela' => ['required', 'in:nequi,bancolombia,breb'], ...$pagosDirectos::reglasComprobante()],
            $pagosDirectos::mensajesComprobante());
        if ($error = $pagosDirectos->errorDeMedio($datos['pasarela'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['pasarela' => [$error]]);
        }
        $reserva = $this->buscar($token);
        if (! $this->puedePagar($reserva)) {
            throw new RuntimeException('Esta reserva ya no espera el pago del depósito.');
        }

        [$reserva, $checkout] = $this->reservas->iniciarDepositoEnLinea($reserva, $datos['pasarela'], $request->file('comprobante'), $datos['referencia'] ?? null);

        return response()->json($this->presentar($reserva->fresh('mesa')) + ['checkout' => $checkout]);
    }

    // La regla vive en ReservaService (también la usa «Mis pedidos y reservas»).
    private function puedePagar(Reserva $reserva): bool
    {
        return $this->reservas->puedePagarDeposito($reserva);
    }

    private function buscar(string $token): Reserva
    {
        abort_if(strlen($token) < 20, 404, 'No encontramos esa reserva.');
        $reserva = Reserva::with('mesa')->where('token_publico', $token)->first();
        abort_if(! $reserva, 404, 'No encontramos esa reserva. Revisa que el enlace esté completo.');

        return $reserva;
    }

    private function motivoNoCancela(Reserva $reserva): ?string
    {
        return match (true) {
            ! in_array($reserva->estado, ['pendiente_pago', 'confirmada'], true) => 'Esta reserva ya no se puede cancelar.',
            now()->gte($reserva->fecha_hora) => 'La hora de la reserva ya pasó; ya no se puede cancelar desde aquí.',
            $reserva->deposito_pagado && $reserva->deposito_metodo === 'efectivo' => 'Pagaste el depósito en efectivo en el local: para cancelarla y recibir tu dinero, llama o pasa por el restaurante.',
            default => null,
        };
    }

    private function presentar(Reserva $reserva): array
    {
        $motivo = $this->motivoNoCancela($reserva);

        return [
            'cliente_nombre' => $reserva->cliente_nombre,
            'mesa' => $reserva->mesa?->nombre,
            'descripcion' => $reserva->descripcion,
            'fecha_hora' => $reserva->fecha_hora->toIso8601String(),
            'estado' => $reserva->estado,
            'deposito_valor' => (float) $reserva->deposito_valor,
            'deposito_pagado' => (bool) $reserva->deposito_pagado,
            'deposito_reembolsado' => (bool) $reserva->deposito_reembolsado,
            'tolerancia_minutos' => PoliticaReservas::toleranciaMinutos(),
            'puede_cancelar' => $motivo === null,
            'motivo_no_cancela' => $motivo,
            // Fase 6, tarea 1: pagar el depósito desde aquí, y en qué va el último intento.
            'puede_pagar' => $this->puedePagar($reserva),
            'deposito_estado' => $reserva->deposito_estado,
            // Pago directo (22/10/2026): si ya subió el comprobante y, si se lo rechazaron, por qué.
            'deposito_pasarela' => $reserva->deposito_pasarela,
            'deposito_tiene_comprobante' => $reserva->deposito_comprobante_path !== null,
            'deposito_rechazo_motivo' => $reserva->deposito_estado === 'rechazado' ? $reserva->deposito_rechazo_motivo : null,
        ];
    }
}
