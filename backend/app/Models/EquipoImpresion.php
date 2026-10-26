<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M12 — Impresoras (24/10/2026): un equipo del local que imprime (navegador de una tablet o un PC).
 * La clave y el código de vinculación no viajan en el JSON: la clave solo se entrega una vez, al
 * registrar o vincular el equipo, y queda guardada en ese navegador.
 */
class EquipoImpresion extends Model
{
    protected $table = 'equipos_impresion';

    protected $fillable = ['nombre', 'imprime_comandas', 'imprime_comprobantes', 'al_confirmar', 'automatico'];

    protected $hidden = ['clave', 'codigo_vinculacion'];

    protected $casts = [
        'imprime_comandas' => 'boolean',
        'imprime_comprobantes' => 'boolean',
        'al_confirmar' => 'boolean',
        'automatico' => 'boolean',
        'ultima_senal_at' => 'datetime',
        'ultima_prueba_at' => 'datetime',
        'codigo_vence_at' => 'datetime',
    ];

    protected $appends = ['en_linea', 'vinculado'];

    /** Mandó su señal en los últimos minutos (el navegador la manda cada minuto mientras está abierto). */
    public function getEnLineaAttribute(): bool
    {
        $minutos = (int) config('fragua_real.impresion.minutos_en_linea', 3);

        return $this->ultima_senal_at !== null && $this->ultima_senal_at->gt(now()->subMinutes($minutos));
    }

    /** Ya hay un navegador con su clave (registrado ahí mismo o vinculado con el código). */
    public function getVinculadoAttribute(): bool
    {
        return $this->clave !== null;
    }

    public function senalPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ultima_senal_por');
    }

    public function pruebaPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ultima_prueba_por');
    }
}
