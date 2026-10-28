<?php

namespace App\Services;

use App\Models\PushSuscripcion;
use App\Models\User;
use App\Support\Push\WebPush;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * M11 — Avisos push del personal (28/10/2026, RF-M11-013, RN-M11-09): los mismos avisos de la campana llegan
 * también al teléfono, la tableta o el computador donde la persona los activó, aunque tenga la app cerrada o
 * la pantalla bloqueada. Solo el personal; el Cliente sigue recibiendo correos.
 *
 * Seguridad: el servidor solo envía a los servicios push de los navegadores (lista SERVICIOS), nunca a otra
 * dirección; cada suscripción va unida a la sesión con que se activó y se borra con ella.
 */
class PushService
{
    /** Servicios push de Chrome/Android, Firefox, Safari/iPhone y Edge/Windows. */
    public const SERVICIOS = [
        'fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com',
        'push.apple.com', 'notify.windows.com',
    ];

    /** Cuánto espera el servicio push para entregarlo si el equipo está apagado (los avisos envejecen). */
    public const VIGENCIA_SEGUNDOS = 3600;

    public function disponible(): bool
    {
        return $this->llavePublica() !== '' && (string) config('fragua_real.push.privada') !== '';
    }

    public function llavePublica(): string
    {
        return (string) config('fragua_real.push.publica');
    }

    public static function endpointPermitido(string $endpoint): bool
    {
        $partes = parse_url($endpoint);
        if (($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])) {
            return false;
        }
        $host = strtolower($partes['host']);
        foreach (self::SERVICIOS as $servicio) {
            if ($host === $servicio || str_ends_with($host, '.'.$servicio)) {
                return true;
            }
        }

        return false;
    }

    public function activaEn(User $usuario, string $endpoint): bool
    {
        return PushSuscripcion::where('user_id', $usuario->id)->where('endpoint_hash', PushSuscripcion::huella($endpoint))->exists();
    }

    /**
     * Guarda (o pasa a esta cuenta y a esta sesión) la suscripción del equipo. Si el equipo ya estaba suscrito
     * con otra cuenta —un equipo compartido—, desde ahora recibe solo los de esta.
     */
    public function suscribir(User $usuario, ?int $tokenId, string $endpoint, string $p256dh, string $auth, ?string $navegador): PushSuscripcion
    {
        if (! self::endpointPermitido($endpoint)) {
            throw new RuntimeException('Ese navegador usa un servicio de avisos que el sistema no reconoce.');
        }
        try {
            $llaveValida = WebPush::esLlavePublica(WebPush::desdeB64($p256dh)) && strlen(WebPush::desdeB64($auth)) === 16;
        } catch (RuntimeException) {
            $llaveValida = false;
        }
        if (! $llaveValida) {
            throw new RuntimeException('La suscripción del navegador no es válida. Vuelve a intentarlo.');
        }

        return PushSuscripcion::updateOrCreate(['endpoint_hash' => PushSuscripcion::huella($endpoint)], [
            'user_id' => $usuario->id,
            'token_id' => $tokenId,
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'navegador' => $navegador !== null ? mb_substr($navegador, 0, 120) : null,
        ]);
    }

    public function desuscribir(User $usuario, string $endpoint): bool
    {
        return PushSuscripcion::where('user_id', $usuario->id)->where('endpoint_hash', PushSuscripcion::huella($endpoint))->delete() > 0;
    }

    /**
     * Envía a todos los equipos suscritos de cada persona su aviso. Un equipo que el servicio push ya no
     * reconoce (404/410: desinstaló la app, quitó el permiso…) se borra.
     *
     * @param  array<int, array{titulo: string, texto: string, enlace: ?string, etiqueta: string}>  $avisos  por user_id
     * @return int cuántos equipos lo recibieron
     */
    public function enviar(array $avisos): int
    {
        if (! $this->disponible() || ! $avisos) {
            return 0;
        }
        $enviados = 0;
        PushSuscripcion::whereIn('user_id', array_keys($avisos))->get()->each(function (PushSuscripcion $s) use ($avisos, &$enviados) {
            $enviados += $this->entregar($s, $avisos[$s->user_id]) ? 1 : 0;
        });

        return $enviados;
    }

    /** @param  array{titulo: string, texto: string, enlace: ?string, etiqueta: string}  $aviso */
    public function entregar(PushSuscripcion $suscripcion, array $aviso): bool
    {
        try {
            $cuerpo = WebPush::cifrar(
                json_encode($aviso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                WebPush::desdeB64($suscripcion->p256dh),
                WebPush::desdeB64($suscripcion->auth),
            );
            $respuesta = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => WebPush::cabeceraVapid($suscripcion->endpoint, $this->llavePublica(),
                        (string) config('fragua_real.push.privada'), (string) config('fragua_real.push.asunto'), time() + 12 * 3600),
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => (string) self::VIGENCIA_SEGUNDOS,
                    'Urgency' => 'high',
                ])
                ->withBody($cuerpo, 'application/octet-stream')
                ->post($suscripcion->endpoint);
        } catch (Throwable $e) {
            Log::warning('Aviso push no enviado', ['suscripcion' => $suscripcion->id, 'error' => $e->getMessage()]);

            return false;
        }

        if ($respuesta->successful()) {
            $suscripcion->forceFill(['ultimo_envio_at' => now()])->save();

            return true;
        }
        if (in_array($respuesta->status(), [404, 410], true)) {
            $suscripcion->delete();
        } else {
            Log::warning('El servicio push rechazó un aviso', ['suscripcion' => $suscripcion->id, 'estado' => $respuesta->status()]);
        }

        return false;
    }
}
