<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comanda;
use App\Models\Pedido;
use App\Services\ComandaService;
use Illuminate\Http\Request;

/**
 * Fase 6, tarea 2 (RN-PED-06/09/17 a 19, RF-M02-T-004 a 007, D5): comandas de cocina impresas
 * desde el navegador. Solo el personal (rutas del grupo administrador/mesero).
 */
class ComandaController extends Controller
{
    public function __construct(private readonly ComandaService $comandas)
    {
    }

    // GET /api/comandas/pendientes — RNF-M02-011: lo que falta imprimir (pendientes y las que no salieron).
    public function pendientes()
    {
        return response()->json($this->comandas->porImprimir()->map(fn (Comanda $c) => ComandaService::resumen($c) + [
            'pedido' => [
                'id' => $c->pedido->id,
                'tipo' => $c->pedido->tipo,
                'estado' => $c->pedido->estado,
                'mesa' => $c->pedido->mesa?->nombre,
                'cliente' => $c->pedido->cliente?->nombre ?? $c->pedido->cliente_invitado_nombre,
            ],
        ])->values());
    }

    // GET /api/pedidos/{pedido}/comandas — todas las de un pedido, en orden.
    public function dePedido(Pedido $pedido)
    {
        return response()->json($pedido->comandas()->with('impresaPorUsuario:id,nombre')->get()
            ->map(fn (Comanda $c) => ComandaService::resumen($c) + ['impresa_por' => $c->impresaPorUsuario?->nombre]));
    }

    // GET /api/comandas/{comanda}/ticket — el ticket de 80 mm (PDF) para imprimir; queda registrado.
    public function ticket(Request $request, Comanda $comanda)
    {
        return $this->comandas->imprimir($comanda, $request->user());
    }

    // POST /api/comandas/{comanda}/descartar — M12: ya no hace falta imprimirla (Administrador, con motivo).
    public function descartar(Request $request, Comanda $comanda)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:255']], ['motivo.required' => 'Escribe por qué no se imprime.']);

        try {
            return response()->json(ComandaService::resumen($this->comandas->descartar($comanda, $request->user(), $datos['motivo'])));
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    // POST /api/comandas/{comanda}/no-impresa — HU-M02-016: no salió; vuelve a pendientes.
    public function noImpresa(Request $request, Comanda $comanda)
    {
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        return response()->json(ComandaService::resumen($this->comandas->marcarNoImpresa($comanda, $request->user(), $datos['motivo'] ?? null)));
    }
}
