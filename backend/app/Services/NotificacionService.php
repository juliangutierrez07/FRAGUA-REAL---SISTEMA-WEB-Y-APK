<?php

namespace App\Services;

use App\Models\CajaTurno;
use App\Models\CierreDiario;
use App\Models\Comanda;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Reserva;
use App\Models\TransaccionPasarela;
use App\Models\User;
use App\Support\Usuarios\MenusPorRol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * M11 (21/10/2026): notificaciones del sistema para el personal (la campana del panel).
 *
 * Cada tipo dice a qué roles le llega y con qué menús (RN-M11-02/03): a una cuenta a la que el
 * SuperAdmin le quitó ese menú no le llega, Soporte solo recibe las del sistema y quien hizo la
 * acción no recibe su propio aviso. `clave` evita repetir el mismo aviso a la misma persona.
 *
 * Una notificación nunca rompe la acción que la causó: se guarda después de que esa acción quedó
 * guardada (DB::afterCommit) y, si falla, solo queda en el registro de errores.
 */
class NotificacionService
{
    private const ADMIN = ['superadmin', 'administrador'];
    private const PERSONAL = ['superadmin', 'administrador', 'mesero'];
    private const ZONA = 'America/Bogota';

    /** tipo => [categoría, roles que la reciben, menús (basta uno)] */
    public const TIPOS = [
        'pedido_nuevo' => ['pedidos', self::PERSONAL, ['pedidos']],
        'pedido_listo' => ['pedidos', ['mesero'], ['pedidos']],
        'pedido_cancelado' => ['pedidos', self::PERSONAL, ['pedidos']],
        'pago_en_linea' => ['pedidos', self::PERSONAL, ['pedidos', 'caja', 'cobrar']],
        // Pago directo (22/10/2026): el Cliente subió el comprobante de una transferencia.
        'pago_por_confirmar' => ['pedidos', self::PERSONAL, ['pedidos', 'caja', 'cobrar']],
        'deposito_por_confirmar' => ['reservas', self::PERSONAL, ['mesas', 'reservas']],
        'reserva_nueva' => ['reservas', self::PERSONAL, ['mesas', 'reservas']],
        'reserva_confirmada' => ['reservas', self::PERSONAL, ['mesas', 'reservas']],
        'reserva_cancelada' => ['reservas', self::PERSONAL, ['mesas', 'reservas']],
        'reserva_proxima' => ['reservas', self::PERSONAL, ['mesas', 'reservas']],
        'reserva_no_show' => ['reservas', self::PERSONAL, ['mesas', 'reservas']],
        'pago_por_revisar' => ['caja', self::ADMIN, ['caja']],
        'pago_anulado' => ['caja', self::ADMIN, ['caja']],
        // Decisión del 22/10/2026: el pedido finalizado volvió a cobro; lo cobra el Mesero.
        'pedido_reabierto' => ['caja', self::PERSONAL, ['caja', 'cobrar', 'pedidos']],
        'caja_abierta' => ['caja', self::ADMIN, ['caja']],
        'cierre_pendiente' => ['caja', self::ADMIN, ['compras_gastos']],
        'producto_agotado' => ['operacion', self::PERSONAL, ['catalogo', 'menu', 'pedidos']],
        'comanda_no_salio' => ['operacion', self::PERSONAL, ['pedidos']],
        // M12 (24/10/2026): un equipo que imprime comandas se desconectó con la caja abierta.
        'equipo_desconectado' => ['operacion', self::ADMIN, ['impresoras']],
        'sistema' => ['sistema', [...self::ADMIN, 'soporte'], ['configuracion']],
        // Seguridad (21/10/2026): una cuenta quedó bloqueada por intentos fallidos.
        'cuenta_bloqueada' => ['sistema', self::ADMIN, ['usuarios', 'auditoria']],
    ];

    // ---------------------------------------------------------------- Pedidos

    /** Pedido nuevo por la web o por el QR de la mesa, que espera confirmación. */
    public function pedidoNuevo(Pedido $pedido): void
    {
        if (! in_array($pedido->origen, ['cliente_web', 'qr'], true)) {
            return;
        }
        $this->notificar('pedido_nuevo', "Pedido nuevo #{$pedido->id}",
            "{$this->canal($pedido)} · {$this->quien($pedido)} · {$this->plata($pedido->total)}. Espera confirmación.",
            "pedido_nuevo:{$pedido->id}", $this->enlacesPedidos());
    }

    public function pedidoListo(Pedido $pedido, ?User $actor): void
    {
        $this->notificar('pedido_listo', "Pedido #{$pedido->id} listo para recoger",
            "{$this->quien($pedido)} ya puede recogerlo.", "pedido_listo:{$pedido->id}", $this->enlacesPedidos(), $actor);
    }

    /** Solo cuando lo cancela el cliente (con su cuenta o con su enlace). */
    public function pedidoCanceladoPorCliente(Pedido $pedido, ?User $actor): void
    {
        if ($actor !== null && ! $actor->esCliente()) {
            return;
        }
        $this->notificar('pedido_cancelado', "El cliente canceló el pedido #{$pedido->id}",
            "{$this->canal($pedido)} · {$this->quien($pedido)}.", "pedido_cancelado:{$pedido->id}", $this->enlacesPedidos());
    }

    public function pagoEnLinea(Pago $pago, bool $aprobado, ?User $actor): void
    {
        $this->notificar('pago_en_linea', $aprobado ? "Pago en línea aprobado · pedido #{$pago->pedido_id}" : "Pago en línea rechazado · pedido #{$pago->pedido_id}",
            $this->plata($pago->monto).($aprobado ? ' recibidos.' : '. El cliente puede intentar de nuevo o pagar al recibir.'),
            "pago_en_linea:{$pago->id}:".($aprobado ? 'aprobado' : 'rechazado'), $this->enlacesPedidos(), $actor);
    }

    public function pagoPorConfirmar(Pago $pago): void
    {
        $this->notificar('pago_por_confirmar', "Comprobante por revisar · pedido #{$pago->pedido_id}",
            "{$this->plata($pago->monto)} por ".self::medio($pago->pasarela).'. Revisa la captura y aprueba o rechaza el pago.',
            "pago_por_confirmar:{$pago->id}", ['administrador' => '/admin/pedidos', 'mesero' => '/mesero/caja']);
    }

    // ---------------------------------------------------------------- Reservas

    public function depositoPorConfirmar(Reserva $reserva): void
    {
        $this->notificar('deposito_por_confirmar', 'Comprobante de depósito por revisar',
            "{$this->reserva($reserva)} · {$this->plata($reserva->deposito_valor)} por ".self::medio($reserva->deposito_pasarela).'.',
            "deposito_por_confirmar:{$reserva->id}:".now()->timestamp, $this->enlacesReservas());
    }

    public function reservaNueva(Reserva $reserva, ?User $actor): void
    {
        $this->notificar('reserva_nueva', 'Reserva nueva', "{$this->reserva($reserva)}. Espera el depósito.",
            "reserva_nueva:{$reserva->id}", $this->enlacesReservas(), $actor);
    }

    public function reservaConfirmada(Reserva $reserva, ?User $actor): void
    {
        $this->notificar('reserva_confirmada', 'Depósito pagado: reserva confirmada', "{$this->reserva($reserva)}.",
            "reserva_confirmada:{$reserva->id}", $this->enlacesReservas(), $actor);
    }

    /** Solo cuando la cancela el cliente (con su cuenta o con su enlace). */
    public function reservaCanceladaPorCliente(Reserva $reserva, ?User $actor, bool $porEnlace): void
    {
        if (! $porEnlace && $actor !== null && ! $actor->esCliente()) {
            return;
        }
        $this->notificar('reserva_cancelada', 'El cliente canceló su reserva', "{$this->reserva($reserva)}.",
            "reserva_cancelada:{$reserva->id}", $this->enlacesReservas());
    }

    public function reservaNoShow(Reserva $reserva): void
    {
        $this->notificar('reserva_no_show', 'Reserva no honrada (no-show)',
            "{$this->reserva($reserva)}. La mesa quedó libre.", "reserva_no_show:{$reserva->id}", $this->enlacesReservas());
    }

    // ---------------------------------------------------------------- Caja y dinero

    public function pagoPorRevisar(TransaccionPasarela $tx): void
    {
        $this->notificar('pago_por_revisar', 'Pago en línea por revisar',
            mb_substr("{$tx->referencia}: ".($tx->motivo_revision ?? 'revisa el pago en Caja.'), 0, 255),
            "pago_por_revisar:{$tx->id}:".substr(md5((string) $tx->motivo_revision), 0, 8), ['administrador' => '/admin/caja']);
    }

    public function pagoAnulado(Pago $pago, User $actor): void
    {
        $this->notificar('pago_anulado', 'Pago anulado'.($pago->pedido_id ? " · pedido #{$pago->pedido_id}" : ''),
            "{$this->plata($pago->monto)} ({$pago->metodo}) por {$actor->nombre}.", "pago_anulado:{$pago->id}",
            ['administrador' => '/admin/caja'], $actor);
    }

    public function pedidoReabierto(Pedido $pedido, float $saldo, User $actor): void
    {
        $this->notificar('pedido_reabierto', "El pedido #{$pedido->id} volvió a cobro",
            "Se anuló un pago y le falta {$this->plata($saldo)}. Cóbralo desde Pedidos.", "pedido_reabierto:{$pedido->id}:".now()->timestamp,
            $this->enlacesPedidos(), $actor);
    }

    // ---------------------------------------------------------------- Operación

    public function productoAgotado(string $nombre, string $clave, ?User $actor): void
    {
        $this->notificar('producto_agotado', 'Producto agotado', "{$nombre} se marcó como agotado.",
            'producto_agotado:'.$clave.':'.now()->timestamp, ['administrador' => '/admin/catalogo', 'mesero' => '/mesero/menu'], $actor);
    }

    public function comandaNoSalio(Comanda $comanda, ?User $actor): void
    {
        $this->notificar('comanda_no_salio', "La comanda del pedido #{$comanda->pedido_id} no salió",
            ($comanda->ultimo_error ?: 'No salió de la impresora.').' Vuelve a imprimirla.',
            "comanda_no_salio:{$comanda->id}:".now()->timestamp, $this->enlacesPedidos(), $actor);
    }

    // ---------------------------------------------------------------- Seguridad

    /** M12: el equipo que imprime las comandas lleva varios minutos sin señal y la caja está abierta. */
    public function equipoDesconectado(\App\Models\EquipoImpresion $equipo): void
    {
        $desde = $equipo->ultima_senal_at?->setTimezone(self::ZONA)->format('g:i a');
        $this->notificar('equipo_desconectado', "El equipo «{$equipo->nombre}» está desconectado",
            "No responde desde las {$desde}: las comandas no van a salir solas ahí. Revisa que esté prendido, con internet y con el sistema abierto.",
            "equipo_desconectado:{$equipo->id}:{$equipo->ultima_senal_at?->timestamp}", ['administrador' => '/admin/impresoras']);
    }

    public function cuentaBloqueada(User $usuario, ?string $ip): void
    {
        $minutos = (int) config('fragua_real.seguridad.bloqueo_minutos', 15);
        $this->notificar('cuenta_bloqueada', 'Cuenta bloqueada por intentos fallidos',
            "{$usuario->nombre} ({$usuario->email}): {$minutos} min sin poder entrar".($ip ? " · IP {$ip}" : '').'. Si no fue esa persona, revisa la Auditoría.',
            "cuenta_bloqueada:{$usuario->id}:".now()->format('YmdHi'), ['administrador' => '/admin/auditoria'], $usuario);
    }

    // ---------------------------------------------------------------- Tareas programadas

    /**
     * Cada minuto (routes/console.php, «notificaciones:revisar»): reservas que empiezan pronto, caja
     * abierta de noche, cierre diario pendiente, cola de trabajos detenida y revisión diaria.
     *
     * @return array<string, int>
     */
    public function revisar(): array
    {
        return [
            'reservas_proximas' => $this->reservasProximas(),
            'caja_abierta' => $this->cajaAbierta(),
            'cierre_pendiente' => $this->cierrePendiente(),
            'sistema' => $this->sistema(),
            'equipos_desconectados' => app(ImpresionService::class)->revisarDesconectados(),
        ];
    }

    /** Cada noche: borra las notificaciones con más días de los configurados. */
    public function limpiar(): int
    {
        return Notificacion::where('created_at', '<', now()->subDays((int) config('fragua_real.notificaciones.dias_guardadas', 30)))->delete();
    }

    /**
     * Si el programador de tareas dejó de latir, nada programado puede avisarlo. Lo revisa la campana
     * de quien recibe avisos del sistema cada vez que pregunta (una vez al día como mucho).
     */
    public function revisarProgramador(User $usuario): void
    {
        if (! $this->recibe($usuario, 'sistema')) {
            return;
        }
        $programador = app(EstadoSistemaService::class)->estado()['programador'];
        if ($programador['ultimo_latido'] !== null && ! $programador['activo']) {
            $this->notificar('sistema', 'Las tareas automáticas están detenidas',
                'El programador de tareas no responde: no salen no-show, recordatorios ni avisos. Revisa el cron del servidor.',
                'sistema_programador:'.$this->hoy());
        }
    }

    private function reservasProximas(): int
    {
        $minutos = (int) config('fragua_real.notificaciones.reserva_proxima_minutos', 30);
        $n = 0;
        Reserva::with('mesa')->where('estado', 'confirmada')
            ->whereBetween('fecha_hora', [now(), now()->addMinutes($minutos)])
            ->get()
            ->each(function (Reserva $r) use (&$n) {
                $faltan = max(1, (int) ceil(now()->diffInMinutes($r->fecha_hora, false)));
                $this->notificar('reserva_proxima', "Reserva en {$faltan} min", "{$this->reserva($r)}.",
                    "reserva_proxima:{$r->id}", $this->enlacesReservas());
                $n++;
            });

        return $n;
    }

    private function cajaAbierta(): int
    {
        if (! $this->yaEs('caja_abierta_hora', '23:30')) {
            return 0;
        }
        $turno = app(CajaService::class)->turnoAbierto();
        if (! $turno instanceof CajaTurno) {
            return 0;
        }
        $this->notificar('caja_abierta', 'La caja sigue abierta', 'Cierra el turno de hoy en Caja: cuenta el efectivo y registra el cierre.',
            "caja_abierta:{$turno->id}", ['administrador' => '/admin/caja']);

        return 1;
    }

    private function cierrePendiente(): int
    {
        if (! $this->yaEs('cierre_pendiente_hora', '10:00')) {
            return 0;
        }
        $ayer = now(self::ZONA)->subDay()->toDateString();
        $huboTurno = CajaTurno::whereDate('fecha', $ayer)->exists();
        if (! $huboTurno || CierreDiario::whereDate('fecha', $ayer)->exists()) {
            return 0;
        }
        $this->notificar('cierre_pendiente', 'Falta el cierre diario de ayer',
            'El día '.Carbon::parse($ayer)->format('d/m/Y').' tuvo caja pero no tiene cierre. Hazlo en Compras y gastos.',
            "cierre_pendiente:{$ayer}", ['administrador' => '/admin/compras-gastos']);

        return 1;
    }

    private function sistema(): int
    {
        $n = 0;
        $estado = app(EstadoSistemaService::class)->estado();
        $cola = $estado['cola'];
        if (! $cola['sincronica'] && $cola['ultimo_latido'] !== null && ! $cola['activa']) {
            $this->notificar('sistema', 'La cola de trabajos está detenida',
                'Los correos y avisos al cliente no están saliendo. Revisa el trabajador (Supervisor) en el servidor.',
                'sistema_cola:'.$this->hoy());
            $n++;
        }
        $fallidos = (int) $cola['fallidos'];
        if ($fallidos > (int) Cache::get('notificaciones:fallidos', 0)) {
            $this->notificar('sistema', 'Hay trabajos fallidos en la cola',
                "{$fallidos} trabajo(s) fallaron (por ejemplo, un correo que no salió). Míralos en Configuración > Tareas automáticas.",
                "sistema_fallidos:{$fallidos}");
            $n++;
        }
        Cache::forever('notificaciones:fallidos', $fallidos);

        // La revisión antes de producción, una vez al día.
        if ($this->yaEs('revision_hora', '07:00') && Cache::add('notificaciones:revision:'.$this->hoy(), true, now()->addDays(2))) {
            $errores = collect(app(RevisionProduccionService::class)->revisar())->where('nivel', 'error');
            if ($errores->isNotEmpty()) {
                $this->notificar('sistema', 'La revisión antes de producción tiene errores',
                    mb_substr($errores->count().' punto(s): '.$errores->pluck('titulo')->implode(', ').'.', 0, 255),
                    'sistema_revision:'.$this->hoy());
                $n++;
            }
        }

        return $n;
    }

    // ---------------------------------------------------------------- Lo común

    /**
     * Guarda el aviso para cada persona que debe recibirlo. $enlaces: pantalla del panel según la zona
     * de quien lo recibe ('administrador' o 'mesero'; sin la de su zona, el aviso no lleva enlace).
     */
    public function notificar(string $tipo, string $titulo, string $texto, string $clave, array $enlaces = [], ?User $actor = null): void
    {
        DB::afterCommit(function () use ($tipo, $titulo, $texto, $clave, $enlaces, $actor) {
            try {
                $ahora = now();
                $filas = $this->destinatarios($tipo, $actor)->map(fn (User $u) => [
                    'user_id' => $u->id,
                    'tipo' => $tipo,
                    'categoria' => self::TIPOS[$tipo][0],
                    'titulo' => mb_substr($titulo, 0, 120),
                    'texto' => mb_substr($texto, 0, 255),
                    'enlace' => $u->esMesero() ? ($enlaces['mesero'] ?? null) : ($enlaces['administrador'] ?? ($tipo === 'sistema' ? '/admin/configuracion' : null)),
                    'clave' => mb_substr($clave, 0, 120),
                    'created_at' => $ahora,
                ])->all();
                if ($filas) {
                    Notificacion::insertOrIgnore($filas);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** ¿Esta cuenta recibe ese tipo de aviso? (rol, cuenta activa y menús). */
    public function recibe(User $usuario, string $tipo): bool
    {
        [, $roles, $menus] = self::TIPOS[$tipo];

        return $usuario->activo && in_array($usuario->role, $roles, true) && MenusPorRol::permite($usuario, $menus);
    }

    private function destinatarios(string $tipo, ?User $actor)
    {
        return User::whereIn('role', self::TIPOS[$tipo][1])->where('activo', true)->get()
            ->filter(fn (User $u) => $this->recibe($u, $tipo) && ! ($actor && $u->is($actor)));
    }

    private function enlacesPedidos(): array
    {
        return ['administrador' => '/admin/pedidos', 'mesero' => '/mesero'];
    }

    private function enlacesReservas(): array
    {
        return ['administrador' => '/admin/reservas', 'mesero' => '/mesero/reservas'];
    }

    private function canal(Pedido $pedido): string
    {
        return match ($pedido->tipo) {
            'domicilio' => 'A domicilio',
            'recogida' => 'Para recoger',
            default => ($pedido->mesa?->nombre ?? 'Mesa').($pedido->origen === 'qr' ? ' (QR)' : ''),
        };
    }

    private function quien(Pedido $pedido): string
    {
        return $pedido->cliente?->nombre ?? $pedido->cliente_invitado_nombre ?? 'Cliente';
    }

    private function reserva(Reserva $reserva): string
    {
        $hora = $reserva->fecha_hora->copy()->setTimezone(self::ZONA)->format('d/m g:i a');

        return ($reserva->mesa?->nombre ?? 'Mesa')." · {$hora} · {$reserva->cliente_nombre}";
    }

    private static function medio(?string $medio): string
    {
        return PagoDirectoService::MEDIOS[$medio][0] ?? 'transferencia';
    }

    private function plata(mixed $valor): string
    {
        return '$'.number_format((float) $valor, 0, ',', '.');
    }

    private function hoy(): string
    {
        return now(self::ZONA)->toDateString();
    }

    /** ¿Ya pasó la hora configurada (hora de Colombia) hoy? */
    private function yaEs(string $clave, string $porDefecto): bool
    {
        [$h, $m] = array_map('intval', explode(':', (string) config("fragua_real.notificaciones.{$clave}", $porDefecto)) + [1 => 0]);

        return now(self::ZONA)->gte(now(self::ZONA)->setTime($h, $m));
    }
}
