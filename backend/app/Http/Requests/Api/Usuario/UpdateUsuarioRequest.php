<?php

namespace App\Http\Requests\Api\Usuario;

use App\Models\User;
use App\Support\Usuarios\GestionCuentas;
use App\Support\Usuarios\NombreUsuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * M01 — Fase 3, tarea 2 (12/10/2026): el Administrador corrige los datos de una cuenta y su rol.
 * RF-M01-003 (actualizar datos), RF-M01-005 y RF-M01-022 (solo roles del catálogo),
 * RF-M01-011 y RNF-M01-004 (correo único), RF-M01-021 (obligatorios y formato),
 * CA-M01-003.1 (solo campos permitidos: la contraseña no se cambia aquí).
 */
class UpdateUsuarioRequest extends FormRequest
{
    // Catálogo de roles que se asignan desde Usuarios (RNF-TRANS-011, RN-USR-01/14/15). El de
    // SuperAdmin (M09) no se asigna desde la aplicación: se crea en el servidor.
    public const ROLES = ['administrador', 'mesero', 'cliente'];

    public function authorize(): bool
    {
        // La ruta ya exige role:administrador. M09 (19/10/2026): además, las cuentas de Administrador
        // solo las edita el SuperAdmin y la del SuperAdmin no se edita desde aquí. M10 (20/10/2026):
        // la de Soporte tampoco (se cambia en el servidor).
        $usuario = $this->route('usuario');

        return ! $usuario instanceof User || GestionCuentas::motivoNoEdita($this->user(), $usuario) === null;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException((string) GestionCuentas::motivoNoEdita($this->user(), $this->route('usuario')));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre,
            'email' => is_string($this->email) ? trim($this->email) : $this->email,
            'telefono' => is_string($this->telefono) && trim($this->telefono) === '' ? null : $this->telefono,
        ]);
        if ($this->has('username')) {
            $this->merge(['username' => NombreUsuario::normalizar($this->input('username'))]);
        }
        \App\Support\Validacion\Formatos::normalizar($this, ['telefono' => 'telefono', 'nombre' => 'nombre']);
    }

    public function rules(): array
    {
        $usuario = $this->route('usuario');

        return [
            'nombre' => ['required', 'string', 'max:80', 'regex:'.\App\Support\Validacion\Formatos::PERSONA],
            'email' => ['bail', 'required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('eliminado_at')->ignore($usuario?->id)],
            'telefono' => ['nullable', 'string', 'regex:'.\App\Support\Validacion\Formatos::CELULAR],
            // Fase 3, tarea 3 (RF-M01-007/011): vacío = sin nombre de usuario (entra con el correo);
            // si no se envía, no se toca.
            'username' => ['sometimes', ...NombreUsuario::reglas($usuario?->id)],
            'role' => ['required', Rule::in(self::ROLES)],
            // Fase 7, tarea 2 (RF-M03-32): permiso de compras y gastos, solo para un Mesero.
            'puede_registrar_gastos' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...NombreUsuario::mensajes(),
            'email.unique' => 'Ese correo ya lo usa otra cuenta.',
            'role.in' => 'Ese rol no existe. Los roles del sistema son Administrador, Mesero y Cliente.',
        ];
    }
}
