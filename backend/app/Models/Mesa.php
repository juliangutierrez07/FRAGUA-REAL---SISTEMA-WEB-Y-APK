<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mesa extends Model
{
    protected $fillable = ['nombre', 'estado', 'ocupada_desde', 'qr_code', 'activa'];

    protected $casts = ['activa' => 'boolean', 'ocupada_desde' => 'datetime', 'eliminado_at' => 'datetime'];

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class);
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    // Fase 1, tarea 3 (M05, introducción): pasar a Ocupada abre la cuenta de una visita nueva y
    // pasar a Libre la cierra. Si la mesa ya tenía una visita abierta (por ejemplo, la marcaron
    // reservada mientras alguien seguía sentado), la visita continúa: no se reinicia la cuenta.
    public function ocupar(): void
    {
        $this->estado = 'ocupada';
        $this->ocupada_desde ??= now();
        $this->save();
    }

    public function liberar(): void
    {
        $this->estado = 'libre';
        $this->ocupada_desde = null;
        $this->save();
    }

    // RF-M02-L-019/020, RN-PED-13/14/30: pedidos locales de la visita en curso, sin cancelados.
    // Una mesa sin visita abierta no tiene cuenta.
    public function pedidosDeLaCuenta()
    {
        if ($this->estado !== 'ocupada' || $this->ocupada_desde === null) {
            return $this->pedidos()->whereRaw('1 = 0');
        }

        return $this->pedidos()
            ->where('tipo', 'local')
            ->where('estado', '!=', 'cancelado')
            ->where('created_at', '>=', $this->ocupada_desde)
            ->orderBy('id');
    }

    /**
     * Mejora (26/10/2026): cuentas separadas. Los números de cuenta de la visita en curso (1, 2, 3…),
     * de menor a mayor. Un pedido sin número (anterior a esta mejora) es de la cuenta 1.
     *
     * @return array<int, int>
     */
    public function cuentasDeLaVisita(): array
    {
        return $this->pedidosDeLaCuenta()->get(['cuenta'])
            ->map(fn ($p) => (int) ($p->cuenta ?? 1))
            ->unique()->sort()->values()->all();
    }

    // RF-M05-010: no puede liberarse con pedidos PENDIENTE o CONFIRMADO
    public function tienePedidosActivos(): bool
    {
        return $this->pedidos()->whereIn('estado', ['pendiente', 'confirmado', 'preparando'])->exists();
    }
}
