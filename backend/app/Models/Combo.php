<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Combo extends Model
{
    protected $fillable = [
        'nombre', 'precio', 'tipo_venta', 'vigencia_inicio', 'vigencia_fin',
        'franja_inicio', 'franja_fin', 'dias_semana', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'eliminado_at' => 'datetime',
        'precio' => 'decimal:2',
        'vigencia_inicio' => 'date',
        'vigencia_fin' => 'date',
        'dias_semana' => 'array',
    ];

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'combo_producto')->withPivot('cantidad');
    }

    // RN-M04-11: se oculta solo si algún componente está agotado/inactivo
    public function disponible(): bool
    {
        return $this->activo && $this->productos->every(fn (Producto $p) => $p->puedeAgregarseAPedido());
    }
}
