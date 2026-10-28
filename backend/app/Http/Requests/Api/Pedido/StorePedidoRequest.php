<?php

namespace App\Http\Requests\Api\Pedido;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePedidoRequest extends FormRequest
{
    // Teléfono colombiano o internacional: dígitos con espacios, guiones o paréntesis, y un "+"
    // opcional al inicio; entre 7 y 15 dígitos en total.
    // 22/10/2026: 10 dígitos (celular o fijo de Colombia); el teléfono llega ya sin espacios ni +57.
    public const FORMATO_TELEFONO = \App\Support\Validacion\Formatos::CELULAR;

    // CORREGIDO (Fase 0, tarea 2, 09/10/2026): antes devolvía true siempre, y como POST
    // /pedidos es pública, cualquiera sin sesión podía crear un pedido con origen=mesero.
    // Ahora el origen "mesero" exige un token de Mesero o Administrador (RNF-M02-006,
    // RNF-TRANS-010). El invitado y el QR de mesa siguen entrando sin sesión.
    public function authorize(): bool
    {
        if ($this->input('origen') === 'mesero') {
            return $this->esPersonal();
        }

        return true;
    }

    private function esPersonal(): bool
    {
        $usuario = $this->user('sanctum');

        return $usuario !== null && ($usuario->esMesero() || $usuario->esAdministrador());
    }

    // CORREGIDO (29/09/2026): PedidoController::store() recién asigna cliente_id (del usuario
    // autenticado) DESPUÉS de $request->validated() — así que, sin este merge, esta validación
    // veía cliente_id vacío incluso para un Cliente con sesión, y "cliente_invitado_nombre"/
    // "telefono" (required_without:cliente_id) fallaban igual. Se adelanta aquí para que la
    // validación vea el mismo dato que el controlador va a usar.
    //
    // CORREGIDO (29/09/2026, 2da vuelta): "POST /pedidos" es una ruta pública (fuera del
    // grupo auth:sanctum, a propósito, para permitir Domicilio/Recogida de invitado) — así
    // que $this->user() (sin guard) siempre daba null, incluso con un Bearer token válido de
    // un Cliente con sesión: nada en el pipeline de esta ruta le pide a Sanctum que lo
    // resuelva. $this->user('sanctum') sí lo resuelve (Sanctum valida el token bajo cualquier
    // guard que se le pida, no solo cuando el middleware de la ruta ya lo hizo), sin exigir
    // el token — si no viene ninguno, sigue devolviendo null y el invitado no se ve afectado.
    protected function prepareForValidation(): void
    {
        $usuario = $this->user('sanctum');

        // CORREGIDO (Fase 0, tarea 2): el Cliente con sesión siempre compra a su nombre; antes
        // solo se completaba si venía vacío, y el cuerpo podía traer el id de otro cliente.
        if ($usuario && $usuario->esCliente()) {
            $this->merge(['cliente_id' => $usuario->id]);
        }
        \App\Support\Validacion\Formatos::normalizar($this, [
            'cliente_invitado_telefono' => 'telefono', 'telefono_contacto' => 'telefono', 'cliente_invitado_nombre' => 'nombre',
        ]);
    }

    public function rules(): array
    {
        // CORREGIDO (29/09/2026): "cliente_invitado_nombre/telefono" eran required_without:
        // cliente_id SIN importar el tipo — pero un pedido Local (Mesero, RN-PED) no identifica
        // al cliente en absoluto (se identifica por la mesa, ver PedidoService::
        // validarReglasDeCanal, que solo exige mesa_id para "local"); un Mesero creando un
        // pedido Local nunca manda cliente_id ni datos de invitado, así que la validación
        // rechazaba TODO pedido Local con "cliente_invitado_nombre is required". Ahora coincide
        // con esa misma regla de negocio: solo se exige cliente_id o invitado en domicilio/recogida.
        $requiereClienteOInvitado = fn () => in_array($this->input('tipo'), ['domicilio', 'recogida'], true)
            && ! $this->filled('cliente_id');

        // Canal y origen coherentes: el pedido local nace del Mesero o del QR de la mesa
        // (M02); domicilio y recogida nacen en la web o los registra el Mesero por teléfono
        // (M07, RF-M07-001). El QR nunca crea domicilios y la web nunca crea pedidos de mesa.
        $origenesValidos = $this->input('tipo') === 'local' ? ['mesero', 'qr'] : ['cliente_web', 'mesero'];

        // cliente_id solo lo fija el servidor (Cliente con sesión, arriba) o el personal al
        // registrar un pedido por teléfono para un cliente con cuenta. Un invitado no puede
        // atribuirle un pedido a otra persona.
        $usuario = $this->user('sanctum');
        $reglaCliente = $this->esPersonal() || ($usuario && $usuario->esCliente())
            ? ['nullable', Rule::exists('users', 'id')->where('role', 'cliente')]
            : ['prohibited'];

        // RF-M07-004, RF-M07-R-003 (Fase 1, tarea 2): un Cliente con cuenta sin teléfono en su
        // perfil tiene que escribir uno para este pedido; el invitado ya lo da en sus datos.
        $esRemoto = in_array($this->input('tipo'), ['domicilio', 'recogida'], true);
        $clienteSinTelefono = fn () => $esRemoto && $this->filled('cliente_id')
            && ! $this->filled('cliente_invitado_telefono')
            && blank(\App\Models\User::whereKey($this->input('cliente_id'))->value('telefono'));
        $telefono = ['nullable', 'string', 'regex:'.self::FORMATO_TELEFONO];
        // Seguridad (28/10/2026, RN-PED-36): un pedido de la web o del QR tiene un tope de líneas y de unidades por
        // línea (antes no había ninguno y se podía crear un pedido de millones). El personal tiene más margen.
        $personal = $this->esPersonal();
        $maxLineas = $personal ? 60 : (int) config('fragua_real.limites.lineas_por_pedido', 30);
        $maxCantidad = $personal ? 99 : (int) config('fragua_real.limites.unidades_por_linea', 20);

        return [
            'tipo' => ['required', 'in:local,domicilio,recogida'],
            'origen' => ['required', Rule::in($origenesValidos)],

            // RF-M02-L-002, RN-PED-02: solo mesas activas reciben pedidos.
            'mesa_id' => ['required_if:tipo,local', 'nullable', Rule::exists('mesas', 'id')->where('activa', true)],
            // Mejora (26/10/2026, RF-M02-L-033): cuentas separadas; el número lo valida PedidoService.
            'cuenta' => ['nullable', 'integer', 'min:1', 'max:50'],

            'cliente_id' => $reglaCliente,
            'cliente_invitado_nombre' => [Rule::requiredIf($requiereClienteOInvitado), 'nullable', 'string', 'max:80', 'regex:'.\App\Support\Validacion\Formatos::PERSONA],
            'cliente_invitado_telefono' => [Rule::requiredIf($requiereClienteOInvitado), ...$telefono],
            'telefono_contacto' => [Rule::requiredIf($clienteSinTelefono), ...$telefono],
            // Fase 4, tarea 5 (RF-M07-R-012): correo opcional del invitado para avisarle cuando su
            // pedido esté listo. El Cliente con cuenta usa el de su cuenta.
            'cliente_invitado_correo' => ['nullable', 'email', 'max:120'],

            // RN-M07-01, RF-M07-005/006/007, HU-M07-003: datos de entrega obligatorios.
            'direccion_entrega' => ['required_if:tipo,domicilio', 'nullable', 'string', 'max:160'],
            'barrio' => ['required_if:tipo,domicilio', 'nullable', 'string', 'max:80'],
            'referencia' => ['required_if:tipo,domicilio', 'nullable', 'string', 'max:160'],
            // Mejora (26/10/2026, RF-M07-033): la ubicación actual que comparte el Cliente (botón «Usar mi ubicación»).
            'ubicacion_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:ubicacion_lng'],
            'ubicacion_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:ubicacion_lat'],
            // RF-M07-010: observaciones de entrega del pedido completo.
            'observaciones_entrega' => ['nullable', 'string', 'max:500'],
            // RF-M07-013/015, RF-M07-R-007/008: cómo va a pagar y, si es en línea, por dónde.
            'metodo_pago_preferido' => ['nullable', 'in:contra_entrega,en_linea'],
            'pasarela_preferida' => ['required_if:metodo_pago_preferido,en_linea', 'nullable', 'in:nequi,bancolombia,breb'],
            // Mejora (26/10/2026, RF-M07-034): con qué billete paga contra entrega, para que el domiciliario lleve el
            // cambio. Vacío: paga con el valor exacto. PedidoService revisa que alcance para el total.
            'paga_con' => ['nullable', 'numeric', 'min:1000', 'max:'.\App\Support\Validacion\Formatos::MAX_PESOS],
            // valor_domicilio NO se recibe del Cliente: lo fija el servidor con el valor
            // vigente de config('fragua_real.valor_domicilio_default') — ver PedidoController.
            // Aceptarlo del body permitiría a cualquiera fijar su propio costo de domicilio.

            'lineas' => ['required', 'array', 'min:1', 'max:'.$maxLineas],
            'lineas.*.producto_id' => ['required_without:lineas.*.combo_id', 'nullable', 'exists:productos,id'],
            'lineas.*.combo_id' => ['required_without:lineas.*.producto_id', 'nullable', 'exists:combos,id'],
            'lineas.*.variante_id' => ['nullable', 'exists:variantes,id'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:'.$maxCantidad],
            'lineas.*.extras_ids' => ['nullable', 'array'],
            'lineas.*.extras_ids.*' => ['exists:extras,id'],
            'lineas.*.observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'cliente_invitado_telefono.regex' => \App\Support\Validacion\Formatos::MENSAJE_CELULAR,
            'lineas.max' => 'El pedido tiene demasiados productos distintos. Si es un pedido grande, llámanos.',
            'lineas.*.cantidad.max' => 'Puedes pedir hasta :max unidades de cada producto. Si necesitas más, llámanos.',
            'telefono_contacto.regex' => \App\Support\Validacion\Formatos::MENSAJE_CELULAR,
            'cliente_invitado_nombre.regex' => \App\Support\Validacion\Formatos::MENSAJE_PERSONA,
            'telefono_contacto.required' => 'Escribe un teléfono de contacto para este pedido.',
            'cliente_invitado_correo.email' => 'Escribe un correo válido o déjalo vacío.',
            'barrio.required_if' => 'Escribe el barrio de la entrega.',
            'referencia.required_if' => 'Escribe un punto de referencia para ubicar la dirección.',
            'direccion_entrega.required_if' => 'Escribe la dirección de entrega.',
        ];
    }
}
