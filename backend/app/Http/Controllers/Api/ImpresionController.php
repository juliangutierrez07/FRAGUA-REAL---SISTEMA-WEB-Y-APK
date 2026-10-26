<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipoImpresion;
use App\Services\ImpresionService;
use Illuminate\Http\Request;

/**
 * M12 — Impresoras (24/10/2026). Ver ImpresionService.
 *
 *  Desde cualquier equipo del personal (la clave del equipo va en la cabecera X-Equipo-Impresion):
 *   POST /impresion/equipos/vincular   {codigo} → {equipo, clave}
 *   POST /impresion/equipos/senal      → el equipo con sus ajustes (404 si ya no está registrado)
 *   GET  /impresion/prueba             → ticket de prueba (PDF de 80 mm)
 *   POST /impresion/equipos/prueba     {resultado: ok|fallo|cortado}
 *  Administrador y SuperAdmin (menú «Impresoras»):
 *   GET/POST /impresion/equipos, PUT/DELETE /impresion/equipos/{equipo}, POST /impresion/equipos/{equipo}/codigo,
 *   GET/PUT /impresion/formato, POST /impresion/vista-previa, GET /impresion/estado
 */
class ImpresionController extends Controller
{
    public function __construct(private readonly ImpresionService $impresion) {}

    // ------------------------------------------------------------------ desde el equipo

    public function vincular(Request $request)
    {
        $datos = $request->validate(['codigo' => ['required', 'digits:6']], [
            'codigo.required' => 'Escribe el código de 6 números que aparece en Impresoras.',
            'codigo.digits' => 'El código tiene 6 números.',
        ]);
        $resultado = $this->impresion->vincular($datos['codigo'], $request->user());
        abort_if($resultado === null, 422, 'Ese código no existe o ya venció. Pide uno nuevo en Impresoras.');
        [$equipo, $clave] = $resultado;

        return response()->json(['equipo' => $equipo, 'clave' => $clave]);
    }

    public function senal(Request $request)
    {
        return response()->json($this->impresion->senal($this->equipoDeLaPeticion(), $request->user()));
    }

    public function prueba(Request $request)
    {
        return $this->impresion->pdfPrueba($this->impresion->equipoActual(), $request->user());
    }

    public function resultadoPrueba(Request $request)
    {
        $datos = $request->validate(['resultado' => ['required', 'in:'.implode(',', array_keys(ImpresionService::RESULTADOS))]]);

        return response()->json($this->impresion->registrarPrueba($this->equipoDeLaPeticion(), $datos['resultado'], $request->user()));
    }

    // ------------------------------------------------------------------ administración

    public function equipos()
    {
        return response()->json(EquipoImpresion::with(['senalPorUsuario:id,nombre', 'pruebaPorUsuario:id,nombre'])->orderBy('nombre')->get()
            ->map(fn (EquipoImpresion $e) => $e->toArray() + [
                // El código solo se muestra mientras sirve.
                'codigo' => $e->codigo_vence_at?->isFuture() ? $e->codigo_vinculacion : null,
            ]));
    }

    public function crear(Request $request)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:60', $this->nombreLibre()],
            'aqui' => ['required', 'boolean'],
        ], ['nombre.required' => 'Ponle un nombre al equipo (por ejemplo, Cocina o Caja).']);

        if ($datos['aqui']) {
            [$equipo, $clave] = $this->impresion->registrarAqui($datos['nombre'], $request->user());

            return response()->json(['equipo' => $equipo, 'clave' => $clave], 201);
        }
        [$equipo, $codigo] = $this->impresion->crearConCodigo($datos['nombre'], $request->user());

        return response()->json(['equipo' => $equipo, 'codigo' => $codigo], 201);
    }

    public function actualizar(Request $request, EquipoImpresion $equipo)
    {
        $datos = $request->validate([
            'nombre' => ['sometimes', 'string', 'min:2', 'max:60', $this->nombreLibre($equipo->id)],
            'imprime_comandas' => ['sometimes', 'boolean'],
            'imprime_comprobantes' => ['sometimes', 'boolean'],
            'al_confirmar' => ['sometimes', 'boolean'],
            'automatico' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->impresion->actualizar($equipo, $datos, $request->user()));
    }

    public function quitar(Request $request, EquipoImpresion $equipo)
    {
        $this->impresion->quitar($equipo, $request->user());

        return response()->json(['message' => 'Equipo quitado.']);
    }

    public function codigo(EquipoImpresion $equipo)
    {
        $codigo = $this->impresion->nuevoCodigo($equipo);

        return response()->json(['codigo' => $codigo, 'vence_at' => $equipo->fresh()->codigo_vence_at]);
    }

    public function formato()
    {
        return response()->json($this->impresion->formato());
    }

    public function actualizarFormato(Request $request)
    {
        return response()->json($this->impresion->actualizarFormato($request->validate($this->reglasFormato()), $request->user()));
    }

    public function vistaPrevia(Request $request)
    {
        $datos = $request->validate(['tipo' => ['required', 'in:comprobante,comanda']] + $this->reglasFormato());

        return $this->impresion->vistaPrevia($datos['tipo'], $this->impresion->formatoCon($datos));
    }

    public function estado()
    {
        return response()->json($this->impresion->estado());
    }

    private function reglasFormato(): array
    {
        return [
            'ticket_logo' => ['sometimes', 'boolean'],
            'ticket_letra' => ['sometimes', 'in:normal,grande'],
            'ticket_mensaje_pie' => ['sometimes', 'nullable', 'string', 'max:160'],
            'comanda_letra_grande' => ['sometimes', 'boolean'],
            'comanda_mostrar_hora' => ['sometimes', 'boolean'],
            'comanda_mostrar_mesero' => ['sometimes', 'boolean'],
            'copias_comprobante' => ['sometimes', 'integer', 'between:1,3'],
            'copias_comanda' => ['sometimes', 'integer', 'between:1,3'],
        ];
    }

    /** Sin importar mayúsculas: «Caja» y «caja» son el mismo equipo. */
    private function nombreLibre(?int $ignorar = null): \Closure
    {
        return function (string $atributo, mixed $valor, \Closure $fallar) use ($ignorar) {
            $existe = EquipoImpresion::whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim((string) $valor))])
                ->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar))->exists();
            if ($existe) {
                $fallar('Ya hay un equipo con ese nombre.');
            }
        };
    }

    private function equipoDeLaPeticion(): EquipoImpresion
    {
        $equipo = $this->impresion->equipoActual();
        abort_if($equipo === null, 404, 'Este equipo ya no está registrado en Impresoras.');

        return $equipo;
    }
}
