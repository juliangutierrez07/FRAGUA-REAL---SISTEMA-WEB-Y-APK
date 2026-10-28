<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CajaTurno extends Model
{
    protected $fillable = [
        'fecha', 'base_inicial', 'efectivo_esperado', 'efectivo_contado',
        'diferencia', 'justificacion_descuadre', 'abierto_por', 'abierto_at',
        'cerrado_por', 'cerrado_at', 'estado', 'cierre_automatico',
    ];

    protected $casts = [
        'fecha' => 'date',
        'abierto_at' => 'datetime',
        'cerrado_at' => 'datetime',
        'base_inicial' => 'decimal:2',
        'efectivo_esperado' => 'decimal:2',
        'efectivo_contado' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'cierre_automatico' => 'boolean', // 27/10/2026, RF-M06-039
    ];

    // Nombrado "...PorUsuario" (no "abiertoPor"/"cerradoPor" a secas) a propósito: Eloquent
    // serializa la relación cargada usando Str::snake(nombre), y Str::snake('abiertoPor')
    // === 'abierto_por' — el mismo nombre que la columna FK. Con el nombre corto, cargar
    // la relación SOBRESCRIBE el id crudo en el JSON con el objeto User completo bajo la
    // misma llave (bug real, visto en vivo: "abierto_por" salía como objeto en vez del id
    // en GET /caja/turnos). Este nombre más largo evita la colisión sin tocar las columnas.
    public function abiertoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'abierto_por');
    }

    public function cerradoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    // RF-M06-019: ajustes extraordinarios registrados sobre este turno después de cerrado.
    public function ajustes(): HasMany
    {
        return $this->hasMany(AjusteCaja::class);
    }

    public function estaAbierta(): bool
    {
        return $this->estado === 'abierta';
    }
}
