<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mesa\LiberarMesaForzadaRequest;
use App\Http\Requests\Api\Mesa\StoreMesaRequest;
use App\Http\Requests\Api\Mesa\UpdateMesaRequest;
use App\Http\Requests\Api\Pago\RegistrarCobroRequest;
use App\Services\AuditoriaService;
use App\Services\MesaCuentaService;
use App\Models\Mesa;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MesaController extends Controller
{
    // Público (Cliente/QR/Mesero) solo ve mesas activas; el Administrador necesita ver
    // también las inactivas para poder reactivarlas — mismo criterio que
    // CatalogoController::categorias()/productos(), y mismo motivo: sin esto, una mesa
    // dada de baja desaparecería para siempre de la gestión. Esta ruta es pública (vive
    // fuera del grupo auth:sanctum) así que se pide el guard "sanctum" explícitamente en
    // vez de $request->user() — ver la nota en CatalogoController sobre por qué.
    public function index(Request $request)
    {
        $query = Mesa::query();

        $usuario = $request->user('sanctum');
        // CORREGIDO (19/10/2026): el filtro solo se aplicaba al Cliente y al visitante, así que el
        // Mesero seguía viendo (y podía abrir o pedir en) las mesas dadas de baja. Solo el
        // Administrador ve las inactivas, para poder reactivarlas.
        if (! $usuario || ! $usuario->esAdministrador()) {
            $query->where('activa', true);
        }
        // Eliminar (24/10/2026): las archivadas solo salen con ?eliminadas=1 (para restaurarlas).
        $eliminadas = $request->boolean('eliminadas') && $usuario?->esAdministrador();
        $eliminadas ? $query->whereNotNull('eliminado_at') : $query->whereNull('eliminado_at');

        $mesas = $query->get();

        // Backfill: las mesas sembradas antes de que store() generara qr_code (ver esta
        // misma clase, más abajo) se quedaron sin QR — RF-M05-005/006 las necesita a
        // todas para el QR imprimible y el pedido por QR del Cliente. Se completa aquí,
        // la primera vez que el personal lista las mesas, en vez de depender de una
        // migración de datos o de un comando artisan manual.
        if ($usuario && ($usuario->esAdministrador() || $usuario->esMesero())) {
            $mesas->whereNull('qr_code')->each(function (Mesa $mesa) {
                $mesa->qr_code = (string) Str::uuid();
                $mesa->save();
            });
        }

        // Fase 1, tarea 3 (RF-M02-L-019): el personal ve en cada mesa ocupada cuánto lleva su
        // cuenta y cuánto falta por pagar, sin abrir el detalle.
        if ($usuario && ($usuario->esAdministrador() || $usuario->esMesero())) {
            $mesas->where('estado', 'ocupada')->each(function (Mesa $mesa) {
                $pedidos = $mesa->pedidosDeLaCuenta()->with('pagos')->get();
                $total = (float) $pedidos->sum('total');
                $pagado = (float) $pedidos->sum(fn ($p) => $p->pagos->where('anulado', false)->where('estado', 'aprobado')->sum('monto'));
                $mesa->setAttribute('cuenta_total', round($total, 2));
                $mesa->setAttribute('cuenta_saldo', round(max(0, $total - $pagado), 2));
                $mesa->setAttribute('cuenta_pedidos', $pedidos->count());
                // Mejora (26/10/2026): cuentas separadas, para elegir a cuál va un pedido nuevo.
                $mesa->setAttribute('cuentas', app(MesaCuentaService::class)->porCuenta($pedidos));
            });
        }

        return response()->json($mesas);
    }

    // RF-M02-L-025: resolver la mesa que el Cliente escaneó, público y sin autenticar —
    // es el primer paso del pedido por QR (ver MesaQrView.vue). Solo mesas activas: una
    // mesa dada de baja (desactivar()) no debe aceptar pedidos aunque alguien conserve el
    // QR viejo impreso. 404 genérico (sin distinguir "no existe" de "inactiva") para no
    // filtrar esa diferencia a quien escanea un código ajeno o viejo.
    public function porQrCode(string $qr_code)
    {
        $mesa = Mesa::where('qr_code', $qr_code)->where('activa', true)->first();

        abort_if(! $mesa, 404, 'Este código QR ya no es válido. Pide ayuda a un mesero.');

        return response()->json($mesa);
    }

    public function store(StoreMesaRequest $request)
    {
        $mesa = Mesa::create([
            ...$request->validated(),
            'estado' => 'libre',
            // Identificador único del QR de la mesa (canal LOCAL) — RN-M05-10/RF-M05-004.
            'qr_code' => (string) Str::uuid(),
        ]);

        // RF-M05-021 (Fase 1, tarea 6): crear, editar, dar de baja o regenerar el QR de una mesa
        // queda en la auditoría.
        $this->auditar('crear_mesa', $mesa, $request, ['nombre' => $mesa->nombre]);

        return response()->json($mesa, 201);
    }

    // RF-M05-002, HU-M05-002 (Fase 1, tarea 6): corregir el número o nombre de una mesa. El QR no
    // cambia (sigue apuntando a la misma mesa) ni se tocan su estado ni su historial.
    public function update(UpdateMesaRequest $request, Mesa $mesa)
    {
        abort_if($mesa->eliminado_at !== null, 422, 'La mesa está eliminada: restáurala primero desde «Eliminadas».');
        $anterior = $mesa->nombre;
        $mesa->nombre = trim($request->nombre);
        $mesa->save();

        $this->auditar('editar_mesa', $mesa, $request, ['antes' => $anterior, 'despues' => $mesa->nombre]);

        return response()->json($mesa);
    }

    private function auditar(string $accion, Mesa $mesa, Request $request, array $detalles = []): void
    {
        app(AuditoriaService::class)->registrar($accion, 'Mesa', $mesa->id, $detalles, $request->user());
    }

    // RF-M05-009: el Mesero (o el Administrador) puede abrir una mesa manualmente, sin
    // que exista todavía un pedido — a diferencia de PedidoService::crear(), que solo
    // marca 'ocupada' cuando ya hay un pedido local.
    public function abrir(Request $request, Mesa $mesa)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);
        abort_if($mesa->estado !== 'libre', 422, 'Solo una mesa libre puede abrirse.');

        $mesa->ocupar();

        return response()->json($mesa);
    }

    // RF-M05-010: liberar una mesa (vuelve a libre) — bloqueado si tiene pedidos activos,
    // igual que la liberación automática ya existente en PedidoService/ReservaService.
    public function liberar(Request $request, Mesa $mesa)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);
        abort_if(
            $mesa->tienePedidosActivos(),
            422,
            'La mesa tiene pedidos activos; usa la liberación forzada si de verdad hace falta.'
        );

        $mesa->liberar();

        return response()->json($mesa);
    }

    // RF-M05-011: liberación forzada, con motivo — salta la validación de pedidos activos
    // de liberar(). Mesero o Administrador (M05, HU-M05-007); el motivo es obligatorio y deja
    // registro en auditoría.
    public function liberarForzada(LiberarMesaForzadaRequest $request, Mesa $mesa)
    {
        $mesa->liberar();

        app(AuditoriaService::class)->registrar('liberar_mesa_forzada', 'Mesa', $mesa->id, ['motivo' => $request->motivo], $request->user());

        return response()->json($mesa);
    }

    // RF-M02-L-019/020, HU-M02-006: pedidos de la visita en curso, total, pagado y saldo.
    public function cuenta(Request $request, Mesa $mesa, MesaCuentaService $cuentas)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);

        return response()->json($cuentas->resumen($mesa));
    }

    // RF-M02-L-021, RF-M06-003/011: el Mesero cobra la mesa completa o una parte (pago mixto).
    public function cobrar(RegistrarCobroRequest $request, Mesa $mesa, MesaCuentaService $cuentas)
    {
        abort_if(! $request->user()->esMesero(), 403, 'Solo el Mesero registra cobros (RF-M06-003).');

        // Mejora (26/10/2026): se cobra una cuenta de la mesa (o toda, sin `cuenta`) y, si viene, con la
        // foto del comprobante (obligatoria en transferencia).
        $comprobante = $request->hasFile('comprobante')
            ? app(\App\Services\PagoDirectoService::class)->guardarComprobante($request->file('comprobante'), "cobro-mesa-{$mesa->id}")
            : null;

        return response()->json($cuentas->cobrar(
            $mesa, $request->user(), $request->metodo, (float) $request->monto,
            $request->filled('cuenta') ? (int) $request->cuenta : null, $comprobante,
        ));
    }

    // Baja lógica (RF-M05-004): nunca se borra una mesa con historial de pedidos/reservas.
    public function desactivar(Request $request, Mesa $mesa)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        abort_if($mesa->tienePedidosActivos(), 422, 'La mesa tiene pedidos activos.');

        $mesa->activa = false;
        $mesa->save();

        $this->auditar('desactivar_mesa', $mesa, $request, ['nombre' => $mesa->nombre]);

        return response()->json($mesa);
    }

    // Reverso de desactivar() — sin esto, una mesa dada de baja quedaría fuera de
    // circulación para siempre (no existe forma de reactivarla desde la UI ni la API).
    public function activar(Request $request, Mesa $mesa)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        abort_if($mesa->eliminado_at !== null, 422, 'La mesa está eliminada: restáurala primero desde «Eliminadas».');

        $mesa->activa = true;
        $mesa->save();

        $this->auditar('activar_mesa', $mesa, $request, ['nombre' => $mesa->nombre]);

        return response()->json($mesa);
    }

    // RF-M05-006: regenerar el QR de una mesa invalida el anterior (el UUID viejo deja de
    // resolver ninguna mesa) — útil si el QR impreso se pierde, se daña o se filtra.
    // Exclusiva Administrador, igual que activar()/desactivar().
    public function regenerarQr(Request $request, Mesa $mesa)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        $mesa->qr_code = (string) Str::uuid();
        $mesa->save();

        $this->auditar('regenerar_qr_mesa', $mesa, $request, ['nombre' => $mesa->nombre]);

        return response()->json($mesa);
    }
}
