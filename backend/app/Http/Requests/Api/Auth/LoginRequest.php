<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RF-M01-007 (Fase 3, tarea 3): iniciar sesión con el correo O con el nombre de usuario.
 * El campo nuevo es "login"; "email" se sigue aceptando para no romper clientes anteriores
 * (la futura app móvil, por ejemplo). Si el texto lleva "@" se busca como correo; si no, como
 * nombre de usuario (que nunca lleva "@", ver App\Support\Usuarios\NombreUsuario).
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:login', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
            // Seguridad (28/10/2026, RF-M01-036): las llaves de equipo de confianza que guarda este navegador.
            'equipos_confianza' => ['nullable', 'array', 'max:10'],
            'equipos_confianza.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required_without' => 'Escribe tu correo o tu nombre de usuario.',
            'email.required_without' => 'Escribe tu correo o tu nombre de usuario.',
        ];
    }

    /** Lo que escribió la persona (correo o nombre de usuario), sin espacios a los lados. */
    public function identificador(): string
    {
        return trim((string) ($this->input('login') ?? $this->input('email')));
    }

    /** Campo donde se muestra el error: el mismo que envió el formulario. */
    public function campo(): string
    {
        return $this->filled('login') ? 'login' : 'email';
    }

    public function esCorreo(): bool
    {
        return str_contains($this->identificador(), '@');
    }
}
