<?php

namespace App\Services;

use App\Models\Mesa;
use App\Models\Reserva;
use App\Models\User;
use App\Support\Reservas\PoliticaReservas;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * M05 — Mesas y reservas con depósito obligatorio (RN-M05-14 a 19, Idea de Negocio:
 * "con depósito obligatorio"). Dos formas de pagar el depósito:
 * - En línea (RN-M05-16): el Cliente paga por Nequi/Bancolombia reutilizando el mismo
 *   mecanismo de pasarela de M06 (confirmar/rechazar/reintentar) aplicado a la reserva
 *   en vez de a un pedido — ver PagoService::registrarPagoEnLinea().
 * - Manual (RN-M05-16): el Mesero o el Administrador registran el pago con cualquier
 *   método de M06 (efectivo, tarjeta, transferencia).
 */
class ReservaService
{
    /**
     * Crea la reserva en estado pendiente_pago. El depósito exigido se copia como valor
     * vigente (RF-M05-028); igual que valor_domicilio en M07, el valor "vigente" todavía no
     * tiene una tabla `configuraciones` de la que leerse (ver README de la base de datos),
     * así que se recibe como parámetro hasta que esa tabla exista.
     */
    public function crear(array $data, float $depositoVigente, ?User $registradaPor = null): Reserva
    {
        // RN-M05-05 a 07 (Fase 1, tarea 4): solo mesas activas, y sin cruzarse con otra reserva
        // que ya aparta la mesa a esa hora.
        $mesa = Mesa::find($data['mesa_id']);
        if (! $mesa || ! $mesa->activa) {
            throw new RuntimeException('La mesa no existe o está desactivada.');
        }
        $fechaHora = Carbon::parse($data['fecha_hora']);
        if ($otra = $this->choque($mesa->id, $fechaHora)) {
            throw new RuntimeException("{$mesa->nombre} ya tiene una reserva a las {$this->hora($otra->fecha_hora)}. Elige otra hora u otra mesa.");
        }

        $reserva = new Reserva();
        $reserva->mesa_id = $data['mesa_id'];
        $reserva->cliente_nombre = $data['cliente_nombre'];
        $reserva->cliente_telefono = $data['cliente_telefono'];
        $reserva->cliente_correo = $data['cliente_correo'];
        // Corrección (19/10/2026): nota opcional del cliente para el restaurante.
        $reserva->descripcion = filled($data['descripcion'] ?? null) ? trim($data['descripcion']) : null;
        $reserva->fecha_hora = $data['fecha_hora'];
        $reserva->estado = 'pendiente_pago';
        $reserva->deposito_valor = $depositoVigente;
        $reserva->deposito_pagado = false;
        $reserva->deposito_reembolsado = false;
        $reserva->deposito_aplicado_abono = false;
        $reserva->creado_por = $data['creado_por']; // cliente_web | mesero | administrador
        $reserva->registrada_por = $registradaPor?->id;
        $reserva->save();

        if ($registradaPor) {
            app(AuditoriaService::class)->registrar('crear_reserva_manual', 'Reserva', $reserva->id, [
                'mesa' => $mesa->nombre,
                'fecha_hora' => $reserva->fecha_hora->toIso8601String(),
            ], $registradaPor);
        }
        app(NotificacionService::class)->reservaNueva($reserva->setRelation('mesa', $mesa), $registradaPor); // M11

        return $reserva;
    }

    /**
     * La reserva que ya aparta esta mesa a una hora que se cruza con $fechaHora, si existe.
     * Dos reservas se cruzan si empiezan a menos de `duracion_reserva_minutos` una de la otra.
     */
    public function choque(int $mesaId, CarbonInterface $fechaHora, ?int $excepto = null): ?Reserva
    {
        $duracion = (int) config('fragua_real.duracion_reserva_minutos', 120);

        return Reserva::queApartanMesa()
            ->where('mesa_id', $mesaId)
            ->when($excepto, fn ($q) => $q->where('id', '!=', $excepto))
            ->where('fecha_hora', '>', $fechaHora->copy()->subMinutes($duracion))
            ->where('fecha_hora', '<', $fechaHora->copy()->addMinutes($duracion))
            ->orderBy('fecha_hora')
            ->first();
    }

    /** @return Collection<int, Mesa> mesas activas sin reservas que se crucen con $fechaHora */
    public function mesasDisponibles(CarbonInterface $fechaHora): Collection
    {
        return Mesa::where('activa', true)->orderBy('nombre')->get()
            ->reject(fn (Mesa $mesa) => $this->choque($mesa->id, $fechaHora) !== null)
            ->values();
    }

    private function hora(CarbonInterface $momento): string
    {
        return $momento->copy()->setTimezone('America/Bogota')->format('g:i a');
    }

    // Al confirmar el depósito se vuelve a mirar: dos reservas web pudieron pedir la misma hora
    // sin pagar; la primera que paga se queda con la mesa.
    private function validarSinChoque(Reserva $reserva): void
    {
        if ($otra = $this->choque($reserva->mesa_id, $reserva->fecha_hora, $reserva->id)) {
            if ($otra->estado === 'confirmada') {
                throw new RuntimeException("La mesa ya quedó reservada a las {$this->hora($otra->fecha_hora)} por otra persona. Cancela esta reserva o cámbiala de mesa.");
            }
        }
    }

    /**
     * RN-M05-16: el Cliente inicia el pago del depósito en línea. Deja el depósito en
     * 'pendiente' — la reserva sigue pendiente_pago hasta que se confirme, igual que
     * PagoService::registrarPagoEnLinea() con un pedido.
     */
    /**
     * Fase 6, tarea 1 (RF-M05-030): el depósito usa el mismo camino que el pago de un pedido. Con la
     * pasarela activa, se crea el intento en Wompi y se devuelve el enlace del checkout; el depósito
     * se confirma solo con el evento firmado. Sin pasarela, queda en revisión como antes.
     *
     * @return array{0: Reserva, 1: array|null}
     */
    public function iniciarDepositoEnLinea(Reserva $reserva, string $pasarela, ?\Illuminate\Http\UploadedFile $comprobante = null, ?string $referencia = null): array
    {
        $servicio = app(PasarelaPagoService::class);

        if ($servicio->activa() && $reserva->deposito_estado === 'pendiente' && ($tx = $reserva->transaccionDeposito) && ! $tx->esFinal()) {
            $servicio->liberarParaReintento($tx);
            $reserva->refresh();
        }

        $reserva = $this->registrarDepositoEnLinea($reserva, $pasarela);
        if (! $servicio->activa()) {
            // Pago directo (22/10/2026, RF-M06-031/032): la captura queda con el depósito y avisa al personal.
            if ($comprobante) {
                $archivo = app(PagoDirectoService::class)->guardarComprobante($comprobante, "reserva-{$reserva->id}");
                $reserva->forceFill([
                    'deposito_comprobante_path' => $archivo['path'],
                    'deposito_comprobante_mime' => $archivo['mime'],
                    'deposito_referencia' => filled($referencia) ? trim($referencia) : null,
                ])->save();
                app(NotificacionService::class)->depositoPorConfirmar($reserva);
            }

            return [$reserva, null];
        }

        return [$reserva, $servicio->iniciar($reserva, (float) $reserva->deposito_valor, $pasarela, [
            'correo' => $reserva->cliente_correo,
            'nombre' => $reserva->cliente_nombre,
            'telefono' => $reserva->cliente_telefono,
        ])];
    }

    public function registrarDepositoEnLinea(Reserva $reserva, string $pasarela): Reserva
    {
        if ($reserva->estado !== 'pendiente_pago') {
            throw new RuntimeException('Solo una reserva pendiente de pago puede iniciar el depósito.');
        }

        $reserva->deposito_pasarela = $pasarela;
        $reserva->deposito_estado = 'pendiente';
        $reserva->deposito_metodo = 'en_linea';
        // Un intento nuevo empieza sin la captura ni el motivo de rechazo del anterior.
        $reserva->deposito_comprobante_path = null;
        $reserva->deposito_comprobante_mime = null;
        $reserva->deposito_referencia = null;
        $reserva->deposito_rechazo_motivo = null;
        $reserva->save();

        return $reserva;
    }

    /**
     * Confirma el depósito en línea. Fase 6, tarea 1: lo hace la pasarela con su evento firmado
     * ($via = 'pasarela', PasarelaPagoService) o, de respaldo, el Administrador o el Mesero ('manual').
     * La mesa se marca 'reservada' recién aquí, no al crear la reserva — así no se
     * bloquea una mesa por una reserva sin pagar.
     */
    public function confirmarDepositoEnLinea(Reserva $reserva, ?User $actor = null, string $via = 'manual'): Reserva
    {
        if ($reserva->estado !== 'pendiente_pago' || $reserva->deposito_estado !== 'pendiente') {
            throw new RuntimeException('Esta reserva no tiene un depósito en línea pendiente de confirmar.');
        }
        $this->validarSinChoque($reserva);

        $reserva->deposito_pagado = true;
        $reserva->deposito_estado = 'aprobado';
        $reserva->deposito_confirmado_via = $via; // Fase 6: 'pasarela' (evento firmado) o 'manual'
        $reserva->deposito_pagado_at = now();
        $reserva->estado = 'confirmada';
        $reserva->save();

        $this->marcarMesaReservada($reserva);
        // Fase 4, tarea 4 (RF-M05-023): correo de confirmación con el enlace de la reserva.
        app(AvisoReservaService::class)->programar($reserva, 'confirmacion');

        // RF-M05-036: cada confirmación, rechazo o registro manual de un depósito queda auditado.
        app(AuditoriaService::class)->registrar('confirmar_deposito_reserva', 'Reserva', $reserva->id, [
            'monto' => (float) $reserva->deposito_valor, 'pasarela' => $reserva->deposito_pasarela, 'via' => $via,
        ], $actor);
        app(NotificacionService::class)->reservaConfirmada($reserva, $actor); // M11

        return $reserva;
    }

    // Rechaza el depósito en línea; la reserva sigue pendiente_pago para que el Cliente
    // pueda reintentar (mismo patrón de M06: confirmar/rechazar/reintentar).
    public function rechazarDepositoEnLinea(Reserva $reserva, ?User $actor = null, string $via = 'manual', ?string $motivo = null): Reserva
    {
        if ($reserva->estado !== 'pendiente_pago' || $reserva->deposito_estado !== 'pendiente') {
            throw new RuntimeException('Esta reserva no tiene un depósito en línea pendiente de rechazar.');
        }

        $reserva->deposito_estado = 'rechazado';
        $reserva->deposito_confirmado_via = $via;
        // Pago directo (RF-M06-033): el Cliente ve el motivo en el enlace de su reserva.
        $reserva->deposito_rechazo_motivo = filled($motivo) ? mb_substr(trim($motivo), 0, 255) : null;
        $reserva->save();

        app(AuditoriaService::class)->registrar('rechazar_deposito_reserva', 'Reserva', $reserva->id, [
            'monto' => (float) $reserva->deposito_valor, 'pasarela' => $reserva->deposito_pasarela, 'via' => $via,
        ], $actor);

        return $reserva;
    }

    /**
     * Fase 6, tarea 1: el Cliente puede pagar (o volver a intentar) el depósito de su reserva web
     * mientras esté pendiente de pago y no haya llegado la hora. En modo manual, un depósito ya
     * registrado espera la aprobación del personal: no se repite.
     * Mejora (26/10/2026, RF-M05-044, RN-M05-25): también lo usa «Mis pedidos y reservas» (antes solo el enlace del correo).
     */
    public function puedePagarDeposito(Reserva $reserva): bool
    {
        $esperandoAprobacion = $reserva->deposito_estado === 'pendiente' && ! app(PasarelaPagoService::class)->activa();

        return $reserva->estado === 'pendiente_pago' && $reserva->creado_por === 'cliente_web'
            && now()->lt($reserva->fecha_hora) && ! $esperandoAprobacion;
    }

    // RN-M05-16: depósito manual registrado por el Mesero o el Administrador, con
    // cualquier método de cobro de M06.
    // Mejora (26/10/2026, RF-M05-045 y 046, RN-M05-26): en efectivo se puede indicar con cuánto pagó el cliente (queda en la
    // auditoría con el cambio entregado); en transferencia o tarjeta, la foto del comprobante.
    public function registrarDepositoManual(Reserva $reserva, string $metodo, User $registradoPor, ?float $recibido = null, ?\Illuminate\Http\UploadedFile $comprobante = null): Reserva
    {
        if ($reserva->estado !== 'pendiente_pago') {
            throw new RuntimeException('Solo una reserva pendiente de pago puede confirmarse.');
        }
        // RN-M05-16: el depósito manual es presencial (efectivo, tarjeta o transferencia).
        if (! in_array($metodo, ['efectivo', 'tarjeta', 'transferencia'], true)) {
            throw new RuntimeException('El depósito manual se registra en efectivo, tarjeta o transferencia (RN-M05-16).');
        }
        $valor = (float) $reserva->deposito_valor;
        if ($metodo !== 'efectivo') {
            $recibido = null;
        } elseif ($recibido !== null && round($recibido, 2) < round($valor, 2)) {
            throw new RuntimeException('Lo que entregó el cliente ($'.number_format($recibido, 0, ',', '.').') no alcanza para el depósito ($'.number_format($valor, 0, ',', '.').').');
        }
        $this->validarSinChoque($reserva);

        // Fase 2, tarea 2 (RN-M06-02, RN-M06-08): el depósito manual es dinero que entra a la caja
        // del día, así que, como cualquier cobro, exige la caja abierta; en efectivo suma al
        // efectivo esperado de ese turno.
        $cajaService = app(CajaService::class);
        $turno = $cajaService->turnoAbierto();
        if (! $turno) {
            throw new RuntimeException('No hay un turno de caja abierto: el depósito entra a la caja del día (RN-M06-02).');
        }

        $reserva->deposito_pagado = true;
        $reserva->deposito_metodo = $metodo;
        $reserva->deposito_caja_turno_id = $turno->id;
        // Un depósito manual no pasa por una pasarela, aunque el método coincida con
        // "en_linea" en el catálogo de M06.
        $reserva->deposito_pasarela = null;
        $reserva->deposito_pagado_at = now();
        $reserva->deposito_registrado_por = $registradoPor->id;
        $reserva->estado = 'confirmada';
        // Un registro manual no lleva la captura de un intento en línea anterior.
        $reserva->deposito_comprobante_path = null;
        $reserva->deposito_comprobante_mime = null;
        $reserva->deposito_referencia = null;
        if ($comprobante && $metodo !== 'efectivo') {
            $archivo = app(PagoDirectoService::class)->guardarComprobante($comprobante, "reserva-{$reserva->id}");
            $reserva->deposito_comprobante_path = $archivo['path'];
            $reserva->deposito_comprobante_mime = $archivo['mime'];
        }
        $reserva->save();

        $this->marcarMesaReservada($reserva);
        app(AvisoReservaService::class)->programar($reserva, 'confirmacion'); // Fase 4, tarea 4
        if ($metodo === 'efectivo') {
            $cajaService->recalcularEfectivoEsperado($turno);
        }

        app(AuditoriaService::class)->registrar('registrar_deposito_reserva', 'Reserva', $reserva->id, array_filter([
            'monto' => $valor, 'metodo' => $metodo, 'caja_turno_id' => $turno->id,
            'recibido' => $recibido, 'cambio' => $recibido !== null ? round($recibido - $valor, 2) : null,
            'con_foto' => $reserva->deposito_comprobante_path !== null ?: null,
        ], fn ($v) => $v !== null), $registradoPor);
        app(NotificacionService::class)->reservaConfirmada($reserva, $registradoPor); // M11

        return $reserva;
    }

    // RN-M05-18: liberar por no-show (el cliente no llegó) — el depósito NO se reembolsa.
    // Fase 4, tarea 3 (RN-M05-07, RF-M05-016): solo cuando ya venció la tolerancia (hora de la
    // reserva + los minutos configurados). Lo hace el sistema solo cada minuto
    // (ReservaAutomaticaService) o una persona desde Reservas; antes se podía marcar no-show
    // incluso antes de la hora y el cliente perdía el depósito sin haber llegado tarde.
    public function marcarNoShow(Reserva $reserva, ?User $actor = null, bool $automatico = false): Reserva
    {
        if ($reserva->estado !== 'confirmada') {
            throw new RuntimeException('Solo una reserva confirmada puede marcarse como no-show.');
        }
        $libera = $this->horaDeLiberacion($reserva);
        if (now()->lt($libera)) {
            throw new RuntimeException("Todavía está dentro de la tolerancia: se puede marcar no-show desde las {$this->hora($libera)}.");
        }

        $reserva->estado = 'no_show';
        $reserva->no_show_at = now();
        $reserva->no_show_automatico = $automatico;
        $reserva->save();

        $this->sincronizarMesa($reserva->mesa_id);

        app(AuditoriaService::class)->registrar($automatico ? 'no_show_automatico' : 'marcar_no_show', 'Reserva', $reserva->id, [
            'hora_reserva' => $reserva->fecha_hora->copy()->setTimezone('America/Bogota')->format('Y-m-d H:i'),
            'tolerancia_minutos' => PoliticaReservas::toleranciaMinutos(),
            'deposito_retenido' => $reserva->deposito_pagado ? (float) $reserva->deposito_valor : 0,
        ], $actor);
        // Fase 4, tarea 4 (RF-M05-025): aviso de no-show al cliente.
        app(AvisoReservaService::class)->programar($reserva, 'no_show');
        // M11: el no-show automático avisa al personal (el manual lo marcó alguien que ya lo sabe).
        if ($automatico) {
            app(NotificacionService::class)->reservaNoShow($reserva);
        }

        return $reserva;
    }

    /** Hora + tolerancia: desde ahí la reserva se considera no honrada (RN-M05-07/08). */
    public function horaDeLiberacion(Reserva $reserva): CarbonInterface
    {
        return $reserva->fecha_hora->copy()->addMinutes(PoliticaReservas::toleranciaMinutos());
    }

    /**
     * La reserva confirmada que hoy aparta esta mesa: su hora está dentro de la ventana previa
     * (mesa Reservada desde N minutos antes) o ya pasó pero sigue dentro de la tolerancia.
     */
    public function reservaEnVentana(int $mesaId, ?CarbonInterface $momento = null): ?Reserva
    {
        $ahora = $momento ? Carbon::instance($momento) : now();

        return Reserva::where('mesa_id', $mesaId)
            ->where('estado', 'confirmada')
            ->where('fecha_hora', '<=', $ahora->copy()->addMinutes(PoliticaReservas::ventanaReservadaMinutos()))
            ->where('fecha_hora', '>', $ahora->copy()->subMinutes(PoliticaReservas::toleranciaMinutos()))
            ->orderBy('fecha_hora')
            ->first();
    }

    /**
     * Fase 4, tarea 3 (RF-M05-014, RN-M05-07): pone la mesa en el estado que le corresponde según
     * sus reservas. Una mesa libre pasa a Reservada solo dentro de la ventana previa a una reserva
     * confirmada; una mesa Reservada sin reserva en la ventana vuelve a Libre (si no tiene pedidos
     * activos). Una mesa Ocupada no se toca: los clientes que están sentados no se levantan; en
     * cuanto se libere, el siguiente proceso la marca Reservada si toca.
     */
    public function sincronizarMesa(?int $mesaId): ?string
    {
        $mesa = $mesaId ? Mesa::find($mesaId) : null;
        if (! $mesa || ! $mesa->activa) {
            return null;
        }

        $tieneReserva = $this->reservaEnVentana($mesa->id) !== null;

        if ($mesa->estado === 'libre' && $tieneReserva) {
            $mesa->estado = 'reservada';
            $mesa->save();

            return 'reservada';
        }
        if ($mesa->estado === 'reservada' && ! $tieneReserva && ! $mesa->tienePedidosActivos()) {
            $mesa->liberar();

            return 'libre';
        }

        return null;
    }

    // RN-M05-19: si el cliente sí llega, la mesa pasa de reservada a ocupada y el depósito
    // queda aplicado como abono al pedido de esa visita (RF-M05-015/034).
    // Fase 2, tarea 2: el abono ahora es real. Se marca la llegada y el depósito se abona como un
    // pago de tipo "deposito" a los pedidos de la visita que ya existan; si todavía no hay pedidos,
    // se abona al primero que se cree en la mesa (AbonoDepositoService). Antes solo se ponía
    // deposito_aplicado_abono en true y la cuenta de la mesa seguía cobrando el total completo.
    public function completar(Reserva $reserva, ?User $actor = null): Reserva
    {
        if ($reserva->estado !== 'confirmada') {
            throw new RuntimeException('Solo una reserva confirmada puede completarse.');
        }

        $mesa = Mesa::find($reserva->mesa_id);
        if ($mesa) {
            $mesa->ocupar();
        }

        $reserva->estado = 'completada';
        $reserva->llegada_at = now();
        $reserva->save();

        app(AuditoriaService::class)->registrar('confirmar_llegada_reserva', 'Reserva', $reserva->id, [
            'deposito' => $reserva->deposito_pagado ? (float) $reserva->deposito_valor : 0,
        ], $actor);

        app(AbonoDepositoService::class)->aplicar($reserva, $actor);

        return $reserva->fresh(['mesa']);
    }

    // RN-M05-17: cancelar antes de la hora reservada reembolsa el depósito completo.
    // Fase 4, tarea 4: queda en la auditoría (quién, o "por enlace" si fue el cliente desde el
    // correo) y se avisa al cliente por correo (RF-M05-025).
    public function cancelar(Reserva $reserva, ?User $actor = null, bool $porEnlace = false): Reserva
    {
        if (! in_array($reserva->estado, ['pendiente_pago', 'confirmada'], true)) {
            throw new RuntimeException('Esta reserva no puede cancelarse en su estado actual.');
        }

        // Fase 2, tarea 2 (RN-M06-08): devolver un depósito recibido en efectivo saca dinero de la
        // caja, así que exige un turno abierto y resta del efectivo esperado de ese turno. Los
        // depósitos en línea se devuelven por la pasarela y no pasan por la caja.
        $turnoReembolso = null;
        if ($reserva->deposito_pagado && $reserva->deposito_metodo === 'efectivo') {
            $turnoReembolso = app(CajaService::class)->turnoAbierto();
            if (! $turnoReembolso) {
                throw new RuntimeException('Para devolver un depósito en efectivo abre la caja del día: el dinero sale de ahí (RN-M05-17).');
            }
        }

        $reserva->estado = 'cancelada';
        if ($reserva->deposito_pagado) {
            $reserva->deposito_reembolsado = true;
            $reserva->deposito_reembolso_caja_turno_id = $turnoReembolso?->id;
        }
        $reserva->save();

        if ($turnoReembolso) {
            app(CajaService::class)->recalcularEfectivoEsperado($turnoReembolso);
        }

        $this->liberarMesa($reserva);

        app(AuditoriaService::class)->registrar('cancelar_reserva', 'Reserva', $reserva->id, array_filter([
            'por_enlace' => $porEnlace ?: null,
            'deposito_reembolsado' => $reserva->deposito_pagado ? (float) $reserva->deposito_valor : null,
        ], fn ($v) => $v !== null), $actor);
        app(AvisoReservaService::class)->programar($reserva, 'cancelacion');
        app(NotificacionService::class)->reservaCanceladaPorCliente($reserva, $actor, $porEnlace); // M11

        // Fase 6, tarea 1 (RN-M05-17, RF-M06-017): el depósito pagado por la pasarela se devuelve por
        // la pasarela (si el medio lo permite; si no, la transacción dice cómo hacerlo).
        if ($reserva->deposito_pagado && $reserva->deposito_metodo === 'en_linea') {
            $tx = $reserva->transaccionesDeposito()->where('estado', 'aprobado')->first();
            if ($tx) {
                app(PasarelaPagoService::class)->reembolsar($tx, $actor);
            }
        }

        return $reserva;
    }

    // Fase 4, tarea 3: confirmar el depósito ya no marca la mesa Reservada de inmediato (antes lo
    // hacía aunque la reserva fuera para otro día, y hasta pisaba una mesa Ocupada). Ahora solo
    // si la reserva cae dentro de la ventana previa; si no, lo hace el proceso de cada minuto.
    private function marcarMesaReservada(Reserva $reserva): void
    {
        $this->sincronizarMesa($reserva->mesa_id);
    }

    private function liberarMesa(Reserva $reserva): void
    {
        $this->sincronizarMesa($reserva->mesa_id);
    }
}
