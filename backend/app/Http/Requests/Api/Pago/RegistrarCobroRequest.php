<?php

namespace App\Http\Requests\Api\Pago;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cobro presencial hecho por un empleado (Administrador o Mesero, según el canal).
 * Soporta pago mixto: el cliente envía uno o varios pagos para el mismo pedido.
 */
class RegistrarCobroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PagoPolicy::cobrar() se verifica en el Controller.
    }

    public function rules(): array
    {
        return [
            'metodo' => ['required', 'in:efectivo,tarjeta,transferencia'],
            'monto' => ['required', 'numeric', 'min:0.01', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
            // Mejora (26/10/2026): foto del comprobante tomada con la cámara; obligatoria en transferencia.
            'comprobante' => [\Illuminate\Validation\Rule::requiredIf(fn () => $this->input('metodo') === 'transferencia'), 'nullable', 'file',
                'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
            // Mejora (26/10/2026): cobrar solo una cuenta de la mesa (POST /mesas/{mesa}/cobrar).
            'cuenta' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'comprobante.required' => 'Toma la foto del comprobante de la transferencia.',
            'comprobante.mimes' => 'El comprobante debe ser una foto (JPG, PNG o WebP).',
            'comprobante.mimetypes' => 'El comprobante debe ser una foto (JPG, PNG o WebP).',
            'comprobante.max' => 'La foto no puede pesar más de 5 MB.',
        ];
    }
}
