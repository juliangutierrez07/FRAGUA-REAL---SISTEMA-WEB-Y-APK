<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Categoria extends Model
{
    protected $fillable = ['nombre', 'activo', 'permite_quitar_ingredientes'];

    protected $casts = ['activo' => 'boolean', 'permite_quitar_ingredientes' => 'boolean', 'eliminado_at' => 'datetime'];

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    public function promociones(): BelongsToMany
    {
        return $this->belongsToMany(Promocion::class, 'promocion_categoria');
    }
}
