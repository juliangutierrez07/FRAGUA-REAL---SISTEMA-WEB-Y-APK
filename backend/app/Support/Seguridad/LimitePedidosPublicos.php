<?php

namespace App\Support\Seguridad;

use App\Models\Pedido;
use Illuminate\Validation\ValidationException;

/**
 * Seguridad (28/10/2026, RN-PED-36): topes para los pedidos que llegan sin el personal de por medio (la web y
 * el QR de la mesa). El límite de peticiones (LimitesDePeticiones, «pedido-publico») frena a quien manda muchos
 * seguidos; esto frena a quien llena la cola de la cocina con pedidos falsos aunque vaya despacio:
 *  - un mismo comprador (cuenta del Cliente o celular del invitado) puede tener hasta 3 pedidos esperando que
 *    el personal los confirme;
 *  - una mesa, hasta 4 pedidos del QR esperando confirmación.
 * El pedido que toma el personal no tiene estos topes. Los números están en config/fragua_real.php, «limites».
 */
class LimitePedidosPublicos
{
    public function revisar(array $data): void
    {
        if (($data['origen'] ?? null) === 'mesero') {
            return;
        }

        if ($data['tipo'] === 'local' && ! empty($data['mesa_id'])) {
            $maximo = $this->numero('pendientes_por_mesa', 4);
            $enEspera = Pedido::where('mesa_id', $data['mesa_id'])->where('origen', 'qr')->where('estado', 'pendiente')->count();
            if ($enEspera >= $maximo) {
                throw ValidationException::withMessages([
                    'mesa_id' => ["Esta mesa ya tiene {$enEspera} pedidos esperando que el mesero los confirme. Espera un momento o llama al mesero."],
                ]);
            }

            return;
        }

        $consulta = Pedido::where('estado', 'pendiente')->whereIn('tipo', ['domicilio', 'recogida']);
        if (! empty($data['cliente_id'])) {
            $consulta->where('cliente_id', $data['cliente_id']);
        } elseif (! empty($data['cliente_invitado_telefono'])) {
            $consulta->whereNull('cliente_id')->where('cliente_invitado_telefono', $data['cliente_invitado_telefono']);
        } else {
            return;
        }

        $maximo = $this->numero('pendientes_por_comprador', 3);
        $enEspera = $consulta->count();
        if ($enEspera >= $maximo) {
            throw ValidationException::withMessages([
                'lineas' => ["Ya tienes {$enEspera} pedidos esperando que el restaurante los confirme. Espera la confirmación o llámanos para agregar algo."],
            ]);
        }
    }

    private function numero(string $clave, int $porDefecto): int
    {
        return max(1, (int) config("fragua_real.limites.{$clave}", $porDefecto));
    }
}
