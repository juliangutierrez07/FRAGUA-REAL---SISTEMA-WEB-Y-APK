<?php

namespace App\Http\Requests\Api\Mesa;

use Illuminate\Foundation\Http\FormRequest;

class StoreMesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['bail', 'required', 'string', 'max:255', \App\Support\Validacion\Eliminados::libre('mesas', 'nombre', 'Ya hay una mesa eliminada con ese nombre: restáurala desde Mesas › Eliminadas o usa otro nombre.'), 'unique:mesas,nombre'], // RN-M05-10
            'activa' => ['boolean'],
        ];
    }
}
