<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\User;
use App\Services\EliminacionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Eliminar mesas, productos y usuarios (24/10/2026). Ver EliminacionService: lo que nunca se usó se
 * borra y lo que tiene historial se archiva; se restaura desde «Eliminados». Solo Administrador y
 * SuperAdmin (rutas del grupo role:administrador), con contraseña y motivo.
 *
 *  GET  /{mesas|productos|usuarios}/{id}/eliminacion  → qué pasaría (borrar o archivar) y si hay bloqueo
 *  POST /{mesas|productos|usuarios}/{id}/eliminar     → {password, motivo}
 *  POST /{mesas|productos|usuarios}/{id}/restaurar
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

    public function restaurarMesa(Request $request, Mesa $mesa)
    {
        return $this->restaurar($request, $mesa);
    }

    public function vistaProducto(Request $request, Producto $producto)
    {
        return $this->vista($request, $producto);
    }

    public function eliminarProducto(Request $request, Producto $producto)
    {
        return $this->eliminar($request, $producto);
    }

    public function restaurarProducto(Request $request, Producto $producto)
    {
        return $this->restaurar($request, $producto);
    }

    public function vistaUsuario(Request $request, User $usuario)
    {
        return $this->vista($request, $usuario);
    }

    public function eliminarUsuario(Request $request, User $usuario)
    {
        return $this->eliminar($request, $usuario);
    }

    public function restaurarUsuario(Request $request, User $usuario)
    {
        return $this->restaurar($request, $usuario);
    }

    private function vista(Request $request, Model $registro)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json($this->eliminacion->vistaPrevia($registro, $request->user()));
    }

    private function eliminar(Request $request, Model $registro)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $datos = $request->validate([
            'password' => ['required', 'string'],
            'motivo' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'password.required' => 'Escribe tu contraseña para confirmar.',
            'motivo.required' => 'Escribe por qué lo eliminas.',
            'motivo.min' => 'Escribe por qué lo eliminas (al menos 3 letras).',
        ]);

        return response()->json($this->eliminacion->eliminar($registro, $request->user(), $datos['password'], $datos['motivo']));
    }

    private function restaurar(Request $request, Model $registro)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json($this->eliminacion->restaurar($registro, $request->user()));
    }
}
