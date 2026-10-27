<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use App\Services\EliminacionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Eliminar mesas, productos y usuarios (24/10/2026) y, desde el 27/10/2026 (RF-M04-057 a 060),
 * categorías, combos, extras y promociones. Ver EliminacionService: eliminar es definitivo (ya no hay
 * restaurar); lo que tiene historial sale de todo pero su fila se guarda. Solo Administrador y
 * SuperAdmin (rutas del grupo role:administrador), con contraseña; el motivo solo para una cuenta.
 *
 *  GET  /{tipo}/{id}/eliminacion  → qué pasaría, si hay bloqueo y si pide motivo
 *  POST /{tipo}/{id}/eliminar     → {password, motivo (solo usuarios)}
 */
class EliminacionController extends Controller
{
    public function __construct(private readonly EliminacionService $eliminacion) {}

    public function vistaMesa(Request $request, Mesa $mesa)
    {
        return $this->vista($request, $mesa);
    }

    public function eliminarMesa(Request $request, Mesa $mesa)
    {
        return $this->eliminar($request, $mesa);
    }


    public function vistaProducto(Request $request, Producto $producto)
    {
        return $this->vista($request, $producto);
    }

    public function eliminarProducto(Request $request, Producto $producto)
    {
        return $this->eliminar($request, $producto);
    }


    public function vistaUsuario(Request $request, User $usuario)
    {
        return $this->vista($request, $usuario);
    }

    public function eliminarUsuario(Request $request, User $usuario)
    {
        return $this->eliminar($request, $usuario);
    }


    public function vistaCategoria(Request $request, Categoria $categoria)
    {
        return $this->vista($request, $categoria);
    }

    public function eliminarCategoria(Request $request, Categoria $categoria)
    {
        return $this->eliminar($request, $categoria);
    }


    public function vistaCombo(Request $request, Combo $combo)
    {
        return $this->vista($request, $combo);
    }

    public function eliminarCombo(Request $request, Combo $combo)
    {
        return $this->eliminar($request, $combo);
    }


    public function vistaExtra(Request $request, Extra $extra)
    {
        return $this->vista($request, $extra);
    }

    public function eliminarExtra(Request $request, Extra $extra)
    {
        return $this->eliminar($request, $extra);
    }


    public function vistaPromocion(Request $request, Promocion $promocion)
    {
        return $this->vista($request, $promocion);
    }

    public function eliminarPromocion(Request $request, Promocion $promocion)
    {
        return $this->eliminar($request, $promocion);
    }


    private function vista(Request $request, Model $registro)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json($this->eliminacion->vistaPrevia($registro, $request->user()));
    }

    private function eliminar(Request $request, Model $registro)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $motivo = EliminacionService::pideMotivo($registro) ? ['required', 'string', 'min:3', 'max:255'] : ['nullable', 'string', 'max:255'];
        $datos = $request->validate([
            'password' => ['required', 'string'],
            'motivo' => $motivo,
        ], [
            'password.required' => 'Escribe tu contraseña para confirmar.',
            'motivo.required' => 'Escribe por qué eliminas la cuenta.',
            'motivo.min' => 'Escribe por qué eliminas la cuenta (al menos 3 letras).',
        ]);

        return response()->json($this->eliminacion->eliminar($registro, $request->user(), $datos['password'], $datos['motivo'] ?? null));
    }

}
