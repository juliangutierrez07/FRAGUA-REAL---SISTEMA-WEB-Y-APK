<?php

namespace App\Jobs;

use App\Services\PushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * M11 (28/10/2026, RF-M11-013): manda por push un aviso de la campana a los equipos de quienes lo reciben.
 * Va por la cola para no demorar la acción que lo produjo. Un solo intento: un aviso que llega tarde ya no
 * sirve, y la campana lo sigue mostrando de todos modos.
 */
class EnviarAvisosPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param  array<int, array{titulo: string, texto: string, enlace: ?string, etiqueta: string}>  $avisos  por user_id */
    public function __construct(public readonly array $avisos)
    {
    }

    public function handle(PushService $push): void
    {
        try {
            $push->enviar($this->avisos);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
