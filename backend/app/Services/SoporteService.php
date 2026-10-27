<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * M10 (20/10/2026): la cuenta de Soporte, la del desarrollador. Se crea solo en el servidor con
 * `php artisan fragua:crear-soporte` (RF-M10-001, RN-M10-01). El comando no convierte en Soporte
 * una cuenta del negocio (Mesero, Administrador, SuperAdmin o Cliente): solo crea una nueva o
 * cambia el nombre o la contraseña de una que ya es de Soporte. No la activa ni la desactiva:
 * eso lo decide el SuperAdmin en Usuarios (RN-M10-04).
 */
class SoporteService
{
    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    /**
     * @return array{usuario: User, creada: bool}
     */
    public function crearOActualizar(string $correo, ?string $nombre, ?string $password): array
    {
        $correo = mb_strtolower(trim($correo));
        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Escribe un correo válido.');
        }
        // Seguridad (21/10/2026, RN-USR-23): la misma regla de contraseña segura de la aplicación.
        if ($password !== null) {
            $validador = \Illuminate\Support\Facades\Validator::make(['password' => $password], ['password' => [\Illuminate\Validation\Rules\Password::defaults()]]);
            if ($validador->fails()) {
                throw new RuntimeException($validador->errors()->first('password'));
            }
        }

        return DB::transaction(function () use ($correo, $nombre, $password) {
            $usuario = User::where('email', $correo)->whereNull('eliminado_at')->first();
            if ($usuario && ! $usuario->esSoporte()) {
                throw new RuntimeException("Ese correo ya es de una cuenta de {$usuario->role}. Usa otro correo para Soporte: una cuenta del negocio no se convierte en Soporte.");
            }
            $creada = $usuario === null;

            if ($creada) {
                if ($password === null) {
                    throw new RuntimeException('Para crear la cuenta hace falta una contraseña.');
                }
                $usuario = User::create([
                    'role' => 'soporte',
                    'nombre' => $nombre ?: 'Soporte técnico',
                    'email' => $correo,
                    'password' => Hash::make($password),
                    'activo' => true,
                ]);
            } else {
                $usuario->forceFill(array_filter([
                    'nombre' => $nombre ?: null,
                    'password' => $password !== null ? Hash::make($password) : null,
                ], fn ($v) => $v !== null))->save();
                // Con contraseña nueva, las sesiones abiertas con la anterior se cierran.
                if ($password !== null) {
                    $usuario->tokens()->delete();
                }
            }

            $this->auditoria->registrar($creada ? 'crear_soporte' : 'actualizar_soporte', 'User', $usuario->id, array_filter([
                'correo' => $usuario->email,
                'contrasena_cambiada' => ! $creada && $password !== null ? true : null,
                'origen' => 'servidor (php artisan fragua:crear-soporte)',
            ], fn ($v) => $v !== null));

            return ['usuario' => $usuario->fresh(), 'creada' => $creada];
        });
    }
}
