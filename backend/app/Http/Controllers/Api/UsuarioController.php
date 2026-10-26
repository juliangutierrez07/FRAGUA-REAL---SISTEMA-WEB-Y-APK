<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Usuario\StoreUsuarioRequest;
use App\Http\Requests\Api\Usuario\UpdateUsuarioRequest;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\SesionService;
use App\Support\Usuarios\GestionCuentas;
use App\Support\Usuarios\MenusPorRol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * M01 — Gestión de Usuarios del Administrador: listar, crear cuentas de Mesero/Administrador,
 * activar/desactivar y revocar sesiones. El autorregistro de Cliente vive aparte en
 * AuthController::register() (RegisterClienteRequest) — este Controller es exclusivo para las
 * cuentas de staff que el Administrador crea y administra (role:administrador, routes/api.php).
 * revocarSesiones() vivía en AuthController como solución provisional (su propio comentario lo
 * documentaba: "no existe todavía un UsuarioController dedicado") — se traslada aquí ahora que
 * sí existe.
 */
class UsuarioController extends Controller
{
    // RF-M01: listado con búsqueda simple (nombre/correo) y filtro por rol — el Administrador
    // ve staff y clientes desde la misma pantalla.
    public function index(Request $request)
    {
        $actor = $request->user();
        // Fase 3, tarea 4: cuántas sesiones vigentes tiene cada cuenta.
        $usuarios = User::query()
            ->withCount(['tokens as sesiones_activas' => fn ($q) => app(SesionService::class)->vigentes($q)])
            ->when($request->rol, fn ($q) => $q->where('role', $request->rol))
            // Eliminar (24/10/2026): las cuentas archivadas solo salen con ?eliminados=1.
            ->when($request->boolean('eliminados'), fn ($q) => $q->whereNotNull('eliminado_at'), fn ($q) => $q->whereNull('eliminado_at'))
            // M10 (20/10/2026): la cuenta de Soporte solo la ven el SuperAdmin y ella misma.
            ->when(! $actor->esSuperAdmin() && ! $actor->esSoporte(), fn ($q) => $q->where('role', '!=', 'soporte'))
            ->when($request->busqueda, fn ($q) => $q->where(
                fn ($q2) => $q2->where('nombre', 'like', "%{$request->busqueda}%")
                    ->orWhere('email', 'like', "%{$request->busqueda}%")
                    ->orWhere('username', 'like', '%'.mb_strtolower((string) $request->busqueda).'%')
            ))
            ->orderBy('nombre')
            ->paginate(20);

        // M09 (19/10/2026): la pantalla sabe qué cuentas puede tocar quien la mira (el
        // Administrador ve a los Administradores y al SuperAdmin, pero no los modifica).
        $usuarios->getCollection()->each(fn (User $u) => $u->setAttribute('gestionable', GestionCuentas::puedeGestionar($actor, $u)));

        return response()->json($usuarios);
    }

    // Crea una cuenta de Mesero o Administrador. El Administrador define la contraseña
    // inicial y se la comunica él mismo — M01 no pide enviarla por correo.
    public function store(StoreUsuarioRequest $request)
    {
        // M09: una cuenta de Administrador solo la crea el SuperAdmin.
        $motivo = GestionCuentas::motivoNoAsigna($request->user(), (string) $request->role);
        abort_if($motivo !== null, 403, (string) $motivo);

        $usuario = User::create([
            'role' => $request->role,
            'nombre' => $request->nombre,
            'email' => $request->email,
            'username' => $request->validated('username'),
            'telefono' => $request->telefono,
            'password' => Hash::make($request->password),
            'activo' => true,
        ]);
        // RF-M01-017 (Fase 3, tarea 1): el alta de una cuenta del personal queda auditada.
        app(AuditoriaService::class)->registrar('crear_usuario', 'User', $usuario->id, [
            'rol' => $usuario->role,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->email,
            'usuario' => $usuario->username,
        ], $request->user());

        return response()->json($usuario, 201);
    }

    // RF-M01-003/005, HU-M01-003/005 (Fase 3, tarea 2): corregir nombre, correo y teléfono de una
    // cuenta y cambiar su rol. La contraseña no se toca aquí (CA-M01-003.1): cada quien la cambia en
    // su perfil o con "¿Olvidaste tu contraseña?".
    //  - Solo roles del catálogo (RF-M01-022, validado en UpdateUsuarioRequest).
    //  - El Administrador no cambia su propio rol: podría quedarse sin acceso a esta pantalla.
    //  - Un cambio de rol cierra todas las sesiones de esa cuenta (RF-M01-023): sus permisos
    //    cambian y debe volver a entrar; así ninguna pestaña abierta sigue con el rol anterior.
    //  - Queda en la auditoría: los datos cambiados con antes → después (`editar_usuario`) y el
    //    cambio de rol aparte (`cambiar_rol`), para poder filtrarlo.
    public function update(UpdateUsuarioRequest $request, User $usuario)
    {
        // M10: UpdateUsuarioRequest ya rechazó editar la cuenta de Soporte (se cambia en el servidor).
        abort_if($usuario->eliminado_at !== null, 422, 'La cuenta está eliminada: restáurala primero desde «Eliminados».');
        $this->exigirGestion($request, $usuario);
        $datos = $request->validated();
        $motivo = $datos['role'] !== $usuario->role ? GestionCuentas::motivoNoAsigna($request->user(), $datos['role']) : null;
        abort_if($motivo !== null, 403, (string) $motivo);
        $cambiaRol = $datos['role'] !== $usuario->role;
        // Fase 7, tarea 2 (RNF-TRANS-012, RF-M03-32): el permiso de compras y gastos es solo de un
        // Mesero; si deja de serlo, se le quita. No es un rol: no cierra sus sesiones.
        $permisoAntes = (bool) $usuario->puede_registrar_gastos;
        if ($datos['role'] !== 'mesero') {
            $datos['puede_registrar_gastos'] = false;
        }
        // M09: los menús de un rol no sirven para otro; con el rol nuevo vuelve a ver todos los suyos.
        if ($cambiaRol) {
            $datos['menus_permitidos'] = null;
        }
        $permisoDespues = (bool) ($datos['puede_registrar_gastos'] ?? $permisoAntes);

        abort_if($cambiaRol && $usuario->is($request->user()), 422, 'No puedes cambiar tu propio rol. Pídeselo a otro Administrador.');

        $campos = array_values(array_intersect(['nombre', 'email', 'username', 'telefono'], array_keys($datos)));
        $antes = $usuario->only([...$campos, 'role']);

        DB::transaction(function () use ($usuario, $datos, $cambiaRol, $campos, $antes, $request, $permisoAntes, $permisoDespues) {
            $usuario->update($datos);

            $cambios = collect($campos)
                ->filter(fn (string $campo) => (string) ($antes[$campo] ?? '') !== (string) ($datos[$campo] ?? ''))
                ->mapWithKeys(fn (string $campo) => [$campo => ['antes' => $antes[$campo], 'despues' => $datos[$campo] ?? null]])
                ->all();

            if ($cambios) {
                app(AuditoriaService::class)->registrar('editar_usuario', 'User', $usuario->id, ['cambios' => $cambios], $request->user());
            }

            if ($permisoAntes !== $permisoDespues) {
                app(AuditoriaService::class)->registrar('cambiar_permiso_gastos', 'User', $usuario->id, [
                    'cambios' => ['puede_registrar_gastos' => ['antes' => $permisoAntes, 'despues' => $permisoDespues]],
                ], $request->user());
            }

            if ($cambiaRol) {
                $sesiones = $usuario->tokens()->count();
                $usuario->tokens()->delete();
                app(AuditoriaService::class)->registrar('cambiar_rol', 'User', $usuario->id, [
                    'cambios' => ['rol' => ['antes' => $antes['role'], 'despues' => $datos['role']]],
                    'sesiones_cerradas' => $sesiones,
                ], $request->user());
            }
        });

        return response()->json([
            'usuario' => $usuario->fresh(),
            'sesiones_cerradas' => $cambiaRol,
        ]);
    }

    // Activar/desactivar es un cambio de estado (columna `activo`), no un borrado — igual
    // que Mesa::activa o la disponibilidad de un Producto. AuthController::login() ya
    // rechaza a un usuario inactivo, pero un token ya emitido seguiría funcionando hasta
    // que el Administrador también revoque sus sesiones (revocarSesiones() abajo) — son dos
    // acciones separadas a propósito (activar/desactivar es reversible y frecuente; revocar
    // sesiones es más drástico), el frontend las ofrece juntas para que no se olvide una.
    public function activar(Request $request, User $usuario)
    {
        abort_if($usuario->eliminado_at !== null, 422, 'La cuenta está eliminada: restáurala primero desde «Eliminados».');
        $this->impedirAutogestion($request, $usuario);
        $this->exigirGestion($request, $usuario);
        $usuario->update(['activo' => true]);
        app(AuditoriaService::class)->registrar('activar_usuario', 'User', $usuario->id, [], $request->user());

        return response()->json($usuario->fresh());
    }

    public function desactivar(Request $request, User $usuario)
    {
        $this->impedirAutogestion($request, $usuario);
        $this->exigirGestion($request, $usuario);
        $usuario->update(['activo' => false]);
        // RF-M01-023, RN-USR-06 (Fase 0, tarea 6): desactivar cierra todas sus sesiones. Antes
        // había que pedirlo aparte con "revocar sesiones".
        $usuario->tokens()->delete();
        // RF-M01-017: activar, desactivar y revocar sesiones quedan auditados.
        app(AuditoriaService::class)->registrar('desactivar_usuario', 'User', $usuario->id, ['sesiones_revocadas' => true], $request->user());

        return response()->json($usuario->fresh());
    }

    // RF-M01-023: el Administrador revoca las sesiones de otro usuario (ej. al desactivar
    // una cuenta de Mesero, o si sospecha que un token quedó comprometido).
    public function revocarSesiones(Request $request, User $usuario)
    {
        $this->impedirAutogestion($request, $usuario);
        $this->exigirGestion($request, $usuario);
        $usuario->tokens()->delete();
        app(AuditoriaService::class)->registrar('revocar_sesiones', 'User', $usuario->id, [], $request->user());

        return response()->json(['message' => 'Sesiones revocadas.']);
    }

    // RF-M01-010, RF-M01-024, HU-M01-010 (Fase 3, tarea 4): las sesiones abiertas de una cuenta,
    // con dispositivo, IP, último uso y vencimiento, para revisar o cerrar una en particular.
    public function sesiones(Request $request, User $usuario, SesionService $sesiones)
    {
        // M10: Soporte consulta las sesiones de cualquier cuenta (no puede cerrarlas).
        if (! $request->user()->esSoporte()) {
            $this->exigirGestion($request, $usuario);
        }

        return response()->json([
            'sesiones' => $sesiones->listar($usuario),
            'politica' => $sesiones->politica(),
        ]);
    }

    public function revocarSesion(Request $request, User $usuario, SesionService $sesiones, int $sesion)
    {
        $this->impedirAutogestion($request, $usuario);
        $this->exigirGestion($request, $usuario);
        $cerrada = $sesiones->cerrar($usuario, $sesion);
        abort_if(! $cerrada, 404, 'Esa sesión ya no está abierta.');

        app(AuditoriaService::class)->registrar('revocar_sesion', 'User', $usuario->id, [
            'sesion_id' => $sesion, 'dispositivo' => $cerrada['dispositivo'], 'ip' => $cerrada['ip'],
        ], $request->user());

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    // Un Administrador no puede desactivarse ni revocarse sus propias sesiones desde esta
    // pantalla — se quedaría sin forma de deshacerlo (no hay otro Administrador de por
    // medio todavía, y esto no es un borrado que un superadmin pueda revertir).
    private function impedirAutogestion(Request $request, User $usuario): void
    {
        abort_if($usuario->is($request->user()), 422, 'No puedes hacer esto sobre tu propia cuenta.');
    }

    // M09 (20/10/2026): permisos de menú por cuenta (RF-M09-011 a 015). Solo el SuperAdmin los ve y
    // los cambia, y solo para cuentas de Administrador o de Mesero.
    public function menus(Request $request, User $usuario)
    {
        $this->exigirPermisosDeMenu($request, $usuario);

        return response()->json($this->presentarMenus($usuario));
    }

    public function actualizarMenus(Request $request, User $usuario)
    {
        $this->exigirPermisosDeMenu($request, $usuario);
        $claves = MenusPorRol::claves($usuario->role);
        $datos = $request->validate([
            'menus' => ['present', 'array'],
            'menus.*' => ['string', \Illuminate\Validation\Rule::in($claves)],
        ], ['menus.*.in' => 'Ese menú no existe para este rol.']);

        $antes = MenusPorRol::efectivos($usuario);
        $elegidos = array_values(array_unique([...MenusPorRol::fijos($usuario->role), ...$datos['menus']]));
        // Con todos los menús elegidos se guarda «sin restricción» (null): así un menú nuevo del
        // sistema también le aparece.
        $usuario->forceFill(['menus_permitidos' => count(array_intersect($claves, $elegidos)) === count($claves) ? null : $elegidos])->save();
        $despues = MenusPorRol::efectivos($usuario->fresh());

        if ($antes !== $despues) {
            app(AuditoriaService::class)->registrar('cambiar_menus', 'User', $usuario->id, [
                'quitados' => array_values(array_diff($antes, $despues)),
                'agregados' => array_values(array_diff($despues, $antes)),
                'menus' => $despues,
            ], $request->user());
        }

        return response()->json($this->presentarMenus($usuario->fresh()));
    }

    private function presentarMenus(User $usuario): array
    {
        return [
            'usuario_id' => $usuario->id,
            'rol' => $usuario->role,
            'catalogo' => MenusPorRol::paraPantalla($usuario->role),
            'menus' => MenusPorRol::efectivos($usuario),
            'todos' => $usuario->menus_permitidos === null,
        ];
    }

    private function exigirPermisosDeMenu(Request $request, User $usuario): void
    {
        abort_if(! $request->user()->esSuperAdmin(), 403, 'Solo el SuperAdmin cambia los menús de una cuenta.');
        abort_if(! MenusPorRol::tieneMenus($usuario->role), 422, 'Solo las cuentas de Administrador y de Mesero tienen permisos de menú.');
    }

    // M09 (19/10/2026): las cuentas de Administrador solo las gestiona el SuperAdmin, y la del
    // SuperAdmin no se gestiona desde la aplicación (GestionCuentas).
    private function exigirGestion(Request $request, User $usuario): void
    {
        $motivo = GestionCuentas::motivoNoGestiona($request->user(), $usuario);
        abort_if($motivo !== null, 403, (string) $motivo);
    }
}
