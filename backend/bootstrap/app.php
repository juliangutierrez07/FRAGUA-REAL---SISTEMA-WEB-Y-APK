<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'activo' => \App\Http\Middleware\EnsureUserIsActive::class,
            // M09 (20/10/2026): permisos de menú por cuenta.
            'menu' => \App\Http\Middleware\EnsureMenuPermitido::class,
            // 28/10/2026 (RF-M01-038): un token que ya no sirve no se toma como invitado en una ruta pública.
            'sesion.vigente' => \App\Http\Middleware\SesionVencidaEnRutaPublica::class,
        ]);
        // M10 (20/10/2026): la cuenta de Soporte solo consulta. En toda la API, porque las rutas
        // públicas (crear un pedido, reservar) también reconocen la sesión si llega el token.
        $middleware->api(append: [\App\Http\Middleware\SoporteSoloConsulta::class]);
        // Endurecimiento (13/10/2026): cabeceras de seguridad en toda respuesta de la API.
        // Global (no solo en el grupo api) para que también vayan en los errores, como un 401 sin sesión.
        $middleware->append(\App\Http\Middleware\CabecerasSeguridad::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // CORREGIDO (29/09/2026): las reglas de negocio de los Services (PedidoService,
        // PagoService, CajaService, ReservaService...) se señalan lanzando RuntimeException
        // o InvalidArgumentException directo (este proyecto no tiene una clase de excepción
        // propia) — ningún Controller las capturaba, así que llegaban sin manejar hasta el
        // handler por defecto de Laravel: un 500 con la traza completa expuesta (APP_DEBUG=true)
        // o un "Server Error" genérico sin ninguna pista (APP_DEBUG=false), en vez de un 4xx
        // con el mensaje real (ej. "No hay un turno de caja abierto para registrar el cobro").
        // Se excluyen los RuntimeException que YA tienen su propio render correcto y no son
        // una regla de negocio: ModelNotFoundException (404 automático del route-model-binding),
        // toda la familia HttpException (abort()/abort_if(), 404 de ruta no encontrada, 405,
        // etc.) y HttpResponseException (ya trae su propia Response armada).
        $esReglaDeNegocio = function (\Throwable $e) {
            return ! $e instanceof ModelNotFoundException
                && ! $e instanceof HttpExceptionInterface
                && ! $e instanceof HttpResponseException;
        };

        $exceptions->render(function (\RuntimeException $e, Request $request) use ($esReglaDeNegocio) {
            if (! $request->is('api/*') || ! $esReglaDeNegocio($e)) {
                return null;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\InvalidArgumentException $e, Request $request) use ($esReglaDeNegocio) {
            if (! $request->is('api/*') || ! $esReglaDeNegocio($e)) {
                return null;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
