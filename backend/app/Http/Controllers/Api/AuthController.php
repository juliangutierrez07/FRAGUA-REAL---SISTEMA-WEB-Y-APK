<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\CambiarPasswordRequest;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterClienteRequest;
use App\Http\Requests\Api\Auth\RestablecerContrasenaRequest;
use App\Http\Requests\Api\Auth\SolicitarRecuperacionRequest;
use App\Http\Requests\Api\Auth\UpdatePerfilRequest;
use App\Models\User;
use App\Notifications\RecuperarPasswordNotification;
use App\Services\AuditoriaService;
use App\Services\BloqueoInicioSesionService;
use App\Services\SesionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación por token de Sanctum (no por cookie/SPA), a propósito: evita problemas de
 * CORS entre dominios y el mismo mecanismo sirve para la futura app móvil (ver Documento
 * Técnico del Stack).
 */
class AuthController extends Controller
{
    public function register(RegisterClienteRequest $request)
    {
        $user = User::create([
            'role' => 'cliente',
            'nombre' => $request->nombre,
            'email' => $request->email,
            'username' => $request->validated('username'),
            'telefono' => $request->telefono,
            'password' => Hash::make($request->password),
            'activo' => true,
        ]);

        // Fase 3, tarea 4: la sesión guarda IP, navegador y su vencimiento máximo.
        ['token' => $token, 'sesion' => $sesion] = app(SesionService::class)->abrir($user, $request, 'cliente');
        $this->auditar('registro_cliente', $user, ['sesion_id' => $sesion->id], $user);

        return response()->json(['user' => $user, 'token' => $token], 201);
    }

    // RF-M01-007 (Fase 3, tarea 3): con el correo o con el nombre de usuario. Si lo escrito lleva
    // "@" es un correo; si no, un nombre de usuario (se guarda en minúsculas, así que "Mario" y
    // "mario" son el mismo). El mensaje de error es el mismo en todos los casos.
    public function login(LoginRequest $request, BloqueoInicioSesionService $bloqueo)
    {
        $identificador = $request->identificador();
        $esCorreo = $request->esCorreo();

        // Seguridad (21/10/2026, RF-M01-029, RN-USR-24): con demasiados intentos fallidos de esta
        // cuenta o de esta IP, ni siquiera se revisa la contraseña hasta que pase el bloqueo.
        if ($minutos = $bloqueo->minutosBloqueado($identificador, $request)) {
            app(AuditoriaService::class)->registrar('inicio_sesion_bloqueado', 'User', null, [
                ($esCorreo ? 'correo' : 'usuario') => mb_substr($identificador, 0, 120),
                'minutos_restantes' => $minutos,
            ]);

            return response()->json(['message' => $bloqueo->mensaje($minutos), 'bloqueado_minutos' => $minutos], 429);
        }

        $user = $esCorreo
            ? User::where('email', $identificador)->whereNull('eliminado_at')->first()
            : User::where('username', mb_strtolower($identificador))->whereNull('eliminado_at')->first();

        if (! $user || ! Hash::check($request->password, $user->password) || ! $user->activo) {
            // RF-M01-017 (Fase 3, tarea 1): el intento fallido queda registrado con el correo
            // usado, el motivo y el origen. El mensaje al usuario sigue siendo el mismo en
            // todos los casos (no revela si la cuenta existe).
            $motivo = ! $user ? 'cuenta_inexistente'
                : (Hash::check($request->password, $user->password) ? 'cuenta_inactiva' : 'contrasena_incorrecta');
            app(AuditoriaService::class)->registrar('inicio_sesion_fallido', 'User', $user?->id, [
                ($esCorreo ? 'correo' : 'usuario') => mb_substr($identificador, 0, 120),
                'motivo' => $motivo,
            ]);

            if ($bloqueo->fallido($identificador, $request, $user)) {
                $minutos = (int) config('fragua_real.seguridad.bloqueo_minutos', 15);

                return response()->json(['message' => $bloqueo->mensaje($minutos), 'bloqueado_minutos' => $minutos], 429);
            }

            throw ValidationException::withMessages([
                $request->campo() => ['Las credenciales no son válidas o el usuario está inactivo.'],
            ]);
        }

        $bloqueo->limpiar($identificador);

        ['token' => $token, 'sesion' => $sesion] = app(SesionService::class)->abrir($user, $request);
        $this->auditar('inicio_sesion', $user, ['via' => $esCorreo ? 'correo' : 'usuario', 'sesion_id' => $sesion->id], $user);

        return response()->json(['user' => $user, 'token' => $token]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        $this->auditar('cierre_sesion', $request->user());

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    // RF-M01-010/023: cerrar sesión en todos los dispositivos (revoca todos los tokens
    // del usuario autenticado, no solo el de la petición actual).
    public function logoutTodos(Request $request)
    {
        $request->user()->tokens()->delete();
        $this->auditar('cierre_sesion_todos', $request->user());

        return response()->json(['message' => 'Sesión cerrada en todos los dispositivos.']);
    }

    // RF-M01-010, RF-M01-024, HU-M01-010 (Fase 3, tarea 4): las sesiones abiertas de quien
    // consulta, con su dispositivo, IP, último uso y cuándo vencen; la actual va marcada.
    public function sesiones(Request $request, SesionService $sesiones)
    {
        return response()->json([
            'sesiones' => $sesiones->listar($request->user(), $request->user()->currentAccessToken()),
            'politica' => $sesiones->politica(),
        ]);
    }

    // Cerrar una sesión propia en particular (p. ej. el celular que se quedó en otro lado).
    public function cerrarSesion(Request $request, SesionService $sesiones, int $sesion)
    {
        $usuario = $request->user();
        $esActual = $usuario->currentAccessToken()?->getKey() === $sesion;
        $cerrada = $sesiones->cerrar($usuario, $sesion);
        abort_if(! $cerrada, 404, 'Esa sesión ya no está abierta.');

        $this->auditar('cerrar_sesion_dispositivo', $usuario, [
            'sesion_id' => $sesion, 'dispositivo' => $cerrada['dispositivo'], 'ip' => $cerrada['ip'], 'era_la_actual' => $esActual,
        ]);

        return response()->json(['message' => 'Sesión cerrada.', 'era_la_actual' => $esActual]);
    }

    // Cerrar todas las demás sesiones y quedarse con la actual.
    public function cerrarOtrasSesiones(Request $request, SesionService $sesiones)
    {
        $usuario = $request->user();
        $cerradas = $sesiones->cerrarOtras($usuario, $usuario->currentAccessToken());
        $this->auditar('cerrar_otras_sesiones', $usuario, ['sesiones_cerradas' => $cerradas]);

        return response()->json(['message' => 'Se cerraron tus demás sesiones.', 'cerradas' => $cerradas]);
    }

    // revocarSesiones() se trasladó a UsuarioController (M01 — Gestión de Usuarios ya tiene
    // su propio Controller; esta acción vivía aquí solo como solución provisional).

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    // RF-M01: el usuario autenticado edita su propio nombre/teléfono (ver
    // UpdatePerfilRequest — el email no se toca aquí, es el identificador de login).
    public function actualizarPerfil(UpdatePerfilRequest $request)
    {
        $usuario = $request->user();
        $antes = $usuario->only(array_keys($request->validated()));
        $usuario->update($request->validated());

        $cambios = collect($request->validated())
            ->filter(fn ($valor, $campo) => (string) ($antes[$campo] ?? '') !== (string) $valor)
            ->map(fn ($valor, $campo) => ['antes' => $antes[$campo] ?? null, 'despues' => $valor])
            ->all();
        if ($cambios) {
            $this->auditar('actualizar_perfil', $usuario, ['cambios' => $cambios]);
        }

        return response()->json($usuario->fresh());
    }

    // RF-M01: el usuario autenticado cambia su propia contraseña, verificando la actual
    // antes de aceptar la nueva. Al igual que restablecerContrasena(), revoca las demás
    // sesiones activas — solo se deja viva la que hizo el cambio (currentAccessToken).
    public function cambiarPassword(CambiarPasswordRequest $request)
    {
        $usuario = $request->user();

        if (! Hash::check($request->password_actual, $usuario->password)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $usuario->password = Hash::make($request->password);
        $usuario->save();

        $tokenActual = $usuario->currentAccessToken();
        $usuario->tokens()->when($tokenActual, fn ($q) => $q->where('id', '!=', $tokenActual->id))->delete();
        $this->auditar('cambiar_contrasena', $usuario, ['otras_sesiones_cerradas' => true]);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    // RF-M01-012: solicita la recuperación de contraseña. No revela si el correo existe
    // (evita enumeración de cuentas) — siempre responde igual.
    public function solicitarRecuperacion(SolicitarRecuperacionRequest $request)
    {
        $user = User::where('email', $request->email)->whereNull('eliminado_at')->first();

        if ($user) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            // El token en texto plano solo vive en memoria para este correo — en la tabla
            // password_reset_tokens se guarda hasheado (línea de arriba), igual que una
            // contraseña.
            $user->notify(new RecuperarPasswordNotification($token));
            // Solo queda en la auditoría interna; la respuesta sigue siendo igual exista o no.
            $this->auditar('solicitar_recuperacion', $user, [], $user);
        }

        return response()->json(['message' => 'Si el correo existe, se enviarán instrucciones de recuperación.']);
    }

    // RF-M01-013: restablece la contraseña con el token de solicitarRecuperacion().
    public function restablecerContrasena(RestablecerContrasenaRequest $request)
    {
        $registro = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (! $registro || ! Hash::check($request->token, $registro->token)) {
            $this->restablecimientoFallido($request->email, 'token_invalido');
            throw ValidationException::withMessages(['token' => ['El token de recuperación no es válido.']]);
        }

        if (CarbonImmutable::parse($registro->created_at)->addHour()->isPast()) {
            $this->restablecimientoFallido($request->email, 'token_vencido');
            throw ValidationException::withMessages(['token' => ['El token de recuperación expiró; solicita uno nuevo.']]);
        }

        $user = User::where('email', $request->email)->whereNull('eliminado_at')->firstOrFail();
        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // RF-M01-010: al cambiar la contraseña se revocan todas las sesiones activas —
        // quien tenía una sesión abierta con la contraseña vieja queda desconectado.
        $user->tokens()->delete();
        // Seguridad (21/10/2026): con la contraseña nueva, la cuenta deja de estar bloqueada.
        app(BloqueoInicioSesionService::class)->limpiarCuenta($user);
        $this->auditar('restablecer_contrasena', $user, ['sesiones_revocadas' => true], $user);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    // RF-M01-017, RF-M01-024 (Fase 3, tarea 1): eventos de seguridad de la cuenta — quién,
    // cuándo, desde qué IP y con qué navegador (AuditoriaService guarda el origen).
    private function auditar(string $accion, User $usuario, array $detalles = [], ?User $actor = null): void
    {
        app(AuditoriaService::class)->registrar($accion, 'User', $usuario->id, $detalles, $actor ?? $usuario);
    }

    private function restablecimientoFallido(?string $correo, string $motivo): void
    {
        $usuario = User::where('email', $correo)->whereNull('eliminado_at')->first();
        app(AuditoriaService::class)->registrar('restablecer_contrasena_fallido', 'User', $usuario?->id, [
            'correo' => mb_substr((string) $correo, 0, 120),
            'motivo' => $motivo,
        ]);
    }
}
