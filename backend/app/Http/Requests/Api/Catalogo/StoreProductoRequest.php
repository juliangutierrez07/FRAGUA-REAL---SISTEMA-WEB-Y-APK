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
            // 27/10/2026 (RF-M04-057): no en una categoría eliminada.
            'categoria_id' => ['required', \Illuminate\Validation\Rule::exists('categorias', 'id')->whereNull('eliminado_at')],
            // RF-M04-028, RN-M04-14 (Fase 2, tarea 3): nombre único en todo el catálogo, con mensaje
            // claro (antes solo lo frenaba la base de datos con un error 500).
            'nombre' => ['required', 'string', 'max:255', \Illuminate\Validation\Rule::unique('productos', 'nombre')->whereNull('eliminado_at')->ignore($this->route('producto'))],
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
            'categoria_id.exists' => 'Esa categoría no existe: elige otra.',
            'precio_base.gt' => 'El precio debe ser mayor a cero.',
        ];
    }
}
