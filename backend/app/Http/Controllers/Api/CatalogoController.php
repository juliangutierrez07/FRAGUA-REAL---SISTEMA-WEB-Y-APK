<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Catalogo\StoreCategoriaRequest;
use App\Http\Requests\Api\Catalogo\StoreComboRequest;
use App\Http\Requests\Api\Catalogo\StoreExtraRequest;
use App\Http\Requests\Api\Catalogo\StoreProductoRequest;
use App\Http\Requests\Api\Catalogo\StoreVarianteRequest;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Configuracion;
use App\Models\Extra;
use App\Models\Producto;
use App\Models\Variante;
use App\Services\CatalogoService;
use App\Services\ComboService;
use App\Services\FotoProductoService;
use App\Services\OfertaService;
use App\Services\PromocionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * M04 — Menú y Catálogo. Agrupa categorías, productos, variantes/extras (a través del
 * producto) y combos. Las promociones activas se consultan aquí también porque son parte
 * del catálogo que ve el Cliente, aunque su gestión de reglas vive en PromocionService.
 */
class CatalogoController extends Controller
{
    public function __construct(
        private readonly ComboService $comboService,
        private readonly PromocionService $promocionService,
        private readonly CatalogoService $catalogoService,
    ) {
    }

    // ---- Categorías ----

    // Mismo criterio que productos(): el catálogo público (Cliente/invitado) solo ve
    // categorías activas; el Administrador necesita ver también las inactivas para poder
    // reactivarlas (si no, una vez desactivada una categoría desaparecería para siempre
    // de la gestión).
    //
    // BUG encontrado y corregido junto con esto: esta ruta (y productos()) es pública
    // — vive fuera del grupo auth:sanctum de routes/api.php porque Cliente/invitado
    // deben poder consultarla sin sesión — y el guard por defecto de la app es "web"
    // (config/auth.php), no "sanctum". $request->user() resuelve con el guard por
    // defecto, así que SIEMPRE daba null aquí, sin importar el token Bearer enviado:
    // el filtro por rol de productos() (ya existente antes de este cambio) nunca
    // distinguía Admin/Mesero de un invitado. Se resuelve pidiendo el guard "sanctum"
    // explícitamente — sí lee el header Authorization por sí solo, sin depender de
    // que el middleware auth:sanctum haya corrido antes.
    // Fase 2, tarea 3 (RF-M04-020/021, RN-M04-09): la consulta pública trae solo lo que se puede
    // pedir también DENTRO de cada categoría. Antes, con la categoría activa, venían todos sus
    // productos (incluidos los dados de baja y los agotados) a cualquiera sin sesión.
    public function categorias(Request $request)
    {
        $usuario = $request->user('sanctum');
        $esPublico = ! $usuario || $usuario->esCliente();

        $query = Categoria::with(['productos' => fn ($q) => $esPublico
            ? $q->where('activo', true)->where('disponible', true)->orderBy('nombre')
            : $q->orderBy('nombre')]);
        if ($esPublico) {
            $query->where('activo', true);
        }

        return response()->json($query->orderBy('nombre')->get());
    }

    public function storeCategoria(StoreCategoriaRequest $request)
    {
        $categoria = Categoria::create($request->validated());
        $this->catalogoService->auditar('crear_categoria', $categoria, null, $request->user());

        return response()->json($categoria, 201);
    }

    // El backend solo permitía crear categorías, no editarlas (ni el nombre ni
    // reactivarlas/desactivarlas) — necesario para que la Vista Administrador tenga CRUD
    // completo de categorías.
    // RF-M04-003: desactivar una categoría oculta sus productos (y los combos que los usan) en
    // todos los canales; no se borra nada.
    public function updateCategoria(StoreCategoriaRequest $request, Categoria $categoria)
    {
        $antes = $categoria->getAttributes();
        $categoria->update($request->validated());
        $this->catalogoService->auditar('editar_categoria', $categoria, $antes, $request->user());

        return response()->json($categoria);
    }

    // ---- Productos ----

    // RN-M04-08 / RF-M04-018: el catálogo público solo lista lo activo y disponible.
    // Ver la nota en categorias() sobre por qué usa $request->user('sanctum') en vez de
    // $request->user() — esta ruta también es pública, así que el guard por defecto
    // ("web") nunca autenticaba al Admin/Mesero aquí.
    // Fase 1, tarea 5: RF-M04-025, HU-M04-013 — ?q= busca por nombre y ?categoria_id= filtra por
    // categoría, para el Administrador, el Mesero y el Cliente. El público (Cliente o sin sesión)
    // solo ve lo que se puede pedir: producto activo y disponible, de una categoría activa, y sus
    // variantes activas y no agotadas (RF-M04-018/020/021). El personal ve también lo agotado,
    // para poder volver a habilitarlo (RF-M04-017).
    public function productos(Request $request)
    {
        $usuario = $request->user('sanctum');
        $esPublico = ! $usuario || $usuario->esCliente();

        $query = Producto::with([
            'variantes' => fn ($q) => $esPublico ? $q->where('activa', true)->where('disponible', true) : $q,
            'extrasEspecificos' => fn ($q) => $esPublico ? $q->where('activo', true) : $q,
            'extrasGenerales:id,nombre,precio,alcance,activo',
            'categoria',
        ]);

        if ($esPublico) {
            $query->where('activo', true)->where('disponible', true)
                ->whereHas('categoria', fn ($q) => $q->where('activo', true))
                // Fase 7, tarea 1 (RN-M04-04): sin presentaciones, o con al menos una que se pueda vender.
                ->where(fn ($q) => $q->whereDoesntHave('variantes')
                    ->orWhereHas('variantes', fn ($v) => $v->where('activa', true)->where('disponible', true)));
        }
        // Eliminar (24/10/2026): los archivados solo salen con ?eliminados=1 (para restaurarlos).
        $request->boolean('eliminados') && ! $esPublico ? $query->whereNotNull('eliminado_at') : $query->whereNull('eliminado_at');
        if ($request->filled('q')) {
            $texto = '%'.mb_strtolower(trim((string) $request->input('q'))).'%';
            $query->whereRaw('LOWER(nombre) LIKE ?', [$texto]);
        }
        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->integer('categoria_id'));
        }

        $productos = $query->orderBy('nombre')->get();
        // Fase 2, tarea 3 (RF-M04-010/011): la misma lista de extras para Menú, QR y Mesero.
        $this->catalogoService->conExtrasDisponibles($productos);

        // Fase 2, tarea 1 (RF-M04-035): cada producto trae las promociones automáticas de producto o
        // de categoría que le aplican ahora, para mostrar el precio rebajado en el menú, en el QR y
        // al Mesero. Las promociones al total del pedido se ven en el carrito (cotización).
        // Mejora (16/10/2026): `variante_id` dice si la promoción es de una sola presentación.
        $reglas = $this->promocionService->automaticasVigentes();
        $productos->each(fn (Producto $p) => $p->setAttribute('promociones_vigentes', array_values(array_map(
            fn ($r) => ['nombre' => $r['nombre'], 'tipo_valor' => $r['tipo_valor'], 'valor' => $r['valor'], 'variante_id' => $r['variante_id']],
            array_filter($reglas, fn ($r) => ($r['alcance'] === 'producto' && in_array($p->id, $r['productos'], true))
                || ($r['alcance'] === 'categoria' && in_array((int) $p->categoria_id, $r['categorias'], true))),
        ))));

        // Mejora (16/10/2026): el personal ve en Catálogo la oferta de cada producto (vigente o próxima),
        // para quitarla o cambiarla desde ahí.
        if (! $esPublico) {
            $ofertas = app(OfertaService::class)->ofertasPorProducto($productos->pluck('id')->all());
            $productos->each(fn (Producto $p) => $p->setAttribute('oferta', $ofertas[$p->id] ?? null));
        }

        return response()->json($productos);
    }

    // ---- Fase 7, tarea 3 (RF-M04-004, HU-M04-002, RNF-M04-002): foto del producto ----

    // Pública: la ve el Menú, el QR y el Inicio sin sesión. Lleva caché larga porque la dirección
    // cambia (?v=…) cada vez que se sube otra foto.
    public function verFoto(Producto $producto, FotoProductoService $fotos)
    {
        return $this->respuestaFoto($producto, $fotos);
    }

    public function subirFoto(Request $request, Producto $producto, FotoProductoService $fotos)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $this->validarFoto($request);

        return response()->json($fotos->guardar($producto, $request->file('foto'), $request->user())->load(['categoria', 'extrasGenerales:id,nombre']));
    }

    public function quitarFoto(Request $request, Producto $producto, FotoProductoService $fotos)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json($fotos->quitar($producto, $request->user())->load(['categoria', 'extrasGenerales:id,nombre']));
    }

    // Mejora (16/10/2026): la foto propia de cada presentación (la "Familiar"), con las mismas reglas
    // que la del producto. Sin foto propia, el Menú muestra la del producto.
    public function verFotoVariante(Variante $variante, FotoProductoService $fotos)
    {
        return $this->respuestaFoto($variante, $fotos);
    }

    public function subirFotoVariante(Request $request, Variante $variante, FotoProductoService $fotos)
    {
        abort_if(! $request->user()->esAdministrador(), 403);
        $this->validarFoto($request);

        return response()->json($fotos->guardar($variante, $request->file('foto'), $request->user()));
    }

    public function quitarFotoVariante(Request $request, Variante $variante, FotoProductoService $fotos)
    {
        abort_if(! $request->user()->esAdministrador(), 403);

        return response()->json($fotos->quitar($variante, $request->user()));
    }

    private function respuestaFoto(Producto|Variante $modelo, FotoProductoService $fotos)
    {
        abort_if(! $fotos->existe($modelo), 404);
        $ruta = (string) $modelo->imagen_path;
        $tipo = match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'png' => 'image/png',
            default => 'image/jpeg',
        };

        return Storage::disk(FotoProductoService::DISCO)->response($ruta, basename($ruta), [
            'Content-Type' => $tipo,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ], 'inline');
    }

    private function validarFoto(Request $request): void
    {
        $request->validate([
            'foto' => [
                'required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.config('fragua_real.foto_producto_max_kb', 5120),
                'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000',
            ],
        ], [
            'foto.required' => 'Elige una foto.',
            'foto.mimes' => 'La foto debe ser JPG, PNG o WebP.',
            'foto.mimetypes' => 'La foto debe ser JPG, PNG o WebP.',
            'foto.max' => 'La foto es muy pesada (máximo '.round(config('fragua_real.foto_producto_max_kb', 5120) / 1024).' MB).',
            'foto.dimensions' => 'La foto debe medir al menos 200 × 200 píxeles.',
        ]);
    }

    public function storeProducto(StoreProductoRequest $request)
    {
        $data = $request->validated();
        $generales = $data['extras_generales'] ?? null;
        unset($data['extras_generales']);

        $producto = Producto::create($data);
        if ($generales !== null) {
            $producto->extrasGenerales()->sync($generales);
        }
        $this->catalogoService->auditar('crear_producto', $producto, null, $request->user());

        return response()->json($producto->load(['categoria', 'extrasGenerales:id,nombre']), 201);
    }

    // Fase 2, tarea 3: al dar de baja un producto se desactivan sus combos (RF-M04-027, RN-M04-12);
    // `extras_generales` asocia los extras generales que ofrece (RF-M04-011). Cada cambio, incluido
    // el de precio, queda en la auditoría (RF-M04-022).
    public function updateProducto(StoreProductoRequest $request, Producto $producto)
    {
        abort_if($producto->eliminado_at !== null, 422, 'El producto está eliminado: restáuralo primero desde «Eliminados».');
        $data = $request->validated();
        $generales = $data['extras_generales'] ?? null;
        unset($data['extras_generales']);

        // Fase 7, tarea 3: con una foto subida, la dirección la maneja la foto (subirFoto/quitarFoto).
        if ($producto->imagen_path !== null) {
            unset($data['imagen_url']);
        }

        $antes = $producto->getAttributes();
        $estabaActivo = $producto->activo;
        $producto->update($data);

        $extra = [];
        if ($generales !== null) {
            $cambio = $producto->extrasGenerales()->sync($generales);
            if ($cambio['attached'] || $cambio['detached']) {
                $extra['extras_generales'] = $producto->extrasGenerales()->pluck('extras.id')->all();
            }
        }
        if ($estabaActivo && ! $producto->activo) {
            $desactivados = $this->catalogoService->desactivarCombosDe($producto, $request->user());
            if ($desactivados) {
                $extra['combos_desactivados'] = $desactivados;
            }
        }
        $this->catalogoService->auditar('editar_producto', $producto, $antes, $request->user(), $extra);

        $producto->load(['categoria', 'extrasGenerales:id,nombre']);
        // Para avisar en pantalla qué combos se desactivaron con la baja.
        $producto->setAttribute('combos_desactivados', $extra['combos_desactivados'] ?? []);

        return response()->json($producto);
    }

    // RF-M04-016/017: alternar agotado/disponible no requiere el permiso de edición
    // completa de updateProducto() (exclusivo Administrador) — el Mesero también puede
    // hacerlo desde el piso, sin tocar el resto del producto.
    public function toggleDisponible(Request $request, Producto $producto)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);
        $request->validate(['disponible' => ['required', 'boolean']]);

        $producto->disponible = $request->boolean('disponible');
        $producto->save();

        // Fase 1, tarea 5: quién marcó agotado o disponible, y cuándo (M00, auditoría).
        app(\App\Services\AuditoriaService::class)->registrar('cambiar_disponibilidad', 'Producto', $producto->id, [
            'nombre' => $producto->nombre, 'disponible' => $producto->disponible,
        ], $request->user());
        // M11 (21/10/2026): marcar un producto agotado avisa al resto del personal.
        if (! $producto->disponible) {
            app(\App\Services\NotificacionService::class)->productoAgotado($producto->nombre, "producto:{$producto->id}", $request->user());
        }

        return response()->json($producto->load(['variantes', 'categoria']));
    }

    // RF-M04-016/017 (Fase 1, tarea 5): lo mismo para una variante (ej. se acabó el tamaño grande).
    public function toggleDisponibleVariante(Request $request, Variante $variante)
    {
        abort_if(! ($request->user()->esAdministrador() || $request->user()->esMesero()), 403);
        $request->validate(['disponible' => ['required', 'boolean']]);

        $variante->disponible = $request->boolean('disponible');
        $variante->save();

        app(\App\Services\AuditoriaService::class)->registrar('cambiar_disponibilidad', 'Variante', $variante->id, [
            'nombre' => $variante->producto?->nombre.' ('.$variante->nombre.')', 'disponible' => $variante->disponible,
        ], $request->user());
        if (! $variante->disponible) {
            app(\App\Services\NotificacionService::class)->productoAgotado($variante->producto?->nombre.' ('.$variante->nombre.')', "variante:{$variante->id}", $request->user());
        }

        return response()->json($variante);
    }

    public function promocionesDeProducto(Producto $producto)
    {
        return response()->json($this->promocionService->activasParaProducto($producto));
    }

    // ---- Variantes (M04 parte 2) ----
    // Se manejan siempre en el contexto de su producto (ya vienen incluidas en
    // productos() vía with('variantes')) — no hay un listado propio, solo alta/edición.

    public function storeVariante(StoreVarianteRequest $request)
    {
        $variante = Variante::create($request->validated());
        $this->catalogoService->auditar('crear_variante', $variante, null, $request->user());

        return response()->json($variante, 201);
    }

    public function updateVariante(StoreVarianteRequest $request, Variante $variante)
    {
        $antes = $variante->getAttributes();
        $variante->update($request->validated());
        $this->catalogoService->auditar('editar_variante', $variante, $antes, $request->user());

        return response()->json($variante);
    }

    // ---- Extras (M04 parte 2) ----

    // Único listado propio: a diferencia de las variantes y los extras "específicos" (que
    // viajan dentro de su producto vía extrasEspecificos), los extras "generales" no tienen
    // producto_id — no hay forma de descubrirlos salvo pidiéndolos todos aquí.
    // Fase 2, tarea 3: cada extra general trae los productos a los que se asoció (vacío = no se ofrece con ninguno).
    public function extras()
    {
        return response()->json(Extra::with(['producto', 'productos:id,nombre'])->orderBy('nombre')->get());
    }

    public function storeExtra(StoreExtraRequest $request)
    {
        $extra = Extra::create($request->validated());
        $this->catalogoService->auditar('crear_extra', $extra, null, $request->user());

        return response()->json($extra->load(['producto', 'productos:id,nombre']), 201);
    }

    public function updateExtra(StoreExtraRequest $request, Extra $extra)
    {
        $antes = $extra->getAttributes();
        $extra->update($request->validated());
        $this->catalogoService->auditar('editar_extra', $extra, $antes, $request->user());

        return response()->json($extra->load(['producto', 'productos:id,nombre']));
    }

    // ---- Combos ----

    // Lista lo que el Cliente puede pedir hoy: vigente Y sin componentes agotados.
    public function combos()
    {
        return response()->json($this->comboService->disponibles());
    }

    // Gestión (Administrador): a diferencia de combos(), esta trae TODOS los combos —
    // incluidos los inactivos o fuera de vigencia — porque si no, uno desactivado o
    // vencido desaparecería para siempre de la pantalla de edición.
    public function combosAdmin()
    {
        return response()->json(Combo::with('productos')->latest()->get());
    }

    public function storeCombo(StoreComboRequest $request)
    {
        $data = $request->validated();
        $productos = $data['productos'];
        unset($data['productos']);
        $this->exigirComponentesActivos($data, $productos);

        $combo = Combo::create($data);
        foreach ($productos as $p) {
            $combo->productos()->attach($p['producto_id'], ['cantidad' => $p['cantidad']]);
        }
        $this->catalogoService->auditar('crear_combo', $combo, null, $request->user(), ['componentes' => $productos]);

        return response()->json($combo->load('productos'), 201);
    }

    public function updateCombo(StoreComboRequest $request, Combo $combo)
    {
        $data = $request->validated();
        $productos = $data['productos'];
        unset($data['productos']);
        $this->exigirComponentesActivos($data + ['activo' => $combo->activo], $productos);

        $antes = $combo->getAttributes();
        $combo->update($data);
        $cambio = $combo->productos()->sync(
            collect($productos)->mapWithKeys(fn (array $p) => [$p['producto_id'] => ['cantidad' => $p['cantidad']]])
        );
        $this->catalogoService->auditar('editar_combo', $combo, $antes, $request->user(),
            ($cambio['attached'] || $cambio['detached'] || $cambio['updated']) ? ['componentes' => $productos] : []);

        return response()->json($combo->load('productos'));
    }

    // RN-M04-12, RF-M04-027: un combo con un componente dado de baja no puede quedar activo: hay que
    // quitar o reemplazar ese componente primero.
    private function exigirComponentesActivos(array $data, array $productos): void
    {
        if (! ($data['activo'] ?? true)) {
            return;
        }
        $inactivos = $this->catalogoService->componentesInactivos(array_column($productos, 'producto_id'));
        if ($inactivos->isNotEmpty()) {
            $mensaje = 'El combo no puede quedar activo: '.$inactivos->join(', ', ' y ').($inactivos->count() > 1 ? ' están dados' : ' está dado').' de baja. Quítalo o reemplázalo, o deja el combo inactivo.';
            abort(response()->json(['message' => $mensaje, 'errors' => ['productos' => [$mensaje]]], 422));
        }
    }

    // ---- Configuración pública ----

    // Valores "vigentes" que el frontend necesita mostrar ANTES de crear el pedido/reserva
    // (costo de domicilio en el Checkout, depósito en Reservar) — tabla `configuraciones`,
    // editable por el Administrador (ver ConfiguracionController). Son solo lectura: el
    // servidor sigue siendo quien fija el valor real al crear el Pedido/Reserva
    // (PedidoController/ReservaController), nunca el body del Cliente.
    public function configuracion()
    {
        return response()->json([
            'valor_domicilio' => Configuracion::obtenerNumerico(
                'valor_domicilio',
                (float) config('fragua_real.valor_domicilio_default')
            ),
            'deposito_reserva' => Configuracion::obtenerNumerico(
                'deposito_reserva',
                (float) config('fragua_real.deposito_reserva_default')
            ),
            // Fase 4, tarea 2: tiempos de las reservas (el Cliente ve la tolerancia al reservar).
            ...\App\Support\Reservas\PoliticaReservas::valores(),
            // Seguridad (21/10/2026): las pantallas muestran los requisitos de la contraseña.
            'clave' => [
                'minimo' => max(8, (int) config('fragua_real.seguridad.clave_minimo', 8)),
                'mezcla' => (bool) config('fragua_real.seguridad.clave_mezcla', true),
            ],
            // Pago directo (22/10/2026): las cuentas a las que transfiere el Cliente (solo las listas).
            'pagos_directos' => app(\App\Services\PagoDirectoService::class)->publico(),
            // Mejora (19/10/2026): dirección, teléfono, WhatsApp y logo que cambia el Administrador.
            'negocio' => app(\App\Services\NegocioService::class)->publico(),
        ]);
    }
}
