<?php

namespace App\Http\Requests\Api\Reserva;

use Illuminate\Foundation\Http\FormRequest;

// RN-M05-16: el depósito de una reserva manual lo registra el Mesero o el Administrador,
// con cualquier método de cobro presencial de M06.
class RegistrarDepositoManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El rol se filtra por la ruta (role:administrador,mesero).
    }

    public function rules(): array
    {
        return [
            // RN-M05-16: el depósito manual es presencial. CORREGIDO (Fase 1): antes aceptaba
            // también "en_linea", que es el pago del Cliente por la pasarela, no un registro manual.
            'metodo' => ['required', 'in:efectivo,tarjeta,transferencia'],
            // Mejora (26/10/2026, RF-M05-045 y 046): en efectivo, con cuánto pagó el cliente (para el cambio); en
            // transferencia, la foto del comprobante (obligatoria; en tarjeta, opcional).
            'recibido' => ['nullable', 'numeric', 'min:0', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
            'comprobante' => [\Illuminate\Validation\Rule::requiredIf(fn () => $this->input('metodo') === 'transferencia'), 'nullable', 'file',
                'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
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
