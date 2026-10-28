<?php

namespace App\Http\Requests\Api\Auth;

use App\Support\Usuarios\IdentidadCliente;
use App\Support\Usuarios\NombreUsuario;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Autorregistro público, exclusivo para Clientes. Administrador y Mesero no se autorregistran
 * (M01 no describe un endpoint público para crear esos roles) — los crea un Administrador ya
 * autenticado a través de un endpoint aparte, fuera del alcance de este documento.
 */
class RegisterClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // 22/10/2026: el teléfono se guarda solo con números y el nombre sin espacios de más.
        \App\Support\Validacion\Formatos::normalizar($this, ['telefono' => 'telefono', 'nombre' => 'nombre']);
        if ($this->has('username')) {
            $this->merge(['username' => NombreUsuario::normalizar($this->input('username'))]);
        }
        if ($this->has('documento')) {
            $this->merge(['documento' => IdentidadCliente::normalizarDocumento($this->input('documento'))]);
        }
    }

    public function messages(): array
    {
        return NombreUsuario::mensajes() + IdentidadCliente::mensajes() + [
            'telefono.regex' => \App\Support\Validacion\Formatos::MENSAJE_CELULAR,
            'nombre.regex' => \App\Support\Validacion\Formatos::MENSAJE_PERSONA,
        ];
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:80', 'regex:'.\App\Support\Validacion\Formatos::PERSONA],
            'email' => ['bail', 'required', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->whereNull('eliminado_at')],
            // Fase 3, tarea 3 (RF-M01-007/011): nombre de usuario opcional para iniciar sesión.
            'username' => NombreUsuario::reglas(),
            // 27/10/2026 (RF-M01-035, RN-USR-26): documento obligatorio y único; celular opcional, pero único
            // entre clientes, para que el Mesero lo encuentre al cobrar.
            'documento' => IdentidadCliente::reglasDocumento(true),
            'telefono' => ['nullable', 'string', 'regex:'.\App\Support\Validacion\Formatos::CELULAR, IdentidadCliente::reglaCelularUnico()],
            'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()], // contraseña segura (RN-USR-23)
        ];
    }
}
