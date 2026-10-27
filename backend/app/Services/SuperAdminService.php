<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * M09 (19/10/2026): la cuenta del SuperAdmin, el dueño del sistema. No se crea desde la aplicación
 * (nadie la puede crear, desactivar ni cambiar de rol en Usuarios): se crea o se asigna en el
 * servidor con `php artisan fragua:crear-superadmin` (RF-M09-001, RN-M09-01/03).
 */
class SuperAdminService
{
    public function __construct(private readonly AuditoriaService $auditoria)
    {
    }

    /**
     * Crea la cuenta o, si ya existe una con ese correo (por ejemplo, el Administrador de siempre),
     * la vuelve SuperAdmin. La contraseña solo se pone al crearla o si se pide cambiarla.
     *
     * @return array{usuario: User, creada: bool}
     */
    public function crearOAsignar(string $correo, ?string $nombre, ?string $password): array
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
            $creada = $usuario === null;

            if ($creada) {
                if ($password === null) {
                    throw new RuntimeException('Para crear la cuenta hace falta una contraseña.');
                }
                $usuario = User::create([
                    'role' => 'superadmin',
                    'nombre' => $nombre ?: 'SuperAdmin',
                    'email' => $correo,
                    'password' => Hash::make($password),
                    'activo' => true,
                ]);
            } else {
                $antes = $usuario->role;
                $usuario->forceFill(array_filter([
                    'role' => 'superadmin',
                    'activo' => true,
                    'puede_registrar_gastos' => false,
                    'nombre' => $nombre ?: null,
                    'password' => $password !== null ? Hash::make($password) : null,
                ], fn ($v) => $v !== null))->save();
                // Con otro rol, las sesiones abiertas cambian de permisos: se cierran (RF-M01-023).
                if ($antes !== 'superadmin') {
                    $usuario->tokens()->delete();
                }
            }

            $this->auditoria->registrar($creada ? 'crear_superadmin' : 'asignar_superadmin', 'User', $usuario->id, [
                'correo' => $usuario->email,
                'origen' => 'servidor (php artisan fragua:crear-superadmin)',
            ]);

            return ['usuario' => $usuario->fresh(), 'creada' => $creada];
        });
    }
}
