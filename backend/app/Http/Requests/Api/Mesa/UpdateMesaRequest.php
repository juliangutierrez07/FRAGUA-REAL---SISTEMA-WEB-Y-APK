<?php

namespace App\Http\Requests\Api\Mesa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Fase 1, tarea 6: RF-M05-002, HU-M05-002, RN-M05-01/10, CA-M05-002 — el Administrador corrige
// el número o nombre de una mesa; no puede repetir el de otra mesa.
class UpdateMesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['bail', 'required', 'string', 'max:255', \App\Support\Validacion\Eliminados::libre('mesas', 'nombre', 'Ya hay una mesa eliminada con ese nombre: restáurala desde Mesas › Eliminadas o usa otro nombre.', $this->route('mesa')), Rule::unique('mesas', 'nombre')->ignore($this->route('mesa'))],
        ];
    }

    public function messages(): array
    {
        return ['nombre.unique' => 'Ya existe otra mesa con ese nombre.'];
    }
}
