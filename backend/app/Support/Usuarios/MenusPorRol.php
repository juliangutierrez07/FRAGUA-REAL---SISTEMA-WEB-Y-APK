<?php

namespace App\Support\Usuarios;

use App\Models\User;

/**
 * M09 (20/10/2026): los menús que el SuperAdmin habilita a cada cuenta (RF-M09-011 a 015).
 *
 * Cada rol del personal tiene su catálogo de menús. Una cuenta sin lista guardada
 * (`users.menus_permitidos = null`) ve todos los de su rol. Los menús fijos no se pueden quitar,
 * para que nadie quede sin poder trabajar: Dashboard del Administrador y Pedidos del Mesero.
 *
 * Quitar un menú lo esconde y, además, el servidor rechaza sus acciones (middleware `menu`).
 * El SuperAdmin y el Cliente no tienen permisos de menú: el primero ve todo; el segundo no usa el panel.
 */
final class MenusPorRol
{
    /** rol => [clave => [nombre, fijo]] (el orden es el del menú lateral) */
    public const CATALOGO = [
        'administrador' => [
            'dashboard' => ['Dashboard', true],
            'usuarios' => ['Usuarios', false],
            'pedidos' => ['Pedidos', false],
            'compras_gastos' => ['Compras y Gastos', false],
            'catalogo' => ['Catálogo', false],
            'mesas' => ['Mesas y Reservas', false],
            'caja' => ['Caja', false],
            'reportes' => ['Reportes', false],
            'auditoria' => ['Auditoría', false],
            'configuracion' => ['Configuración', false],
            // M12 (24/10/2026): equipos que imprimen, formato del ticket, prueba y estado.
            'impresoras' => ['Impresoras', false],
        ],
        'mesero' => [
            'pedidos' => ['Pedidos', true],
            'mesas' => ['Mesas', false],
            'reservas' => ['Reservas', false],
            'menu' => ['Menú (agotados)', false],
            'cobrar' => ['Cobrar', false],
            'gastos' => ['Gastos (además necesita el permiso de compras y gastos)', false],
        ],
    ];

    public const SIN_PERMISO = 'Esta opción no está habilitada para tu cuenta. Pídele al SuperAdmin que te la active.';

    public static function tieneMenus(?string $rol): bool
    {
        return $rol !== null && isset(self::CATALOGO[$rol]);
    }

    /** @return array<int, string> */
    public static function claves(?string $rol): array
    {
        return array_keys(self::CATALOGO[$rol ?? ''] ?? []);
    }

    /** @return array<int, string> */
    public static function fijos(?string $rol): array
    {
        return array_keys(array_filter(self::CATALOGO[$rol ?? ''] ?? [], fn ($m) => $m[1]));
    }

    /**
     * Los menús que esa cuenta ve. El SuperAdmin, todos los del Administrador; el Cliente, ninguno.
     *
     * @return array<int, string>
     */
    public static function efectivos(User $usuario): array
    {
        // M10: Soporte ve todos los menús del Administrador (solo para consultar); nadie se los recorta.
        if ($usuario->esSuperAdmin() || $usuario->esSoporte()) {
            return self::claves('administrador');
        }
        if (! self::tieneMenus($usuario->role)) {
            return [];
        }
        $guardados = $usuario->menus_permitidos;
        if (! is_array($guardados)) {
            return self::claves($usuario->role);
        }

        // En el orden del catálogo, sin claves viejas y siempre con los fijos.
        return array_values(array_filter(self::claves($usuario->role),
            fn ($clave) => in_array($clave, $guardados, true) || in_array($clave, self::fijos($usuario->role), true)));
    }

    /** ¿Puede esa cuenta usar alguno de estos menús? El SuperAdmin y el Cliente no se filtran aquí. */
    public static function permite(User $usuario, array $claves): bool
    {
        if ($usuario->esSuperAdmin() || $usuario->esSoporte() || ! self::tieneMenus($usuario->role)) {
            return true;
        }

        return (bool) array_intersect($claves, self::efectivos($usuario));
    }

    /** Lo que la pantalla de permisos muestra para un rol. */
    public static function paraPantalla(string $rol): array
    {
        return array_map(fn ($clave, $m) => ['clave' => $clave, 'nombre' => $m[0], 'fijo' => $m[1]],
            array_keys(self::CATALOGO[$rol] ?? []), self::CATALOGO[$rol] ?? []);
    }
}
