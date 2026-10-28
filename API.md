# API — Fragua Real Burger

Referencia de los 204 endpoints de `backend/routes/api.php`, agrupados por módulo. Donde dice «Administrador» entra también el SuperAdmin (M09) y, solo para consultar, la cuenta de Soporte (M10): a ella el servidor le rechaza con 403 «La cuenta de Soporte solo puede consultar.» toda escritura (POST, PUT, PATCH o DELETE), salvo las de su propia cuenta (`/auth/*`, `/perfil`), `POST /reportes/exportar`, `POST /pedidos/cotizacion` y marcar sus notificaciones como leídas, y también `GET /comandas/{comanda}/ticket`, que marca la comanda como impresa. Las rutas del personal llevan además el permiso de menú de la cuenta (M09): si el SuperAdmin le quitó ese menú, responden 403 «Esta opción no está habilitada para tu cuenta…». Todas las rutas van bajo `/api` y responden JSON, salvo los PDF, tickets, fotos y archivos exportados.

Generada desde el código el 13/10/2026 (`php artisan route:list --path=api`). Si cambia una ruta, actualiza esta tabla.

## Cómo leer la tabla

- **Quién**: el rol que deja pasar la ruta (middleware `role`). «Pública» no pide sesión; «Con sesión» acepta cualquier rol.
- Algunas reglas finas las decide la Policy, no la ruta. Por ejemplo, cobrar, finalizar y confirmar entrega o recogida pasan el middleware con Administrador y Mesero, pero la Policy solo deja al Mesero (ADR-004). Esas filas lo dicen.
- **Límite**: peticiones por minuto desde una misma dirección IP (`throttle`), solo en las rutas públicas que se pueden abusar. Al pasarlo, la respuesta es 429 hasta que termina ese minuto.
- Autenticación: `Authorization: Bearer <token>` con el token que devuelve `POST /auth/login`. Una cuenta desactivada recibe 403 («Tu cuenta está desactivada») en cualquier ruta con sesión.
- Errores: 401 sin sesión válida, 403 sin permiso, 404 si no existe (o no es tuyo), 422 con los mensajes de validación en español, 429 por exceso de intentos.
- Formatos (22/10/2026, `App\Support\Validacion\Formatos`): los teléfonos se aceptan con espacios, guiones o +57 y se guardan solo con números (10 dígitos: celular 3xx o fijo 60x); los nombres de personas y titulares no llevan números; la cédula o NIT va con 5 a 10 dígitos y, si lleva, el guion del dígito de verificación (se guarda sin puntos); la cuenta Bancolombia, con 11 dígitos; los valores en pesos, hasta 99.999.999.

## Índice

- [Cuenta y sesión (M01)](#cuenta-y-sesión-m01) (12)
- [Usuarios y auditoría (M01)](#usuarios-y-auditoría-m01) (14)
- [Catálogo (M04)](#catálogo-m04) (43)
- [Pedidos (M02 y M07)](#pedidos-m02-y-m07) (21)
- [Comandas de cocina (M02)](#comandas-de-cocina-m02) (5)
- [Mesas (M05)](#mesas-m05) (14)
- [Reservas (M05)](#reservas-m05) (18)
- [Caja y pagos (M06)](#caja-y-pagos-m06) (26)
- [Compras, gastos y cierre diario (M03)](#compras-gastos-y-cierre-diario-m03) (12)
- [Reportes (M08)](#reportes-m08) (9)
- [Notificaciones del personal (M11)](#notificaciones-del-personal-m11) (4)
- [Impresoras (M12)](#impresoras-m12) (13)
- [Configuración, sistema y tablero](#configuración-sistema-y-tablero) (13)

## Cuenta y sesión (M01)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `POST` | `/auth/login` | Pública | Inicia sesión con el correo o el nombre de usuario y devuelve el token. Tras 5 intentos fallidos seguidos de la misma cuenta, o 20 desde la misma IP en 15 minutos, responde 429 con `message` («Hiciste demasiados intentos fallidos…») y `bloqueado_minutos` durante 15 minutos, aun con la contraseña correcta. | 10/min |
| `POST` | `/auth/logout` | Con sesión (cualquier rol) | Cierra la sesión actual. |  |
| `POST` | `/auth/logout-todos` | Con sesión (cualquier rol) | Cierra todas las sesiones de la cuenta. |  |
| `GET` | `/auth/me` | Con sesión (cualquier rol) | Datos de la cuenta con sesión (rol, permisos). |  |
| `POST` | `/auth/recuperar` | Pública | Envía el correo para recuperar la contraseña. No revela si el correo existe. | 10/min |
| `POST` | `/auth/register` | Pública | Crea la cuenta de un Cliente (autorregistro). Solo crea Clientes. 27/10/2026: `documento` obligatorio y único entre clientes; el celular, si se da, también único. La contraseña debe ser segura: 8 caracteres como mínimo, con al menos una mayúscula, una minúscula y un número (en producción, además, no filtrada). | 10/min |
| `POST` | `/auth/restablecer` | Pública | Cambia la contraseña con el enlace del correo (contraseña segura), cierra todas las sesiones y quita el bloqueo por intentos de la cuenta. | 10/min |
| `GET` | `/auth/sesiones` | Con sesión (cualquier rol) | Sesiones abiertas de la cuenta: dispositivo, IP, último uso y vencimiento. |  |
| `POST` | `/auth/sesiones/cerrar-otras` | Con sesión (cualquier rol) | Cierra todas las sesiones menos la actual. |  |
| `DELETE` | `/auth/sesiones/{sesion}` | Con sesión (cualquier rol) | Cierra una sesión propia. |  |
| `PUT` | `/perfil` | Con sesión (cualquier rol) | Edita nombre, teléfono y nombre de usuario propios. 27/10/2026: el Cliente agrega o corrige su `documento` (quien ya lo tiene no lo deja vacío). |  |
| `POST` | `/perfil/password` | Con sesión (cualquier rol) | Cambia la contraseña propia (pide la actual; la nueva debe ser segura). |  |

## Usuarios y auditoría (M01)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/auditorias` | Administrador | Historial de auditoría con filtros (fechas, usuario, grupo, acción, registro, IP, texto). Solo lectura. |  |
| `GET` | `/auditorias/opciones` | Administrador | Listas para los filtros de Auditoría y resumen de seguridad (intentos fallidos). |  |
| `GET` | `/usuarios` | Administrador | Usuarios con filtros por rol y texto (nombre, correo o usuario); sin las eliminadas. Cada cuenta trae `gestionable`: si quien mira puede modificarla (M09). La cuenta de Soporte solo la ven el SuperAdmin y ella misma (M10). |  |
| `POST` | `/usuarios` | Administrador | Crea una cuenta de Mesero; la de Administrador, solo el SuperAdmin (403 para el Administrador). El rol `superadmin` no se crea aquí. La contraseña inicial debe ser segura. |  |
| `PUT` | `/usuarios/{usuario}` | Administrador | Edita datos, rol y el permiso de compras y gastos (cambiar el rol cierra sus sesiones). Una cuenta de Administrador solo la edita el SuperAdmin y la del SuperAdmin no se edita aquí (403). 27/10/2026: el `documento` de un Cliente. |  |
| `POST` | `/usuarios/{usuario}/activar` | Administrador | Activa una cuenta. |  |
| `POST` | `/usuarios/{usuario}/desactivar` | Administrador | Desactiva una cuenta y cierra sus sesiones. |  |
| `GET` | `/usuarios/{usuario}/eliminacion` | Administrador | Muestra qué pasa al eliminar: se borra (nunca se usó) o sale de todo y su historial se guarda; `bloqueo` dice qué lo impide ahora y `pide_motivo` si hace falta motivo (solo cuentas). |  |
| `POST` | `/usuarios/{usuario}/eliminar` | Administrador | Elimina una cuenta con `password` (la de quien lo hace) y `motivo` (obligatorio): sin acciones ni pedidos se borra; con historial sale de todo para siempre y no vuelve a entrar (su historial se guarda). No la propia; las de Administrador solo el SuperAdmin. Queda en Auditoría. |  |
| `POST` | `/usuarios/{usuario}/revocar-sesiones` | Administrador | Cierra todas las sesiones de una cuenta. |  |
| `GET` | `/usuarios/{usuario}/sesiones` | Administrador | Sesiones abiertas de una cuenta. |  |
| `GET` | `/usuarios/{usuario}/menus` | SuperAdmin | Menús de una cuenta de Administrador o Mesero: el catálogo del rol (con los fijos) y los habilitados (M09). |  |
| `PUT` | `/usuarios/{usuario}/menus` | SuperAdmin | Cambia los menús de esa cuenta (`menus`: claves del rol; Dashboard o Pedidos siempre quedan). Queda en Auditoría. |  |
| `DELETE` | `/usuarios/{usuario}/sesiones/{sesion}` | Administrador | Cierra una sesión de una cuenta. |  |

## Catálogo (M04)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/categorias` | Pública | Categorías activas del menú; el personal las recibe todas, menos las eliminadas. |  |
| `POST` | `/categorias` | Administrador | Crea una categoría. 26/10/2026: `permite_quitar_ingredientes` (falso en Bebidas: el menú no pregunta qué ingrediente quitar). |  |
| `PUT` | `/categorias/{categoria}` | Administrador | Edita o desactiva una categoría (desactivarla oculta sus productos y combos). |  |
| `GET` | `/combos` | Pública | Combos disponibles ahora (vigencia, franja y días). |  |
| `POST` | `/combos` | Administrador | Crea un combo (al menos dos productos). |  |
| `GET` | `/combos-admin` | Administrador | Todos los combos, también inactivos, con el ahorro frente a sus productos; sin los eliminados. |  |
| `PUT` | `/combos/{combo}` | Administrador | Edita o desactiva un combo. |  |
| `GET` | `/extras` | Administrador | Extras (generales y específicos), sin los eliminados. |  |
| `POST` | `/extras` | Administrador | Crea un extra. |  |
| `PUT` | `/extras/{extra}` | Administrador | Edita un extra o los productos que lo ofrecen. |  |
| `GET` | `/productos` | Pública | Productos con presentaciones, extras y foto. Sin sesión o como Cliente, solo lo que se puede pedir; con sesión del personal, todo el catálogo menos lo eliminado. Filtros `q`, `categoria_id` y `eliminados=1` (solo los eliminados, para el personal). |  |
| `POST` | `/productos` | Administrador | Crea un producto. |  |
| `GET` | `/productos/importacion/plantilla` | Administrador | Descarga la plantilla de Excel para importar productos (hoja «Productos» e «Instrucciones»). |  |
| `POST` | `/productos/importacion/vista-previa` | Administrador | Lee el archivo (`archivo`: .xlsx, .xls o .csv, hasta 2 MB y 500 filas; `actualizar`: 1 o 0) y devuelve qué haría cada fila (crear, actualizar, sin cambios o error). No guarda nada. |  |
| `POST` | `/productos/importacion` | Administrador | Importa el mismo archivo: crea y actualiza todo en una transacción, o nada si alguna fila tiene error (422). |  |
| `PUT` | `/productos/{producto}` | Administrador | Edita un producto (darlo de baja desactiva sus combos). Uno eliminado responde 404. |  |
| `GET` | `/productos/{producto}/eliminacion` | Administrador | Muestra qué pasa al eliminar: se borra (nunca se usó) o sale de todo y su historial se guarda; `bloqueo` dice qué lo impide ahora y `pide_motivo` si hace falta motivo (solo cuentas). |  |
| `POST` | `/productos/{producto}/eliminar` | Administrador | Elimina un producto con `password`: si nunca se vendió ni está en un combo se borra (con sus fotos); si no, sale de todo para siempre (su historial se guarda) y sus combos se desactivan. Queda en Auditoría. |  |
| `GET` | `/categorias/{categoria}/eliminacion` | Administrador | Muestra qué pasa al eliminar: se borra (nunca se usó) o sale de todo y su historial se guarda; `bloqueo` dice qué lo impide ahora y `pide_motivo` si hace falta motivo (solo cuentas). |  |
| `POST` | `/categorias/{categoria}/eliminar` | Administrador | Elimina una categoría con `password`: sin productos se borra; si solo tiene productos eliminados, sale de todo para siempre (su historial se guarda). No si tiene productos vigentes o una promoción vigente la usa. Queda en Auditoría. |  |
| `GET` | `/combos/{combo}/eliminacion` | Administrador | Muestra qué pasa al eliminar: se borra (nunca se usó) o sale de todo y su historial se guarda; `bloqueo` dice qué lo impide ahora y `pide_motivo` si hace falta motivo (solo cuentas). |  |
| `POST` | `/combos/{combo}/eliminar` | Administrador | Elimina un combo con `password`: si nunca se vendió se borra; si no, sale de todo para siempre (su historial se guarda) y deja de venderse. Queda en Auditoría. |  |
| `GET` | `/extras/{extra}/eliminacion` | Administrador | Muestra qué pasa al eliminar: se borra (nunca se usó) o sale de todo y su historial se guarda; `bloqueo` dice qué lo impide ahora y `pide_motivo` si hace falta motivo (solo cuentas). |  |
| `POST` | `/extras/{extra}/eliminar` | Administrador | Elimina un extra con `password`: si nunca se vendió se borra; si no, sale de todo para siempre (su historial se guarda) y deja de ofrecerse. Queda en Auditoría. |  |
| `GET` | `/promociones/{promocion}/eliminacion` | Administrador | Muestra qué pasa al eliminar: se borra (nunca se usó) o sale de todo y su historial se guarda; `bloqueo` dice qué lo impide ahora y `pide_motivo` si hace falta motivo (solo cuentas). |  |
| `POST` | `/promociones/{promocion}/eliminar` | Administrador | Elimina una promoción con `password`: si nunca se aplicó a un pedido se borra; si no, sale de todo para siempre (su historial se guarda) y deja de aplicarse. Queda en Auditoría. |  |
| `POST` | `/productos/{producto}/disponibilidad` | Administrador, Mesero | Marca un producto agotado o disponible. |  |
| `GET` | `/productos/{producto}/foto` | Pública | Foto del producto (WebP, caché larga). Pública para el Menú. | 600/min |
| `POST` | `/productos/{producto}/foto` | Administrador | Sube o cambia la foto del producto (JPG, PNG o WebP; se reduce a 900 px). |  |
| `DELETE` | `/productos/{producto}/foto` | Administrador | Quita la foto del producto. |  |
| `GET` | `/productos/{producto}/promociones` | Pública | Promociones vigentes de un producto. |  |
| `GET` | `/promociones` | Administrador | Promociones, sin las eliminadas. |  |
| `POST` | `/promociones` | Administrador | Crea una promoción (producto, categoría o total; automática o manual). |  |
| `GET` | `/promociones/manuales` | Administrador, Mesero | Promociones de tipo manual (para el descuento en Pedidos). |  |
| `PUT` | `/promociones/{promocion}` | Administrador | Edita o desactiva una promoción. |  |
| `POST` | `/productos/{producto}/oferta` | Administrador | «Poner en oferta»: `modo` (`precio` o `porcentaje`), `precio_oferta` o `porcentaje`, `variante_id` (opcional; obligatoria con precio y presentaciones), `hasta` (opcional). Crea una promoción automática de producto y reemplaza la oferta anterior. |  |
| `DELETE` | `/productos/{producto}/oferta` | Administrador | Quita la oferta del producto (queda inactiva, con su historial). |  |
| `POST` | `/variantes` | Administrador | Crea una presentación (tamaño) de un producto. |  |
| `PUT` | `/variantes/{variante}` | Administrador | Edita una presentación (incluida su `descripcion`, hasta 160 caracteres). |  |
| `GET` | `/variantes/{variante}/foto` | Pública | Foto propia de la presentación (WebP, caché larga). | 600/min |
| `POST` | `/variantes/{variante}/foto` | Administrador | Sube o cambia la foto de la presentación (mismas reglas que la del producto). |  |
| `DELETE` | `/variantes/{variante}/foto` | Administrador | Quita la foto de la presentación (el Menú vuelve a mostrar la del producto). |  |
| `POST` | `/variantes/{variante}/disponibilidad` | Administrador, Mesero | Marca una presentación agotada o disponible. |  |

## Pedidos (M02 y M07)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `POST` | `/pedidos` | Pública | Crea un pedido: Cliente o invitado (domicilio o recogida), QR de mesa, o el personal (local o por teléfono). Devuelve el enlace de seguimiento al invitado. 26/10/2026: `cuenta` (local: una cuenta de la mesa o la siguiente), `ubicacion_lat`/`ubicacion_lng` y `paga_con` (domicilio contra entrega; 422 si no alcanza para el total). 27/10/2026: el que crea el personal (`origen` = `mesero`) sale ya `confirmado`, con su comanda en `comandas` (salvo pago en línea); el del QR y el de la web quedan `pendiente`. | 30/min |
| `GET` | `/pedidos` | Administrador, Mesero, Cliente | Pedidos con filtros (tipo, estado, mesa, activos). El Cliente ve solo los suyos. |  |
| `POST` | `/pedidos/cotizacion` | Pública | Calcula el total con promociones antes de crear el pedido. | 60/min |
| `GET` | `/pedidos/seguimiento/{token}` | Pública | Pedido del invitado por su enlace (sin datos de contacto ni del personal). | 30/min |
| `POST` | `/pedidos/seguimiento/{token}/cancelar` | Pública | El invitado cancela su pedido pendiente con su enlace. | 30/min |
| `GET` | `/pedidos/{pedido}` | Administrador, Mesero, Cliente | Detalle de un pedido. El Cliente solo los suyos. |  |
| `POST` | `/pedidos/{pedido}/avisos/{aviso}/reintentar` | Administrador, Mesero | Reintenta un aviso del pedido que falló. |  |
| `POST` | `/pedidos/{pedido}/cancelar` | Administrador, Mesero, Cliente | Cancela con motivo. El Cliente, su domicilio o recogida mientras esté pendiente; el Mesero, uno pendiente (confirmado: con la contraseña de un Administrador). |  |
| `POST` | `/pedidos/{pedido}/confirmar` | Administrador, Mesero | Confirma el pedido (un pago en línea pendiente lo impide) y genera la comanda. |  |
| `POST` | `/pedidos/{pedido}/descuento` | Administrador, Mesero | Aplica un descuento manual con motivo (reemplaza las promociones automáticas). |  |
| `DELETE` | `/pedidos/{pedido}/descuento` | Administrador, Mesero | Quita el descuento manual. |  |
| `POST` | `/pedidos/{pedido}/detalles` | Administrador, Mesero | Agrega un producto o combo a un pedido pendiente o confirmado. |  |
| `PATCH` | `/pedidos/{pedido}/detalles/{detalle}` | Administrador, Mesero | Cambia cantidad u observaciones de una línea no preparada. |  |
| `DELETE` | `/pedidos/{pedido}/detalles/{detalle}` | Administrador, Mesero | Retira una línea no preparada. |  |
| `POST` | `/pedidos/{pedido}/detalles/{detalle}/preparado` | Administrador, Mesero | Marca una línea como preparada. |  |
| `POST` | `/pedidos/{pedido}/entregar` | Administrador, Mesero | Confirma la entrega de un domicilio. Solo el Mesero. |  |
| `POST` | `/pedidos/{pedido}/finalizar` | Administrador, Mesero | Finaliza un pedido en preparación, pagado y, si aplica, entregado o recogido. Solo el Mesero. |  |
| `GET` | `/pedidos/{pedido}/historial` | Administrador, Mesero | Historial del pedido: quién lo creó, confirmó, cambió, cobró y sus comandas. |  |
| `POST` | `/pedidos/{pedido}/listo` | Administrador, Mesero | Marca una recogida «lista para recoger» y avisa al cliente por correo. |  |
| `POST` | `/pedidos/{pedido}/preparando` | Administrador, Mesero | Pasa el pedido a PREPARANDO. |  |
| `POST` | `/pedidos/{pedido}/recoger` | Administrador, Mesero | Confirma que el cliente recogió el pedido. Solo el Mesero. |  |

## Comandas de cocina (M02)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/comandas/pendientes` | Administrador, Mesero | Comandas por imprimir (para la franja amarilla del Mesero). |  |
| `POST` | `/comandas/{comanda}/no-impresa` | Administrador, Mesero | «¿No salió?»: devuelve la comanda a la lista por imprimir. |  |
| `POST` | `/comandas/{comanda}/descartar` | Administrador | M12: ya no hace falta imprimirla (`motivo`, obligatorio). Sale de las pendientes; el pedido no cambia. Queda en Auditoría. |  |
| `GET` | `/comandas/{comanda}/ticket` | Administrador, Mesero | Ticket de 80 mm de la comanda; desde la segunda impresión sale marcado REIMPRESIÓN. |  |
| `GET` | `/pedidos/{pedido}/comandas` | Administrador, Mesero | Comandas de un pedido (inicial, cambios y cancelación). |  |

## Mesas (M05)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/mesas` | Pública | Mesas con su estado. Solo las activas; el Administrador ve también las dadas de baja, para reactivarlas (corregido el 19/10/2026: el Mesero también las veía). Las eliminadas no salen; con `eliminadas=1` (Administrador), solo ellas. |  |
| `POST` | `/mesas` | Administrador | Crea una mesa con su QR. |  |
| `GET` | `/mesas/qr/{qr_code}` | Pública | Mesa por el código de su QR (para el pedido desde la mesa). |  |
| `PUT` | `/mesas/{mesa}` | Administrador | Cambia el nombre de la mesa (el QR no cambia). |  |
| `POST` | `/mesas/{mesa}/abrir` | Administrador, Mesero | Marca la mesa ocupada sin pedido. |  |
| `POST` | `/mesas/{mesa}/activar` | Administrador | Reactiva una mesa. |  |
| `POST` | `/mesas/{mesa}/cobrar` | Administrador, Mesero | Cobra la cuenta de la mesa (uno o varios pagos). Solo el Mesero. 26/10/2026: `cuenta` cobra solo esa cuenta; `comprobante` (foto, multipart) obligatorio en transferencia. |  |
| `GET` | `/mesas/{mesa}/cuenta` | Administrador, Mesero | Cuenta de la mesa: pedidos de la visita, abono del depósito y saldo. |  |
| `POST` | `/mesas/{mesa}/desactivar` | Administrador | Desactiva una mesa (con historial no se borra). |  |
| `GET` | `/mesas/{mesa}/eliminacion` | Administrador | Muestra qué pasa al eliminar: se borra (nunca se usó) o sale de todo y su historial se guarda; `bloqueo` dice qué lo impide ahora y `pide_motivo` si hace falta motivo (solo cuentas). |  |
| `POST` | `/mesas/{mesa}/eliminar` | Administrador | Elimina una mesa con `password`: sin pedidos ni reservas se borra; con historial sale de todo para siempre (su historial se guarda). No con clientes, pedidos activos o reservas próximas. Queda en Auditoría. |  |
| `POST` | `/mesas/{mesa}/liberar` | Administrador, Mesero | Libera la mesa si no tiene pedidos activos. |  |
| `POST` | `/mesas/{mesa}/liberar-forzada` | Administrador, Mesero | Libera la mesa aunque tenga pedidos activos, con motivo. Queda en Auditoría. |  |
| `POST` | `/mesas/{mesa}/regenerar-qr` | Administrador | Genera un QR nuevo (el anterior deja de servir). |  |

## Reservas (M05)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/mesas/disponibles` | Pública | Mesas libres a una fecha y hora (sin datos de nadie). | 60/min |
| `GET` | `/mis-reservas` | Cliente | Reservas del Cliente (por su correo), con `puede_pagar`. |  |
| `POST` | `/mis-reservas/{reserva}/deposito` | Cliente | Paga el depósito pendiente de su reserva (`pasarela`, `comprobante`, `referencia`), como con el enlace del correo. 404 si no es suya. | 30/min |
| `POST` | `/reservas` | Pública | Crea una reserva (web o del personal) con `descripcion` opcional (hasta 500 caracteres); solo de 4:00 p. m. a 11:00 p. m. y con al menos 3 horas de anticipación (422 si no). Devuelve el enlace secreto. | 30/min |
| `GET` | `/reservas` | Administrador, Mesero | Reservas con filtros, sus avisos y su depósito. |  |
| `GET` | `/reservas/depositos-retenidos` | Administrador | Depósitos retenidos por no-show. |  |
| `GET` | `/reservas/enlace/{token}` | Pública | Reserva por su enlace secreto (sin cuenta). | 30/min |
| `POST` | `/reservas/enlace/{token}/cancelar` | Pública | Cancela la reserva desde su enlace antes de la hora. | 30/min |
| `POST` | `/reservas/enlace/{token}/deposito` | Pública | Paga el depósito en línea desde el enlace de la reserva (formulario con `pasarela`; sin Wompi, también `comprobante` y `referencia`, como en `pagar-en-linea`). La respuesta trae `deposito_tiene_comprobante` y, si se rechazó, `deposito_rechazo_motivo`. | 30/min |
| `POST` | `/reservas/{reserva}/avisos/{aviso}/reintentar` | Administrador, Mesero | Reintenta un aviso de la reserva que falló. |  |
| `POST` | `/reservas/{reserva}/cancelar` | Administrador, Mesero, Cliente | Cancela una reserva; antes de la hora devuelve el depósito. El Cliente solo las suyas, y no si pagó el depósito en efectivo. |  |
| `POST` | `/reservas/{reserva}/completar` | Administrador, Mesero | Confirma la llegada: abre la mesa y abona el depósito. |  |
| `GET` | `/reservas/{reserva}/deposito/comprobante` | Administrador, Mesero | La captura del depósito que subió el Cliente (imagen o PDF, sin caché). 404 si no hay. |  |
| `POST` | `/reservas/{reserva}/deposito/en-linea` | Pública | Inicia el pago en línea del depósito (pide el enlace secreto de la reserva; sin Wompi, con `comprobante` y `referencia`). | 30/min |
| `POST` | `/reservas/{reserva}/deposito/en-linea/confirmar` | Administrador, Mesero | Aprueba a mano el depósito en línea (respaldo). |  |
| `POST` | `/reservas/{reserva}/deposito/en-linea/rechazar` | Administrador, Mesero | Rechaza a mano el depósito en línea, con `motivo` opcional (hasta 255) que el Cliente ve en el enlace de su reserva. |  |
| `POST` | `/reservas/{reserva}/deposito/manual` | Administrador, Mesero | Registra el depósito en efectivo, tarjeta o transferencia (con la caja abierta). En efectivo, `recibido` (no menor que el depósito); en transferencia, la foto `comprobante` (obligatoria; en tarjeta, opcional). |  |
| `POST` | `/reservas/{reserva}/no-show` | Administrador, Mesero | Marca no-show cuando ya pasó la tolerancia; el depósito se retiene. |  |

## Caja y pagos (M06)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `POST` | `/caja/abrir` | Administrador | Abre la caja del día con la base inicial. Un solo turno por día. |  |
| `GET` | `/caja/estado` | Administrador, Mesero | Estado de la caja de hoy (sin abrir, abierta o cerrada), quién la abrió y el efectivo esperado. Solo lectura. |  |
| `GET` | `/caja/turno-actual` | Administrador | Turno abierto hoy con su `resumen` (ingresos por método, desglose del efectivo esperado, depósitos de reserva del día y anulados), o nulo. 26/10/2026: `pedidos_sin_finalizar` (lo que impide cerrar). 27/10/2026: `se_cierra_sola_at`, cuándo se cierra sola si nadie la cierra. |  |
| `GET` | `/caja/turnos` | Administrador | Historial de turnos de caja. Cada turno trae `cierre_automatico` (27/10/2026). |  |
| `GET` | `/caja/turnos/{turno}` | Administrador | Detalle de un turno: pagos, pagos y depósitos en línea del día (`depositos_en_linea`), gastos, ajustes y `resumen`. |  |
| `POST` | `/caja/turnos/{turno}/cerrar` | Administrador | Cierra el turno con el efectivo contado y guarda la diferencia. 26/10/2026: 422 si queda un pedido sin finalizar o cancelar. |  |
| `POST` | `/caja/turnos/{turno}/conteo` | Administrador | 27/10/2026: el efectivo contado (`efectivo_contado`, `justificacion_descuadre` si no cuadra) de un turno que se cerró solo; una sola vez. 422 si el turno no espera conteo o falta la justificación. |  |
| `POST` | `/pagos/{pago}/anular` | Administrador | Anula o reembolsa un pago; con la caja del día cerrada pide contraseña y queda como ajuste. Si es una anulación y el pedido estaba finalizado y queda con saldo, el pedido vuelve a `preparando` y la respuesta trae `pedido_reabierto` (`pedido_id`, `saldo`); un reembolso no lo reabre. |  |
| `POST` | `/pagos/{pago}/confirmar-en-linea` | Administrador, Mesero | Aprueba a mano un pago en línea (respaldo de la pasarela). |  |
| `GET` | `/pagos/{pago}/comprobante` | Administrador, Mesero | La captura del pago en línea que subió el Cliente (imagen o PDF, sin caché). 404 si no hay. |  |
| `POST` | `/pagos/{pago}/rechazar-en-linea` | Administrador, Mesero | Rechaza a mano un pago en línea, con `motivo` opcional (hasta 255) que el Cliente ve al seguir su pedido. |  |
| `GET` | `/pasarela/estado` | Administrador | Si la pasarela está activa y en qué ambiente; URL de eventos. |  |
| `GET` | `/pasarela/transacciones` | Administrador | Pagos en línea por revisar. |  |
| `GET` | `/pasarela/transacciones/{referencia}` | Pública | Estado del pago al volver de Wompi (página «Tu pago»). | 60/min |
| `POST` | `/pasarela/transacciones/{transaccion}/reembolsar` | Administrador | Pide el reembolso a Wompi. |  |
| `POST` | `/pasarela/transacciones/{transaccion}/revisada` | Administrador | Marca un pago por revisar como resuelto, con nota. |  |
| `POST` | `/pasarela/wompi/eventos` | Pública | Eventos firmados de Wompi (webhook). Verifica la firma y aplica cada evento una sola vez. | 240/min |
| `GET` | `/pedidos/seguimiento/{token}/comprobante` | Pública | Comprobante del pedido para el invitado, con su enlace. | 30/min |
| `POST` | `/pedidos/seguimiento/{token}/pagar-en-linea` | Pública | Inicia el pago en línea del invitado con su enlace. Formulario: `pasarela` (`nequi`, `bancolombia` o, sin Wompi, `breb`); sin Wompi también `comprobante` (JPG, PNG, WebP o PDF, hasta 5 MB, obligatorio) y `referencia` (opcional). El monto es siempre el saldo del pedido. Sin Wompi, 422 si el medio no está configurado. | 30/min |
| `POST` | `/pedidos/{pedido}/cobrar` | Administrador, Mesero | Registra un cobro presencial (efectivo, tarjeta o transferencia). Solo el Mesero, con la caja abierta. 26/10/2026: `comprobante` (foto, multipart), obligatorio en transferencia. |  |
| `GET` | `/clientes/buscar` | Mesero | 27/10/2026: al cobrar, busca al Cliente por `dato` (documento, correo o celular, exactos) y devuelve `id`, `nombre` y `coincide`; 404 si no hay. | 30/min |
| `POST` | `/pedidos/asignar-cliente` | Mesero | 27/10/2026: deja los pedidos (`pedido_ids`: uno, una cuenta o toda la mesa) en el historial del Cliente (`cliente_id`). 422 si uno está cancelado o ya es de otro cliente. |  |
| `GET` | `/pedidos/{pedido}/comprobante` | Administrador, Mesero, Cliente | Comprobante vigente del pedido: ticket de 80 mm o PDF. El Cliente solo el de sus pedidos; cada impresión del personal se cuenta. |  |
| `GET` | `/pedidos/{pedido}/comprobantes` | Administrador, Mesero, Cliente | Todos los comprobantes del pedido, también los anulados. Solo el Administrador (Policy). |  |
| `GET` | `/pedidos/{pedido}/comprobantes/{comprobante}` | Administrador, Mesero, Cliente | Abre un comprobante puntual (el anulado sale con su sello). Solo el Administrador (Policy). |  |
| `POST` | `/pedidos/{pedido}/pagar-en-linea` | Administrador, Mesero, Cliente | Inicia el pago en línea del Cliente dueño del pedido (Wompi o, sin Wompi, transferencia con captura: los mismos campos que la ruta del invitado). La respuesta trae `tiene_comprobante` y `referencia_cliente`. |  |

## Compras, gastos y cierre diario (M03)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `POST` | `/cierres-diarios` | Administrador | Cierra el día (exige la caja de ese día cerrada) y guarda el desglose. |  |
| `GET` | `/cierres-diarios` | Administrador | Historial de cierres diarios. |  |
| `GET` | `/cierres-diarios/previa` | Administrador | Vista previa del cierre de un día con su desglose. |  |
| `GET` | `/cierres-diarios/{cierre}` | Administrador | Detalle de un cierre con sus correcciones posteriores. |  |
| `GET` | `/cierres-diarios/{cierre}/pdf` | Administrador | Cierre diario en PDF (o vista previa de un día sin cerrar). |  |
| `GET` | `/compras-gastos` | Administrador, Mesero | Compras y gastos con filtros y totales. El Mesero con permiso ve solo los suyos. |  |
| `POST` | `/compras-gastos` | Administrador, Mesero | Registra una compra o un gasto. El Mesero con permiso no registra en días cerrados. |  |
| `GET` | `/compras-gastos/categorias` | Administrador, Mesero | Categorías de compras y de gastos. |  |
| `PUT` | `/compras-gastos/{compraGasto}` | Administrador | Corrige un registro (en un día cerrado pide contraseña y motivo). |  |
| `POST` | `/compras-gastos/{compraGasto}/anular` | Administrador | Anula un registro con motivo (en un día cerrado pide contraseña). |  |
| `POST` | `/compras-gastos/{compraGasto}/soporte` | Administrador, Mesero | Adjunta la foto o el PDF del soporte (almacenamiento privado). |  |
| `GET` | `/compras-gastos/{compraGasto}/soporte` | Administrador, Mesero | Descarga el soporte. El Mesero con permiso solo los suyos. |  |

## Reportes (M08)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/reportes/compras-y-gastos` | Administrador | Reporte de compras y gastos por categoría. |  |
| `GET` | `/reportes/exportaciones` | Administrador | Exportaciones guardadas. |  |
| `GET` | `/reportes/exportaciones/{exportacion}/descargar` | Administrador | Descarga una exportación guardada tal cual (sin regenerarla). |  |
| `POST` | `/reportes/exportar` | Administrador | Exporta el reporte en pantalla a PDF o Excel (.xlsx) y lo guarda. |  |
| `GET` | `/reportes/resultado-operativo` | Administrador | Ventas − compras − gastos del periodo (usa los cierres diarios). |  |
| `GET` | `/reportes/uso-de-mesas` | Administrador | Reservas por estado y ocupación por mesa (visitas, ventas, minutos). |  |
| `GET` | `/reportes/ventas-por-producto` | Administrador | Ventas por producto y categoría, más y menos vendidos. |  |
| `GET` | `/reportes/ventas-por-tipo-y-metodo` | Administrador | Ventas por tipo de venta y por método de pago. |  |
| `GET` | `/reportes/ventas-totales` | Administrador | Monto y cantidad de pedidos finalizados del periodo. |  |

## Notificaciones del personal (M11)

La campana del Mesero y del Administrador (también del SuperAdmin y de Soporte). Cada cuenta ve y marca solo las suyas; las crea el sistema según el rol y los menús de la cuenta, nunca para quien hizo la acción, y se borran solas a los 30 días.

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/notificaciones` | Administrador, Mesero | Las 30 más recientes de la cuenta (`tipo`, `categoria`: pedidos, reservas, caja, operacion o sistema; `titulo`, `texto`, `enlace` a la pantalla del panel, `leida_at`, `created_at`) y `no_leidas`. |  |
| `GET` | `/notificaciones/contador` | Administrador, Mesero | `no_leidas` y `ultima_id`; la campana lo pide cada 20 segundos. Si la cuenta recibe los avisos del sistema y el programador de tareas dejó de latir, crea ese aviso (una vez al día). |  |
| `POST` | `/notificaciones/{notificacion}/leida` | Administrador, Mesero | Marca una como leída; 404 si no es de la cuenta. Devuelve `no_leidas`. |  |
| `POST` | `/notificaciones/leidas` | Administrador, Mesero | Marca todas las de la cuenta como leídas. |  |

## Impresoras (M12)

Los equipos del local que imprimen (decisión D5: imprime el navegador). La clave del equipo va en la cabecera `X-Equipo-Impresion`; con ella el servidor sabe desde qué equipo se imprimió cada comanda, comprobante o cierre (queda en la auditoría como `equipo`). Las rutas del Administrador llevan el permiso de menú `impresoras`.

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `POST` | `/impresion/equipos/vincular` | Administrador, Mesero | Vincula este navegador con el `codigo` de 6 números; devuelve `equipo` y `clave` (una sola vez). 422 si no existe o venció. | 10/min |
| `POST` | `/impresion/equipos/senal` | Administrador, Mesero | La señal de cada minuto (con la cabecera); devuelve el equipo con sus ajustes. 404 si ya no está registrado. |  |
| `GET` | `/impresion/prueba` | Administrador, Mesero | Ticket de prueba de 80 mm (PDF): logo, fecha, quién y desde qué equipo, tildes y ñ, regla del ancho y negro parejo. |  |
| `POST` | `/impresion/equipos/prueba` | Administrador, Mesero | Resultado de la prueba de este equipo: `resultado` = `ok`, `fallo` o `cortado`. Queda en Auditoría. |  |
| `GET` | `/impresion/equipos` | Administrador | Los equipos con `en_linea`, `vinculado`, última señal (y de quién), última prueba y, mientras sirve, el `codigo`. |  |
| `POST` | `/impresion/equipos` | Administrador | Crea un equipo (`nombre`, único sin importar mayúsculas): con `aqui: true` lo registra en este navegador y devuelve la `clave`; con `aqui: false` devuelve un `codigo` para vincularlo. |  |
| `PUT` | `/impresion/equipos/{equipo}` | Administrador | Cambia `nombre`, `imprime_comandas`, `imprime_comprobantes`, `al_confirmar` y `automatico`; el equipo los recibe con su señal. |  |
| `DELETE` | `/impresion/equipos/{equipo}` | Administrador | Quita el equipo (su navegador vuelve a las preferencias propias). |  |
| `POST` | `/impresion/equipos/{equipo}/codigo` | Administrador | Código nuevo para vincular (otro) navegador; el anterior deja de serlo al vincular. |  |
| `GET` | `/impresion/formato` | Administrador | Formato del ticket: `ticket_logo`, `ticket_letra` (normal o grande), `ticket_mensaje_pie`, `comanda_letra_grande`, `comanda_mostrar_hora`, `comanda_mostrar_mesero`, `copias_comprobante` y `copias_comanda` (1 a 3). |  |
| `PUT` | `/impresion/formato` | Administrador | Guarda el formato. Queda en Auditoría con el antes y el después. |  |
| `POST` | `/impresion/vista-previa` | Administrador | PDF de muestra (`tipo`: comprobante o comanda) con el formato enviado, sin guardarlo. También Soporte. |  |
| `GET` | `/impresion/estado` | Administrador | `pendientes` (comandas por imprimir o que no salieron) e `historial` (las últimas 30 impresiones, pruebas y comandas descartadas, con quién y en qué equipo). |  |

## Configuración, sistema y tablero

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/configuracion` | Pública | Valores públicos: valor del domicilio, depósito de reserva, tiempos de reserva y su horario (`reserva_hora_inicio`, `reserva_hora_fin`, `reserva_anticipacion_minutos`), cierre automático de la caja (`caja_hora_cierre`, `caja_tolerancia_minutos`; 27/10/2026), `clave` (requisitos de la contraseña: `minimo` y `mezcla`), `pagos_directos` (`activo`, `automatico` = Wompi activo, y `medios`: solo las cuentas completas, con `campos` `{campo, etiqueta, valor, copiar}`) y `negocio` (nombre, dirección, teléfono, enlace de WhatsApp, `latitud`/`longitud` del local, `mapa_url` de «Cómo llegar» y `logo_url`). |  |
| `GET` | `/negocio/logo` | Pública | El logo del negocio (PNG). 404 si no hay. | 600/min |
| `GET` | `/negocio/pagos-directos` | Administrador | Las cuentas para pagos en línea sin Wompi: `datos` (Nequi `numero`/`titular`; Bancolombia `tipo`/`numero`/`titular`/`documento`; Bre-B `llave`/`titular`) y `publico` (lo que ve el Cliente). |  |
| `PUT` | `/negocio/pagos-directos` | SuperAdmin | Cambia esas cuentas (vacío = el medio no se ofrece; 403 si lo intenta el Administrador). Queda en Auditoría (`editar_pagos_directos`, antes y después). |  |
| `PUT` | `/negocio` | Administrador | Cambia la dirección y el teléfono (también el de WhatsApp) del negocio; `nombre`, `nit`, `latitud` y `longitud` (punto del local en el mapa; los dos o ninguno, `null` lo quita), solo el SuperAdmin (403 si los manda el Administrador). Queda en Auditoría. |  |
| `POST` | `/negocio/ubicacion-desde-enlace` | SuperAdmin | Lee el punto de un enlace de Google Maps (`enlace`: el de «Compartir», `maps.app.goo.gl`, uno largo con `@lat,lng` o `!3d…!4d…`, o «lat, lng»). Los enlaces cortos se siguen solo hasta páginas de Google. Devuelve `{latitud, longitud}` sin guardar nada; 422 si no encuentra el punto. | 20/min |
| `POST` | `/negocio/logo` | Administrador | Sube o cambia el logo (`logo`: PNG, JPG o WebP, mínimo 64 × 64 px, hasta 5 MB); se guarda en PNG de 512 px. Queda en Auditoría. |  |
| `DELETE` | `/negocio/logo` | Administrador | Quita el logo; vuelve el círculo «FR». Queda en Auditoría. |  |
| `GET` | `/configuraciones` | Administrador | Valores editables del sistema. |  |
| `PUT` | `/configuraciones` | Administrador | Edita domicilio, depósito y tiempos de reserva. Queda en Auditoría. |  |
| `GET` | `/dashboard/resumen` | Administrador | Resumen del día para el Administrador. |  |
| `GET` | `/sistema/estado` | Administrador | Estado del programador de tareas, la cola y el correo. |  |
| `GET` | `/sistema/revision-produccion` | Administrador | Revisión antes de producción (la misma de fragua:revisar-produccion). |  |
