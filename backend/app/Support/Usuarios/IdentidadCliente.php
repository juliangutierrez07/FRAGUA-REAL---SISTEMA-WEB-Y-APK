<?php

namespace App\Support\Usuarios;

use App\Models\User;
use App\Support\Validacion\Formatos;
use Illuminate\Validation\Rule;

/**
 * Fidelización (27/10/2026, M01: RF-M01-035, RN-USR-26; M06: RF-M06-040): el Cliente tiene un número de
 * documento (cédula, cédula de extranjería o pasaporte) que no se repite, y su celular, si lo da, tampoco.
 * Así el Mesero lo encuentra al cobrar por documento, correo o celular y le deja el pedido en su historial.
 * El personal no cuenta en la unicidad (índices parciales `WHERE role = 'cliente'`).
 */
final class IdentidadCliente
{
    /** 5 a 15 letras o números, con al menos un número (sin puntos, espacios ni guiones). */
    public const DOCUMENTO = '/^(?=.*\d)[A-Z0-9]{5,15}$/';

    public const MENSAJE_DOCUMENTO = 'Escribe tu número de documento (cédula, cédula de extranjería o pasaporte) sin puntos ni espacios: de 5 a 15 letras o números.';

    /** «1.020.304.050» → «1020304050»; «ab 123-456» → «AB123456». Vacío → null. */
    public static function normalizarDocumento(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }
        $limpio = strtoupper(preg_replace('/[\s.\-]+/', '', $valor));

        return $limpio === '' ? null : $limpio;
    }

    /** @return array<int, mixed> */
    public static function reglasDocumento(bool $obligatorio, ?int $ignorar = null): array
    {
        return [
            $obligatorio ? 'required' : 'nullable', 'string', 'regex:'.self::DOCUMENTO,
            Rule::unique('users', 'documento')->where('role', 'cliente')->whereNull('eliminado_at')->ignore($ignorar),
        ];
    }

    public static function reglaCelularUnico(?int $ignorar = null): \Illuminate\Validation\Rules\Unique
    {
        return Rule::unique('users', 'telefono')->where('role', 'cliente')->whereNull('eliminado_at')->ignore($ignorar);
    }

    /** @return array<string, string> */
    public static function mensajes(): array
    {
        return [
            'documento.required' => 'Escribe tu número de documento.',
            'documento.regex' => self::MENSAJE_DOCUMENTO,
            'documento.unique' => 'Ese número de documento ya está registrado en otra cuenta.',
            'telefono.unique' => 'Ese celular ya está registrado en otra cuenta.',
        ];
    }

    /**
     * El cliente vigente y activo con ese documento, correo o celular (exacto). Si el dato sirve a la vez de
     * documento de uno y de celular de otro, gana el documento.
     *
     * @return array{cliente: User, coincide: string}|null
     */
    public static function buscar(string $dato): ?array
    {
        $dato = trim($dato);
        $clientes = User::query()->where('role', 'cliente')->where('activo', true)->whereNull('eliminado_at');
        if (str_contains($dato, '@')) {
            // Seguridad (28/10/2026, RN-USR-28): por correo, solo si el Cliente lo confirmó (si no, cualquiera
            // podría registrarse con el correo de otro y quedarse con sus pedidos).
            $cliente = (clone $clientes)->whereNotNull('correo_verificado_at')->whereRaw('LOWER(email) = ?', [mb_strtolower($dato)])->first();

            return $cliente ? ['cliente' => $cliente, 'coincide' => 'correo'] : null;
        }
        $documento = self::normalizarDocumento($dato);
        if (is_string($documento) && $documento !== '' && $cliente = (clone $clientes)->where('documento', $documento)->first()) {
            return ['cliente' => $cliente, 'coincide' => 'documento'];
        }
        $celular = Formatos::telefono($dato);
        if (is_string($celular) && preg_match(Formatos::CELULAR, $celular) && $cliente = (clone $clientes)->where('telefono', $celular)->first()) {
            return ['cliente' => $cliente, 'coincide' => 'celular'];
        }

        return null;
    }
}
