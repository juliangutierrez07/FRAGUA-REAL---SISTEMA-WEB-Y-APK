<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Seguridad (21/10/2026, RF-M01-029 a 031, RN-USR-24): bloqueo temporal del inicio de sesión.
 *
 * Se cuentan los intentos fallidos de dos maneras, y basta con que una llegue a su límite:
 *  - por cuenta (el correo o el usuario escrito): 5 seguidos la bloquean 15 minutos, aunque el
 *    atacante cambie de IP;
 *  - por IP: 20 en 15 minutos la bloquean, aunque pruebe cuentas distintas.
 * El bloqueo dura los 15 minutos completos desde el intento que lo activó.
 * Un inicio de sesión correcto o recuperar la contraseña borra el conteo de esa cuenta. El mensaje
 * es el mismo exista o no la cuenta (no revela cuáles existen). El bloqueo queda en la auditoría y,
 * si la cuenta existe, avisa al Administrador y al SuperAdmin en la campana (M11).
 * Los límites están en config/fragua_real.php, «seguridad».
 *
 * Equipo de confianza (28/10/2026, RF-M01-036, RN-USR-27): al entrar bien, el navegador recibe una llave
 * secreta de ese equipo para esa cuenta (dura 60 días). Si la persona vuelve a entrar desde ese equipo, ni el
 * bloqueo de su cuenta ni el de la IP la detienen: así un intruso que prueba contraseñas (desde otra red o
 * desde el mismo wifi del local) no deja sin poder entrar al personal que ya usaba ese teléfono o computador.
 * Los intentos fallidos desde un equipo de confianza se cuentan aparte y lo bloquean solo a él.
 */
class BloqueoInicioSesionService
{
    /** Minutos que faltan para poder intentar de nuevo, o null si no está bloqueado. */
    public function minutosBloqueado(string $identificador, Request $request, ?string $equipo = null): ?int
    {
        $hasta = $equipo
            ? (int) Cache::get($this->bloqueo($equipo), 0)
            : max((int) Cache::get($this->bloqueo($this->claveCuenta($identificador)), 0), (int) Cache::get($this->bloqueo($this->claveIp($request)), 0));
        $segundos = $hasta - now()->getTimestamp();

        return $segundos > 0 ? max(1, (int) ceil($segundos / 60)) : null;
    }

    /** Cuenta un intento fallido. Devuelve true si con este intento la cuenta (o el equipo) quedó bloqueada. */
    public function fallido(string $identificador, Request $request, ?User $usuario, ?string $equipo = null): bool
    {
        $decaimiento = $this->limite('bloqueo_minutos', 15) * 60;
        // Desde un equipo de confianza solo cuenta (y se bloquea) ese equipo.
        if ($equipo) {
            $intentos = RateLimiter::hit($equipo, $decaimiento);
            if ($intentos < $this->limite('intentos_cuenta', 5)) {
                return false;
            }
            $this->bloquear($equipo, $decaimiento);
            app(AuditoriaService::class)->registrar('bloqueo_inicio_sesion', 'User', $usuario?->id, [
                'cuenta' => mb_substr($identificador, 0, 120),
                'intentos' => $intentos,
                'minutos' => $this->limite('bloqueo_minutos', 15),
                'equipo_de_confianza' => true,
            ]);

            return true;
        }

        $intentos = RateLimiter::hit($this->claveCuenta($identificador), $decaimiento);
        if (RateLimiter::hit($this->claveIp($request), $decaimiento) >= $this->limite('intentos_ip', 20)) {
            $this->bloquear($this->claveIp($request), $decaimiento);
        }

        $limite = $this->limite('intentos_cuenta', 5);
        if ($intentos < $limite) {
            return false;
        }
        $this->bloquear($this->claveCuenta($identificador), $decaimiento);

        app(AuditoriaService::class)->registrar('bloqueo_inicio_sesion', 'User', $usuario?->id, [
            'cuenta' => mb_substr($identificador, 0, 120),
            'intentos' => $intentos,
            'minutos' => $this->limite('bloqueo_minutos', 15),
        ]);
        if ($usuario) {
            app(NotificacionService::class)->cuentaBloqueada($usuario, $request->ip());
        }

        return true;
    }

    public function limpiar(string $identificador, ?string $equipo = null): void
    {
        RateLimiter::clear($this->claveCuenta($identificador));
        Cache::forget($this->bloqueo($this->claveCuenta($identificador)));
        if ($equipo) {
            RateLimiter::clear($equipo);
        }
    }

    /**
     * La clave de conteo del equipo de confianza de esta cuenta, si el navegador mandó una llave válida para
     * ella (null si no). El navegador guarda las llaves de las cuentas que entraron en él.
     */
    public function equipoDeConfianza(?User $usuario, mixed $llaves): ?string
    {
        $llave = $usuario ? $this->llaveVigente($usuario, $llaves) : null;

        return $llave ? 'login:equipo:'.hash('sha256', $llave) : null;
    }

    /** Al entrar bien: la llave de este equipo para la cuenta (la misma si ya la tenía; dura 60 días más). */
    public function recordarEquipo(User $usuario, mixed $llaves): string
    {
        $llave = $this->llaveVigente($usuario, $llaves) ?? Str::random(64);
        Cache::put($this->claveConfianza($llave), $usuario->id, now()->addDays(self::DIAS_CONFIANZA));

        return $llave;
    }

    private const DIAS_CONFIANZA = 60;

    private function llaveVigente(User $usuario, mixed $llaves): ?string
    {
        foreach (is_array($llaves) ? array_slice($llaves, 0, 10) : [] as $llave) {
            if (is_string($llave) && strlen($llave) === 64 && (int) Cache::get($this->claveConfianza($llave)) === $usuario->id) {
                return $llave;
            }
        }

        return null;
    }

    private function claveConfianza(string $llave): string
    {
        return 'login:confianza:'.hash('sha256', $llave);
    }

    /** Al recuperar la contraseña se borra el conteo por correo y por usuario. */
    public function limpiarCuenta(User $usuario): void
    {
        $this->limpiar($usuario->email);
        if ($usuario->username) {
            $this->limpiar($usuario->username);
        }
    }

    public function mensaje(int $minutos): string
    {
        $tiempo = $minutos === 1 ? '1 minuto' : "{$minutos} minutos";

        return "Hiciste demasiados intentos fallidos. Por seguridad, espera {$tiempo} o usa «¿Olvidaste tu contraseña?».";
    }

    /** Bloquea desde ahora y vuelve a empezar el conteo de intentos de esa clave. */
    private function bloquear(string $clave, int $segundos): void
    {
        Cache::put($this->bloqueo($clave), now()->getTimestamp() + $segundos, $segundos);
        RateLimiter::clear($clave);
    }

    private function bloqueo(string $clave): string
    {
        return 'bloqueo:'.$clave;
    }

    private function claveCuenta(string $identificador): string
    {
        return 'login:cuenta:'.sha1(mb_strtolower(trim($identificador)));
    }

    private function claveIp(Request $request): string
    {
        return 'login:ip:'.sha1((string) $request->ip());
    }

    private function limite(string $clave, int $porDefecto): int
    {
        return max(1, (int) config("fragua_real.seguridad.{$clave}", $porDefecto));
    }
}
