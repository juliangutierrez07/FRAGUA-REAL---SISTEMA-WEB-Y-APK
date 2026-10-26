<?php

namespace App\Support\Validacion;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Eliminar (24/10/2026): una mesa, un producto o una cuenta archivados siguen ocupando su nombre o
 * correo (los reportes y la auditoría los nombran). Esta regla va antes de `unique` para decir por
 * qué no se puede usar ese nombre y cómo recuperarlo, en vez del «ya existe» de siempre.
 */
final class Eliminados
{
    public static function libre(string $tabla, string $columna, string $mensaje, mixed $ignorar = null): Closure
    {
        return function (string $atributo, mixed $valor, Closure $fallar) use ($tabla, $columna, $mensaje, $ignorar) {
            $id = is_object($ignorar) ? $ignorar->getKey() : $ignorar;
            $ocupado = DB::table($tabla)
                ->whereRaw("LOWER({$columna}) = ?", [mb_strtolower((string) $valor)])
                ->whereNotNull('eliminado_at')
                ->when($id, fn ($q) => $q->where('id', '!=', $id))
                ->exists();
            if ($ocupado) {
                $fallar($mensaje);
            }
        };
    }
}
