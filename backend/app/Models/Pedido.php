<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tabla unificada para los 3 canales (ADR-001): local, domicilio y recogida.
 * Ver M02 (Pedidos Locales) y M07 (Domicilios y Recogida) para las reglas de negocio completas.
 */
class Pedido extends Model
{
    // Fase 5, tarea 1 (RN-M06-07): cuando cambia lo que vale el pedido o su estado, el comprobante
    // se pone al día (se genera al quedar pagado; se anula si deja de estarlo o si se cancela).
    protected static function booted(): void
    {
        static::saved(function (Pedido $pedido) {
            if ($pedido->wasChanged(['total', 'estado'])) {
                app(\App\Services\ComprobanteService::class)->sincronizar($pedido);
            }
        });
    }

    protected $fillable = [
        'tipo', 'origen', 'mesa_id', 'cuenta', 'cliente_id',
        'cliente_invitado_nombre', 'cliente_invitado_telefono', 'cliente_invitado_correo', 'telefono_contacto',
        'direccion_entrega', 'barrio', 'referencia', 'ubicacion_lat', 'ubicacion_lng', 'observaciones_entrega', 'valor_domicilio',
        'metodo_pago_preferido', 'pasarela_preferida', 'paga_con',
        'estado', 'subtotal', 'descuento', 'total',
        'promociones_vigentes', 'promociones_aplicadas',
        'descuento_manual', 'descuento_manual_motivo', 'descuento_manual_por', 'descuento_manual_at',
        'confirmado_por', 'confirmado_at', 'preparando_at', 'listo_at', 'listo_por',
        'entregado_por', 'entregado_at', 'recogido_por', 'recogido_at',
        'finalizado_por', 'finalizado_at',
        'cancelado_por', 'cancelado_motivo', 'cancelado_at',
    ];

    // RF-M06-005 (Fase 0, tarea 4): el token del invitado no viaja en los listados ni en el
    // detalle; solo se entrega una vez, en la respuesta de crear el pedido.
    // Fase 2, tarea 1: la foto de promociones vigentes es interna (para recalcular); lo que se
    // muestra es promociones_aplicadas.
    protected $hidden = ['token_publico', 'promociones_vigentes'];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'total' => 'decimal:2',
        'promociones_vigentes' => 'array',
        'promociones_aplicadas' => 'array',
        'descuento_manual' => 'array',
        'descuento_manual_at' => 'datetime',
        'valor_domicilio' => 'decimal:2',
        'paga_con' => 'decimal:2',
        'ubicacion_lat' => 'float',
        'ubicacion_lng' => 'float',
        'cuenta' => 'integer',
        'confirmado_at' => 'datetime',
        'preparando_at' => 'datetime',
        'listo_at' => 'datetime',
        'entregado_at' => 'datetime',
        'recogido_at' => 'datetime',
        'finalizado_at' => 'datetime',
        'cancelado_at' => 'datetime',
    ];

    // ---- Relaciones ----
    public function mesa(): BelongsTo
    {
        return $this->belongsTo(Mesa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cliente_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    public function finalizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizado_por');
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por');
    }

    // Fase 2, tarea 1 (RF-M04-039): quién aplicó el descuento manual.
    public function descuentoManualPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'descuento_manual_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PedidoDetalle::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    // Fase 5, tarea 1 (RF-M06-009/010, RN-M06-07): el comprobante vigente (el último no anulado)
    // y la historia completa, con los anulados.
    public function comprobante(): HasOne
    {
        return $this->hasOne(Comprobante::class)->whereNull('anulado_at')->latest('id');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class)->orderBy('id');
    }

    // Fase 6, tarea 2 (RN-PED-06/09): lo que se mandó a la cocina, en orden.
    public function comandas(): HasMany
    {
        return $this->hasMany(Comanda::class)->orderBy('numero');
    }

    public function modificaciones(): HasMany
    {
        return $this->hasMany(PedidoModificacion::class)->orderBy('id');
    }

    // Fase 4, tarea 5 (RF-M07-R-012): avisos al cliente, del más reciente al más antiguo.
    public function avisos(): HasMany
    {
        return $this->hasMany(PedidoAviso::class)->latest('id');
    }

    // Correo para avisarle al cliente: el de su cuenta o el que dejó el invitado (opcional).
    public function correoDeContacto(): ?string
    {
        $correo = $this->cliente_id ? $this->cliente?->email : $this->cliente_invitado_correo;

        return $correo ?: null;
    }

    // Enlace privado de seguimiento (el mismo que recibe el invitado al terminar el Checkout).
    public function enlaceSeguimiento(): string
    {
        return rtrim((string) config('fragua_real.frontend_url'), '/').'/seguimiento/'.$this->token_publico;
    }

    // Pedidos que todavía no terminan (ni finalizados ni cancelados).
    public const ESTADOS_ACTIVOS = ['pendiente', 'confirmado', 'preparando'];

    // ---- Helpers de negocio ----
    public function esLocal(): bool { return $this->tipo === 'local'; }
    public function esDomicilio(): bool { return $this->tipo === 'domicilio'; }
    public function esRecogida(): bool { return $this->tipo === 'recogida'; }

    // RN-PED-10: producto ya preparado no puede modificarse
    public function puedeModificarse(): bool
    {
        return in_array($this->estado, ['pendiente', 'confirmado', 'preparando']);
    }

    // RN-PED-07, RN-PED-08, RF-M07-009/021: las líneas se agregan, cambian o retiran con el
    // pedido PENDIENTE, o CONFIRMADO mientras la línea no esté preparada. En PREPARANDO la
    // cocina ya trabaja el pedido completo: lo nuevo va en otro pedido de la mesa (RN-PED-13).
    public function admiteCambiosDeLineas(): bool
    {
        return in_array($this->estado, ['pendiente', 'confirmado'], true);
    }

    // RF-M06-003, RF-M06-004, RNF-M06-004: el Mesero registra todos los cobros
    // presenciales, sin importar el canal (local, domicilio contra entrega o
    // recogida al recoger). El Administrador nunca cobra: solo abre/cierra caja
    // (RN-M06-01) y autoriza anulaciones (RF-M06-017). El pago en línea es
    // autoservicio del Cliente y no pasa por este helper.
    public function actorQueCobra(): string
    {
        return 'mesero';
    }

    public function totalPagado(): float
    {
        return (float) $this->pagos()->where('anulado', false)->where('estado', 'aprobado')->sum('monto');
    }

    // Lo que ya está pagado o en camino (un pago en línea aún sin aprobar). Un cambio en las
    // líneas nunca puede dejar el total por debajo de esto (RF-M06-011).
    public function montoComprometido(): float
    {
        return (float) $this->pagos()->where('anulado', false)->where('estado', '!=', 'rechazado')->sum('monto');
    }

    public function pagoCompleto(): bool
    {
        return $this->totalPagado() >= (float) $this->total;
    }

    // RF-M07-025 / RF-M07-R-013: en domicilio el Mesero confirma la entrega y en recogida
    // confirma que el Cliente se lo llevó. El pedido local se sirve en la mesa: no tiene un
    // paso aparte, su condición es el pago (RN-PED-23).
    public function entregaConfirmada(): bool
    {
        return match ($this->tipo) {
            'domicilio' => $this->entregado_at !== null,
            'recogida' => $this->recogido_at !== null,
            default => true,
        };
    }

    /**
     * Lo que todavía impide finalizar el pedido, en palabras para el Mesero; null si ya puede.
     * RN-PED-32: de PREPARANDO a FINALIZADO con la condición de pago y, si aplica, la entrega
     * o la recogida (RN-M07-06, RN-M07-R-04).
     */
    public function faltaParaFinalizar(): ?string
    {
        if ($this->estado !== 'preparando') {
            return 'solo un pedido en preparación puede finalizarse';
        }
        if (! $this->pagoCompleto()) {
            return 'falta completar el pago';
        }
        if (! $this->entregaConfirmada()) {
            return $this->esDomicilio() ? 'falta confirmar la entrega' : 'falta confirmar que el cliente lo recogió';
        }

        return null;
    }

    public function puedeFinalizarse(): bool
    {
        return $this->faltaParaFinalizar() === null;
    }
}
