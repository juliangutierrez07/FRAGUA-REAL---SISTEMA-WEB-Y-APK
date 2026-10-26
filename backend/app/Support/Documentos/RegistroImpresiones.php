<?php

namespace App\Support\Documentos;

use App\Models\User;
use App\Services\AuditoriaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Regla de reimpresión (decisión del usuario, 13/10/2026): todo lo que el sistema imprime se puede
 * volver a imprimir, pero una reimpresión no se confunde con el original y queda registrada.
 *
 * registrar() suma una impresión al documento (columna `impresiones`, con bloqueo de fila para que
 * dos impresiones a la vez no saquen el mismo número), la deja en la auditoría con quién la pidió y
 * devuelve la marca que el documento debe llevar: null la primera vez (el original) y, desde la
 * segunda, ['vez' => n, 'fecha' => ...] para que diga "REIMPRESIÓN · copia n".
 * La usan el comprobante de pago y el cierre diario; la usarán los reportes guardados y las comandas.
 *
 * M10 (20/10/2026): la cuenta de Soporte solo consulta. Abrir un documento no le suma una impresión
 * (así la próxima del negocio no sale como reimpresión por culpa de Soporte): queda en la auditoría
 * como `consultar_documento` y el documento dice "COPIA DE CONSULTA · SOPORTE".
 */
final class RegistroImpresiones
{
    /** Opciones de dompdf comunes: solo las letras usadas de la fuente y nada remoto al dibujar. */
    public const OPCIONES_PDF = ['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false];

    /** @return array{vez: int, fecha: string, consulta?: bool}|null */
    public static function registrar(Model $documento, string $accion, string $entidad, int $entidadId, array $detalles, User $actor): ?array
    {
        if ($actor->esSoporte()) {
            app(AuditoriaService::class)->registrar('consultar_documento', $entidad, $entidadId, ['documento' => $accion] + $detalles, $actor);

            return ['vez' => (int) $documento->getAttribute('impresiones'), 'fecha' => now()->toIso8601String(), 'consulta' => true];
        }

        $vez = DB::transaction(function () use ($documento) {
            $fila = $documento->newQuery()->whereKey($documento->getKey())->lockForUpdate()->firstOrFail();
            $fila->forceFill(['impresiones' => (int) $fila->impresiones + 1])->save();

            return (int) $fila->impresiones;
        });
        $documento->setAttribute('impresiones', $vez);

        // M12 (24/10/2026): en qué equipo se imprimió (si el navegador está registrado en Impresoras).
        $equipo = app(\App\Services\ImpresionService::class)->equipoActual();
        app(AuditoriaService::class)->registrar($accion, $entidad, $entidadId, $detalles + [
            'vez' => $vez,
            'reimpresion' => $vez > 1,
        ] + ($equipo ? ['equipo' => $equipo->nombre] : []), $actor);

        return $vez > 1 ? ['vez' => $vez, 'fecha' => now()->toIso8601String()] : null;
    }
}
