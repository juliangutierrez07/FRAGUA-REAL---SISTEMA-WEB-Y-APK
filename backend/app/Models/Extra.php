<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Extra extends Model
{
    protected $fillable = ['nombre', 'precio', 'alcance', 'producto_id', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
        'eliminado_at' => 'datetime',
        'precio' => 'decimal:2',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function esGeneral(): bool
    {
        return $this->alcance === 'general';
    }

    // Fase 2, tarea 3 (RF-M04-011): productos a los que el Administrador asoció este extra general.
    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'producto_extra')->withTimestamps();
    }

    /**
     * Si este extra puede acompañar a ese producto (null = línea de combo, sin producto propio).
     * - Específico (RN-M04-05): solo con su propio producto.
     * - General (RF-M04-011, CA-M04-004): solo con los productos a los que el Administrador lo asoció.
     *
     * CORREGIDO (16/10/2026, producción): un general sin asociar se ofrecía con todo el menú, y a una
     * Limonada se le podía pedir "Extra queso". Ahora sigue a M04 al pie de la letra: sin asociar no se
     * ofrece con ningún producto. La migración 2026_10_23_000001 asoció los generales que había sueltos
     * a las comidas, para no quitárselos de golpe.
     */
    public function aplicaA(?int $productoId): bool
    {
        if (! $this->activo || $productoId === null) {
            return false;
        }
        if (! $this->esGeneral()) {
            return (int) $this->producto_id === $productoId;
        }
        $asociados = $this->relationLoaded('productos') ? $this->productos->pluck('id') : $this->productos()->pluck('productos.id');

        return $asociados->contains($productoId);
    }
}
