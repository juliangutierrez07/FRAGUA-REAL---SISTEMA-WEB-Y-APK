<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mejora (19/10/2026): datos del negocio que el Administrador cambia desde Configuración —
 * dirección, teléfono (también el de WhatsApp) y logo — y que salen en el Inicio, el pie de
 * página, Reservar, los encabezados de las pantallas y los PDF (comprobante, cierre y reportes).
 *
 * - Dirección y teléfono viven en la tabla `configuraciones` (`negocio_direccion`,
 *   `negocio_telefono`); mientras no se hayan cambiado, salen de config/fragua_real.php.
 * - El logo se guarda en el disco privado (`storage/app/private/negocio`, dentro del respaldo
 *   diario) y se sirve por GET /api/negocio/logo con una versión en la dirección (?v=…) para que el
 *   navegador lo guarde en caché y uno nuevo se vea enseguida. Se reduce a 512 px y se guarda en PNG
 *   (conserva la transparencia y los PDF lo pueden mostrar).
 * - Cada cambio queda en Auditoría (RF-M01-017), con el antes y el después.
 */
class NegocioService
{
    public const DISCO = 'local';
    public const CARPETA = 'negocio';
    public const LADO_MAXIMO = 512;

    public const DIRECCION = 'negocio_direccion';
    public const TELEFONO = 'negocio_telefono';
    public const LOGO = 'negocio_logo_path';
    // M09 (19/10/2026): nombre y NIT, solo los cambia el SuperAdmin.
    public const NOMBRE = 'negocio_nombre';
    public const NIT = 'negocio_nit';
    // M09 (20/10/2026): el punto del local en el mapa, lo elige el SuperAdmin.
    public const LATITUD = 'negocio_latitud';
    public const LONGITUD = 'negocio_longitud';

    /** Dominios de Google desde los que se aceptan enlaces (y redirecciones) de ubicación. */
    private const HOSTS_GOOGLE = ['goo.gl', 'maps.app.goo.gl', 'google.com', 'maps.google.com', 'www.google.com', 'google.com.co', 'www.google.com.co', 'consent.google.com'];

    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    /** Lo que usan los PDF: nombre, NIT, dirección, teléfono y el logo listo para incrustar. */
    public function datos(): array
    {
        $base = config('fragua_real.negocio');

        return [
            'nombre' => Configuracion::obtener(self::NOMBRE) ?? ($base['nombre'] ?? 'Fragua Real Burger'),
            'nit' => Configuracion::obtener(self::NIT) ?? ($base['nit'] ?? ''),
            'direccion' => Configuracion::obtener(self::DIRECCION) ?? ($base['direccion'] ?? ''),
            'telefono' => Configuracion::obtener(self::TELEFONO) ?? ($base['telefono'] ?? ''),
            'latitud' => $this->coordenada(self::LATITUD),
            'longitud' => $this->coordenada(self::LONGITUD),
            'logo' => $this->logoParaPdf(),
        ];
    }

    /** Mejora (28/10/2026): el pie de los correos (nombre, dirección y WhatsApp), sin cargar el logo. */
    public function contacto(): array
    {
        $base = config('fragua_real.negocio');
        $telefono = Configuracion::obtener(self::TELEFONO) ?? ($base['telefono'] ?? '');

        return [
            'nombre' => Configuracion::obtener(self::NOMBRE) ?? ($base['nombre'] ?? 'Fragua Real Burger'),
            'direccion' => Configuracion::obtener(self::DIRECCION) ?? ($base['direccion'] ?? ''),
            'telefono' => $telefono,
            'whatsapp_url' => $this->whatsapp($telefono),
        ];
    }

    /** Lo que ve cualquiera en el sitio (GET /configuracion). */
    public function publico(): array
    {
        $datos = $this->datos();
        $ruta = $this->rutaLogo();

        return [
            'nombre' => $datos['nombre'],
            'nit' => $datos['nit'],
            'direccion' => $datos['direccion'],
            'telefono' => $datos['telefono'],
            'whatsapp_url' => $this->whatsapp($datos['telefono']),
            'latitud' => $datos['latitud'],
            'longitud' => $datos['longitud'],
            // «Cómo llegar»: Google Maps en el punto exacto; sin punto, buscando la dirección.
            'mapa_url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(
                $datos['latitud'] !== null ? $datos['latitud'].','.$datos['longitud'] : $datos['direccion']),
            'logo_url' => $ruta ? '/api/negocio/logo?v='.substr(md5($ruta), 0, 10) : null,
        ];
    }

    /**
     * @param  array{direccion?: string, telefono?: string, nombre?: string, nit?: string|null}  $valores
     *         solo los que se cambian; nombre y NIT los manda solo el SuperAdmin (NegocioController).
     */
    public function actualizar(array $valores, ?User $actor): array
    {
        $claves = ['direccion' => self::DIRECCION, 'telefono' => self::TELEFONO, 'nombre' => self::NOMBRE, 'nit' => self::NIT,
            'latitud' => self::LATITUD, 'longitud' => self::LONGITUD];
        $antes = $this->datos();
        $cambios = [];
        foreach ($claves as $campo => $clave) {
            if (! array_key_exists($campo, $valores)) {
                continue;
            }
            $nuevo = in_array($campo, ['latitud', 'longitud'], true)
                ? ($valores[$campo] === null || $valores[$campo] === '' ? '' : (string) round((float) $valores[$campo], 7))
                : trim((string) ($valores[$campo] ?? ''));
            Configuracion::fijar($clave, $nuevo);
            if ((string) $antes[$campo] !== $nuevo) {
                $cambios[$campo] = [(string) $antes[$campo], $nuevo];
            }
        }
        if ($cambios) {
            $this->auditoria->registrar('editar_datos_negocio', 'Configuracion', null, [
                'cambios' => array_map(fn ($par) => ['antes' => $par[0], 'despues' => $par[1]], $cambios),
            ], $actor);
        }

        return $this->publico();
    }

    public function guardarLogo(UploadedFile $archivo, ?User $actor): array
    {
        $contenido = $this->preparar($archivo);
        $ruta = self::CARPETA.'/logo-'.Str::lower(Str::random(12)).'.png';
        Storage::disk(self::DISCO)->put($ruta, $contenido);

        $anterior = $this->rutaLogo();
        Configuracion::fijar(self::LOGO, $ruta);
        if ($anterior && $anterior !== $ruta) {
            Storage::disk(self::DISCO)->delete($anterior);
        }
        $this->auditoria->registrar('cambiar_logo_negocio', 'Configuracion', null, ['antes' => $anterior, 'despues' => $ruta], $actor);

        return $this->publico();
    }

    public function quitarLogo(?User $actor): array
    {
        $anterior = $this->rutaLogo();
        Configuracion::query()->where('clave', self::LOGO)->delete();
        if ($anterior) {
            Storage::disk(self::DISCO)->delete($anterior);
            $this->auditoria->registrar('quitar_logo_negocio', 'Configuracion', null, ['antes' => $anterior], $actor);
        }

        return $this->publico();
    }

    /** Ruta del logo en el disco, solo si el archivo existe. */
    public function rutaLogo(): ?string
    {
        $ruta = Configuracion::obtener(self::LOGO);

        return $ruta && Storage::disk(self::DISCO)->exists($ruta) ? $ruta : null;
    }

    private function coordenada(string $clave): ?float
    {
        $valor = Configuracion::obtener($clave);

        return $valor === null || $valor === '' ? null : (float) $valor;
    }

    /**
     * M09 (20/10/2026): las coordenadas de un enlace de Google Maps (o de un texto «lat, lng»).
     * Los enlaces cortos de «Compartir» (maps.app.goo.gl) se siguen hasta la dirección larga, solo
     * por dominios de Google. Devuelve null si no encuentra un punto.
     *
     * @return array{latitud: float, longitud: float}|null
     */
    public function coordenadasDeEnlace(string $enlace): ?array
    {
        $enlace = trim($enlace);
        if ($punto = $this->leerCoordenadas(rawurldecode($enlace))) {
            return $punto;
        }
        $host = strtolower((string) parse_url($enlace, PHP_URL_HOST));
        if (! $this->esDeGoogle($host)) {
            return null;
        }
        try {
            $respuesta = \Illuminate\Support\Facades\Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (FraguaReal)'])
                ->withOptions(['allow_redirects' => [
                    'max' => 6,
                    'on_redirect' => function ($pedido, $resp, $uri) {
                        if (! $this->esDeGoogle(strtolower($uri->getHost()))) {
                            throw new RuntimeException('El enlace no es de Google Maps.');
                        }
                    },
                ]])
                ->get($enlace);
        } catch (\Throwable) {
            return null;
        }
        $final = rawurldecode((string) $respuesta->effectiveUri());

        return $this->leerCoordenadas($final) ?? $this->leerCoordenadas(substr((string) $respuesta->body(), 0, 200000));
    }

    private function esDeGoogle(string $host): bool
    {
        foreach (self::HOSTS_GOOGLE as $permitido) {
            if ($host === $permitido || str_ends_with($host, '.'.$permitido)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{latitud: float, longitud: float}|null */
    private function leerCoordenadas(string $texto): ?array
    {
        $num = '(-?\d{1,3}\.\d+)';
        $patrones = [
            "/!3d{$num}!4d{$num}/",                                   // el pin del lugar
            "/[?&](?:q|query|ll|destination|center|daddr)=(?:loc:)?{$num}\s*,\s*{$num}/",
            "/@{$num},{$num}/",                                       // el centro del mapa
            "/^\s*{$num}\s*,\s*{$num}\s*$/",                       // «1.6144, -75.6062»
        ];
        foreach ($patrones as $patron) {
            if (preg_match($patron, $texto, $m)) {
                [$lat, $lng] = [(float) $m[1], (float) $m[2]];
                if (abs($lat) <= 90 && abs($lng) <= 180 && ! ($lat == 0.0 && $lng == 0.0)) {
                    return ['latitud' => round($lat, 7), 'longitud' => round($lng, 7)];
                }
            }
        }

        return null;
    }

    /** "322 725 9965" → https://wa.me/573227259965 (sin indicativo se asume Colombia). */
    public function whatsapp(string $telefono): ?string
    {
        $digitos = preg_replace('/\D+/', '', $telefono);
        if ($digitos === '') {
            return null;
        }
        if (strlen($digitos) === 10) {
            $digitos = '57'.$digitos;
        }

        return 'https://wa.me/'.$digitos;
    }

    /** El logo como data URI para los PDF (dompdf no puede pedirlo por HTTP a la API). */
    public function logoParaPdf(): ?string
    {
        $ruta = $this->rutaLogo();

        return $ruta ? 'data:image/png;base64,'.base64_encode((string) Storage::disk(self::DISCO)->get($ruta)) : null;
    }

    /** PNG de 512 px como máximo, con su transparencia. Sin GD se guarda tal cual (debe ser PNG). */
    private function preparar(UploadedFile $archivo): string
    {
        $original = (string) file_get_contents($archivo->getRealPath());
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            if ($archivo->getMimeType() !== 'image/png') {
                throw new RuntimeException('El servidor no puede convertir la imagen: sube el logo en PNG.');
            }

            return $original;
        }

        $imagen = @imagecreatefromstring($original);
        if ($imagen === false) {
            throw new RuntimeException('No pudimos leer la imagen. Prueba con otra (PNG, JPG o WebP).');
        }
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $escala = min(1, self::LADO_MAXIMO / max($ancho, $alto));
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));
        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        imagecopyresampled($destino, $imagen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($imagen);

        ob_start();
        imagepng($destino, null, 8);
        $contenido = (string) ob_get_clean();
        imagedestroy($destino);

        return $contenido;
    }
}
