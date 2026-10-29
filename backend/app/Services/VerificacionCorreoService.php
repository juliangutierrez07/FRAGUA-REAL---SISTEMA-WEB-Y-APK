<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\CodigoCorreoNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Seguridad (28/10/2026, RF-M01-037, RN-USR-28): el Cliente confirma que el correo de su cuenta es suyo con un
 * código de 6 dígitos que le llega a ese correo. Hasta entonces el sistema no le muestra nada que se busque
 * por ese correo («Mis reservas») ni lo encuentra por correo al cobrar.
 *  - El código vence a los 30 minutos y admite 5 intentos; pedir uno nuevo anula el anterior.
 *  - En la caché solo queda su hash.
 *  - Recuperar la contraseña también lo confirma: el enlace llegó a ese correo.
 */
class VerificacionCorreoService
{
    private const MINUTOS = 30;
    private const INTENTOS = 5;

    public function enviarCodigo(User $usuario): void
    {
        if ($usuario->correo_verificado_at) {
            return;
        }
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->clave($usuario), ['hash' => hash('sha256', $usuario->id.'|'.$codigo), 'intentos' => 0], now()->addMinutes(self::MINUTOS));
        $usuario->notify(new CodigoCorreoNotification($codigo, self::MINUTOS));
    }

    public function verificar(User $usuario, string $codigo): void
    {
        if ($usuario->correo_verificado_at) {
            return;
        }
        $guardado = Cache::get($this->clave($usuario));
        if (! is_array($guardado)) {
            throw ValidationException::withMessages(['codigo' => ['El código venció o no lo has pedido. Pide uno nuevo.']]);
        }
        if (! hash_equals($guardado['hash'], hash('sha256', $usuario->id.'|'.trim($codigo)))) {
            $intentos = $guardado['intentos'] + 1;
            if ($intentos >= self::INTENTOS) {
                Cache::forget($this->clave($usuario));
                throw ValidationException::withMessages(['codigo' => ['Hiciste demasiados intentos. Pide un código nuevo.']]);
            }
            Cache::put($this->clave($usuario), [...$guardado, 'intentos' => $intentos], now()->addMinutes(self::MINUTOS));
            throw ValidationException::withMessages(['codigo' => ['El código no es correcto.']]);
        }

        Cache::forget($this->clave($usuario));
        $this->marcar($usuario, 'codigo');
    }

    /** Lo confirma por otra vía (recuperar la contraseña con el enlace del correo). */
    public function marcar(User $usuario, string $via): void
    {
        if ($usuario->correo_verificado_at) {
            return;
        }
        $usuario->forceFill(['correo_verificado_at' => now()])->save();
        app(AuditoriaService::class)->registrar('correo_verificado', 'User', $usuario->id, ['via' => $via], $usuario);
    }

    private function clave(User $usuario): string
    {
        return 'correo:codigo:'.$usuario->id;
    }
}
