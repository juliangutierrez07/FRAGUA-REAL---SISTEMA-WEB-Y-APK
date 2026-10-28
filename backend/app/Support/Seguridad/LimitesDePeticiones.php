<?php

namespace App\Support\Seguridad;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Seguridad (28/10/2026, RNF-TRANS-045): los límites de peticiones de las rutas públicas, con nombre.
 *
 * Antes cada ruta usaba `throttle:N,1` sin nombre, y Laravel cuenta esas peticiones con UNA sola clave por
 * IP (o por usuario) para todas las rutas: el inicio de sesión, crear un pedido y seguir un pedido gastaban
 * el mismo contador. Unos cuantos intentos fallidos desde la red del restaurante dejaban sin poder entrar a
 * todo el personal, aunque cada uno usara su propia cuenta. Ahora cada límite tiene su contador:
 *  - inicio-sesion: 10 por minuto para una misma cuenta desde una IP y 40 por minuto para toda la IP (el
 *    personal comparte el wifi del local). El bloqueo por intentos fallidos sigue en
 *    App\Services\BloqueoInicioSesionService.
 *  - registro y recuperacion: por IP y, en la recuperación, también por correo.
 *  - pedido-publico: el pedido de la web o del QR sin sesión del personal; por IP, por minuto y por hora,
 *    y por celular del invitado o por cuenta del Cliente. El personal (Mesero, Administrador) tiene un margen
 *    amplio por cuenta: toma muchos pedidos seguidos en la hora pico.
 * Las demás rutas públicas siguen con `throttle:N,1,<nombre>`: el nombre separa su contador.
 * Los números se ajustan en config/fragua_real.php, «limites».
 */
class LimitesDePeticiones
{
    public static function registrar(): void
    {
        RateLimiter::for('inicio-sesion', function (Request $request) {
            $cuenta = mb_strtolower(trim((string) ($request->input('login') ?? $request->input('email'))));

            return [
                Limit::perMinute(self::numero('login_cuenta_minuto', 10))->by('cuenta:'.sha1($cuenta).'|'.$request->ip()),
                Limit::perMinute(self::numero('login_ip_minuto', 40))->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('registro', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perHour(self::numero('registro_ip_hora', 20))->by('ip-hora:'.$request->ip()),
        ]);

        RateLimiter::for('recuperacion', function (Request $request) {
            $correo = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perHour(5)->by('correo:'.sha1($correo)),
            ];
        });

        RateLimiter::for('pedido-publico', function (Request $request) {
            $usuario = $request->user('sanctum');
            if ($usuario && ($usuario->esMesero() || $usuario->esAdministrador())) {
                return Limit::perMinute(120)->by('personal:'.$usuario->id);
            }

            $limites = [
                Limit::perMinute(self::numero('pedidos_ip_minuto', 10))->by('ip:'.$request->ip()),
                Limit::perHour(self::numero('pedidos_ip_hora', 60))->by('ip-hora:'.$request->ip()),
            ];
            // Quien compra: la cuenta del Cliente o el celular del invitado (aunque cambie de IP).
            $quien = $usuario ? 'cliente:'.$usuario->id
                : (($tel = preg_replace('/\D/', '', (string) $request->input('cliente_invitado_telefono'))) !== '' ? 'tel:'.sha1(substr($tel, -10)) : null);
            if ($quien) {
                $limites[] = Limit::perHour(self::numero('pedidos_comprador_hora', 10))->by($quien);
            }

            return $limites;
        });
    }

    private static function numero(string $clave, int $porDefecto): int
    {
        return max(1, (int) config("fragua_real.limites.{$clave}", $porDefecto));
    }
}
