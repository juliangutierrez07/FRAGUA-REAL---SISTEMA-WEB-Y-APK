<?php

namespace App\Support\Usuarios;

use Illuminate\Validation\Rule;

/**
 * M01 — Fase 3, tarea 3 (12/10/2026): nombre de usuario para iniciar sesión (RF-M01-007) y su
 * unicidad (RF-M01-011, RNF-M01-004, RN-USR-04).
 *
 * Reglas, iguales en registro, perfil y Usuarios:
 *  - Opcional: las cuentas que ya existían no tienen uno y siguen entrando con el correo.
 *  - De 3 a 30 caracteres; empieza con una letra; solo letras sin tilde, números, punto, guion y
 *    guion bajo. Nunca lleva "@", así el inicio de sesión sabe si le escribieron un correo o un
 *    nombre de usuario.
 *  - Se guarda en minúsculas y es único sin importar mayúsculas: "Mario" y "mario" son el mismo.
 */
final class NombreUsuario
{
    public const PATRON = '/^[a-z][a-z0-9._-]{2,29}$/';

    public const MENSAJE_FORMATO = 'El nombre de usuario debe tener de 3 a 30 caracteres, empezar con una letra y usar solo letras sin tilde, números, punto, guion o guion bajo.';

    public const MENSAJE_UNICO = 'Ese nombre de usuario ya lo usa otra cuenta.';

    /** Minúsculas y sin espacios a los lados; vacío = sin nombre de usuario. */
    public static function normalizar(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }
        $valor = mb_strtolower(trim($valor));

        return $valor === '' ? null : $valor;
    }

    public static function reglas(?int $ignorarId = null): array
    {
        return ['nullable', 'string', 'regex:'.self::PATRON, Rule::unique('users', 'username')->whereNull('eliminado_at')->ignore($ignorarId)];
    }

    public static function mensajes(): array
    {
        return [
            'username.regex' => self::MENSAJE_FORMATO,
            'username.string' => self::MENSAJE_FORMATO,
            'username.unique' => self::MENSAJE_UNICO,
        ];
    }
}
