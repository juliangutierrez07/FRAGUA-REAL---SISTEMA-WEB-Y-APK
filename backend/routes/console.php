<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fase 3, tarea 4 (RF-M01-010, RNF-M01-005): borra de la base las sesiones vencidas o que
// llevan más del tiempo de inactividad sin usarse (config/fragua_real.php, "sesiones"). Ya no
// sirven para entrar (Sanctum las rechaza); esto solo evita que la tabla crezca sin fin.
// Corre a diario con el programador de Laravel (`php artisan schedule:run` cada minuto en el
// cron del VPS; se pone en marcha en la Fase 4). También se puede correr a mano.
Artisan::command('sesiones:limpiar', function (\App\Services\SesionService $sesiones) {
    $borradas = $sesiones->limpiarVencidas();
    $this->info("Sesiones vencidas borradas: {$borradas}");
})->purpose('Borra las sesiones vencidas o inactivas');

\Illuminate\Support\Facades\Schedule::command('sesiones:limpiar')->dailyAt('03:30')->timezone('America/Bogota');

// Fase 4, tarea 1: latido del programador y de la cola, cada minuto. Si el cron o el
// trabajador de la cola no están corriendo en el servidor, el Administrador lo ve en
// Configuración > Tareas automáticas (EstadoSistemaService).
Artisan::command('fragua:latido', function (\App\Services\EstadoSistemaService $estado) {
    $estado->latido();
    $this->info('Latido registrado.');
})->purpose('Registra que el programador corre y encola una prueba para la cola');

\Illuminate\Support\Facades\Schedule::command('fragua:latido')->everyMinute();

// Fase 4, tarea 3 (RF-M05-014, 016, 024, RN-M05-07): no-show automático al vencer la tolerancia,
// mesa Reservada solo dentro de la ventana previa y recordatorio por correo antes de la hora.
// Usa los tiempos de Configuración (15 min, 2 h y 1 h al empezar).
Artisan::command('reservas:procesar', function (\App\Services\ReservaAutomaticaService $servicio) {
    $r = $servicio->procesar();
    $this->info("No-show: {$r['no_show']} · mesas reservadas: {$r['reservadas']} · mesas liberadas: {$r['liberadas']} · recordatorios: {$r['recordatorios']}");
})->purpose('Aplica el no-show automático, la ventana de mesa Reservada y los recordatorios');

\Illuminate\Support\Facades\Schedule::command('reservas:procesar')->everyMinute()->withoutOverlapping(5);

// M11 (21/10/2026): notificaciones del personal que dependen de la hora: la reserva empieza pronto, la
// caja sigue abierta de noche, falta el cierre diario de ayer, la cola de trabajos se detuvo y la
// revisión antes de producción diaria. Cada aviso sale una sola vez (clave única por persona).
Artisan::command('notificaciones:revisar', function (\App\Services\NotificacionService $servicio) {
    $r = $servicio->revisar();
    $this->info("Reservas próximas: {$r['reservas_proximas']} · caja abierta: {$r['caja_abierta']} · cierre pendiente: {$r['cierre_pendiente']} · sistema: {$r['sistema']}");
})->purpose('Crea las notificaciones del personal que dependen de la hora');

\Illuminate\Support\Facades\Schedule::command('notificaciones:revisar')->everyMinute()->withoutOverlapping(5);

// M11 — Avisos push (28/10/2026, RF-M11-013): crea el par de llaves VAPID para el .env del servidor. Se hace una
// sola vez: si se cambian, los equipos que ya tenían los avisos activos tienen que volver a activarlos.
Artisan::command('push:generar-llaves', function () {
    $llaves = \App\Support\Push\WebPush::generarLlaves();
    $this->info('Copia estas dos líneas en el .env del servidor (la privada no se comparte):');
    $this->line('FRAGUA_REAL_PUSH_PUBLICA='.$llaves['publica']);
    $this->line('FRAGUA_REAL_PUSH_PRIVADA='.$llaves['privada']);
})->purpose('Crea las llaves VAPID de los avisos push del personal');

// Cierre automático de la caja (27/10/2026, RF-M06-039, RN-M06-22): el turno que sigue abierto después de
// la hora de cierre más la tolerancia (Configuración) se cierra solo, sin contar el efectivo.
Artisan::command('caja:cerrar-vencidas', function (\App\Services\CajaService $caja) {
    $n = $caja->cerrarVencidos();
    $this->info($n ? "Turnos cerrados automáticamente: {$n}." : 'No hay turnos vencidos.');
})->purpose('Cierra la caja que nadie cerró a la hora de cierre más la tolerancia');
\Illuminate\Support\Facades\Schedule::command('caja:cerrar-vencidas')->everyMinute()->withoutOverlapping(5);

Artisan::command('notificaciones:limpiar', function (\App\Services\NotificacionService $servicio) {
    $this->info('Notificaciones borradas: '.$servicio->limpiar());
})->purpose('Borra las notificaciones más viejas que los días configurados (30 por defecto)');

\Illuminate\Support\Facades\Schedule::command('notificaciones:limpiar')->dailyAt('03:40')->timezone('America/Bogota');

// Fase 6, tarea 1 (RF-M06-006, D4): por si un evento de la pasarela se pierde, cada 5 minutos se
// pregunta por los pagos en línea que siguen pendientes y se cierran los que vencieron sin pagarse.
// No hace nada mientras la pasarela esté en modo manual.
Artisan::command('pasarela:conciliar', function (\App\Services\PasarelaPagoService $pasarela) {
    $r = $pasarela->conciliar();
    $this->info("Revisadas: {$r['revisadas']} · cerradas: {$r['cerradas']} · abandonadas: {$r['abandonadas']} · sin respuesta: {$r['sin_respuesta']}");
})->purpose('Concilia con la pasarela los pagos en línea pendientes');

\Illuminate\Support\Facades\Schedule::command('pasarela:conciliar')->everyFiveMinutes()->withoutOverlapping(10);

// M09 (19/10/2026): crea la cuenta del SuperAdmin, el dueño del sistema, o vuelve SuperAdmin a una
// cuenta que ya existe (por ejemplo, el Administrador de siempre). Pide la contraseña sin mostrarla.
// Uso en el VPS: sudo -u www-data php artisan fragua:crear-superadmin correo@dominio.com
Artisan::command('fragua:crear-superadmin {correo} {--nombre= : Nombre de la persona} {--cambiar-clave : Pide una contraseña nueva aunque la cuenta ya exista}',
    function (\App\Services\SuperAdminService $servicio) {
        $correo = (string) $this->argument('correo');
        $existe = \App\Models\User::where('email', mb_strtolower(trim($correo)))->first();
        if ($existe && $existe->role !== 'superadmin'
            && ! $this->confirm("Ya existe la cuenta {$existe->email} ({$existe->role}). ¿La vuelvo SuperAdmin?", true)) {
            $this->warn('No se cambió nada.');

            return 1;
        }

        $password = null;
        if (! $existe || $this->option('cambiar-clave')) {
            $password = (string) $this->secret('Contraseña (mínimo 8 caracteres, con mayúscula, minúscula y número)');
            if ($password !== (string) $this->secret('Repite la contraseña')) {
                $this->error('Las contraseñas no coinciden. No se cambió nada.');

                return 1;
            }
        }

        try {
            $r = $servicio->crearOAsignar($correo, $this->option('nombre'), $password);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $this->info(($r['creada'] ? 'Cuenta SuperAdmin creada: ' : 'Ahora es SuperAdmin: ').$r['usuario']->email);

        return 0;
    })->purpose('Crea la cuenta del SuperAdmin o vuelve SuperAdmin a una cuenta existente');

// M10 (20/10/2026): crea la cuenta de Soporte (el desarrollador), que ve todo el panel del
// Administrador sin cambiar nada del negocio. Con el correo de una cuenta que ya es de Soporte,
// cambia el nombre o, con --cambiar-clave, la contraseña. No activa ni desactiva: eso lo decide el
// SuperAdmin en Usuarios. Uso en el VPS: sudo -u www-data php artisan fragua:crear-soporte correo@dominio.com
Artisan::command('fragua:crear-soporte {correo} {--nombre= : Nombre de la persona} {--cambiar-clave : Pide una contraseña nueva aunque la cuenta ya exista}',
    function (\App\Services\SoporteService $servicio) {
        $correo = (string) $this->argument('correo');
        $existe = \App\Models\User::where('email', mb_strtolower(trim($correo)))->first();
        if ($existe && ! $existe->esSoporte()) {
            $this->error("Ese correo ya es de una cuenta de {$existe->role}. Usa otro correo para Soporte. No se cambió nada.");

            return 1;
        }

        $password = null;
        if (! $existe || $this->option('cambiar-clave')) {
            $password = (string) $this->secret('Contraseña (mínimo 8 caracteres, con mayúscula, minúscula y número)');
            if ($password !== (string) $this->secret('Repite la contraseña')) {
                $this->error('Las contraseñas no coinciden. No se cambió nada.');

                return 1;
            }
        }

        try {
            $r = $servicio->crearOActualizar($correo, $this->option('nombre'), $password);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $this->info(($r['creada'] ? 'Cuenta de Soporte creada: ' : 'Cuenta de Soporte actualizada: ').$r['usuario']->email);
        if (! $r['usuario']->activo) {
            $this->warn('La cuenta está desactivada: solo el SuperAdmin puede reactivarla, en Usuarios.');
        }

        return 0;
    })->purpose('Crea la cuenta de Soporte (solo consulta) o le cambia el nombre o la contraseña');

// Endurecimiento (13/10/2026): revisión antes de abrir al público (APP_DEBUG, HTTPS, cuentas de prueba,
// correo, cola, pasarela, CORS). Sale con código 1 si hay algo grave. Correrlo en el VPS después de
// cada despliegue; lo mismo se ve en Administrador > Configuración.
Artisan::command('fragua:revisar-produccion', function (\App\Services\RevisionProduccionService $revision) {
    $items = $revision->revisar();
    $marcas = ['ok' => '[OK]   ', 'aviso' => '[AVISO]', 'error' => '[ERROR]'];
    foreach ($items as $i) {
        $linea = "{$marcas[$i['nivel']]} {$i['titulo']}: {$i['detalle']}";
        match ($i['nivel']) {
            'error' => $this->error($linea),
            'aviso' => $this->warn($linea),
            default => $this->line($linea),
        };
    }
    if ($revision->hayErrores($items)) {
        $this->error('Hay puntos por corregir antes de abrir al público.');

        return 1;
    }
    $this->info('Listo para producción (revisa los avisos, si hay).');

    return 0;
})->purpose('Revisa la configuración de seguridad antes de abrir al público');

// Fase 7, tarea 5 (13/10/2026, RNF-M08-001): mide cuánto tarda cada reporte con los datos reales,
// para el último mes y con la comparación contra el periodo anterior (lo que hace la pantalla de
// Reportes con «Comparar»). Solo lee. Correrlo en el VPS cuando ya haya historia: si un reporte pasa
// de 2 segundos, revisar que la migración de índices (2026_10_22_000001) esté aplicada.
Artisan::command('reportes:medir {--dias=30 : Días hacia atrás, contando hoy}', function (\App\Services\ReporteService $reportes) {
    $dias = max(1, min((int) $this->option('dias'), 366));
    $hoy = now('America/Bogota');
    $periodo = \App\Support\Reportes\PeriodoReporte::resolver('personalizado', $hoy->copy()->subDays($dias - 1)->toDateString(), $hoy->toDateString());
    $anterior = $periodo->anterior();
    $pedidos = \App\Models\Pedido::where('estado', 'finalizado')->whereBetween('finalizado_at', [$periodo->desde, $periodo->hasta])->count();
    $this->line("Periodo: {$periodo->desdeFecha} a {$periodo->hastaFecha} (pedidos finalizados: {$pedidos}), con comparación.");

    $filas = [];
    foreach (['ventas-totales', 'ventas-por-producto', 'ventas-por-tipo-y-metodo', 'compras-y-gastos', 'resultado-operativo', 'uso-de-mesas'] as $reporte) {
        $inicio = microtime(true);
        $reportes->calcular($reporte, $periodo);
        $reportes->calcular($reporte, $anterior);
        $ms = (int) round((microtime(true) - $inicio) * 1000);
        $filas[] = [$reporte, "{$ms} ms", $ms > 2000 ? 'LENTO' : 'OK'];
    }
    $this->table(['Reporte', 'Tiempo', 'Estado'], $filas);
    $lento = collect($filas)->contains(fn ($f) => $f[2] === 'LENTO');
    $lento
        ? $this->warn('Algún reporte pasa de 2 segundos: revisa que las migraciones estén al día (php artisan migrate --force).')
        : $this->info('Todos los reportes responden en menos de 2 segundos.');

    return 0;
})->purpose('Mide cuánto tardan los reportes del último mes con los datos reales');
