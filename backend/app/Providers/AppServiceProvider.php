<?php

namespace App\Providers;

use App\Services\SesionService;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fase 6, tarea 1 (decisión D4): la pasarela de pago es Wompi. Para cambiar de proveedor
        // se escribe otro adaptador de App\Support\Pasarela\PasarelaPago y se cambia aquí.
        $this->app->bind(\App\Support\Pasarela\PasarelaPago::class, \App\Support\Pasarela\WompiPasarela::class);

        // Seguridad (21/10/2026): la consulta de contraseñas filtradas espera como mucho 5 segundos
        // (Laravel trae 30); si el servicio no responde, la contraseña no se rechaza por eso.
        $this->app->singleton(UncompromisedVerifier::class, fn ($app) => new NotPwnedVerifier($app[HttpFactory::class], 5));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fragua Real Burger es una aplicación 100% en español (ver lang/es/validation.php).
        // El .env del proyecto trae APP_LOCALE=en de la plantilla por defecto de Laravel y
        // ese archivo no se puede editar por control remoto — se fuerza aquí para que los
        // mensajes de validación autogenerados de los Form Requests (ej. "El campo nombre
        // es obligatorio.") salgan en español sin depender de ese valor del .env.
        config(['app.locale' => 'es', 'app.fallback_locale' => 'es']);

        // Fase 3, tarea 4 (RF-M01-010, RNF-M01-005): además de su vencimiento máximo
        // (expires_at), una sesión deja de servir si lleva más del tiempo de inactividad sin
        // usarse (config/fragua_real.php, "sesiones"). Sanctum responde 401 y la app vuelve al login.
        // Seguridad (21/10/2026, RN-USR-23): la regla de contraseña segura de toda cuenta nueva o
        // contraseña nueva. La usan los Form Requests con Password::defaults() y los comandos del
        // servidor (SuperAdmin y Soporte). Se ajusta en config/fragua_real.php, «seguridad».
        Password::defaults(function () {
            $c = config('fragua_real.seguridad');
            $regla = Password::min(max(8, (int) ($c['clave_minimo'] ?? 8)));
            if ($c['clave_mezcla'] ?? true) {
                $regla = $regla->letters()->mixedCase()->numbers();
            }

            return ($c['clave_filtradas'] ?? false) ? $regla->uncompromised() : $regla;
        });

        // Seguridad (28/10/2026, RNF-TRANS-045): límites de peticiones con nombre (cada ruta con su contador).
        \App\Support\Seguridad\LimitesDePeticiones::registrar();

        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $esValida) => $esValida && app(SesionService::class)->sigueVigente($token)
        );
    }
}
