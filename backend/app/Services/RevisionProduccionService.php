<?php

namespace App\Services;

use App\Models\User;
use App\Support\Pasarela\PasarelaPago;
use Illuminate\Support\Facades\Hash;

/**
 * Endurecimiento (13/10/2026): lista de revisión antes de abrir el sistema al público. La corre
 * `php artisan fragua:revisar-produccion` en el VPS (sale con error si algo grave falla) y la ve el
 * Administrador en Configuración. Solo lee configuración y cuentas; no cambia nada ni muestra
 * secretos (dice si una llave está puesta, nunca su valor).
 *
 * Niveles: error (no abrir así), aviso (revisar), ok.
 */
class RevisionProduccionService
{
    // Contraseñas que traen las cuentas sembradas o que suelen usarse de prueba.
    private const CONTRASENAS_DE_PRUEBA = ['password', 'secreto123', '12345678', 'fragua123'];

    /** @return list<array{clave: string, nivel: string, titulo: string, detalle: string}> */
    public function revisar(): array
    {
        $r = [];
        $produccion = app()->environment('production');

        $r[] = $this->item('entorno', $produccion ? 'ok' : 'error', 'Entorno de producción',
            $produccion ? 'APP_ENV=production.' : 'APP_ENV es "'.app()->environment().'": en el servidor debe ser production.');

        $debug = (bool) config('app.debug');
        $r[] = $this->item('debug', $debug ? 'error' : 'ok', 'Modo de depuración apagado',
            $debug ? 'APP_DEBUG está prendido: un error mostraría código y datos internos. Ponlo en false.' : 'APP_DEBUG=false.');

        $r[] = $this->item('llave', config('app.key') ? 'ok' : 'error', 'Llave de la aplicación',
            config('app.key') ? 'APP_KEY está puesta.' : 'Falta APP_KEY: corre php artisan key:generate.');

        foreach (['app.url' => 'APP_URL', 'fragua_real.frontend_url' => 'FRONTEND_URL'] as $config => $nombre) {
            $url = (string) config($config);
            $https = str_starts_with($url, 'https://');
            $r[] = $this->item(strtolower($nombre), $https ? 'ok' : 'error', "{$nombre} con HTTPS",
                $https ? "{$nombre} = {$url}." : "{$nombre} = \"{$url}\": debe empezar por https:// (los enlaces de los correos y el retorno de Wompi usan esta dirección).");
        }

        $r[] = $this->cuentasDePrueba();

        // M09 (19/10/2026): sin SuperAdmin nadie puede crear ni recuperar cuentas de Administrador.
        $superadmins = User::where('role', 'superadmin')->where('activo', true)->count();
        $r[] = $this->item('superadmin', $superadmins > 0 ? 'ok' : 'aviso', 'Cuenta de SuperAdmin',
            $superadmins > 0
                ? ($superadmins === 1 ? 'Hay un SuperAdmin activo.' : "Hay {$superadmins} SuperAdmin activos.")
                : 'No hay SuperAdmin: sin él nadie puede crear o cambiar cuentas de Administrador. Créalo con php artisan fragua:crear-superadmin.');

        // M10 (20/10/2026): informativo; la cuenta de Soporte es opcional y solo consulta.
        $soportes = User::where('role', 'soporte')->get(['activo']);
        $activas = $soportes->where('activo', true)->count();
        $r[] = $this->item('soporte', 'ok', 'Cuenta de Soporte',
            $soportes->isEmpty()
                ? 'No hay cuenta de Soporte (es opcional; se crea con php artisan fragua:crear-soporte).'
                : ($activas > 0
                    ? ($activas === 1 ? 'Hay una cuenta de Soporte activa (solo consulta).' : "Hay {$activas} cuentas de Soporte activas (solo consulta).")
                    : 'La cuenta de Soporte está desactivada.'));

        $correo = (string) config('mail.default');
        $r[] = $this->item('correo', in_array($correo, ['log', 'array'], true) ? 'error' : 'ok', 'Correo que sale de verdad',
            in_array($correo, ['log', 'array'], true) ? "MAIL_MAILER={$correo}: los correos no salen. Configura el SMTP." : "MAIL_MAILER={$correo}.");

        $cola = (string) config('queue.default');
        $r[] = $this->item('cola', $cola === 'sync' ? 'aviso' : 'ok', 'Cola de trabajos',
            $cola === 'sync' ? 'QUEUE_CONNECTION=sync: los correos se envían durante la petición y la vuelven lenta. Usa database con el trabajador de Supervisor.' : "QUEUE_CONNECTION={$cola}.");

        // CORREGIDO (16/10/2026): antes se leía el nivel del canal por defecto («stack»), que no tiene
        // nivel propio, así que siempre daba «debug» y avisaba aunque el .env dijera LOG_LEVEL=warning.
        // Ahora se mira el nivel de los canales que de verdad escriben (los del stack, como «daily»).
        $niveles = $this->nivelesDeLog((string) config('logging.default'));
        $conDebug = array_keys(array_filter($niveles, fn ($nivel) => $nivel === 'debug'));
        $r[] = $this->item('log', $conDebug ? 'aviso' : 'ok', 'Registro de errores sin detalles de más',
            $conDebug
                ? 'LOG_LEVEL=debug guarda demasiado detalle (canal '.implode(', ', $conDebug).'). En producción usa warning o error.'
                : 'LOG_LEVEL='.implode(', ', array_unique($niveles)).' (canal '.implode(', ', array_keys($niveles)).').');

        $r[] = $this->pasarela();

        // Fase 7, tarea 3 (RNF-M04-002): sin GD las fotos de productos se guardan sin reducir y el
        // menú carga más lento en el celular. No impide abrir: es un aviso.
        $gd = extension_loaded('gd') && function_exists('imagecreatefromstring');
        $r[] = $this->item('fotos', $gd ? 'ok' : 'aviso', 'Fotos de productos livianas',
            $gd ? 'La extensión GD de PHP está instalada: las fotos se reducen al subirlas.' : 'Falta la extensión GD de PHP (php-gd): las fotos se guardan tal como se suben. Instálala con apt install php-gd.');

        // M11 (28/10/2026, RF-M11-013): sin llaves VAPID la campana funciona, pero no llegan avisos al teléfono.
        $push = app(PushService::class)->disponible();
        $r[] = $this->item('push', $push ? 'ok' : 'aviso', 'Avisos push del personal',
            $push ? 'Las llaves VAPID están puestas: el personal puede activar los avisos en su teléfono.'
                : 'Faltan FRAGUA_REAL_PUSH_PUBLICA y FRAGUA_REAL_PUSH_PRIVADA: corre php artisan push:generar-llaves y cópialas en el .env.');

        $origenes = (array) config('cors.allowed_origins', []);
        $abierto = in_array('*', $origenes, true);
        $r[] = $this->item('cors', $abierto ? 'error' : 'ok', 'Orígenes permitidos para la API',
            $abierto ? 'CORS permite cualquier origen ("*"). Deja solo FRONTEND_URL y lo que haga falta en CORS_ORIGENES.' : 'Solo: '.implode(', ', $origenes).'.');

        return $r;
    }

    /**
     * Nivel de cada canal de log que escribe, siguiendo los «stack» hasta sus canales reales.
     *
     * @return array<string, string> canal => nivel
     */
    private function nivelesDeLog(string $canal, int $profundidad = 0): array
    {
        $config = (array) config("logging.channels.{$canal}", []);
        if (($config['driver'] ?? null) === 'stack' && $profundidad < 3) {
            $niveles = [];
            foreach ((array) ($config['channels'] ?? []) as $hijo) {
                $niveles += $this->nivelesDeLog(trim((string) $hijo), $profundidad + 1);
            }

            return $niveles;
        }

        return [$canal => strtolower((string) ($config['level'] ?? 'debug'))];
    }

    public function hayErrores(array $items): bool
    {
        return collect($items)->contains('nivel', 'error');
    }

    private function cuentasDePrueba(): array
    {
        // El personal (pocas cuentas) y las cuentas de demostración del sembrado; las contraseñas de
        // los clientes son de ellos y no se revisan (además, comprobar cada una tomaría mucho).
        $conPruebas = User::where('activo', true)
            ->where(fn ($q) => $q->whereIn('role', ['superadmin', 'administrador', 'soporte', 'mesero'])->orWhere('email', 'like', '%@fraguareal.com'))
            ->limit(30)
            ->get(['id', 'email', 'password'])
            ->filter(fn (User $u) => $this->usaContrasenaDePrueba((string) $u->password))
            ->pluck('email');

        return $this->item('cuentas_prueba', $conPruebas->isEmpty() ? 'ok' : 'error', 'Sin cuentas activas con contraseña de prueba',
            $conPruebas->isEmpty()
                ? 'Ninguna cuenta activa usa una contraseña de prueba.'
                : 'Cambia la contraseña o desactiva: '.$conPruebas->implode(', ').'.');
    }

    private function usaContrasenaDePrueba(string $hash): bool
    {
        // Una contraseña guardada sin cifrar (no debería existir) cuenta como de prueba.
        if (Hash::info($hash)['algoName'] === 'unknown') {
            return true;
        }

        return collect(self::CONTRASENAS_DE_PRUEBA)->contains(fn ($p) => Hash::check($p, $hash));
    }

    private function pasarela(): array
    {
        $proveedor = (string) config('fragua_real.pasarela.proveedor');
        if ($proveedor !== 'wompi') {
            return $this->item('pasarela', 'aviso', 'Pasarela de pago',
                'Modo manual: los pagos en línea los aprueba el personal. Para la confirmación automática, configura Wompi (README-ENTREGA).');
        }
        if (! app(PasarelaPago::class)->configurada()) {
            return $this->item('pasarela', 'error', 'Pasarela de pago', 'PASARELA_PROVEEDOR=wompi pero faltan llaves WOMPI_*: los pagos quedan en revisión manual.');
        }
        $sandbox = config('services.wompi.ambiente') !== 'produccion';
        $llavePrueba = str_starts_with((string) config('services.wompi.llave_publica'), 'pub_test_');

        return $this->item('pasarela', $sandbox || $llavePrueba ? 'aviso' : 'ok', 'Pasarela de pago',
            $sandbox || $llavePrueba
                ? 'Wompi está en pruebas (sandbox): ningún pago es real. Cambia a las llaves de producción y WOMPI_AMBIENTE=produccion.'
                : 'Wompi en producción.');
    }

    private function item(string $clave, string $nivel, string $titulo, string $detalle): array
    {
        return compact('clave', 'nivel', 'titulo', 'detalle');
    }
}
