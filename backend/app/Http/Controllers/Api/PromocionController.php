<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Catalogo\PonerOfertaRequest;
use App\Http\Requests\Api\Catalogo\StorePromocionRequest;
use App\Models\Producto;
use App\Models\Promocion;
use App\Services\AuditoriaService;
use App\Services\OfertaService;
use App\Services\PromocionService;
use Illuminate\Http\Request;

/**
 * M04 — Menú y Catálogo. CRUD de promociones para el Administrador (RN-M04-19 a 23):
 * fechas especiales (Navidad, Amor y Amistad, etc.) se modelan como una promoción más con
 * vigencia_inicio/vigencia_fin, sin lógica especial por fecha. La evaluación de vigencia
 * (fecha/franja/día de semana) vive en PromocionService — este Controller solo persiste
 * las reglas; CatalogoController::promocionesDeProducto() sigue siendo la consulta pública
 * que usa el Cliente para ver las promociones vigentes de un producto.
 */
class PromocionController extends Controller
{
    // Eliminar (27/10/2026, RF-M04-060): sin las eliminadas.
    public function index()
    {
        return response()->json(Promocion::with(['productos', 'categorias', 'variante:id,nombre'])->whereNull('eliminado_at')->latest()->get());
    }

    // Fase 2, tarea 1 (RF-M04-036, HU-M04-015): promociones de tipo "manual" activas y vigentes,
    // que el Mesero o el Administrador pueden aplicar a un pedido con un motivo.
    public function manuales(PromocionService $promociones)
    {
        return response()->json(
            Promocion::with(['productos:id,nombre', 'categorias:id,nombre'])
                ->where('tipo', 'manual')->where('activo', true)->orderBy('nombre')->get()
                ->filter(fn (Promocion $p) => $promociones->vigente($p))
                ->values()
        );
    }

    public function store(StorePromocionRequest $request)
    {
        $promocion = $this->guardar(new Promocion(), $request);
        $this->auditar('crear_promocion', $promocion, null);

        return response()->json($promocion->load(['productos', 'categorias']), 201);
    }

    public function update(StorePromocionRequest $request, Promocion $promocion)
    {
        abort_if($promocion->eliminado_at !== null, 404, 'Esta promoción ya se eliminó.');
        $antes = $this->foto($promocion);
        $promocion = $this->guardar($promocion, $request);
        // RF-M04-040: desactivar una promoción es una edición más, y queda igual en la auditoría.
        $this->auditar('editar_promocion', $promocion, $antes);

        return response()->json($promocion->load(['productos', 'categorias']));
    }

    // Mejora (16/10/2026): atajo «Poner en oferta» desde el producto (ver OfertaService).
    public function ponerOferta(PonerOfertaRequest $request, Producto $producto, OfertaService $ofertas)
    {
        return response()->json($ofertas->poner($producto, $request->validated(), $request->user()), 201);
    }

    public function quitarOferta(Request $request, Producto $producto, OfertaService $ofertas)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json(['quitadas' => $ofertas->quitar($producto, $request->user())]);
    }

    // RF-M04-039 (Fase 2, tarea 1): cada promoción creada o editada queda en la auditoría.
    private function auditar(string $accion, Promocion $promocion, ?array $antes): void
    {
        app(AuditoriaService::class)->registrar($accion, 'Promocion', $promocion->id, array_filter([
            'antes' => $antes,
            'despues' => $this->foto($promocion->fresh(['productos', 'categorias'])),
        ]));
    }

    private function foto(Promocion $promocion): array
    {
        $promocion->loadMissing(['productos:id', 'categorias:id']);

        return $promocion->only(['nombre', 'tipo', 'alcance', 'tipo_valor', 'valor', 'vigencia_inicio', 'vigencia_fin', 'franja_inicio', 'franja_fin', 'dias_semana', 'activo']) + [
            'productos' => $promocion->productos->pluck('id')->all(),
            'categorias' => $promocion->categorias->pluck('id')->all(),
        ];
    }

    private function guardar(Promocion $promocion, StorePromocionRequest $request): Promocion
    {
        $data = $request->validated();
        $productos = $data['productos'] ?? [];
        $categorias = $data['categorias'] ?? [];
        unset($data['productos'], $data['categorias']);

        $promocion->fill($data);
        // Mejora (16/10/2026): una promoción de una sola presentación (oferta) deja de serlo si se le
        // cambia el alcance o el producto, para no rebajar una presentación de otro producto.
        if ($promocion->variante_id && ($promocion->alcance !== 'producto'
            || array_map('intval', $productos) !== [(int) $promocion->variante?->producto_id])) {
            $promocion->variante_id = null;
        }
        $promocion->save();

        // RN-M04-19/20: el vínculo solo tiene sentido en el lado que coincide con el
        // alcance actual — al cambiar de alcance (ej. de "producto" a "categoria") se
        // limpia el lado que dejó de aplicar, para no dejar un vínculo huérfano.
        $promocion->productos()->sync($promocion->alcance === 'producto' ? $productos : []);
        $promocion->categorias()->sync($promocion->alcance === 'categoria' ? $categorias : []);

        return $promocion;
    }
}
