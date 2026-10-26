<?php

namespace App\Http\Requests\Api\Usuario;

use App\Support\Usuarios\NombreUsuario;
use Illuminate\Foundation\Http\FormRequest;

// El Administrador crea cuentas de staff (Mesero o Administrador) desde esta pantalla —
// el Cliente se autorregistra aparte (RegisterClienteRequest, AuthController::register()),
// por eso "role" solo acepta mesero/administrador acá, nunca cliente.
class StoreUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // 22/10/2026: el teléfono se guarda solo con números y el nombre sin espacios de más.
        \App\Support\Validacion\Formatos::normalizar($this, ['telefono' => 'telefono', 'nombre' => 'nombre']);
        if ($this->has('username')) {
            $this->merge(['username' => NombreUsuario::normalizar($this->input('username'))]);
        }
    }

    public function messages(): array
    {
        return NombreUsuario::mensajes() + [
            'telefono.regex' => \App\Support\Validacion\Formatos::MENSAJE_CELULAR,
            'nombre.regex' => \App\Support\Validacion\Formatos::MENSAJE_PERSONA,
        ];
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'in:mesero,administrador'],
            'nombre' => ['required', 'string', 'max:80', 'regex:'.\App\Support\Validacion\Formatos::PERSONA],
            'email' => ['bail', 'required', 'email', \App\Support\Validacion\Eliminados::libre('users', 'email', 'Ese correo es de una cuenta eliminada: restáurala desde Usuarios › Eliminados.'), 'unique:users,email'],
            // Fase 3, tarea 3 (RF-M01-007/011): nombre de usuario opcional para iniciar sesión.
            'username' => NombreUsuario::reglas(),
            'telefono' => ['nullable', 'string', 'regex:'.\App\Support\Validacion\Formatos::CELULAR],
            'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()], // contraseña segura (RN-USR-23)
        ];
    }
}
