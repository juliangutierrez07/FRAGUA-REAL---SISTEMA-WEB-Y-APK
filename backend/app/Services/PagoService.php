<?php

namespace App\Services;

use App\Models\AjusteCaja;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * M06 (Caja y Ventas): regla de cobro RF-M06-003, RF-M06-004, RNF-M06-004.
 * Responsabilidad (documento de arquitectura, sección 4): registrar pagos —incluido pago
 * mixto—, decidir quién puede cobrar, y anular pagos.
 *
 * La autorización HTTP vive en PagoPolicy; este Service la vuelve a aplicar como
 * defensa en profundidad para que también sea válida si algo la invoca fuera de un
 * request HTTP (consola, colas).
 */
class PagoService
{
    public function __construct(
        private readonly PedidoService $pedidoService,
        private readonly CajaService $cajaService,
    ) {
    }

    /**
     * Registra un cobro presencial (efectivo/tarjeta/transferencia) hecho por el Mesero.
     * $actor es quien cobra: siempre el Mesero, sin importar el canal (local, domicilio
     * contra entrega o recogida). El Administrador nunca cobra (RNF-M06-004).
     */
    public function registrarCobro(Pedido $pedido, User $actor, array $data): Pago
    {
        $this->validarQuienCobra($pedido, $actor);

        $metodo = $data['metodo'];
        if ($metodo === 'en_linea') {
            throw new RuntimeException('Un cobro presencial no puede usar el método en_linea; usa registrarPagoEnLinea().');
        }

        // RF-M06-004, RN-M06-06, CA-M06-003: el pago contra entrega de un domicilio se
        // registra después de confirmar la entrega.
        if ($pedido->esDomicilio() && $pedido->entregado_at === null) {
            throw new RuntimeException('Primero confirma la entrega del domicilio; el pago contra entrega se registra después (RF-M06-004).');
        }

        $turno = $this->cajaService->turnoAbierto();
        if (! $turno) {
            throw new RuntimeException('No hay un turno de caja abierto para registrar el cobro (RN-M06-03).');
        }

        return $this->crearPago($pedido, [
            ...$data,
            'metodo' => $metodo,
            'caja_turno_id' => $turno->id,
            'estado' => 'aprobado',
            'registrado_por' => $actor->id,
        ]);
    }

    /**
     * Pago en línea (Nequi/Bancolombia): autoservicio del Cliente, sin empleado de por medio.
     * `registrado_por` queda en NULL (ADR-004). El pago nace 'pendiente'.
     *
     * Fase 6, tarea 1 (RF-M06-006, D4): con la pasarela activa, además se crea el intento en Wompi
     * y la respuesta trae el enlace del checkout (PasarelaPagoService::iniciar); el pago se
     * confirma solo con el evento firmado. Sin pasarela (modo manual, el respaldo), lo aprueba el
     * Mesero o el Administrador como antes.
     *
     * @return array{0: Pago, 1: array|null} el pago y, si hay pasarela, el checkout
     */
    public function iniciarPagoEnLinea(Pedido $pedido, array $data): array
    {
        $pasarela = app(PasarelaPagoService::class);

        // RF-M06-008: si hay un intento anterior en la pasarela que sigue pendiente, se pregunta
        // por él antes de abrir otro (se cierra si no se pagó; si sigue en curso, se avisa).
        $anterior = $this->pagoEnLineaPendiente($pedido);
        if ($anterior && $pasarela->activa() && ($tx = $anterior->transaccionPasarela) && ! $tx->esFinal()) {
            $pasarela->liberarParaReintento($tx);
            $pedido->refresh();
        }

        // Se cobra exactamente lo que falta: el monto no lo decide el navegador (con Wompi y, desde el
        // 22/10/2026, también en el pago directo, que muestra ese monto para transferirlo).
        $data['monto'] = round((float) $pedido->total - $pedido->totalPagado(), 2);
        $comprobante = $data['comprobante'] ?? null;
        unset($data['comprobante']);

        $pago = $this->registrarPagoEnLinea($pedido, $data);
        if (! $pasarela->activa()) {
            // Pago directo (RF-M06-031/032): la captura queda con el pago y el personal recibe el aviso.
            if ($comprobante instanceof \Illuminate\Http\UploadedFile) {
                $archivo = app(PagoDirectoService::class)->guardarComprobante($comprobante, "pedido-{$pedido->id}");
                $pago->forceFill([
                    'comprobante_path' => $archivo['path'],
                    'comprobante_mime' => $archivo['mime'],
                    'referencia_cliente' => filled($data['referencia'] ?? null) ? trim($data['referencia']) : null,
                ])->save();
                app(NotificacionService::class)->pagoPorConfirmar($pago);
            }

            return [$pago, null];
        }

        $checkout = $pasarela->iniciar($pago, (float) $pago->monto, $pago->pasarela, [
            'correo' => $pedido->correoDeContacto(),
            'nombre' => $pedido->cliente?->nombre ?? $pedido->cliente_invitado_nombre,
            'telefono' => $pedido->telefono_contacto ?? $pedido->cliente_invitado_telefono,
        ]);

        return [$pago, $checkout];
    }

    public function registrarPagoEnLinea(Pedido $pedido, array $data): Pago
    {
        // CORREGIDO (Fase 0, tarea 4, 09/10/2026): RF-M06-005/008. El pago en línea es para
        // domicilio y recogida mientras el pedido está pendiente, y solo puede haber un
        // intento en curso a la vez: antes se podían acumular varios pagos pendientes del
        // mismo pedido (por ejemplo, al reintentar desde el Checkout).
        if (! $pedido->esDomicilio() && ! $pedido->esRecogida()) {
            throw new RuntimeException('El pago en línea es solo para domicilios y recogidas (RN-M07-07).');
        }
        if ($pedido->estado !== 'pendiente') {
            throw new RuntimeException('Este pedido ya no está pendiente; no admite un pago en línea nuevo.');
        }
        if ($this->pagoEnLineaPendiente($pedido)) {
            throw new RuntimeException('Ya hay un pago en línea en revisión para este pedido. Espera su aprobación o rechazo.');
        }

        // RF-M07-015, RF-M07-R-008: el pedido queda con el método y la pasarela aplicados.
        $pedido->forceFill(['metodo_pago_preferido' => 'en_linea', 'pasarela_preferida' => $data['pasarela']])->save();

        return $this->crearPago($pedido, [
            ...$data,
            'metodo' => 'en_linea',
            'pasarela' => $data['pasarela'],
            'caja_turno_id' => null,
            'estado' => $data['estado'] ?? 'pendiente',
            'registrado_por' => null,
        ]);
    }

    private function pagoEnLineaPendiente(Pedido $pedido): ?Pago
    {
        return $pedido->pagos()
            ->where('metodo', 'en_linea')
            ->where('estado', 'pendiente')
            ->where('anulado', false)
            ->latest('id')
            ->first();
    }

    /**
     * Confirma un pago en línea que estaba pendiente: lo hace la pasarela (Fase 6, $via =
     * 'pasarela', sin persona) o, como respaldo, el Mesero o el Administrador ($via = 'manual').
     *
     * CORREGIDO (29/09/2026): este método ya existía pero nada lo invocaba; ver
     * PagoController::confirmarEnLinea()/rechazarEnLinea() y routes/api.php.
     * Fase 6, tarea 1: queda en la auditoría quién o qué lo aprobó.
     */
    public function confirmarPagoEnLinea(Pago $pago, ?User $actor = null, string $via = 'manual'): Pago
    {
        if ($pago->metodo !== 'en_linea') {
            throw new RuntimeException('Solo un pago en_linea se confirma con este método.');
        }
        if ($pago->estado !== 'pendiente') {
            throw new RuntimeException('Este pago no está pendiente de confirmar.');
        }

        return DB::transaction(function () use ($pago, $actor, $via) {
            $pago->estado = 'aprobado';
            $pago->confirmado_via = $via;
            $pago->confirmado_por = $actor?->id;
            $pago->save();

            $this->auditarEnLinea('confirmar_pago_en_linea', $pago, $actor, $via);
            app(NotificacionService::class)->pagoEnLinea($pago, true, $actor); // M11
            $this->finalizarSiQuedoCompleto($pago->pedido, null);

            return $pago;
        });
    }

    // Mismo patrón que ReservaService::rechazarDepositoEnLinea(): el pedido no se cancela
    // solo, sigue 'pendiente' sin un pago válido — el Cliente puede reintentar el pago en
    // línea (RF-M06-008) o el Mesero puede cobrarlo presencial en su lugar.
    public function rechazarPagoEnLinea(Pago $pago, ?User $actor = null, string $via = 'manual', ?string $motivo = null): Pago
    {
        if ($pago->metodo !== 'en_linea') {
            throw new RuntimeException('Solo un pago en_linea se rechaza con este método.');
        }
        if ($pago->estado !== 'pendiente') {
            throw new RuntimeException('Este pago no está pendiente de rechazar.');
        }

        $pago->estado = 'rechazado';
        $pago->confirmado_via = $via;
        $pago->confirmado_por = $actor?->id;
        // Pago directo (RF-M06-033): el Cliente ve por qué no se aceptó su comprobante.
        $pago->rechazo_motivo = filled($motivo) ? mb_substr(trim($motivo), 0, 255) : null;
        $pago->save();

        $this->auditarEnLinea('rechazar_pago_en_linea', $pago, $actor, $via, $motivo);
        app(NotificacionService::class)->pagoEnLinea($pago, false, $actor); // M11

        return $pago;
    }

    private function auditarEnLinea(string $accion, Pago $pago, ?User $actor, string $via, ?string $motivo = null): void
    {
        $tx = $pago->transaccionPasarela()->first();
        app(AuditoriaService::class)->registrar($accion, 'Pago', $pago->id, array_filter([
            'pedido_id' => $pago->pedido_id,
            'monto' => (float) $pago->monto,
            'pasarela' => $pago->pasarela,
            'via' => $via,
            'referencia' => $tx?->referencia,
            'metodo_pasarela' => $tx?->metodo_pago,
            'motivo' => $motivo,
        ], fn ($v) => $v !== null), $via === 'pasarela' ? null : $actor);
    }

    /**
     * Fase 2, tarea 2 (RF-M05-034, RN-M05-19, D7): abono del depósito de una reserva a un pedido de
     * la visita. Es un pago aprobado de tipo "deposito", con el método con el que se pagó el
     * depósito y SIN turno de caja: ese dinero ya entró a la caja cuando se recibió el depósito (o
     * por la pasarela). Lo llama AbonoDepositoService; nadie lo registra a mano.
     */
    public function registrarAbonoDeposito(Pedido $pedido, \App\Models\Reserva $reserva, float $monto, ?User $actor = null): Pago
    {
        return $this->crearPago($pedido, [
            'tipo' => 'deposito',
            'reserva_id' => $reserva->id,
            'metodo' => $reserva->deposito_metodo ?: 'efectivo',
            'pasarela' => $reserva->deposito_metodo === 'en_linea' ? $reserva->deposito_pasarela : null,
            'monto' => $monto,
            'caja_turno_id' => null,
            'estado' => 'aprobado',
            'registrado_por' => $actor?->id,
        ]);
    }

    private function crearPago(Pedido $pedido, array $data): Pago
    {
        return DB::transaction(function () use ($pedido, $data) {
            $monto = (float) $data['monto'];

            if ($monto <= 0) {
                throw new RuntimeException('El monto del pago debe ser mayor a 0.');
            }

            // RF-M06-011: la suma de pagos no anulados de un pedido nunca puede exceder su total.
            $yaPagado = $pedido->totalPagado();
            if (round($yaPagado + $monto, 2) > round((float) $pedido->total, 2)) {
                throw new RuntimeException('La suma de los pagos superaría el total del pedido (RF-M06-011).');
            }

            $pago = new Pago();
            $pago->pedido_id = $pedido->id;
            $pago->tipo = $data['tipo'] ?? 'cobro';
            $pago->reserva_id = $data['reserva_id'] ?? null;
            $pago->caja_turno_id = $data['caja_turno_id'];
            $pago->metodo = $data['metodo'];
            $pago->pasarela = $data['pasarela'] ?? null;
            $pago->monto = $monto;
            $pago->estado = $data['estado'];
            $pago->registrado_por = $data['registrado_por'];
            // Mejora (26/10/2026): la foto del comprobante que tomó el Mesero al cobrar.
            $pago->comprobante_path = $data['comprobante_path'] ?? null;
            $pago->comprobante_mime = $data['comprobante_mime'] ?? null;
            $pago->anulado = false;
            $pago->save();

            if ($pago->metodo === 'efectivo' && $pago->caja_turno_id) {
                $this->cajaService->recalcularEfectivoEsperado($pago->cajaTurno);
            }

            if ($pago->estado === 'aprobado') {
                $actorFinaliza = $data['registrado_por'] ? \App\Models\User::find($data['registrado_por']) : null;
                $this->finalizarSiQuedoCompleto($pedido, $actorFinaliza);
            }

            return $pago;
        });
    }

    // CORREGIDO (Fase 0, 09/10/2026): antes finalizaba con solo tener el pago completo, en
    // cualquier estado. Ahora delega en PedidoService::intentarFinalizar(), que además exige
    // PREPARANDO y, en domicilio y recogida, la entrega o la recogida confirmada (RN-PED-32).
    private function finalizarSiQuedoCompleto(Pedido $pedido, ?User $actor): void
    {
        $this->pedidoService->intentarFinalizar($pedido, $actor);
    }

    /**
     * RF-M06-003, RF-M06-004, RNF-M06-004: solo el Mesero registra el cobro presencial de
     * un pedido, sin importar el canal (local, domicilio contra entrega o recogida). El
     * Administrador nunca cobra — solo abre/cierra caja y autoriza anulaciones. El pago en
     * línea no pasa por aquí (lo hace el Cliente directo).
     */
    public function validarQuienCobra(Pedido $pedido, User $actor): void
    {
        $rolEsperado = $pedido->actorQueCobra();

        if ($rolEsperado === 'mesero' && ! $actor->esMesero()) {
            throw new RuntimeException('Solo el Mesero puede registrar cobros presenciales — local, domicilio contra entrega o recogida (RF-M06-003, RF-M06-004, RNF-M06-004).');
        }
    }

    /**
     * RF-M06-017/018/019, RN-M06-10: el Administrador anula o reembolsa un pago con motivo.
     * - Caja del día abierta: el pago deja de contar y el efectivo esperado se recalcula.
     * - Caja del día ya cerrada: exige autorización adicional (la contraseña del
     *   Administrador) y queda como ajuste extraordinario; las cifras del turno cerrado no
     *   se reescriben.
     *
     * CORREGIDO (Fase 0, tarea 3, 09/10/2026): antes recalculaba el efectivo esperado del
     * turno aunque ya estuviera cerrado, sin pedir nada, y cambiaba la cifra de un arqueo
     * que ya se había hecho.
     */
    /**
     * RN-M06-15: un pedido finalizado que quedó sin pagar completo por una anulación vuelve a «en
     * preparación» (sigue entregado o recogido) para cobrar el saldo. Devuelve el saldo, o null si
     * no hubo que reabrirlo.
     */
    private function reabrirSiQuedoConSaldo(Pago $pago, User $actor, string $motivo): ?float
    {
        $pedido = $pago->pedido()->first();
        if (! $pedido || $pedido->estado !== 'finalizado' || $pedido->pagoCompleto()) {
            return null;
        }
        $saldo = round((float) $pedido->total - $pedido->totalPagado(), 2);

        $pedido->forceFill(['estado' => 'preparando', 'finalizado_por' => null, 'finalizado_at' => null])->save();
        app(AuditoriaService::class)->registrar('reabrir_pedido', 'Pedido', $pedido->id, [
            'motivo' => 'pago anulado',
            'pago_id' => $pago->id,
            'motivo_anulacion' => $motivo,
            'saldo' => $saldo,
        ], $actor);
        app(NotificacionService::class)->pedidoReabierto($pedido, $saldo, $actor);

        return $saldo;
    }

    public function anular(Pago $pago, User $actor, string $motivo, string $tipo = 'anulacion', ?string $passwordConfirmacion = null): Pago
    {
        if ($pago->anulado) {
            throw new RuntimeException('Este pago ya está anulado.');
        }
        if (! in_array($tipo, ['anulacion', 'reembolso'], true)) {
            throw new RuntimeException('El tipo debe ser anulacion o reembolso.');
        }

        // El turno del día del pago: el suyo si se cobró en caja, o el del día en que se
        // registró si fue en línea (los pagos en línea no llevan caja_turno_id, ADR-004).
        // Fase 2, tarea 2: el abono de un depósito no es dinero de ninguna caja (entró cuando se
        // recibió el depósito): anularlo no toca turnos ni deja ajuste extraordinario, y su valor
        // vuelve a quedar por abonar en la reserva.
        $esAbono = $pago->esAbonoDeDeposito();
        $turno = $esAbono ? null : ($pago->cajaTurno ?? $this->cajaService->turnoDeFecha($pago->created_at));
        $cajaCerrada = $turno !== null && ! $turno->estaAbierta();

        if ($cajaCerrada) {
            if (empty($passwordConfirmacion) || ! Hash::check($passwordConfirmacion, $actor->password)) {
                throw new RuntimeException('La caja de ese día ya cerró: confirma tu contraseña para registrar el ajuste extraordinario (RF-M06-019).');
            }
        }

        $pago = DB::transaction(function () use ($pago, $actor, $motivo, $tipo, $turno, $cajaCerrada, $esAbono) {
            $pago->anulado = true;
            $pago->reverso_tipo = $tipo;
            $pago->anulado_motivo = $motivo;
            $pago->anulado_por = $actor->id;
            $pago->anulado_at = now();
            $pago->save();

            if ($esAbono) {
                app(AbonoDepositoService::class)->devolverAReserva($pago);
            }

            if ($cajaCerrada) {
                AjusteCaja::create([
                    'caja_turno_id' => $turno->id,
                    'pago_id' => $pago->id,
                    'tipo' => $tipo,
                    'metodo' => $pago->metodo,
                    'monto' => $pago->monto,
                    'motivo' => $motivo,
                    'autorizado_por' => $actor->id,
                ]);
            } elseif ($pago->metodo === 'efectivo' && $pago->caja_turno_id) {
                // RF-M06-018: anular un pago en efectivo del mismo día ajusta el esperado.
                $this->cajaService->recalcularEfectivoEsperado($pago->cajaTurno);
            }

            // RF-M06-020, RNF-M06-003: toda anulación o reembolso queda auditada.
            app(AuditoriaService::class)->registrar($tipo === 'reembolso' ? 'reembolsar_pago' : 'anular_pago', 'Pago', $pago->id, [
                'motivo' => $motivo,
                'monto' => (float) $pago->monto,
                'metodo' => $pago->metodo,
                'pedido_id' => $pago->pedido_id,
                'abono_de_reserva' => $esAbono ? $pago->reserva_id : null,
                'ajuste_extraordinario' => $cajaCerrada,
            ], $actor);

            app(NotificacionService::class)->pagoAnulado($pago, $actor); // M11

            // Decisión del negocio (22/10/2026, RN-M06-15): si se ANULA un pago (un error de
            // registro) y el pedido ya estaba finalizado, al quedar con saldo vuelve a «en
            // preparación» para cobrarle lo que falta; con el pago completo se vuelve a finalizar
            // solo. Un REEMBOLSO no lo reabre: ahí se le devolvió el dinero al cliente a propósito.
            $saldo = $tipo === 'anulacion' ? $this->reabrirSiQuedoConSaldo($pago, $actor, $motivo) : null;
            $pago->setAttribute('pedido_reabierto', $saldo !== null ? ['pedido_id' => $pago->pedido_id, 'saldo' => $saldo] : null);

            return $pago;
        });

        // Fase 6, tarea 1 (RF-M06-017): el reembolso de un pago en línea aprobado por la pasarela se
        // pide también a la pasarela, después de guardar la anulación (si la pasarela falla o no lo
        // permite para ese medio, el pago igual queda reembolsado en el sistema y la transacción
        // dice qué hacer). La anulación (un error de registro) no mueve dinero y no la toca.
        if ($tipo === 'reembolso' && $pago->metodo === 'en_linea' && ! $esAbono) {
            $tx = $pago->transaccionPasarela()->first();
            if ($tx && $tx->estado === 'aprobado') {
                app(PasarelaPagoService::class)->reembolsar($tx, $actor);
            }
        }

        return $pago;
    }
}
