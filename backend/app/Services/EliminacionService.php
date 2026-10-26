<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\User;
use App\Support\Usuarios\GestionCuentas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Eliminar mesas, productos y usuarios (24/10/2026, RF-M05-043, RF-M04-055, RF-M01-033).
 *
 *  - Lo que nunca se usó se borra de verdad (una mesa sin pedidos ni reservas, un producto que nunca se
 *    vendió ni está en un combo, una cuenta sin acciones en la auditoría ni pedidos).
 *  - Lo que tiene historial se archiva: queda desactivado, fuera de todas las listas y de la operación,
 *    pero los pedidos, los reportes, los comprobantes y la auditoría lo siguen nombrando. Se restaura
 *    desde el filtro «Eliminados» de su pantalla y vuelve desactivado, para revisarlo antes de usarlo.
 *  - Lo hacen el Administrador y el SuperAdmin con su contraseña y un motivo; todo queda en la auditoría.
 *    Las cuentas de Administrador solo las elimina el SuperAdmin, y nadie se elimina a sí mismo.
 */
class EliminacionService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    // ------------------------------------------------------------------ vista previa

    /** Qué pasaría al eliminar: {se_borra, detalle, bloqueo}. `bloqueo` = por qué no se puede ahora. */
    public function vistaPrevia(Model $registro, User $actor): array
    {
        $bloqueo = $this->bloqueo($registro, $actor);
        $historial = $this->historial($registro);

        return [
            'se_borra' => $historial === null,
            'detalle' => $historial ?? 'Nunca se usó: se borrará para siempre.',
            'bloqueo' => $bloqueo,
        ];
    }

    // ------------------------------------------------------------------ eliminar

    /** @return array{resultado: 'borrado'|'archivado', mensaje: string} */
    public function eliminar(Model $registro, User $actor, ?string $password, ?string $motivo): array
    {
        if (blank($password) || ! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['password' => 'La contraseña no es correcta.']);
        }
        $motivo = trim((string) $motivo);
        if (mb_strlen($motivo) < 3) {
            throw ValidationException::withMessages(['motivo' => 'Escribe por qué lo eliminas (al menos 3 letras).']);
        }
        $bloqueo = $this->bloqueo($registro, $actor);
        abort_if($bloqueo !== null, 422, (string) $bloqueo);

        [$tipo, $nombre] = $this->describir($registro);
        $datos = ['nombre' => $nombre, 'motivo' => mb_substr($motivo, 0, 255)];

        if ($this->historial($registro) === null && $this->borrar($registro)) {
            $this->auditoria->registrar("eliminar_{$tipo}", class_basename($registro), $registro->getKey(), $datos, $actor);

            return ['resultado' => 'borrado', 'mensaje' => "«{$nombre}» se borró para siempre."];
        }

        $this->archivar($registro, $actor, $datos['motivo']);
        $this->auditoria->registrar("archivar_{$tipo}", class_basename($registro), $registro->getKey(), $datos, $actor);

        return ['resultado' => 'archivado', 'mensaje' => "«{$nombre}» quedó archivado: ya no aparece en las listas. Puedes restaurarlo desde «Eliminados»."];
    }

    public function restaurar(Model $registro, User $actor): Model
    {
        abort_if($registro->eliminado_at === null, 422, 'Esto no está eliminado.');
        if ($registro instanceof User) {
            $motivo = GestionCuentas::motivoNoGestiona($actor, $registro);
            abort_if($motivo !== null, 403, (string) $motivo);
        }
        [$tipo, $nombre] = $this->describir($registro);

        $registro->forceFill(['eliminado_at' => null, 'eliminado_por' => null, 'eliminado_motivo' => null])->save();
        $this->auditoria->registrar("restaurar_{$tipo}", class_basename($registro), $registro->getKey(), ['nombre' => $nombre], $actor);

        return $registro->fresh();
    }

    // ------------------------------------------------------------------ reglas

    /** Por qué no se puede eliminar ahora (aunque se archive), o null. */
    private function bloqueo(Model $registro, User $actor): ?string
    {
        if ($registro->eliminado_at !== null) {
            return 'Ya está eliminado.';
        }

        if ($registro instanceof Mesa) {
            if ($registro->estado === 'ocupada' || $registro->tienePedidosActivos()) {
                return 'La mesa tiene clientes o pedidos activos: cóbralos o libérala antes de eliminarla.';
            }
            $proximas = $registro->reservas()->whereIn('estado', ['pendiente_pago', 'confirmada'])->where('fecha_hora', '>=', now())->count();
            if ($proximas > 0) {
                return "La mesa tiene {$proximas} ".($proximas === 1 ? 'reserva próxima' : 'reservas próximas').': cancélalas antes de eliminarla.';
            }
        }

        if ($registro instanceof User) {
            if ($registro->is($actor)) {
                return 'No puedes eliminar tu propia cuenta.';
            }
            if ($motivo = GestionCuentas::motivoNoGestiona($actor, $registro)) {
                return $motivo;
            }
        }

        return null;
    }

    /** Qué historial obliga a archivar en vez de borrar, o null si nunca se usó. */
    private function historial(Model $registro): ?string
    {
        if ($registro instanceof Mesa) {
            $pedidos = $registro->pedidos()->count();
            $reservas = $registro->reservas()->count();

            return $pedidos + $reservas === 0 ? null
                : 'Tiene '.$this->contar($pedidos, 'pedido', 'pedidos').($reservas ? ' y '.$this->contar($reservas, 'reserva', 'reservas') : '')
                    .': se archivará para no dañar los reportes ni la caja de esos días.';
        }

        if ($registro instanceof Producto) {
            $ventas = DB::table('pedido_detalles')->where('producto_id', $registro->id)->count();
            $combos = $registro->combos()->count();

            return $ventas + $combos === 0 ? null
                : ($ventas ? 'Se vendió en '.$this->contar($ventas, 'línea de pedido', 'líneas de pedido') : 'Está en '.$this->contar($combos, 'combo', 'combos'))
                    .': se archivará y los reportes y comprobantes lo seguirán mostrando.';
        }

        if ($registro instanceof User) {
            $acciones = Auditoria::where('user_id', $registro->id)->count();
            $pedidos = DB::table('pedidos')->where('cliente_id', $registro->id)->count();

            return $acciones + $pedidos === 0 ? null
                : 'Tiene '.($acciones ? $this->contar($acciones, 'acción', 'acciones').' en la auditoría' : $this->contar($pedidos, 'pedido', 'pedidos'))
                    .': se archivará y la auditoría seguirá diciendo quién hizo qué.';
        }

        return null;
    }

    /** Borra de verdad; si la base lo impide (algún vínculo que no se contó), devuelve false y se archiva. */
    private function borrar(Model $registro): bool
    {
        $fotos = [];
        if ($registro instanceof Producto) {
            $fotos = array_filter([$registro->imagen_path, ...$registro->variantes()->pluck('imagen_path')->all()]);
        }

        try {
            DB::transaction(function () use ($registro) {
                if ($registro instanceof User) {
                    $registro->tokens()->delete();
                }
                $registro->delete();
            });
        } catch (QueryException) {
            return false;
        }

        foreach ($fotos as $ruta) {
            Storage::disk(FotoProductoService::DISCO)->delete($ruta);
        }

        return true;
    }

    private function archivar(Model $registro, User $actor, string $motivo): void
    {
        DB::transaction(function () use ($registro, $actor, $motivo) {
            $marca = ['eliminado_at' => now(), 'eliminado_por' => $actor->id, 'eliminado_motivo' => $motivo];

            if ($registro instanceof Mesa) {
                $registro->forceFill($marca + ['activa' => false])->save();
            } elseif ($registro instanceof Producto) {
                $estabaActivo = $registro->activo;
                $registro->forceFill($marca + ['activo' => false])->save();
                if ($estabaActivo) {
                    // Igual que al darlo de baja: los combos que lo traen dejan de venderse.
                    app(CatalogoService::class)->desactivarCombosDe($registro, $actor);
                }
            } elseif ($registro instanceof User) {
                $registro->forceFill($marca + ['activo' => false])->save();
                $registro->tokens()->delete();
            }
        });
    }

    /** @return array{0: string, 1: string} tipo para la auditoría y nombre para los mensajes */
    private function describir(Model $registro): array
    {
        return match (true) {
            $registro instanceof Mesa => ['mesa', $registro->nombre],
            $registro instanceof Producto => ['producto', $registro->nombre],
            $registro instanceof User => ['usuario', $registro->nombre],
        };
    }

    private function contar(int $n, string $uno, string $varios): string
    {
        return number_format($n, 0, ',', '.').' '.($n === 1 ? $uno : $varios);
    }
}
