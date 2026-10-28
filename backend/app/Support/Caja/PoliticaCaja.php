<?php

namespace App\Support\Caja;

use App\Models\Configuracion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * M06 — Cierre automático de la caja (27/10/2026, RF-M06-039, RN-M06-22): si nadie cierra el turno, se
 * cierra solo a la hora de cierre del negocio más la tolerancia, sin contar el efectivo («Cierre
 * automático, sin contar»). El Administrador ajusta los dos valores en Configuración.
 *
 * Una hora de cierre antes de las 6:00 a. m. se entiende como de la madrugada siguiente (el negocio
 * cierra pasada la medianoche).
 */
final class PoliticaCaja
{
    public const HORA_CIERRE = 'caja_hora_cierre';
    public const TOLERANCIA = 'caja_tolerancia_minutos';

    /** Minutos de tolerancia permitidos: de 0 a 6 horas. */
    public const RANGO_TOLERANCIA = [0, 360];

    private const ZONA = 'America/Bogota';
    private const MADRUGADA = '06:00';

    /** "HH:MM", hora de Colombia. */
    public static function horaCierre(): string
    {
        $valor = (string) Configuracion::obtener(self::HORA_CIERRE, (string) config('fragua_real.caja.hora_cierre', '23:30'));

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor) ? $valor : '23:30';
    }

    public static function toleranciaMinutos(): int
    {
        [$minimo, $maximo] = self::RANGO_TOLERANCIA;
        $respaldo = (int) config('fragua_real.caja.tolerancia_minutos', 60);

        return max($minimo, min($maximo, (int) Configuracion::obtenerNumerico(self::TOLERANCIA, $respaldo)));
    }

    /** @return array<string, int|string> */
    public static function valores(): array
    {
        return [self::HORA_CIERRE => self::horaCierre(), self::TOLERANCIA => self::toleranciaMinutos()];
    }

    /** Cuándo se cierra sola la caja del día `$fecha` si sigue abierta. */
    public static function limite(CarbonInterface|string $fecha): CarbonImmutable
    {
        $dia = CarbonImmutable::parse($fecha instanceof CarbonInterface ? $fecha->toDateString() : $fecha, self::ZONA)->startOfDay();
        [$h, $m] = array_map('intval', explode(':', self::horaCierre()));
        $cierre = $dia->setTime($h, $m);
        if (self::horaCierre() < self::MADRUGADA) {
            $cierre = $cierre->addDay();
        }

        return $cierre->addMinutes(self::toleranciaMinutos());
    }

    /** "12:30 a. m." */
    public static function horaLegible(CarbonInterface $momento): string
    {
        $local = $momento->copy()->setTimezone(self::ZONA);

        return $local->format('g:i').' '.($local->hour < 12 ? 'a. m.' : 'p. m.');
    }
}
