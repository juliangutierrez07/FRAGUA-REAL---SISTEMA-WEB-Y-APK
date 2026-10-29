<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corrección (28/10/2026, RF-M01-038): crear un pedido es una ruta pública (también pide el invitado), así que
 * con una sesión cerrada por el Administrador o vencida el pedido llegaba como si fuera de un invitado: el
 * Cliente seguía viendo su nombre en la pantalla, pero el pedido no quedaba en su historial (o el servidor
 * pedía datos de invitado que la pantalla no mostraba). Si la petición trae un token y ese token ya no sirve,
 * se responde 401 como en las rutas con sesión: la app lo saca y le pide entrar de nuevo.
 */
class SesionVencidaEnRutaPublica
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null && $request->user('sanctum') === null) {
            return response()->json([
                'message' => 'Tu sesión se cerró. Vuelve a entrar o haz el pedido como invitado.',
                'sesion_cerrada' => true,
            ], 401);
        }

        return $next($request);
    }
}
