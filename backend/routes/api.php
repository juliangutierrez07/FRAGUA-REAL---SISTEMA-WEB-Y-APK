<?php

use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CajaController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\EliminacionController;
use App\Http\Controllers\Api\ImpresionController;
use App\Http\Controllers\Api\ComandaController;
use App\Http\Controllers\Api\CompraGastoController;
use App\Http\Controllers\Api\ComprobanteController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportacionProductosController;
use App\Http\Controllers\Api\MesaController;
use App\Http\Controllers\Api\PasarelaController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\PromocionController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\NegocioController;
use App\Http\Controllers\Api\NotificacionController;
use App\Http\Controllers\Api\ReservaController;
use App\Http\Controllers\Api\ReservaPublicaController;
use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| routes/api.php — agrupadas por rol (sección 6 del documento de
| arquitectura). El middleware role:xxx filtra a nivel de ruta; la
| autorización fina sobre un registro puntual vive en las Policies, y se
| aplica dentro de cada Controller ($this->authorize(...)).
|--------------------------------------------------------------------------
*/

// ---- Público (sin autenticar) ----
// Fase 0, tarea 6 (09/10/2026): límite de intentos por IP para que no se pueda probar
// contraseñas ni tokens de recuperación a fuerza bruta (RNF-M01, M00 seguridad).
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    // RF-M01-012/013: recuperación de contraseña.
    Route::post('/auth/recuperar', [AuthController::class, 'solicitarRecuperacion']);
    Route::post('/auth/restablecer', [AuthController::class, 'restablecerContrasena']);
});

// Catálogo público (Cliente web, incluido invitado)
Route::get('/categorias', [CatalogoController::class, 'categorias']);
Route::get('/productos', [CatalogoController::class, 'productos']);
Route::get('/productos/{producto}/promociones', [CatalogoController::class, 'promocionesDeProducto']);
// Fase 7, tarea 3 (RF-M04-004): la foto del producto, pública (la ve el menú sin sesión).
Route::get('/productos/{producto}/foto', [CatalogoController::class, 'verFoto'])->middleware('throttle:600,1')->name('productos.foto');
// Mejora (16/10/2026): la foto propia de una presentación ("Familiar"), pública como la del producto.
Route::get('/variantes/{variante}/foto', [CatalogoController::class, 'verFotoVariante'])->middleware('throttle:600,1')->name('variantes.foto');
Route::get('/combos', [CatalogoController::class, 'combos']);
Route::get('/mesas', [MesaController::class, 'index']);
// RF-M02-L-025: resolver la mesa de un QR escaneado (pedido por QR, sin cuenta).
Route::get('/mesas/qr/{qr_code}', [MesaController::class, 'porQrCode']);

// Valores vigentes de solo lectura (domicilio, depósito de reserva) que el frontend
// necesita mostrar antes de crear el Pedido/la Reserva — ver CatalogoController::configuracion().
Route::get('/configuracion', [CatalogoController::class, 'configuracion']);
// Mejora (19/10/2026): el logo del negocio que sube el Administrador (público: lo ve el sitio sin sesión).
Route::get('/negocio/logo', [NegocioController::class, 'logo'])->middleware('throttle:600,1');

// Pedido de invitado (domicilio/recogida sin cuenta) y pago en línea de invitado.
// El Controller distingue "cliente_id" (autenticado) de "cliente_invitado_*" — StorePedidoRequest
// exige uno de los dos.
Route::post('/pedidos', [PedidoController::class, 'store'])->middleware('throttle:30,1');
// Fase 2, tarea 1 (RF-M04-035): total con promociones antes de confirmar; no guarda nada.
Route::post('/pedidos/cotizacion', [PedidoController::class, 'cotizar'])->middleware('throttle:60,1');

// RF-M06-005, HU-M07-002/011 (Fase 0, tarea 4): el invitado consulta y paga su pedido con el
// token secreto que recibió al crearlo. Con límite de intentos para que no se puedan adivinar.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/pedidos/seguimiento/{token}', [PedidoController::class, 'seguimiento']);
    // Fase 5, tarea 1 (RF-M06-010): el invitado descarga el comprobante con su enlace.
    Route::get('/pedidos/seguimiento/{token}/comprobante', [ComprobanteController::class, 'porEnlace']);
    Route::post('/pedidos/seguimiento/{token}/pagar-en-linea', [PagoController::class, 'pagarEnLineaConToken']);
    // Fase 1, tarea 6 (RF-M07-016, RF-M07-R-009): el invitado cancela su pedido pendiente.
    Route::post('/pedidos/seguimiento/{token}/cancelar', [PedidoController::class, 'cancelarConToken']);
});

// Fase 6, tarea 1 (RF-M06-006, D4: Wompi): eventos firmados de la pasarela (los manda Wompi, sin
// sesión; la firma con el secreto de eventos es la autenticación) y la página de retorno del Cliente.
Route::post('/pasarela/wompi/eventos', [PasarelaController::class, 'evento'])->middleware('throttle:240,1');
Route::get('/pasarela/transacciones/{referencia}', [PasarelaController::class, 'retorno'])
    ->where('referencia', 'FR[PR]-[0-9]+-[A-Z0-9]{20}')
    ->middleware('throttle:60,1');

// Reserva pública (Cliente web) — StoreReservaRequest exige los datos de contacto (RN-M05-12).
// RN-M05-14: depósito obligatorio; el Cliente lo paga en línea por Nequi/Bancolombia.
Route::post('/reservas', [ReservaController::class, 'store'])->middleware('throttle:30,1');
// Fase 1, tarea 4 (RN-M05-07): mesas libres de reservas a una fecha y hora.
Route::get('/mesas/disponibles', [ReservaController::class, 'mesasDisponibles'])->middleware('throttle:60,1');
Route::post('/reservas/{reserva}/deposito/en-linea', [ReservaController::class, 'registrarDepositoEnLinea'])->middleware('throttle:30,1');
// Fase 4, tarea 4 (RF-M05-018, RN-M05-09): ver y cancelar una reserva con el enlace del correo, sin cuenta.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/reservas/enlace/{token}', [ReservaPublicaController::class, 'ver']);
    Route::post('/reservas/enlace/{token}/cancelar', [ReservaPublicaController::class, 'cancelar']);
    // Fase 6, tarea 1 (RF-M05-030): pagar o reintentar el depósito con el mismo enlace.
    Route::post('/reservas/enlace/{token}/deposito', [ReservaPublicaController::class, 'pagarDeposito']);
});

// 'activo' (Fase 0, tarea 6): una cuenta desactivada no usa ninguna ruta con sesión.
// M10 (20/10/2026): la cuenta de Soporte solo consulta; SoporteSoloConsulta (en toda la API,
// bootstrap/app.php) rechaza sus escrituras salvo las de su propia cuenta y exportar un reporte.
Route::middleware(['auth:sanctum', 'activo'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    // RF-M01-010/023: cerrar sesión en todos los dispositivos (revoca todos los tokens).
    Route::post('/auth/logout-todos', [AuthController::class, 'logoutTodos']);
    // Fase 3, tarea 4 (RF-M01-010/024, HU-M01-010): mis sesiones abiertas.
    Route::get('/auth/sesiones', [AuthController::class, 'sesiones']);
    Route::post('/auth/sesiones/cerrar-otras', [AuthController::class, 'cerrarOtrasSesiones']);
    Route::delete('/auth/sesiones/{sesion}', [AuthController::class, 'cerrarSesion'])->whereNumber('sesion');
    Route::get('/auth/me', [AuthController::class, 'me']);
    // RF-M01: el usuario autenticado edita su propio perfil / cambia su propia contraseña
    // (cualquier rol, no solo Cliente — Mesero y Administrador también tienen esta pantalla).
    Route::put('/perfil', [AuthController::class, 'actualizarPerfil']);
    Route::post('/perfil/password', [AuthController::class, 'cambiarPassword']);

    // ---- Pedidos: Administrador y Mesero gestionan; Cliente ve/paga/cancela los suyos ----
    Route::middleware('role:administrador,mesero,cliente')->group(function () {
        Route::get('/pedidos', [PedidoController::class, 'index']);
        Route::get('/pedidos/{pedido}', [PedidoController::class, 'show']);
        // Fase 5, tarea 1 (RF-M06-009/010, HU-M02-019): comprobante vigente, ticket o PDF.
        Route::get('/pedidos/{pedido}/comprobante', [ComprobanteController::class, 'show']);
        // Reimpresión controlada (13/10/2026): todos los comprobantes del pedido, también los
        // anulados, y abrir uno en particular (solo Administrador, PedidoPolicy::verComprobantes).
        Route::get('/pedidos/{pedido}/comprobantes', [ComprobanteController::class, 'index']);
        Route::get('/pedidos/{pedido}/comprobantes/{comprobante}', [ComprobanteController::class, 'mostrar']);
        Route::post('/pedidos/{pedido}/pagar-en-linea', [PagoController::class, 'pagarEnLinea']); // solo Cliente dueño (PagoPolicy)
        // RN-M07-04: el Cliente puede cancelar su propio domicilio mientras esté PENDIENTE
        // (PedidoPolicy::cancelar() filtra esto; Administrador/Mesero cancelan cualquiera).
        Route::post('/pedidos/{pedido}/cancelar', [PedidoController::class, 'cancelar'])->middleware('menu:pedidos');
    });

    Route::middleware('role:administrador,mesero')->group(function () {
        // M11 (21/10/2026): la campana de notificaciones del personal (cada cuenta, las suyas).
        Route::get('/notificaciones', [NotificacionController::class, 'index']);
        Route::get('/notificaciones/contador', [NotificacionController::class, 'contador']);
        Route::post('/notificaciones/leidas', [NotificacionController::class, 'marcarTodas']);
        Route::post('/notificaciones/{notificacion}/leida', [NotificacionController::class, 'marcarLeida'])->whereNumber('notificacion');

        // Fase 7, tarea 4 (RF-M06-023, HU-M06-009): estado de la caja de hoy para Cobrar.
        Route::get('/caja/estado', [CajaController::class, 'estado']);
        // Fase 7, tarea 2 (RNF-TRANS-012, RF-M03-32): compras y gastos para el Administrador y el
        // Mesero con permiso; el controlador y StoreCompraGastoRequest lo revisan, y el Mesero
        // solo ve lo suyo. Fase 2, tarea 4: categorías por tipo y soporte (RF-M03-07, RNF-M03-11).
        Route::get('/compras-gastos', [CompraGastoController::class, 'index'])->middleware('menu:compras_gastos,gastos');
        Route::get('/compras-gastos/categorias', [CompraGastoController::class, 'categorias'])->middleware('menu:compras_gastos,gastos');
        Route::post('/compras-gastos/{compraGasto}/soporte', [CompraGastoController::class, 'adjuntarSoporte'])->middleware('menu:compras_gastos,gastos');
        Route::get('/compras-gastos/{compraGasto}/soporte', [CompraGastoController::class, 'verSoporte'])->middleware('menu:compras_gastos,gastos');
        Route::post('/compras-gastos', [CompraGastoController::class, 'store'])->middleware('menu:compras_gastos,gastos');

        Route::post('/pedidos/{pedido}/confirmar', [PedidoController::class, 'confirmar'])->middleware('menu:pedidos');
        Route::post('/pedidos/{pedido}/preparando', [PedidoController::class, 'marcarPreparando'])->middleware('menu:pedidos');
        // Fase 4, tarea 5 (RF-M07-R-012): la recogida está lista y se avisa al cliente por correo.
        Route::post('/pedidos/{pedido}/listo', [PedidoController::class, 'marcarListo'])->middleware('menu:pedidos');
        Route::post('/pedidos/{pedido}/avisos/{aviso}/reintentar', [PedidoController::class, 'reintentarAviso'])->middleware('menu:pedidos');
        Route::post('/pedidos/{pedido}/detalles/{detalle}/preparado', [PedidoController::class, 'marcarLineaPreparada'])->middleware('menu:pedidos');
        // Fase 1, tarea 1: editar un pedido PENDIENTE o las líneas no preparadas de uno
        // CONFIRMADO (RN-PED-07/08/10), con historial (RN-PED-12, HU-M02-020).
        Route::post('/pedidos/{pedido}/detalles', [PedidoController::class, 'agregarLinea'])->middleware('menu:pedidos');
        Route::patch('/pedidos/{pedido}/detalles/{detalle}', [PedidoController::class, 'actualizarLinea'])->middleware('menu:pedidos');
        Route::delete('/pedidos/{pedido}/detalles/{detalle}', [PedidoController::class, 'retirarLinea'])->middleware('menu:pedidos');
        Route::get('/pedidos/{pedido}/historial', [PedidoController::class, 'historial'])->middleware('menu:pedidos');
        // Fase 2, tarea 1 (RF-M04-036/037, HU-M04-015): descuento manual con motivo, y las
        // promociones de tipo "manual" que el Administrador dejó listas para aplicar a mano.
        Route::post('/pedidos/{pedido}/descuento', [PedidoController::class, 'aplicarDescuento'])->middleware('menu:pedidos');
        Route::delete('/pedidos/{pedido}/descuento', [PedidoController::class, 'quitarDescuento'])->middleware('menu:pedidos');
        Route::get('/promociones/manuales', [PromocionController::class, 'manuales'])->middleware('menu:pedidos');
        // RN-PED-32: entregar, recoger y finalizar. PedidoPolicy deja estas tres solo al Mesero
        // (RF-M07-025, RF-M07-R-013, RF-M02-T-016); el Administrador recibe 403.
        Route::post('/pedidos/{pedido}/entregar', [PedidoController::class, 'confirmarEntrega'])->middleware('menu:pedidos');
        Route::post('/pedidos/{pedido}/recoger', [PedidoController::class, 'confirmarRecogida'])->middleware('menu:pedidos');
        Route::post('/pedidos/{pedido}/finalizar', [PedidoController::class, 'finalizar'])->middleware('menu:pedidos');

        // RF-M06-003/004, RNF-M06-004: PedidoPolicy::cobrar() decide si este actor puede
        // cobrar ESTE pedido (siempre el Mesero; el grupo de rutas también deja pasar al
        // Administrador, pero la Policy lo rechaza — autorización fina en profundidad).
        Route::post('/pedidos/{pedido}/cobrar', [PagoController::class, 'cobrar'])->middleware('menu:cobrar,caja');
        // Fidelización (27/10/2026, RF-M06-040): al cobrar, buscar al Cliente y dejarle el pedido en su historial.
        Route::get('/clientes/buscar', [PedidoController::class, 'buscarCliente'])->middleware(['menu:cobrar,mesas', 'throttle:30,1']);
        Route::post('/pedidos/asignar-cliente', [PedidoController::class, 'asignarCliente'])->middleware('menu:cobrar,mesas');

        // Fase 6, tarea 2 (RN-PED-06/09/17 a 19, D5): comandas de cocina impresas desde el navegador.
        Route::get('/comandas/pendientes', [ComandaController::class, 'pendientes']);
        Route::get('/pedidos/{pedido}/comandas', [ComandaController::class, 'dePedido']);
        Route::get('/comandas/{comanda}/ticket', [ComandaController::class, 'ticket']);
        Route::post('/comandas/{comanda}/no-impresa', [ComandaController::class, 'noImpresa']);

        // M12 — Impresoras (24/10/2026): lo que hace cada equipo del local que imprime, con su clave en
        // la cabecera X-Equipo-Impresion (también con la sesión de un Mesero).
        Route::post('/impresion/equipos/vincular', [ImpresionController::class, 'vincular'])->middleware('throttle:10,1');
        Route::post('/impresion/equipos/senal', [ImpresionController::class, 'senal']);
        Route::get('/impresion/prueba', [ImpresionController::class, 'prueba']);
        Route::post('/impresion/equipos/prueba', [ImpresionController::class, 'resultadoPrueba']);

        // Confirmar/rechazar manualmente un pago en línea (mismo patrón que el depósito de
        // reserva de abajo). Fase 6, tarea 1: con la pasarela activa el pago se confirma solo y
        // esto queda como respaldo (decisión D4). Historia: sin esto, un
        // Pedido pagado en línea quedaba en "pendiente" para siempre: PedidoService::confirmar()
        // exige que no tenga un pago en línea sin aprobar (RN-M06-05) y no había forma de
        // aprobarlo.
        Route::post('/pagos/{pago}/confirmar-en-linea', [PagoController::class, 'confirmarEnLinea'])->middleware('menu:pedidos,caja,cobrar');
        Route::post('/pagos/{pago}/rechazar-en-linea', [PagoController::class, 'rechazarEnLinea'])->middleware('menu:pedidos,caja,cobrar');
        // Pago directo (22/10/2026): la captura del comprobante que subió el Cliente.
        Route::get('/pagos/{pago}/comprobante', [PagoController::class, 'comprobante'])->middleware('menu:pedidos,caja,cobrar');

        // M05 (Admin/Mesero): listado de reservas con filtro opcional ?estado=.
        Route::get('/reservas', [ReservaController::class, 'index'])->middleware('menu:mesas,reservas');

        // RN-M05-16: depósito de reserva — en línea (confirmar/rechazar, hoy manual hasta
        // que exista el webhook real; ver README-BACKEND.md) o manual (cualquier método de M06).
        Route::post('/reservas/{reserva}/deposito/en-linea/confirmar', [ReservaController::class, 'confirmarDepositoEnLinea'])->middleware('menu:mesas,reservas');
        Route::post('/reservas/{reserva}/deposito/en-linea/rechazar', [ReservaController::class, 'rechazarDepositoEnLinea'])->middleware('menu:mesas,reservas');
        Route::get('/reservas/{reserva}/deposito/comprobante', [ReservaController::class, 'comprobanteDeposito'])->middleware('menu:mesas,reservas');
        Route::post('/reservas/{reserva}/deposito/manual', [ReservaController::class, 'registrarDepositoManual'])->middleware('menu:mesas,reservas');

        Route::post('/reservas/{reserva}/no-show', [ReservaController::class, 'marcarNoShow'])->middleware('menu:mesas,reservas');
        Route::post('/reservas/{reserva}/completar', [ReservaController::class, 'completar'])->middleware('menu:mesas,reservas');
        // Fase 4, tarea 4 (RF-M05-027): volver a enviar un aviso que falló.
        Route::post('/reservas/{reserva}/avisos/{aviso}/reintentar', [ReservaController::class, 'reintentarAviso'])->middleware('menu:mesas,reservas');

        // RF-M05-009/010: abrir/liberar una mesa manualmente (sin pedido de por medio).
        Route::post('/mesas/{mesa}/abrir', [MesaController::class, 'abrir'])->middleware('menu:mesas');
        Route::post('/mesas/{mesa}/liberar', [MesaController::class, 'liberar'])->middleware('menu:mesas');
        // RF-M05-011 / HU-M05-007: liberación forzada (salta la validación de pedidos activos),
        // con motivo obligatorio y auditoría — Mesero o Administrador (M05, tabla de actores).
        Route::post('/mesas/{mesa}/liberar-forzada', [MesaController::class, 'liberarForzada'])->middleware('menu:mesas');
        // Fase 1, tarea 3: cuenta acumulada de la mesa (RF-M02-L-019/020) y cobro por mesa
        // (solo el Mesero cobra, RF-M06-003; el Administrador recibe 403).
        Route::get('/mesas/{mesa}/cuenta', [MesaController::class, 'cuenta'])->middleware('menu:mesas,cobrar');
        Route::post('/mesas/{mesa}/cobrar', [MesaController::class, 'cobrar'])->middleware('menu:cobrar,caja');

        // RF-M04-016/017: alternar agotado/disponible no requiere el permiso de edición
        // completa de updateProducto() (exclusivo Administrador).
        Route::post('/productos/{producto}/disponibilidad', [CatalogoController::class, 'toggleDisponible'])->middleware('menu:catalogo,menu');
        // Fase 1, tarea 5: una variante agotada (RF-M04-016/017).
        Route::post('/variantes/{variante}/disponibilidad', [CatalogoController::class, 'toggleDisponibleVariante'])->middleware('menu:catalogo,menu');
    });

    Route::middleware('role:administrador,mesero,cliente')->group(function () {
        Route::post('/reservas/{reserva}/cancelar', [ReservaController::class, 'cancelar'])->middleware('menu:mesas,reservas');
    });

    // "Mis pedidos y reservas" (Cliente): historial de reservas propias — identificadas por
    // correo de contacto, ya que Reserva no tiene cliente_id (RN-M05-12). Ver
    // ReservaController::misReservas().
    Route::middleware('role:cliente')->group(function () {
        Route::get('/mis-reservas', [ReservaController::class, 'misReservas']);
        // Mejora (26/10/2026, RF-M05-044): pagar el depósito pendiente desde «Mis pedidos y reservas».
        Route::post('/mis-reservas/{reserva}/deposito', [ReservaController::class, 'pagarMiDeposito'])->middleware('throttle:30,1');
    });

    // ---- Exclusivo Administrador ----
    Route::middleware('role:administrador')->group(function () {
        // Fase 6, tarea 1: estado de la pasarela y los pagos en línea que hay que revisar.
        Route::get('/pasarela/estado', [PasarelaController::class, 'estado'])->middleware('menu:caja');
        Route::get('/pasarela/transacciones', [PasarelaController::class, 'index'])->middleware('menu:caja');
        Route::post('/pasarela/transacciones/{transaccion}/revisada', [PasarelaController::class, 'revisada'])->middleware('menu:caja');
        Route::post('/pasarela/transacciones/{transaccion}/reembolsar', [PasarelaController::class, 'reembolsar'])->middleware('menu:caja');

        // Dashboard: KPIs resumen del día (ventas, pedidos activos, mesas ocupadas).
        Route::get('/dashboard/resumen', [DashboardController::class, 'resumen'])->middleware('menu:dashboard');

        // Catálogo (M04)
        Route::post('/categorias', [CatalogoController::class, 'storeCategoria'])->middleware('menu:catalogo');
        Route::put('/categorias/{categoria}', [CatalogoController::class, 'updateCategoria'])->middleware('menu:catalogo');
        // Mejora (16/10/2026): importación masiva de productos desde Excel o CSV.
        Route::get('/productos/importacion/plantilla', [ImportacionProductosController::class, 'plantilla'])->middleware('menu:catalogo');
        Route::post('/productos/importacion/vista-previa', [ImportacionProductosController::class, 'vistaPrevia'])->middleware('menu:catalogo');
        Route::post('/productos/importacion', [ImportacionProductosController::class, 'importar'])->middleware('menu:catalogo');
        Route::post('/productos', [CatalogoController::class, 'storeProducto'])->middleware('menu:catalogo');
        Route::put('/productos/{producto}', [CatalogoController::class, 'updateProducto'])->middleware('menu:catalogo');
        // Fase 7, tarea 3 (RF-M04-004, HU-M04-002): subir o quitar la foto del producto.
        Route::post('/productos/{producto}/foto', [CatalogoController::class, 'subirFoto'])->middleware('menu:catalogo');
        Route::delete('/productos/{producto}/foto', [CatalogoController::class, 'quitarFoto'])->middleware('menu:catalogo');

        // Catálogo (M04) parte 2: variantes, extras, combos y promociones.
        Route::post('/variantes', [CatalogoController::class, 'storeVariante'])->middleware('menu:catalogo');
        Route::put('/variantes/{variante}', [CatalogoController::class, 'updateVariante'])->middleware('menu:catalogo');
        Route::post('/variantes/{variante}/foto', [CatalogoController::class, 'subirFotoVariante'])->middleware('menu:catalogo');
        Route::delete('/variantes/{variante}/foto', [CatalogoController::class, 'quitarFotoVariante'])->middleware('menu:catalogo');

        Route::get('/extras', [CatalogoController::class, 'extras'])->middleware('menu:catalogo');
        Route::post('/extras', [CatalogoController::class, 'storeExtra'])->middleware('menu:catalogo');
        Route::put('/extras/{extra}', [CatalogoController::class, 'updateExtra'])->middleware('menu:catalogo');

        // combos-admin trae TODOS los combos (incluidos inactivos/vencidos) para gestión —
        // distinto de GET /combos (público), que solo lista los disponibles hoy.
        Route::get('/combos-admin', [CatalogoController::class, 'combosAdmin'])->middleware('menu:catalogo');
        Route::post('/combos', [CatalogoController::class, 'storeCombo'])->middleware('menu:catalogo');
        Route::put('/combos/{combo}', [CatalogoController::class, 'updateCombo'])->middleware('menu:catalogo');

        // RN-M04-19 a 23: fechas especiales (Navidad, Amor y Amistad, etc.) son solo una
        // promoción más con vigencia_inicio/vigencia_fin — no hay lógica especial por fecha.
        Route::get('/promociones', [PromocionController::class, 'index'])->middleware('menu:catalogo');
        Route::post('/promociones', [PromocionController::class, 'store'])->middleware('menu:catalogo');
        Route::put('/promociones/{promocion}', [PromocionController::class, 'update'])->middleware('menu:catalogo');
        // Mejora (16/10/2026): atajo «Poner en oferta» desde el producto (crea o reemplaza su oferta).
        Route::post('/productos/{producto}/oferta', [PromocionController::class, 'ponerOferta'])->middleware('menu:catalogo');
        Route::delete('/productos/{producto}/oferta', [PromocionController::class, 'quitarOferta'])->middleware('menu:catalogo');

        // Mesas (M05)
        Route::post('/mesas', [MesaController::class, 'store'])->middleware('menu:mesas');
        // Fase 1, tarea 6 (RF-M05-002): corregir el nombre de una mesa.
        Route::put('/mesas/{mesa}', [MesaController::class, 'update'])->middleware('menu:mesas');
        Route::post('/mesas/{mesa}/desactivar', [MesaController::class, 'desactivar'])->middleware('menu:mesas');
        Route::post('/mesas/{mesa}/activar', [MesaController::class, 'activar'])->middleware('menu:mesas');
        // RF-M05-006: regenerar el QR de una mesa (invalida el anterior).
        Route::post('/mesas/{mesa}/regenerar-qr', [MesaController::class, 'regenerarQr'])->middleware('menu:mesas');

        // RF-M05-035, HU-M05-015: depósitos de reserva retenidos por no-show (RN-M05-18).
        Route::get('/reservas/depositos-retenidos', [ReservaController::class, 'depositosRetenidos'])->middleware('menu:mesas');

        // Usuarios (M01): listar, crear Mesero/Administrador, activar/desactivar, revocar sesiones.
        Route::get('/usuarios', [UsuarioController::class, 'index'])->middleware('menu:usuarios');
        Route::post('/usuarios', [UsuarioController::class, 'store'])->middleware('menu:usuarios');
        // Fase 3, tarea 2 (RF-M01-003/005): editar datos y rol de una cuenta.
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->middleware('menu:usuarios');
        Route::post('/usuarios/{usuario}/activar', [UsuarioController::class, 'activar'])->middleware('menu:usuarios');
        Route::post('/usuarios/{usuario}/desactivar', [UsuarioController::class, 'desactivar'])->middleware('menu:usuarios');
        Route::post('/usuarios/{usuario}/revocar-sesiones', [UsuarioController::class, 'revocarSesiones'])->middleware('menu:usuarios');
        // Fase 3, tarea 4: ver y cerrar una sesión en particular de una cuenta.
        Route::get('/usuarios/{usuario}/sesiones', [UsuarioController::class, 'sesiones'])->middleware('menu:usuarios');
        Route::delete('/usuarios/{usuario}/sesiones/{sesion}', [UsuarioController::class, 'revocarSesion'])->whereNumber('sesion')->middleware('menu:usuarios');
        // M09 (20/10/2026): los menús que ve cada Administrador o Mesero (solo el SuperAdmin los cambia).
        Route::get('/usuarios/{usuario}/menus', [UsuarioController::class, 'menus'])->middleware('menu:usuarios');
        Route::put('/usuarios/{usuario}/menus', [UsuarioController::class, 'actualizarMenus'])->middleware('menu:usuarios');

        // M12 — Impresoras (24/10/2026): equipos, formato del ticket, vista previa y estado.
        Route::get('/impresion/equipos', [ImpresionController::class, 'equipos'])->middleware('menu:impresoras');
        Route::post('/impresion/equipos', [ImpresionController::class, 'crear'])->middleware('menu:impresoras');
        Route::put('/impresion/equipos/{equipo}', [ImpresionController::class, 'actualizar'])->middleware('menu:impresoras');
        Route::delete('/impresion/equipos/{equipo}', [ImpresionController::class, 'quitar'])->middleware('menu:impresoras');
        Route::post('/impresion/equipos/{equipo}/codigo', [ImpresionController::class, 'codigo'])->middleware('menu:impresoras');
        Route::get('/impresion/formato', [ImpresionController::class, 'formato'])->middleware('menu:impresoras');
        Route::put('/impresion/formato', [ImpresionController::class, 'actualizarFormato'])->middleware('menu:impresoras');
        Route::post('/impresion/vista-previa', [ImpresionController::class, 'vistaPrevia'])->middleware('menu:impresoras');
        Route::get('/impresion/estado', [ImpresionController::class, 'estado'])->middleware('menu:impresoras');
        Route::post('/comandas/{comanda}/descartar', [ComandaController::class, 'descartar'])->middleware('menu:impresoras,pedidos');

        // Eliminar (24/10/2026; definitivo desde el 27/10/2026): lo que nunca se usó se borra; lo que tiene
        // historial sale de todo y su fila se guarda. Con contraseña; motivo solo en usuarios (EliminacionService).
        Route::get('/mesas/{mesa}/eliminacion', [EliminacionController::class, 'vistaMesa'])->middleware('menu:mesas');
        Route::post('/mesas/{mesa}/eliminar', [EliminacionController::class, 'eliminarMesa'])->middleware('menu:mesas');
        Route::get('/productos/{producto}/eliminacion', [EliminacionController::class, 'vistaProducto'])->middleware('menu:catalogo');
        Route::post('/productos/{producto}/eliminar', [EliminacionController::class, 'eliminarProducto'])->middleware('menu:catalogo');
        Route::get('/usuarios/{usuario}/eliminacion', [EliminacionController::class, 'vistaUsuario'])->middleware('menu:usuarios');
        Route::post('/usuarios/{usuario}/eliminar', [EliminacionController::class, 'eliminarUsuario'])->middleware('menu:usuarios');
        // 27/10/2026 (RF-M04-057 a 060): categorías, combos, extras y promociones del catálogo.
        Route::get('/categorias/{categoria}/eliminacion', [EliminacionController::class, 'vistaCategoria'])->middleware('menu:catalogo');
        Route::post('/categorias/{categoria}/eliminar', [EliminacionController::class, 'eliminarCategoria'])->middleware('menu:catalogo');
        Route::get('/combos/{combo}/eliminacion', [EliminacionController::class, 'vistaCombo'])->middleware('menu:catalogo');
        Route::post('/combos/{combo}/eliminar', [EliminacionController::class, 'eliminarCombo'])->middleware('menu:catalogo');
        Route::get('/extras/{extra}/eliminacion', [EliminacionController::class, 'vistaExtra'])->middleware('menu:catalogo');
        Route::post('/extras/{extra}/eliminar', [EliminacionController::class, 'eliminarExtra'])->middleware('menu:catalogo');
        Route::get('/promociones/{promocion}/eliminacion', [EliminacionController::class, 'vistaPromocion'])->middleware('menu:catalogo');
        Route::post('/promociones/{promocion}/eliminar', [EliminacionController::class, 'eliminarPromocion'])->middleware('menu:catalogo');

        // Fase 3, tarea 1 (RF-M01-025, HU-M01-020, RNF-TRANS-023): consulta de la auditoría y
        // de los eventos de seguridad. Solo lectura.
        Route::get('/auditorias', [AuditoriaController::class, 'index'])->middleware('menu:auditoria');
        Route::get('/auditorias/opciones', [AuditoriaController::class, 'opciones'])->middleware('menu:auditoria');

        // RF-M07-030, RN-M05-08 a 13: valor de domicilio y depósito de reserva, editables por
        // el Administrador "en cualquier momento" — tabla `configuraciones` (clave/valor).
        Route::get('/configuraciones', [ConfiguracionController::class, 'index'])->middleware('menu:configuracion');
        Route::put('/configuraciones', [ConfiguracionController::class, 'update'])->middleware('menu:configuracion');
        // Mejora (19/10/2026): dirección, teléfono y logo del negocio (Inicio, pie de página y PDF).
        Route::put('/negocio', [NegocioController::class, 'actualizar'])->middleware('menu:configuracion');
        // M09 (20/10/2026): leer las coordenadas de un enlace de Google Maps (solo SuperAdmin; limitado).
        Route::post('/negocio/ubicacion-desde-enlace', [NegocioController::class, 'ubicacionDesdeEnlace'])->middleware(['menu:configuracion', 'throttle:20,1']);
        Route::post('/negocio/logo', [NegocioController::class, 'subirLogo'])->middleware('menu:configuracion');
        Route::delete('/negocio/logo', [NegocioController::class, 'quitarLogo'])->middleware('menu:configuracion');
        // Pago directo (22/10/2026): las cuentas de Nequi, Bancolombia y Bre-B (las cambia solo el SuperAdmin).
        Route::get('/negocio/pagos-directos', [NegocioController::class, 'pagosDirectos'])->middleware('menu:configuracion');
        Route::put('/negocio/pagos-directos', [NegocioController::class, 'actualizarPagosDirectos'])->middleware('menu:configuracion');
        // Fase 4, tarea 1: estado del programador de tareas y de la cola de trabajos.
        Route::get('/sistema/estado', [ConfiguracionController::class, 'estadoSistema'])->middleware('menu:configuracion');
        // Endurecimiento (13/10/2026): revisión de seguridad antes de abrir al público.
        Route::get('/sistema/revision-produccion', [ConfiguracionController::class, 'revisionProduccion'])->middleware('menu:configuracion');

        // Caja (M06)
        Route::get('/caja/turnos', [CajaController::class, 'index'])->middleware('menu:caja');
        Route::get('/caja/turnos/{turno}', [CajaController::class, 'show'])->middleware('menu:caja');
        Route::get('/caja/turno-actual', [CajaController::class, 'turnoActual'])->middleware('menu:caja');
        Route::post('/caja/abrir', [CajaController::class, 'abrir'])->middleware('menu:caja');
        Route::post('/caja/turnos/{turno}/cerrar', [CajaController::class, 'cerrar'])->middleware('menu:caja');
        // 27/10/2026 (RF-M06-039): el conteo de un turno que se cerró solo.
        Route::post('/caja/turnos/{turno}/conteo', [CajaController::class, 'registrarConteo'])->middleware('menu:caja');

        // Pagos: anular (control financiero)
        Route::post('/pagos/{pago}/anular', [PagoController::class, 'anular'])->middleware('menu:caja');

        // Compras/Gastos/Cierre Diario (M03). Registrar, consultar y el soporte están más arriba,
        // en el grupo del personal (Fase 7, tarea 2: también el Mesero con permiso).
        // RF-M03-14: editar un registro activo.
        Route::put('/compras-gastos/{compraGasto}', [CompraGastoController::class, 'update'])->middleware('menu:compras_gastos,gastos');
        Route::post('/compras-gastos/{compraGasto}/anular', [CompraGastoController::class, 'anular'])->middleware('menu:compras_gastos,gastos');
        Route::post('/cierres-diarios', [CompraGastoController::class, 'generarCierreDiario'])->middleware('menu:compras_gastos');
        // Fase 2, tarea 5 (RF-M03-26/27, HU-M03-09): consulta previa, historial y detalle con ajustes.
        Route::get('/cierres-diarios/previa', [CompraGastoController::class, 'previaCierre'])->middleware('menu:compras_gastos');
        Route::get('/cierres-diarios', [CompraGastoController::class, 'cierres'])->middleware('menu:compras_gastos');
        Route::get('/cierres-diarios/{cierre}', [CompraGastoController::class, 'verCierre'])->middleware('menu:compras_gastos');
        // Fase 5, tarea 2 (RF-M03-28/29): el cierre en PDF, con reimpresión marcada y auditada.
        Route::get('/cierres-diarios/{cierre}/pdf', [CompraGastoController::class, 'pdfCierre'])->middleware('menu:compras_gastos');

        // Reportes (M08) — los 6 reportes reales del documento (RF-M08-002 a 009), cada
        // uno con periodo (RF-M08-001), comparación (RF-M08-010) y exportación
        // (RF-M08-011/012/014) vía /reportes/exportar.
        Route::get('/reportes/ventas-totales', [ReporteController::class, 'ventasTotales'])->middleware('menu:reportes');
        Route::get('/reportes/ventas-por-producto', [ReporteController::class, 'ventasPorProducto'])->middleware('menu:reportes');
        Route::get('/reportes/ventas-por-tipo-y-metodo', [ReporteController::class, 'ventasPorTipoYMetodo'])->middleware('menu:reportes');
        Route::get('/reportes/compras-y-gastos', [ReporteController::class, 'comprasYGastos'])->middleware('menu:reportes');
        Route::get('/reportes/resultado-operativo', [ReporteController::class, 'resultadoOperativo'])->middleware('menu:reportes');
        Route::get('/reportes/uso-de-mesas', [ReporteController::class, 'usoDeMesas'])->middleware('menu:reportes');
        Route::post('/reportes/exportar', [ReporteController::class, 'exportar'])->middleware('menu:reportes');
        // Fase 5, tarea 3 (RNF-M08-006): exportaciones guardadas y su descarga sin regenerar.
        Route::get('/reportes/exportaciones', [ReporteController::class, 'exportaciones'])->middleware('menu:reportes');
        Route::get('/reportes/exportaciones/{exportacion}/descargar', [ReporteController::class, 'descargar'])->middleware('menu:reportes');
    });
});
