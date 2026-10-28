<?php

namespace App\Http\Requests\Api\Configuracion;

use App\Support\Reservas\PoliticaReservas;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConfiguracionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La ruta ya está detrás de role:administrador (routes/api.php).
        return true;
    }

    public function rules(): array
    {
        $reglas = [
            'valor_domicilio' => ['required', 'numeric', 'min:0', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
            'deposito_reserva' => ['required', 'numeric', 'min:0', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
        ];

        // Fase 4, tarea 2 (RF-M05-017/026): tiempos de las reservas, en minutos enteros. Son
        // opcionales para no romper a quien solo envíe domicilio y depósito.
        foreach (PoliticaReservas::RANGOS as $clave => [$minimo, $maximo]) {
            $reglas[$clave] = ['sometimes', 'integer', "min:{$minimo}", "max:{$maximo}"];
        }
        // 27/10/2026 (RF-M06-039): hora de cierre de la caja y tolerancia antes de cerrarla sola.
        [$minimo, $maximo] = \App\Support\Caja\PoliticaCaja::RANGO_TOLERANCIA;
        $reglas[\App\Support\Caja\PoliticaCaja::HORA_CIERRE] = ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $reglas[\App\Support\Caja\PoliticaCaja::TOLERANCIA] = ['sometimes', 'integer', "min:{$minimo}", "max:{$maximo}"];

        return $reglas;
    }

    public function messages(): array
    {
        return [
            PoliticaReservas::TOLERANCIA.'.min' => 'La tolerancia debe ser de al menos :min minutos.',
            PoliticaReservas::TOLERANCIA.'.max' => 'La tolerancia no puede pasar de :max minutos.',
            PoliticaReservas::RECORDATORIO.'.min' => 'El recordatorio debe salir al menos :min minutos antes.',
            PoliticaReservas::RECORDATORIO.'.max' => 'El recordatorio no puede salir más de 48 horas antes.',
            PoliticaReservas::VENTANA.'.min' => 'La mesa debe marcarse Reservada al menos :min minutos antes.',
            PoliticaReservas::VENTANA.'.max' => 'La mesa no puede marcarse Reservada más de 4 horas antes.',
            '*.integer' => 'Escribe un número entero de minutos.',
            \App\Support\Caja\PoliticaCaja::HORA_CIERRE.'.regex' => 'Escribe la hora en formato de 24 horas, por ejemplo 23:30.',
            \App\Support\Caja\PoliticaCaja::TOLERANCIA.'.max' => 'La tolerancia de la caja no puede pasar de 6 horas (360 minutos).',
        ];
    }
}
