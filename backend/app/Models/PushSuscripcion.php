<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M11 (28/10/2026, RF-M11-013): un equipo donde alguien del personal activó los avisos push. Ver la
 * migración 2026_11_07_000001 y App\Services\PushService.
 */
class PushSuscripcion extends Model
{
    protected $table = 'push_suscripciones';

    protected $fillable = ['user_id', 'token_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'navegador', 'ultimo_envio_at'];

    // Las llaves del navegador no salen en ninguna respuesta.
    protected $hidden = ['endpoint', 'endpoint_hash', 'p256dh', 'auth'];

    protected $casts = ['ultimo_envio_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function huella(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
