<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSuscripcion;
use App\Services\PushService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * M11 — Avisos push del personal (28/10/2026, RF-M11-013, RN-M11-09, HU-M11-008): activar, desactivar y
 * probar los avisos en el equipo que se está usando. Cada cuenta maneja solo los suyos.
 */
class PushController extends Controller
{
    public function __construct(private readonly PushService $push)
    {
    }

    // POST /push/estado — si el servidor tiene avisos push y si este equipo (su `endpoint`) ya los tiene activos.
    public function estado(Request $request)
    {
        $endpoint = (string) ($request->validate(['endpoint' => ['nullable', 'string', 'max:1000']])['endpoint'] ?? '');

        return response()->json([
            'disponible' => $this->push->disponible(),
            'llave_publica' => $this->push->disponible() ? $this->push->llavePublica() : null,
            'activa' => $endpoint !== '' && $this->push->activaEn($request->user(), $endpoint),
        ]);
    }

    // POST /push/suscripciones — activa los avisos en este equipo, unidos a esta sesión.
    public function suscribir(Request $request)
    {
        abort_unless($this->push->disponible(), 422, 'Los avisos push todavía no están configurados en el servidor.');
        $datos = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:40'],
        ]);
        $token = $request->user()->currentAccessToken();
        $this->push->suscribir(
            $request->user(),
            $token instanceof PersonalAccessToken && $token->exists ? $token->id : null,
            $datos['endpoint'], $datos['keys']['p256dh'], $datos['keys']['auth'],
            $request->userAgent(),
        );

        return response()->json(['activa' => true], 201);
    }

    // DELETE /push/suscripciones — los apaga en este equipo.
    public function desuscribir(Request $request)
    {
        $datos = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $this->push->desuscribir($request->user(), $datos['endpoint']);

        return response()->json(['activa' => false]);
    }

    // POST /push/prueba — manda un aviso de prueba solo a este equipo.
    public function probar(Request $request)
    {
        $datos = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $suscripcion = PushSuscripcion::where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSuscripcion::huella($datos['endpoint']))->first();
        abort_if($suscripcion === null, 404, 'Los avisos no están activos en este equipo.');

        $llego = $this->push->entregar($suscripcion, [
            'titulo' => 'Avisos activados',
            'texto' => 'Así te llegarán los avisos de Fragua Real en este equipo.',
            'enlace' => null,
            'etiqueta' => 'prueba',
        ]);
        abort_unless($llego, 502, 'El servicio de avisos del navegador no aceptó la prueba. Intenta de nuevo en un momento.');

        return response()->json(['enviado' => true]);
    }
}
