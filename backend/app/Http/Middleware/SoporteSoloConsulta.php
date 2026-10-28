<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M10 (20/10/2026): la cuenta de Soporte ve todo el panel del Administrador pero no cambia nada del
 * negocio (RF-M10-004, RN-M10-02). Corre en toda la API antes que cualquier controlador, así que
 * una escritura se rechaza aunque se llame la API directamente, también en las rutas públicas que
 * reconocen la sesión (crear un pedido, reservar).
 *
 * Lo único que sí puede escribir:
 *  - su propia cuenta: entrar y salir, sus sesiones, sus datos, su contraseña y marcar como leídas sus
 *    notificaciones (M11);
 *  - exportar un reporte a PDF o Excel y cotizar un pedido (son POST, pero no guardan nada del negocio).
 *
 * Y una consulta que no puede hacer: imprimir una comanda (es un GET, pero la marca como impresa y
 * la cocina la daría por recibida). Los comprobantes y cierres sí los abre, sin contar como
 * impresión (RegistroImpresiones).
 */
class SoporteSoloConsulta
{
    public const MENSAJE = 'La cuenta de Soporte solo puede consultar.';

    private const PERMITIDAS = [
        'api/auth/*',
        'api/perfil',
        'api/perfil/password',
        'api/reportes/exportar',
        'api/pedidos/cotizacion',
        'api/notificaciones/*',
        // M11 (28/10/2026): activar los avisos push en su propio equipo.
        'api/push/*',
        // M12: la vista previa del ticket no guarda nada.
        'api/impresion/vista-previa',
    ];

    private const CONSULTAS_BLOQUEADAS = [
        'api/comandas/*/ticket',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Fuera del grupo auth:sanctum el guard por defecto no conoce el token: se pide a Sanctum.
        $usuario = $request->user() ?? $request->user('sanctum');

        if ($usuario?->esSoporte()) {
            $escribe = ! $request->isMethodSafe() && ! $request->is(...self::PERMITIDAS);
            abort_if($escribe || $request->is(...self::CONSULTAS_BLOQUEADAS), 403, self::MENSAJE);
        }

        return $next($request);
    }
}
