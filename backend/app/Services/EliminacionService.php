<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\Promocion;
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
 * CAMBIO (27/10/2026, decisión del negocio): eliminar es definitivo; ya no hay «Eliminados» ni restaurar.
 *  - Lo que nunca se usó se borra de la base (una mesa sin pedidos ni reservas, un producto que nunca se
 *    vendió ni está en un combo, una cuenta sin acciones en la auditoría ni pedidos).
 *  - Lo que tiene historial desaparece de todas las listas y de la operación, pero su fila se guarda
 *    para que los pedidos, los reportes, los comprobantes y la auditoría lo sigan nombrando. Su nombre
 *    (o correo) queda libre para crear otro igual (migración 2026_11_04_000001).
 *  - Lo hacen el Administrador y el SuperAdmin con su contraseña; el motivo solo se pide al eliminar una
 *    cuenta. Todo queda en la auditoría. Las cuentas de Administrador solo las elimina el SuperAdmin, y
 *    nadie se elimina a sí mismo.
 *
 * 27/10/2026 (RF-M04-057 a 060, RN-M04-34): también categorías, combos, extras y promociones. Un combo o
 * un extra que se vendió, una promoción que se aplicó a un pedido o una categoría que solo tiene productos
 * eliminados se archivan; si no, se borran. Una categoría con productos vigentes, o que una promoción
 * vigente usa, no se elimina hasta moverlos o quitarla de ahí.
 */
class EliminacionService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    // ------------------------------------------------------------------ vista previa

    /** Qué pasaría al eliminar: {se_borra, detalle, bloqueo, pide_motivo}. `bloqueo` = por qué no se puede ahora. */
    public function vistaPrevia(Model $registro, User $actor): array
    {
        $bloqueo = $this->bloqueo($registro, $actor);
        $historial = $this->historial($registro);

        return [
            'se_borra' => $historial === null,
            'detalle' => $historial ?? 'Nunca se usó: se borra por completo.',
            'bloqueo' => $bloqueo,
            'pide_motivo' => self::pideMotivo($registro),
        ];
    }

    /** Solo eliminar una cuenta pide motivo (27/10/2026). */
    public static function pideMotivo(Model $registro): bool
    {
        return $registro instanceof User;
    }

    // ------------------------------------------------------------------ eliminar

    /** @return array{resultado: 'borrado'|'archivado', mensaje: string} */
    public function eliminar(Model $registro, User $actor, ?string $password, ?string $motivo): array
    {
        if (blank($password) || ! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['password' => 'La contraseña no es correcta.']);
        }
        $motivo = trim((string) $motivo);
        if (self::pideMotivo($registro) && mb_strlen($motivo) < 3) {
            throw ValidationException::withMessages(['motivo' => 'Escribe por qué eliminas la cuenta (al menos 3 letras).']);
        }
        $motivo = $motivo !== '' ? mb_substr($motivo, 0, 255) : null;
        $bloqueo = $this->bloqueo($registro, $actor);
        abort_if($bloqueo !== null, 422, (string) $bloqueo);

        [$tipo, $nombre] = $this->describir($registro);
        $datos = array_filter(['nombre' => $nombre, 'motivo' => $motivo], fn ($v) => $v !== null);

        if ($this->historial($registro) === null && $this->borrar($registro)) {
            $this->auditoria->registrar("eliminar_{$tipo}", class_basename($registro), $registro->getKey(), $datos, $actor);

            return ['resultado' => 'borrado', 'mensaje' => "«{$nombre}» se eliminó."];
        }

        // Con historial: fuera de todo, pero la fila se guarda para lo que ya pasó.
        $this->archivar($registro, $actor, $motivo);
        $this->auditoria->registrar("archivar_{$tipo}", class_basename($registro), $registro->getKey(), $datos, $actor);

        return ['resultado' => 'archivado', 'mensaje' => "«{$nombre}» se eliminó. Los pedidos y reportes de antes lo siguen mostrando."];
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

        if ($registro instanceof Categoria) {
            $vigentes = $registro->productos()->whereNull('eliminado_at')->count();
            if ($vigentes > 0) {
                return 'La categoría tiene '.$this->contar($vigentes, 'producto', 'productos').': muévelos a otra categoría o elimínalos antes.';
            }
            $promocion = Promocion::whereNull('eliminado_at')
                ->whereHas('categorias', fn ($q) => $q->where('categorias.id', $registro->id))->value('nombre');
            if ($promocion !== null) {
                return "La promoción «{$promocion}» aplica a esta categoría: quítala de la promoción o elimina la promoción antes.";
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
                    .': desaparece de todas las listas, pero los reportes y la caja de esos días la siguen mostrando.';
        }

        if ($registro instanceof Producto) {
            $ventas = DB::table('pedido_detalles')->where('producto_id', $registro->id)->count();
            $combos = $registro->combos()->count();

            return $ventas + $combos === 0 ? null
                : ($ventas ? 'Se vendió en '.$this->contar($ventas, 'línea de pedido', 'líneas de pedido') : 'Está en '.$this->contar($combos, 'combo', 'combos'))
                    .': desaparece de todas las listas, pero los pedidos, reportes y comprobantes de antes lo siguen mostrando.';
        }

        if ($registro instanceof User) {
            $acciones = Auditoria::where('user_id', $registro->id)->count();
            $pedidos = DB::table('pedidos')->where('cliente_id', $registro->id)->count();

            return $acciones + $pedidos === 0 ? null
                : 'Tiene '.($acciones ? $this->contar($acciones, 'acción', 'acciones').' en la auditoría' : $this->contar($pedidos, 'pedido', 'pedidos'))
                    .': no vuelve a entrar y desaparece de las listas, pero la auditoría sigue diciendo quién hizo qué.';
        }

        if ($registro instanceof Categoria) {
            $archivados = $registro->productos()->count(); // a esta altura, todos están eliminados

            return $archivados === 0 ? null
                : 'Tiene '.$this->contar($archivados, 'producto eliminado', 'productos eliminados').' que los reportes siguen nombrando: la categoría desaparece de todas las listas.';
        }

        if ($registro instanceof Combo) {
            $ventas = DB::table('pedido_detalles')->where('combo_id', $registro->id)->count();

            return $ventas === 0 ? null
                : 'Se vendió en '.$this->contar($ventas, 'línea de pedido', 'líneas de pedido').': desaparece de todas las listas, pero los pedidos, reportes y comprobantes de antes lo siguen mostrando.';
        }

        if ($registro instanceof Extra) {
            $ventas = DB::table('pedido_detalle_extra')->where('extra_id', $registro->id)->count();

            return $ventas === 0 ? null
                : 'Se vendió en '.$this->contar($ventas, 'línea de pedido', 'líneas de pedido').': desaparece de todas las listas, pero los pedidos, reportes y comprobantes de antes lo siguen mostrando.';
        }

        if ($registro instanceof Promocion) {
            // Los pedidos guardan la promoción aplicada como JSON (nombre y monto), sin llave foránea.
            $pedidos = DB::table('pedidos')
                ->whereRaw('CAST(promociones_aplicadas AS TEXT) LIKE ?', ['%"promocion_id":'.$registro->id.',%'])
                ->count();

            return $pedidos === 0 ? null
                : 'Se aplicó en '.$this->contar($pedidos, 'pedido', 'pedidos').': deja de aplicarse y desaparece de las listas, pero los pedidos de antes la siguen mostrando.';
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

    private function archivar(Model $registro, User $actor, ?string $motivo): void
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
            } else {
                // Categoría, combo, extra o promoción: inactivo, ya no se ofrece ni se aplica.
                $registro->forceFill($marca + ['activo' => false])->save();
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
            $registro instanceof Categoria => ['categoria', $registro->nombre],
            $registro instanceof Combo => ['combo', $registro->nombre],
            $registro instanceof Extra => ['extra', $registro->nombre],
            $registro instanceof Promocion => ['promocion', $registro->nombre],
        };
    }

    private function contar(int $n, string $uno, string $varios): string
    {
        return number_format($n, 0, ',', '.').' '.($n === 1 ? $uno : $varios);
    }
}
