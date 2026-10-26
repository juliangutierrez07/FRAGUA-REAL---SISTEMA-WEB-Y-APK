<?php

namespace App\Http\Requests\Api\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esAdministrador() ?? false;
    }

    public function rules(): array
    {
        return [
            'categoria_id' => ['required', 'exists:categorias,id'],
            // RF-M04-028, RN-M04-14 (Fase 2, tarea 3): nombre único en todo el catálogo, con mensaje
            // claro (antes solo lo frenaba la base de datos con un error 500).
            'nombre' => ['bail', 'required', 'string', 'max:255', \App\Support\Validacion\Eliminados::libre('productos', 'nombre', 'Ya hay un producto eliminado con ese nombre: restáuralo desde Catálogo › Eliminados o usa otro nombre.', $this->route('producto')), \Illuminate\Validation\Rule::unique('productos', 'nombre')->ignore($this->route('producto'))],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            // RF-M04-023, RN-M04-10: todo precio mayor a cero.
            'precio_base' => ['required', 'numeric', 'gt:0', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
            'tipo_venta' => ['required', 'in:bebida,alimento,otro'],
            'disponible' => ['boolean'],
            'imagen_url' => ['nullable', 'string', 'max:255'],
            'activo' => ['boolean'],
            // RF-M04-011 (Fase 2, tarea 3): extras generales que ofrece este producto.
            'extras_generales' => ['sometimes', 'array'],
            'extras_generales.*' => ['integer', \Illuminate\Validation\Rule::exists('extras', 'id')->where('alcance', 'general')],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.unique' => 'Ya existe un producto con ese nombre.',
            'precio_base.gt' => 'El precio debe ser mayor a cero.',
        ];
    }
}
