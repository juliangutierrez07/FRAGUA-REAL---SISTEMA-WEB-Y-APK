# Backend — Fragua Real Burger

API REST en Laravel 13 que usa la aplicación web (`frontend/`). Este documento explica cómo está
armado el backend hoy y guarda el historial de los 60 cambios técnicos que lo llevaron a ese estado.

- Instalación y puesta en producción: [`README-ENTREGA.md`](README-ENTREGA.md).
- Tablas, relaciones y migraciones: [`BASE-DE-DATOS.md`](BASE-DE-DATOS.md).
- Todos los endpoints con su rol: [`API.md`](API.md).

## Contenido

- [Cómo está armado](#cómo-está-armado)
- [Módulos y dónde vive cada uno](#módulos-y-dónde-vive-cada-uno)
- [Roles, autorización y seguridad](#roles-autorización-y-seguridad)
- [Tareas programadas, cola y comandos](#tareas-programadas-cola-y-comandos)
- [Configuración (.env)](#configuración-env)
- [Archivos que guarda el sistema](#archivos-que-guarda-el-sistema)
- [Pruebas automáticas](#pruebas-automáticas)
- [Qué falta a propósito](#qué-falta-a-propósito)
- [Historial de cambios (puntos 1 a 50)](#historial-de-cambios)

## Cómo está armado

Cada petición pasa por las mismas capas:

1. **Ruta** (`routes/api.php`): 195 endpoints bajo `/api`, agrupados por nivel de acceso con los
   middleware `auth:sanctum`, `activo` y `role:...`, y límites de peticiones en las rutas públicas.
2. **Form Request** (`app/Http/Requests/Api`, 38 clases): valida los datos y devuelve los mensajes
   en español (`lang/es/validation.php`).
3. **Controller** (`app/Http/Controllers/Api`, 18 clases): delgado; autoriza con la Policy cuando la
   regla depende del registro y delega en un Service.
4. **Policy** (`app/Policies`, 4 clases): reglas sobre un registro puntual, por ejemplo que solo el
   Mesero cobre (ADR-004).
5. **Service** (`app/Services`, 25 clases): las reglas de negocio, dentro de transacciones. Una regla
   que no se cumple lanza `RuntimeException` o `InvalidArgumentException`, que `bootstrap/app.php`
   convierte en un 422 con el mensaje en español.
6. **Model** (`app/Models`, 26 modelos): un modelo por tabla, con los nombres de columna en español
   tal como están en la base.

Apoyos: `app/Support` (pasarela de pago, periodos de reportes, reimpresiones, pie de página de los
PDF, política de reservas, nombre de usuario, catálogo de acciones de auditoría), `app/Jobs` (envío de
avisos y latido de la cola), `app/Notifications` (los correos) y `resources/views` (plantillas de los
PDF y tickets: comprobante, comanda, cierre diario y reportes).

Las respuestas son JSON. Los errores también: 401 sin sesión, 403 sin permiso, 404, 422 con los
mensajes de validación o de la regla de negocio, y 429 por exceso de intentos.

## Módulos y dónde vive cada uno

| Módulo | Controllers | Services | Qué resuelve |
|---|---|---|---|
| M01 Usuarios | `AuthController`, `UsuarioController`, `AuditoriaController` | `SesionService`, `AuditoriaService`, `AuditoriaConsultaService` | Registro de Clientes, inicio de sesión con correo o usuario, recuperación de contraseña, sesiones con vencimiento, cuentas del personal, permisos, auditoría |
| M02 Pedidos locales | `PedidoController`, `ComandaController`, `MesaController` (cuenta y cobro de la mesa) | `PedidoService`, `ComandaService`, `MesaCuentaService` | Pedidos de mesa y por QR, edición con historial, descuentos, comandas de cocina, cuenta acumulada de la mesa |
| M03 Compras y cierre | `CompraGastoController` | `CompraGastoService`, `CierreDiarioService` | Compras y gastos con soporte, Mesero autorizado, cierre diario con desglose, ajustes posteriores y PDF |
| M04 Catálogo | `CatalogoController`, `PromocionController`, `ImportacionProductosController` | `CatalogoService`, `ComboService`, `PromocionService`, `OfertaService`, `FotoProductoService`, `ImportacionProductosService` | Categorías, productos, presentaciones (con descripción y foto), extras, combos, promociones y ofertas, agotados, fotos e importación desde Excel |
| M05 Mesas y reservas | `MesaController`, `ReservaController`, `ReservaPublicaController` | `ReservaService`, `AbonoDepositoService`, `ReservaAutomaticaService`, `AvisoReservaService` | Mesas y QR, reservas con depósito, abono a la cuenta, no-show automático, recordatorios y avisos |
| M06 Caja y ventas | `CajaController`, `PagoController`, `ComprobanteController`, `PasarelaController` | `CajaService`, `PagoService`, `ComprobanteService`, `PasarelaPagoService` | Turnos de caja con resumen por método, cobros, pago mixto, anulaciones, comprobantes, pagos en línea con Wompi |
| M07 Domicilios y recogida | `PedidoController` (la misma tabla de pedidos, ADR-001) | `PedidoService`, `AvisoPedidoService` | Domicilios y recogidas web y por teléfono, entrega y recogida confirmadas, «listo para recoger» |
| M08 Reportes | `ReporteController`, `DashboardController` | `ReporteService`, `ReporteExportService` | Los 6 reportes, comparación con el periodo anterior, exportación a PDF y Excel |
| Transversal | `ConfiguracionController` | `EstadoSistemaService`, `RevisionProduccionService` | Valores editables, estado del programador y la cola, revisión antes de producción |

## Roles, autorización y seguridad

- **Autenticación**: Laravel Sanctum con tokens personales (`Authorization: Bearer`). El frontend
  guarda el token en `localStorage` (decisión del 13/10/2026). Cada sesión guarda su IP y su
  dispositivo y vence tras 7 días sin uso o a los 30 días (`SesionService`, configurable en `.env`).
- **Middleware** (registrados en `bootstrap/app.php`):
  - `role:administrador,mesero` (`EnsureUserHasRole`) deja pasar solo esos roles; donde entra
    `administrador` también entran `superadmin` (M09) y `soporte` (M10);
  - `menu:catalogo` (`EnsureMenuPermitido`, M09) rechaza la acción si el SuperAdmin le quitó ese menú a
    la cuenta (basta con tener uno de los menús listados);
  - `activo` (`EnsureUserIsActive`) responde 403 a una cuenta desactivada en cualquier ruta con sesión;
  - `SoporteSoloConsulta` (M10), en toda la API, rechaza con 403 «La cuenta de Soporte solo puede
    consultar.» cualquier escritura de la cuenta de Soporte (también en las rutas públicas que reconocen
    la sesión) y la impresión de comandas; deja pasar su propia cuenta, exportar reportes y cotizar;
  - `CabecerasSeguridad`, global, pone en toda respuesta de `/api` las cabeceras de seguridad
    (`nosniff`, `X-Frame-Options`, CSP, `Referrer-Policy` y `Cache-Control: no-store`; la foto
    pública de un producto lleva caché larga).
- **Policies**: `PedidoPolicy`, `PagoPolicy`, `CajaTurnoPolicy` y `ReservaPolicy`. Laravel las
  encuentra solas por el nombre del modelo. `app/Providers/AuthServiceProvider.php` todavía existe,
  pero no está en `bootstrap/providers.php` y no hace falta.
- **CORS**: `config/cors.php` deja llamar la API solo desde `FRONTEND_URL` y los orígenes de
  `CORS_ORIGENES`.
- **Datos que no salen**: el enlace secreto del invitado y la respuesta completa de Wompi están
  ocultos en las respuestas; el enlace de seguimiento no trae datos de contacto ni del personal.
- **Auditoría**: `AuditoriaService::registrar()` guarda cada acción crítica con IP y navegador; la
  base de datos no deja cambiar ni borrar ese historial (ADR-010 en `BASE-DE-DATOS.md`).
- **Contraseña segura** (21/10/2026, RN-USR-23): `Password::defaults()` en `AppServiceProvider` (8 caracteres como mínimo, con al menos una mayúscula, una minúscula y un número;
  en producción, además, `uncompromised()` contra «Have I Been Pwned», que solo recibe los 5 primeros
  caracteres del hash y espera como mucho 5 s). La usan el registro, `POST /usuarios`, el cambio y la
  recuperación de contraseña y los comandos `fragua:crear-superadmin` y `fragua:crear-soporte`.
- **Bloqueo por intentos** (21/10/2026, RN-USR-24): `BloqueoInicioSesionService`. 5 fallos seguidos de una
  cuenta (correo o usuario) o 20 de una IP bloquean 15 minutos desde el intento que lo activa; responde
  429 con el mensaje en español. Además sigue el límite de la ruta (10 por minuto por IP).
- **Revisión antes de producción**: `php artisan fragua:revisar-produccion` (y Configuración >
  «Revisión antes de producción») revisa depuración, HTTPS, cuentas de prueba, correo, cola,
  pasarela, CORS y la extensión GD.

## Tareas programadas, cola y comandos

En el servidor, cron corre `php artisan schedule:run` cada minuto y Supervisor mantiene
`php artisan queue:work`. Los archivos listos están en `backend/deploy/`.

| Comando | Cuándo | Qué hace |
|---|---|---|
| `reservas:procesar` | Cada minuto | No-show automático al vencer la tolerancia, mesa Reservada desde la ventana previa y recordatorios por correo |
| `notificaciones:revisar` | Cada minuto | Notificaciones del personal que dependen de la hora: reserva que empieza pronto, caja abierta de noche, cierre diario pendiente, cola detenida o con trabajos fallidos y la revisión antes de producción diaria (M11) |
| `notificaciones:limpiar` | 3:40 a. m., hora de Colombia | Borra las notificaciones de más de 30 días (M11) |
| `pasarela:conciliar` | Cada 5 minutos | Pregunta a Wompi por los pagos pendientes, por si un evento se perdió |
| `fragua:latido` | Cada minuto | Deja el latido del programador y encola el de la cola (Configuración > Tareas automáticas) |
| `sesiones:limpiar` | 3:30 a. m., hora de Colombia | Borra las sesiones vencidas |
| `fragua:revisar-produccion` | A mano, después de cada despliegue | Revisión de seguridad; sale con código 1 si hay algo grave |
| `reportes:medir [--dias=30]` | A mano | Mide cuánto tarda cada reporte con los datos reales y marca «LENTO» lo que pase de 2 s |

La cola envía los correos (avisos de reserva, «listo para recoger», comprobante) con reintentos. Si un
correo falla, el pedido o la reserva siguen normal y el aviso queda como fallido para reintentarlo.

## Configuración (.env)

Además de las variables estándar de Laravel (`APP_*`, `DB_*`, `MAIL_*`, `QUEUE_CONNECTION`,
`CACHE_STORE`), el sistema usa estas. Las que tienen valor por defecto viven en
`config/fragua_real.php` o `config/services.php`.

| Variable | Por defecto | Para qué |
|---|---|---|
| `FRONTEND_URL` | `http://localhost:5173` | Dirección pública del sitio: enlaces de los correos, retorno de Wompi y CORS |
| `CORS_ORIGENES` | vacío | Orígenes extra que pueden llamar la API, separados por coma |
| `FRAGUA_REAL_NOMBRE`, `_NIT`, `_DIRECCION`, `_TELEFONO` | datos del local; NIT vacío | Encabezado del comprobante (sin NIT no lo imprime) |
| `FRAGUA_REAL_SESION_INACTIVIDAD_MINUTOS` | 10080 (7 días) | Una sesión sin uso se cierra sola |
| `FRAGUA_REAL_SESION_DURACION_MAXIMA_MINUTOS` | 43200 (30 días) | Duración máxima de una sesión |
| `FRAGUA_REAL_SOPORTE_MAX_KB` | 5120 | Tamaño máximo del soporte de una compra o un gasto |
| `FRAGUA_REAL_FOTO_PRODUCTO_MAX_KB` | 5120 | Tamaño máximo de la foto de un producto |
| `FRAGUA_REAL_DURACION_RESERVA_MINUTOS` | 120 | Cuánto ocupa una reserva su mesa: dos reservas de la misma mesa no se cruzan |
| `FRAGUA_REAL_VALOR_DOMICILIO`, `FRAGUA_REAL_DEPOSITO_RESERVA` | 2000 y 20000 | Respaldo si la tabla `configuraciones` no tiene el valor |
| `FRAGUA_REAL_RESERVA_TOLERANCIA_MINUTOS`, `_RECORDATORIO_MINUTOS`, `_VENTANA_MINUTOS` | 15, 120 y 60 | Respaldo de los tiempos de reserva (se editan en Configuración) |
| `PASARELA_PROVEEDOR` | `manual` | `wompi` activa la confirmación automática; `manual` deja la aprobación a mano |
| `PASARELA_MINUTOS_CHECKOUT` | 30 | Tiempo para terminar un pago en Wompi |
| `PASARELA_EVENTOS_MAX_HORAS` | 72 | Un evento de Wompi más viejo no se aplica |
| `WOMPI_AMBIENTE` | `sandbox` | `sandbox` o `produccion` |
| `WOMPI_LLAVE_PUBLICA`, `_PRIVADA`, `_SECRETO_EVENTOS`, `_SECRETO_INTEGRIDAD` | vacías | Llaves de Wompi > Desarrolladores |

El valor del domicilio, el depósito de reserva y los tiempos de las reservas se cambian en la
aplicación (Administrador > Configuración, tabla `configuraciones`); las variables de arriba son solo
el respaldo.

## Archivos que guarda el sistema

Todo va en el disco privado `storage/app/private` y se entrega solo a quien tiene permiso. Hay que
incluirlo en las copias de seguridad.

| Carpeta | Qué guarda | Quién lo ve |
|---|---|---|
| `soportes-compras-gastos/` | Fotos y PDF de facturas y recibos | El Administrador; el Mesero autorizado, solo los suyos |
| `productos/` | Fotos de productos reducidas a 900 px en WebP | Todos (la ruta de la foto es pública) |
| `reportes/` | Reportes exportados en PDF y Excel | El Administrador |
| `comprobantes-pago/AAAA-MM/` | Capturas de las transferencias que suben los clientes (pago directo, sin Wompi) | El Administrador y el Mesero, por la API |

Los comprobantes, las comandas y el cierre en PDF no se guardan como archivo: se dibujan cada vez a
partir de la copia guardada en la base, así una reimpresión dice exactamente lo mismo.

## Pruebas automáticas

`backend/tests/Feature` tiene 61 archivos y 431 pruebas; `tests/Unit` tiene la de ejemplo de Laravel.
En total son 344.

```bash
php artisan test                    # SQLite en memoria (4 pruebas se saltan: usan funciones de PostgreSQL)
php artisan test --filter=Caja      # solo las que coinciden
```

Para correrlas contra PostgreSQL, como en producción, se apunta a una base de prueba con las variables
`DB_CONNECTION=pgsql`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` al lanzar `php artisan test`; ahí
pasan las 344. Cada prueba cita el requisito que cubre. `DiaCompletoTest` recorre un día completo de
operación por la API con los tres roles.

## Qué falta a propósito

| Tema | Estado | Referencia |
|---|---|---|
| Avisos por SMS | Los avisos salen por correo; el SMS espera proveedor y presupuesto. Cada aviso ya guarda su canal | Decisión D3; M05 §16, RF-M07-R-012 |
| Cuenta comercial de Wompi | La confirmación automática funciona en el sandbox; falta la cuenta de producción y sus llaves | Decisión D4; punto 41 |
| Impresión sin una persona de por medio | Comandas y comprobantes se imprimen desde el navegador; sin nadie que mire hace falta un agente local junto a la impresora | Decisión D5; punto 42 |
| Mapa simple del salón | Descartado por el negocio: no se hará; el estado de las mesas se ve en la lista | RF-M05-022 (22/10/2026) |
| Exportación asíncrona de reportes | Se genera al momento: un reporte de un mes tarda menos de medio segundo | M08 §15; punto 50 |
| Caché de reportes de periodos cerrados | No hizo falta: se recalcula siempre para no mostrar cifras viejas | M08 §15; punto 50 |
| Factura electrónica, inventario, roles de Cocina y Domiciliario, GPS | Fuera del alcance de los documentos oficiales | Idea de Negocio y M00 |

## Historial de cambios

Lo que sigue es el registro, en orden, de cada corrección y cada tarea del plan, con los requisitos
que cubre, los archivos que tocó y sus pruebas. Es historia: cuando un punto posterior cambia algo de
uno anterior, vale el posterior (por ejemplo, el punto 39 reemplaza la exportación simple del punto 9,
y el punto 50 resuelve la caché que antes figuraba como pendiente). Los puntos 1 a 14 son de la etapa
anterior al plan (septiembre y comienzos de octubre de 2026); del 15 en adelante, una fase o tarea del
plan cada uno.

Los puntos 1 a 6 corrigen el Documento Técnico de Arquitectura original, que tenía
inconsistencias con lo que existía o era correcto en Laravel:

1. **`PagoPolicy` faltante.** El flujo de la sección 3 ("Administrador cobra un pedido
   Local") usaba `PagoPolicy::cobrar()` sin que existiera. Se creó — pero con un ajuste
   técnico importante (punto 2).
2. **`cobrar` y `pagarEnLinea` viven en `PedidoPolicy`, no en `PagoPolicy`.** Laravel
   resuelve la Policy por la clase del modelo que se autoriza: `$this->authorize('cobrar',
   $pedido)` siempre consulta la Policy registrada para `Pedido`, nunca la de `Pago`. Como
   ambas habilidades autorizan sobre un Pedido (antes de que el Pago exista), quedaron en
   `PedidoPolicy`. `PagoPolicy` solo tiene `anular()`, que sí actúa sobre un `Pago` ya
   creado. Ver el comentario en `app/Policies/PedidoPolicy.php`.
3. **`AuthServiceProvider` registra las 3 Policies**, no solo `PedidoPolicy` como mostraba
   el snippet del documento: `Pedido`, `Pago` y `CajaTurno`.
4. **`ComboService` no reimplementa `Combo::disponible()`.** El documento asignaba
   "determinar disponibilidad de combos" al Service sin mencionar que esa lógica ya vivía
   en el Model. `ComboService` ahora solo añade lo que el Model no puede resolver por sí
   solo (vigencia por fecha/franja/día) y delega el resto a `Combo::disponible()`.
5. **M03 (Compras/Gastos/Cierre Diario) y M08 (Reportes) ya tienen Service.** El
   documento no los incluía en la tabla de Services de la sección 4, dejándolos como
   lógica suelta en el Controller — lo cual contradice el propio argumento del documento
   en su sección 2. Se agregaron `CompraGastoService`, `CierreDiarioService` y
   `ReporteService` (con las 6 agregaciones de M08: ventas por período, productos más
   vendidos, ventas por método de pago, cierres de caja, compras/gastos y reservas).
   *(Histórico: ese conjunto de reportes no coincidía con M08 y fue reemplazado — ver punto 9.)*
6. **`valor_domicilio` y `deposito_valor` ya NO se aceptan del Cliente.** El documento no
   lo especificaba, pero dejar que el body del request fije esos valores permitiría a
   cualquiera poner su propio costo de domicilio o depósito. Ahora los fija el servidor
   desde `config/fragua_real.php` (placeholder hasta que exista la tabla
   `configuraciones` mencionada en `README.md`).
7. **Regla de "quién cobra" corregida (22/09/2026, tras revisar la documentación oficial
   de módulos).** La primera versión de `Pedido::actorQueCobra()`,
   `PedidoPolicy::cobrar()` y `PagoService::validarQuienCobra()` tenía la regla al revés
   (Administrador cobrando local/recogida, Mesero solo domicilio contra entrega) y citaba
   reglas inventadas ("RN-PED-33", "RN-M07-R-06") que no existen en la documentación real.
   La regla confirmada en M06 (RF-M06-003, RF-M06-004, RNF-M06-004) y M07 (sección 13): el
   **Mesero** registra todos los cobros presenciales — local, domicilio contra entrega y
   recogida —; el **Administrador nunca cobra**, solo abre/cierra caja y autoriza
   anulaciones. El punto 1 de esta lista describe el ejemplo original del documento de
   arquitectura ("Administrador cobra un pedido Local"), que ya no refleja la regla vigente.
8. **Depósito de reserva ahora es obligatorio (23/09/2026, RN-M05-14 a 19; decisión de
   negocio "con depósito obligatorio").** Se reescribió el flujo de `Reserva`:
   `ReservaService` gana `registrarDepositoEnLinea()` / `confirmarDepositoEnLinea()` /
   `rechazarDepositoEnLinea()` (mismo patrón confirmar/rechazar/reintentar de la pasarela
   en línea de M06, aplicado a la reserva en vez de a un pedido) y
   `registrarDepositoManual()` (Mesero o Administrador, cualquier método presencial de
   M06). `completar()` ahora sí mueve la mesa de reservada a ocupada y marca
   `deposito_aplicado_abono` (RN-M05-19: el depósito se abona al pedido de esa visita).
   `cancelar()` marca `deposito_reembolsado` cuando aplica (RN-M05-17: reembolso completo
   si se cancela antes de la hora reservada). Se quitó `decidirDevolucionDeposito()`: un
   no-show ya no se reembolsa por decisión discrecional del Administrador (RN-M05-18), así
   que esa ruta y ese método dejaron de existir. La migración de `reservas` gana
   `deposito_pasarela`, `deposito_estado`, `deposito_aplicado_abono` y
   `deposito_registrado_por`, y `deposito_devuelto` se renombró a `deposito_reembolsado`.
9. **M08 (Reportes) reconstruido con el conjunto real de reportes (23/09/2026).** La
   primera versión de `ReporteService`/`ReporteController` tenía 6 reportes que no
   coincidían con el documento oficial (ventas por período/canal, productos más vendidos,
   ventas por método de pago, cierres de caja, compras/gastos, reservas) y no tenía
   selección de periodo, comparación ni exportación. Los reportes reales (RF-M08-002 a
   009) son: ventas totales, ventas por producto y categoría (con más/menos vendidos),
   ventas por tipo de venta y por método de pago, compras y gastos, resultado operativo, y
   uso de mesas. Se agregó `App\Support\Reportes\PeriodoReporte` (RF-M08-001, RN-M08-03:
   semana/mes/personalizado, semana actual por defecto; RN-M08-04: periodo anterior
   equivalente; RN-M08-05: marca un periodo en curso como parcial). `ReporteService` ganó
   `compararResumen()` (RF-M08-010, variación absoluta y porcentual) y `aTabla()` +
   `registrarExportacion()` para la exportación (RF-M08-011/012/014, auditoría vía el
   modelo `Auditoria` ya existente). Nuevo `ReporteExportService` genera el archivo
   exportado. `resultadoOperativo()` sigue la recomendación de arquitectura del documento
   (sección 15): agrega sobre los `cierres_diarios` que M03 ya calculó, y solo recorre
   pedidos/compras/gastos en vivo para los días que aún no tienen cierre (normalmente el
   día en curso). El reporte de "cierres de caja" no forma parte del alcance real de M08
   (no está en su sección 2) y se quitó — la información que ofrecía queda cubierta por
   resultado operativo.
10. **Brechas menores de M01/M03/M04/M05/M06 cerradas (23/09/2026).**
    - **RN-M05-04**: `PedidoService::crear()` ahora marca `mesa.estado = 'ocupada'` al
      registrar el primer pedido local (antes solo se marcaba al finalizar/cancelar, nunca
      al crear).
    - **RN-M06-05, RF-M06-007**: `PedidoService::confirmar()` ahora rechaza confirmar un
      pedido de domicilio o recogida si tiene un pago en línea todavía sin aprobar (pendiente
      o rechazado); si el pedido no tiene pago en línea (paga contra entrega/al recoger), no
      aplica esta validación.
    - **RF-M05-009/010/011**: `MesaController` gana `abrir()` (Mesero/Administrador, sin
      pedido de por medio), `liberar()` (bloqueada si hay pedidos activos, igual que la
      liberación automática ya existente) y `liberarForzada()` (exclusiva del Administrador,
      con motivo obligatorio, salta esa validación y deja registro en auditoría).
    - **RF-M04-016/017**: `CatalogoController::toggleDisponible()` — Mesero o Administrador
      pueden alternar agotado/disponible sin el permiso de edición completa que exige
      `updateProducto()`.
    - **RF-M01-012/013**: `AuthController` gana `solicitarRecuperacion()` /
      `restablecerContrasena()`. Genera un token, lo guarda con hash en la nueva tabla
      `password_reset_tokens` (expira a la hora) y no revela si el correo existe. **Falta el
      envío real del correo** con el token — ver "Qué falta a propósito".
    - **RF-M01-010/023**: `AuthController` gana `logoutTodos()` (revoca todos los tokens del
      usuario autenticado) y `revocarSesiones()` (el Administrador revoca las sesiones de
      otro usuario — no hay `UsuarioController` dedicado, así que vive junto al resto de auth).
      Restablecer la contraseña también revoca todas las sesiones activas.
    - **RF-M03-14**: `CompraGastoService::editar()` + `CompraGastoController::update()` —
      edita un registro mientras esté activo (RN-M03-04 sigue vigente: nunca se borra, solo
      se anula; un registro anulado ya no se puede editar).
11. **Corrección del documento M05 y cierre de HU-M05-015 (23/09/2026).** Al revisar cada
    "brecha" contra la documentación oficial se encontró que la corrección del depósito de
    reserva descrita en el punto 8 nunca se había escrito realmente en
    `M05_Gestion_de_Mesas_Fragua_Real.docx`: RN-M05-14 seguía diciendo "fuera de alcance",
    faltaban RN-M05-15 a 19, RF-M05-028 a 036, la HU-M05-015, y las secciones de Alcance,
    Revisión transversal, Pendientes y Conclusión seguían con el texto antiguo (solo el
    documento de M06 sí tenía la referencia correcta a M05 en su tabla de dependencias). Se
    corrigió el documento M05 completo — devuelto ya a la carpeta del usuario — y se agregó
    en el código el endpoint que le faltaba a la nueva HU-M05-015 ("Consultar depósitos
    retenidos"): `ReservaController::depositosRetenidos()` (`GET
    /reservas/depositos-retenidos`, exclusivo Administrador), que filtra reservas con
    `estado = no_show`, `deposito_pagado = true` y `deposito_reembolsado = false`.
12. **Envío real del correo de recuperación de contraseña (01/10/2026).** Cerrado el
    pendiente que el punto 10 y "Qué falta a propósito" dejaban anotado. Nueva
    `App\Notifications\RecuperarPasswordNotification` (Notification, no Mailable suelto —
    queda lista para encolarse el día que `QUEUE_CONNECTION` deje de ser `sync`):
    `AuthController::solicitarRecuperacion()` la dispara con el token en texto plano (el
    hash sigue siendo lo único que se guarda en `password_reset_tokens`), con un enlace a
    `/restablecer-password?email=&token=` que `RestablecerPasswordView.vue` ya sabía
    precargar. Nueva clave `fragua_real.frontend_url` (env `FRONTEND_URL`, default
    `http://localhost:5173`) arma ese enlace. El driver de correo (`MAIL_MAILER=smtp` y las
    credenciales SMTP reales: host, puerto, usuario, contraseña, from) se configuran en el
    `.env` de cada entorno — no se tocan aquí ni se fuerzan por código, porque
    `phpunit.xml` fija `MAIL_MAILER=array` a propósito para pruebas y forzar `smtp` por
    config() rompería eso.
13. **`GET /caja/turno-actual` devolvía `{}` en vez de `null` sin turno abierto (01/10/2026,
    encontrado en vivo en el QA final).** `CajaController::turnoActual()` hacía
    `return response()->json($this->cajaService->turnoAbierto()?->load([...]));` — código
    correcto en apariencia, pero en esta instalación concreta (Laravel 13 + PHP 8.3)
    `response()->json(null)` no serializa al JSON `null`: serializa al literal `"{}"`.
    Se aisló quitando de en medio el Controller, el Service y el modelo (una ruta de
    prueba con un closure que solo hacía `return response()->json(null);` ya daba `"{}"`),
    así que no es un bug de este proyecto sino del framework/entorno — pero había que
    manejarlo en el código igual. Como `{}` es "truthy" en JS,
    `admin/CajaView.vue` (`turnoActual.value = data`, `v-else-if="!turnoActual"`)
    interpretaba "sin turno" como "turno abierto" y mostraba "Abierta · Base inicial $0 ·
    abierto por —" sin que existiera ningún turno para hoy — lo que a su vez bloqueaba al
    Mesero para cobrar (`RN-M06-03` correctamente rechazaba el cobro porque, en la base de
    datos, no había turno de hoy). `turnoActual()` ahora construye la respuesta a mano
    cuando no hay turno (`response('null', 200)->header('Content-Type',
    'application/json')`) en vez de dejar que `response()->json()` serialice un `null` de
    nivel superior; con un turno real, `response()->json($turno)` sí serializa bien (se
    confirmó en vivo), así que solo ese caso puntual necesitaba el rodeo.

14. **Revisión de la documentación oficial (06/10/2026).** Cruce de los 13 documentos con el
    código y los READMEs. Corregido en los documentos: M05 (referencias a M06 como "pendiente",
    actores del depósito, trazabilidad RF-M05-028 a 036 sin historia de usuario, estado de
    HU-M05-015 que figuraba como pendiente aunque el endpoint ya existe, y nueva
    HU-M05-016 "Configurar valor del depósito"), Catálogo de Pantallas, Cronograma,
    Estructura Técnica y estos READMEs. **Decidido el 06/10/2026:** la liberación forzada de mesa
    (RF-M05-011) la pueden hacer el Mesero y el Administrador, como dice M05; la ruta
    `POST /mesas/{mesa}/liberar-forzada` se movió al grupo `role:administrador,mesero`.
    **Corregido el mismo día:** `GET /pedidos` paginaba de a 20 y las pantallas Mesero > Pedidos y
    Mesero > Caja solo veían esa primera página, así que con volumen real podían quedar pedidos
    activos fuera de la lista. `PedidoController::index` ahora acepta `?activos=1` (pendiente,
    confirmado y preparando) y `?per_page=` (hasta 100); ambas pantallas piden solo lo que
    necesitan. Queda sin paginar Cliente > Mis pedidos (muestra los 20 más recientes).
    **Pantalla Configuración (06/10/2026):** el backend permitía editar el valor de domicilio y el
    depósito (`PUT /configuraciones`) pero el frontend no tenía dónde; se agregó
    `frontend/src/views/admin/ConfiguracionView.vue`, con su entrada en el menú del
    Administrador y `configuracionService.actualizar()`.
    **Quedan abiertos:**
    - *Administrador > Pedidos:* construida el 06/10/2026 como vista de supervisión (filtros por
      estado/canal, historial paginado, confirmar, marcar preparando y cancelar con motivo; el
      cobro sigue siendo solo del Mesero). Aclaración: M02 no define un "panel" propio del
      Administrador (solo dice que interviene según los permisos de M01); la pantalla usa los
      permisos que ya daba `PedidoPolicy`.
    - `Requisitos_Funcionales_Fragua_Real.docx` es un borrador antiguo con otra numeración;
      conviene archivarlo.

15. **Fase 0 del plan de implementación (09/10/2026): errores y huecos de seguridad.** Siete
    tareas, cada una con pruebas automáticas en `tests/Feature` (57 pruebas en total). Cuatro
    migraciones nuevas, todas aditivas (`2026_10_13_000001` a `000004`).
    1. *Finalización (RN-PED-32, RN-M07-06, RN-M07-R-04).* Un pedido solo pasa a FINALIZADO
       desde PREPARANDO, con el pago completo y, en domicilio y recogida, con la entrega o la
       recogida confirmada por el Mesero (`POST /pedidos/{id}/entregar`, `/recoger`,
       `/finalizar`). El cobro contra entrega exige la entrega confirmada (RF-M06-004). Antes un
       pago en línea aprobado finalizaba un pedido pendiente.
    2. *Creación pública de pedidos (RNF-M02-006, RNF-M04-004).* `origen=mesero` exige token de
       Mesero o Administrador; el invitado no puede fijar `cliente_id`; se validan mesa activa,
       variante del producto, extras, categoría activa y vigencia del combo.
    3. *Caja (RN-M06-08, RF-M06-017/019).* El efectivo esperado resta compras y gastos en
       efectivo del día. Anular o reembolsar con la caja del día cerrada exige la contraseña del
       Administrador y queda en la tabla `ajustes_caja`, sin reescribir el turno. Los pagos en
       línea del día aparecen en el detalle del turno.
    4. *Checkout y pago del invitado (RF-M06-005/008).* Cada pedido nace con un `token_publico`
       (solo se entrega al crearlo) para que el invitado pague y consulte
       (`/pedidos/seguimiento/{token}`). Solo un pago en línea pendiente por pedido; reintentar el
       pago ya no crea otro pedido.
    5. *Cifras (RN-M04-26, RF-M08-003/005, RF-M03-33).* Ventas por producto y por tipo incluyen
       combos y extras; el cierre diario toma el día en hora de Bogotá, igual que los reportes.
    6. *Sesiones (RF-M01-006/023).* Desactivar una cuenta revoca sus sesiones; el middleware
       `activo` bloquea a una cuenta desactivada en toda ruta con sesión; hay límite de intentos
       en login, recuperación, `POST /pedidos` y `POST /reservas`.
    7. *Auditoría (RNF-TRANS-020/021/022).* `AuditoriaService::registrar()` guarda usuario, acción,
       entidad, IP y navegador; los registros no se pueden editar ni borrar. Ya se auditan:
       apertura y cierre de caja, anulación y reembolso de pagos, cancelación de pedidos,
       activación y desactivación de usuarios, revocación de sesiones, liberación forzada de mesa
       y exportación de reportes.

    **Cómo correr las pruebas:** `php artisan test` (SQLite en memoria, según `phpunit.xml`).
    Para probar contra PostgreSQL como en producción, define `DB_CONNECTION=pgsql` y los datos
    de una base de pruebas antes del comando. La migración `2026_09_29_000001` se ajustó para
    funcionar en ambos motores.

16. **Fase 1, tarea 1 (10/10/2026): editar y modificar pedidos con historial.** RN-PED-07 a 12,
    RF-M02-L-004/005/012/013/014/015/016/017, HU-M02-002/004/005/020 y sus equivalentes de M07
    (RF-M07-009/021/023/024/028, RF-M07-R-005/016). Migración nueva `2026_10_14_000001`
    (tabla `pedido_modificaciones`); 11 pruebas en `EdicionPedidoTest`.
    - `POST /pedidos/{id}/detalles` agrega una línea (precio vigente congelado, mismas validaciones
      que al crear); `PATCH /pedidos/{id}/detalles/{detalle}` cambia cantidad u observaciones;
      `DELETE /pedidos/{id}/detalles/{detalle}` retira una línea. Solo Mesero y Administrador
      (`PedidoPolicy::modificar`).
    - PENDIENTE admite todo. CONFIRMADO solo toca líneas no preparadas (RN-PED-10). PREPARANDO,
      FINALIZADO y CANCELADO no se modifican: lo nuevo de una mesa en preparación va en otro
      pedido (RN-PED-13). Un pedido no puede quedar sin líneas (para eso se cancela) y el total no
      puede quedar por debajo de lo ya pagado (RF-M06-011). Subir la cantidad de algo agotado se
      rechaza; bajarla, no.
    - Cada cambio recalcula subtotal y total y queda en `pedido_modificaciones` (quién, cuándo,
      antes, después, total anterior y nuevo) y en la auditoría (`modificar_pedido`). Los hechos con
      el pedido CONFIRMADO quedan con `afecta_comanda = true`: son los que imprimirá la comanda de
      actualización (RN-PED-09) cuando exista la impresión POS (Fase 2).
    - `GET /pedidos/{id}/historial` (Mesero y Administrador) arma la historia completa: creación,
      cambios de estado, modificaciones y pagos con sus anulaciones, en orden.
    - Frontend: botón "Editar pedido" (pendiente o confirmado) y "Ver historial" en Pedidos del
      Mesero y del Administrador (`components/pedidos/EditarPedidoModal.vue` y
      `HistorialPedidoModal.vue`).

17. **Fase 1, tarea 2 (11/10/2026): domicilios y recogidas por teléfono, datos de entrega
    completos.** RF-M07-001, RF-M07-003 a 007, RF-M07-010, RF-M07-R-001/003, RN-M07-01,
    RN-M07-R-01, RF-M07-013/015, RF-M07-R-007/008, HU-M07-001/003/004. Migraciones
    `2026_10_14_000002` (columnas `telefono_contacto` y `observaciones_entrega` en `pedidos`, con
    relleno del teléfono de los pedidos existentes) y `000003` (`metodo_pago_preferido` y
    `pasarela_preferida`); 10 pruebas en `PedidoTelefonicoTest`.
    - Un domicilio exige nombre, teléfono, dirección, barrio y referencia (antes barrio y
      referencia eran opcionales). El teléfono se valida (7 a 15 dígitos).
    - Todo domicilio y toda recogida guardan `telefono_contacto`: el del invitado, el que escribe
      el Cliente con cuenta o, si no escribe ninguno, el de su perfil. Un Cliente sin teléfono en
      el perfil tiene que escribirlo. `observaciones_entrega` guarda las indicaciones del pedido
      completo (portería, timbre). Una recogida nunca guarda dirección y un local no guarda
      datos de entrega.
    - El Mesero registra domicilios y recogidas pedidos por teléfono o WhatsApp desde "Nuevo
      pedido" (elige Mesa, Domicilio o Recogida en el primer paso); se cobran contra entrega o
      al recoger. Pedidos del Mesero y del Administrador muestran nombre, teléfono (toque para
      llamar), dirección, barrio, referencia e indicaciones (`DatosEntregaPedido.vue`).
    - Cada domicilio y recogida guarda cómo dijo el cliente que iba a pagar: `contra_entrega`
      (por defecto, también en los pedidos por teléfono) o `en_linea` con su pasarela (Nequi o
      Bancolombia, obligatoria en ese caso). Al registrar un pago en línea, el pedido queda con la
      pasarela aplicada. Pedidos muestra "Paga: contra entrega / al recoger / en línea (Nequi)".

18. **Fase 1, tarea 3 (11/10/2026): cuenta acumulada de la mesa y cobro por mesa.**
    RF-M02-L-018/019/020/021, RN-PED-13/14/30, HU-M02-006, RF-M06-003/011. Migración
    `2026_10_14_000004` (`mesas.ocupada_desde`); 10 pruebas en `CuentaMesaTest`.
    - La cuenta de una mesa son los pedidos locales de la visita en curso, sin cancelados. La
      visita empieza cuando la mesa pasa a Ocupada (primer pedido, "Abrir mesa" o llegada de una
      reserva) y termina cuando pasa a Libre, como define M05; así no se mezclan los pedidos de
      clientes anteriores. Los cambios de estado de la mesa pasan por `Mesa::ocupar()` y
      `Mesa::liberar()`.
    - `GET /mesas/{id}/cuenta` (Mesero y Administrador) devuelve pedidos, total, pagado y saldo.
      `GET /mesas` agrega `cuenta_total`, `cuenta_saldo` y `cuenta_pedidos` a cada mesa ocupada
      para el personal. `GET /pedidos?mesa_id=` filtra por mesa.
    - `POST /mesas/{id}/cobrar` (solo el Mesero) cobra la mesa completa o una parte (pago mixto).
      `MesaCuentaService` reparte el monto entre los pedidos con saldo, del más antiguo al más
      nuevo; cada parte es un pago del pedido (ADR-003) y cada pedido finaliza solo cuando cumple
      RN-PED-32. Nunca cobra más que el saldo y, si algo falla, no queda ningún pago a medias.
    - Frontend: Mesero > Mesas muestra en cada mesa ocupada el total y lo que debe, con "Ver
      cuenta"; Mesero > Cobrar agrupa los pedidos locales en "Mesas por cobrar" y deja los
      domicilios y recogidas uno por uno (`components/pedidos/CuentaMesaModal.vue`).
    - Pendiente (decisión D7 del plan, Fases 2 y 3): el depósito de una reserva todavía no se
      descuenta de la cuenta de la mesa; hoy solo queda marcado como abono en la reserva.

19. **Fase 1, tarea 4 (11/10/2026): reservas del personal.** RF-M05-012/013/015/031/036,
    RN-M05-06/07/12/16, HU-M05-009/010. Migración `2026_10_14_000005` (`reservas.creado_por`
    acepta `administrador` y nueva columna `registrada_por`); 9 pruebas en `ReservaPersonalTest`.
    - CORREGIDO: `POST /reservas` es pública y el controlador usaba `$request->user()`, que ahí
      siempre es null; toda reserva quedaba como `cliente_web`, también las del personal. Ahora
      lee la sesión con Sanctum: la del Mesero queda `mesero`, la del Administrador
      `administrador`, y `registrada_por` guarda quién la registró (y se audita
      `crear_reserva_manual`).
    - Solo mesas activas. Dos reservas de la misma mesa no se cruzan: deben estar separadas al
      menos `duracion_reserva_minutos` (config, 120 por defecto; supuesto de trabajo hasta la
      ventana configurable de la Fase 4). Aparta la mesa una reserva confirmada, una con pago en
      línea en revisión o una manual del personal; una reserva web que nunca empezó a pagar no.
      Si dos reservas web piden la misma hora, la primera cuyo depósito se confirma se queda con
      la mesa y la otra se rechaza al confirmar.
    - `GET /mesas/disponibles?fecha_hora=` (pública) devuelve las mesas activas libres de
      reservas a esa hora; la usan la reserva web y la del personal. `GET /reservas?proximas=1`
      lista pendientes y confirmadas de hoy en adelante, en orden de hora.
    - El depósito manual solo acepta efectivo, tarjeta o transferencia (RN-M05-16; antes también
      `en_linea`). Confirmar, rechazar o registrar un depósito queda auditado (RF-M05-036).
    - Frontend: Mesero > Reservas (nueva pestaña) usa la misma pantalla del Administrador, que
      ahora tiene "Nueva reserva", empieza en "Próximas", dice "Confirmar llegada" y muestra quién
      registró cada reserva; los depósitos retenidos siguen siendo solo del Administrador. La
      reserva web muestra solo las mesas libres a la hora elegida.

20. **Fase 1, tarea 5 (11/10/2026): agotado y búsqueda del menú.** RF-M04-016 a 021 y 025,
    RN-M04-07/08, HU-M04-009/010/013. Migración `2026_10_14_000006` (`variantes.disponible`);
    7 pruebas en `DisponibilidadCatalogoTest`.
    - Una variante (tamaño) se marca agotada o disponible con
      `POST /variantes/{id}/disponibilidad` (Administrador y Mesero), igual que un producto. Una
      variante agotada no se puede pedir ni aumentar en un pedido; los pedidos ya creados no cambian.
      Cada cambio de disponibilidad (producto o variante) queda en la auditoría.
    - `GET /productos` acepta `?q=` (nombre, sin distinguir mayúsculas) y `?categoria_id=`. El
      público solo recibe productos activos y disponibles de categorías activas, con sus variantes
      activas y no agotadas; el personal recibe también lo agotado, para poder reactivarlo.
    - Frontend: pestaña Menú del Mesero (`views/mesero/DisponibilidadView.vue`, también en
      `/admin/disponibilidad` con el botón "Agotados y disponibilidad" del Catálogo): buscador,
      filtro por categoría, "Solo agotados" e interruptores por producto y por tamaño. "Nuevo
      pedido" tiene buscador en todo el menú y ya no ofrece tamaños agotados.

21. **Fase 1, tarea 6 (11/10/2026): cancelación según estado y edición de mesas.**
    RF-M02-L-024, RF-M07-017, RF-M07-R-009/010, RN-M07-05, RF-M05-002/021, HU-M05-002. Sin
    migraciones; 8 pruebas en `CancelacionYMesasTest`.
    - El Mesero cancela un pedido PENDIENTE. Uno CONFIRMADO o en PREPARANDO solo con la contraseña
      de un Administrador activo (`password_administrador`), y la auditoría guarda quién autorizó.
      El Administrador cancela cualquier pedido activo. Decisión tomada para "con autorización"
      de RN-M07-05: la cocina ya tiene ese pedido.
    - El Cliente cancela su domicilio o su recogida PENDIENTE (antes solo el domicilio). El
      invitado lo hace con su enlace: `POST /pedidos/seguimiento/{token}/cancelar`.
    - `PUT /mesas/{id}` (solo Administrador) corrige el nombre sin repetir el de otra mesa; el QR no
      cambia. Crear, renombrar, dar de baja, reactivar y regenerar el QR de una mesa quedan en la
      auditoría (RF-M05-021).
    - Frontend: el modal "Cancelar pedido" del Mesero pide la contraseña del Administrador cuando
      el pedido ya está confirmado; Mis pedidos del Cliente permite cancelar recogidas pendientes;
      Administrador > Mesas tiene "Cambiar nombre".
    - Seguimiento del invitado (RF-M07-027, RF-M07-R-015): página pública
      `/seguimiento/<token>` (`views/cliente/SeguimientoView.vue`) con el avance del pedido, sus
      productos, total y pagos; mientras esté PENDIENTE permite pagar en línea o cancelarlo. Se
      actualiza sola cada 30 segundos. El Checkout del invitado muestra ese enlace al terminar.

22. **Fase 2, tarea 1 (11/10/2026): promociones aplicadas al pedido y descuento manual.**
    RF-M04-035 a 039 y 041, RN-M04-18 a 23, HU-M04-014/015. Migración `2026_10_15_000001`
    (`pedidos.descuento`, `promociones_vigentes`, `promociones_aplicadas`, `descuento_manual*` y
    `pedido_detalles.descuento`); 23 pruebas en `PromocionesYDescuentosTest`.
    - Las promociones **automáticas** vigentes se aplican solas al crear el pedido, en los tres
      canales. Se guardan con el pedido (como los precios, ADR-002): si después se agrega una
      línea, se le aplican las mismas reglas aunque la promoción haya vencido o cambiado.
    - Cómo se calcula (`PromocionService::calcular`): las de producto o categoría rebajan el
      precio del producto en cada línea que coincide (el valor fijo es por unidad); varias se
      suman sin pasar del valor de la línea. Las de "total del pedido" se calculan sobre lo que
      queda (productos y extras) y se reparten entre las líneas. Un combo solo recibe las del
      total. El valor del domicilio nunca se rebaja. Todo se redondea al peso. El total queda
      `subtotal − descuento + domicilio`.
    - CORREGIDO: la vigencia de promociones **y combos** se evaluaba en la hora del servidor
      (UTC); ahora en la de Colombia. Una franja de 12:00 a 15:00 empezaba a las 7:00 a. m.
    - **Descuento manual** (`POST /pedidos/{id}/descuento`, Mesero y Administrador): porcentaje
      (1 a 100) o valor fijo mayor a cero, o una promoción de tipo "manual" preparada por el
      Administrador (`promocion_id`; lista en `GET /promociones/manuales`). El motivo es
      obligatorio. Reemplaza a las automáticas del pedido (no se acumulan); `DELETE` lo quita y
      vuelven las automáticas. Se permite hasta que el pedido se finaliza y nunca deja el total por
      debajo de lo ya pagado. Queda en el historial del pedido y en la auditoría
      (`aplicar_descuento_manual` / `quitar_descuento_manual`, con usuario, motivo y monto).
    - Crear y editar una promoción (incluido desactivarla, RF-M04-040) queda en la auditoría
      (`crear_promocion` / `editar_promocion`, con antes y después).
    - `POST /pedidos/cotizacion` (pública, no guarda nada) devuelve subtotal, promociones,
      domicilio y total de un carrito, con la misma cuenta que usará el pedido. `GET /productos`
      trae `promociones_vigentes` de cada producto para mostrar el precio rebajado.
    - Reportes: las ventas por producto, categoría y tipo de venta restan la parte del descuento
      de cada línea, así cuadran con lo cobrado.
    - Frontend: botón "Descuento" en Pedidos del Mesero y del Administrador
      (`components/pedidos/DescuentoPedidoModal.vue`); los totales con sus rebajas
      (`components/pedidos/TotalesPedido.vue`) en esos pedidos, Mis pedidos y el seguimiento del
      invitado; el carrito, el Checkout, el QR de mesa y "Nuevo pedido" del Mesero muestran las
      promociones vigentes y el total cotizado (`composables/useCotizacion.ts`); el menú, el QR y
      "Nuevo pedido" marcan los productos en promoción.

23. **Fase 2, tarea 2 (12/10/2026): el depósito de la reserva se abona a la cuenta de la visita.**
    RF-M05-034, RN-M05-19, decisión D7 del plan. Migración `2026_10_15_000002` (`pagos.tipo` y
    `pagos.reserva_id`; `reservas.pedido_id`, `deposito_abonado`, `llegada_at`,
    `deposito_caja_turno_id` y `deposito_reembolso_caja_turno_id`); 13 pruebas en
    `AbonoDepositoReservaTest`.
    - D7: el abono es un **pago de tipo `deposito`** sobre el pedido, aprobado, con el método con
      el que se pagó el depósito y **sin turno de caja** (`AbonoDepositoService`,
      `PagoService::registrarAbonoDeposito`). Así la cuenta de la mesa, el saldo, el cobro y la
      finalización funcionan igual que con cualquier pago, y el dinero no se cuenta dos veces.
    - Cuándo: al "Confirmar llegada" (`POST /reservas/{id}/completar`, que ahora guarda
      `llegada_at` y queda en la auditoría) o con el primer pedido en una mesa RESERVADA, que cuenta
      como la llegada de la reserva confirmada de hoy para esa mesa. Se abona del pedido más
      antiguo al más nuevo de la visita; si la visita consume menos que el depósito, lo que sobra
      queda "por abonar" y se usa en los pedidos nuevos de esa visita.
    - Si un pedido baja de valor (edición o descuento) por debajo de lo abonado, o se cancela, el
      abono se reduce solo: el pago se reversa con `reverso_tipo = ajuste_abono` y se registra uno
      nuevo por lo que cabe; lo liberado pasa a otros pedidos de la visita con saldo
      (auditoría `ajustar_abono_deposito`). Si el Administrador anula un abono, no toca ninguna
      caja ni deja ajuste extraordinario, y el valor vuelve a quedar por abonar.
    - Caja (RN-M06-02/08): registrar un depósito manual exige la caja abierta; en efectivo suma
      al efectivo esperado del turno en que se recibe. Cancelar una reserva con depósito en
      efectivo exige la caja abierta y resta la devolución de ese turno. Los depósitos en línea no
      pasan por la caja. El detalle del turno (`GET /caja/turnos/{id}`) trae `depositos_reserva`,
      `depositos_devueltos` y sus totales en efectivo; los abonos no salen en "pagos en línea".
    - Reportes: en ventas por método de pago el abono sale aparte como `deposito_reserva`.
    - Historial del pedido: "Abono del depósito de la reserva #N" y sus ajustes.
    - Frontend: la cuenta de la mesa dice cuánto de lo pagado es depósito; Reservas muestra
      cuánto se abonó y cuánto queda; el detalle del turno en Caja lista los depósitos recibidos y
      devueltos; Reportes tiene la etiqueta "Depósito de reserva".
    - CORREGIDO de paso: la visita de la mesa empieza en el momento exacto del primer pedido, así
      ese pedido siempre queda dentro de la cuenta aunque el reloj cambie de segundo entre guardar
      el pedido y ocupar la mesa.

24. **Fase 2, tarea 3 (12/10/2026): el mismo catálogo en todos los canales.** RF-M04-003, 010,
    011, 020, 021, 022, 023, 027, 028, RN-M04-09, 10, 12, 14, HU-M04-004, 008, 011, 012. Migración
    `2026_10_16_000001` (tabla `producto_extra`); 10 pruebas en `CatalogoCoherenteTest`.
    - Consultas públicas separadas de las de gestión: `GET /categorias` sin sesión (o con un
      Cliente) trae dentro de cada categoría solo los productos activos y disponibles; antes traía
      todos, incluidos los dados de baja. `GET /productos` ya no manda al público los extras
      específicos inactivos. El personal sigue viendo todo para poder reactivarlo.
    - Una categoría desactivada oculta sus productos y los combos que los usan en todos los
      canales, y no se pueden pedir; el combo no se da de baja: vuelve solo al reactivarla.
    - Extras generales (RF-M04-010/011): el Administrador elige en cada producto qué extras
      generales ofrece (`extras_generales` en `POST/PUT /productos`, tabla `producto_extra`). Un
      extra general que no se asocia a ningún producto no se ofrece con ninguno (CA-M04-004).
      CORREGIDO (16/10/2026, producción): antes se ofrecía con todo el menú, y una Limonada aceptaba
      "Extra queso"; la migración `2026_10_23_000001` asoció los generales sueltos a los productos
      de tipo Alimento. Cada producto trae `extras_disponibles`, la misma lista para el Menú,
      el QR y el pedido del Mesero, y `PedidoService` acepta exactamente esa lista
      (`Extra::aplicaA`). `GET /extras` dice con qué productos se ofrece cada general.
    - Bajas (RF-M04-027, RN-M04-12): dar de baja un producto desactiva los combos que lo usan (queda
      en la auditoría y la respuesta trae `combos_desactivados`); un combo no puede quedar activo
      mientras tenga un componente dado de baja (422 con el nombre del componente).
    - Validaciones: todo precio (producto, variante, extra, combo) mayor a cero (RN-M04-10);
      nombres de producto y de categoría repetidos con mensaje claro (antes, error 500).
    - Auditoría (RF-M04-022): crear o editar categorías, productos, variantes, extras y combos
      queda con quién, cuándo y qué cambió (antes y después, incluido el precio). Guardar sin
      cambios no deja registro.
    - Frontend: `utils/extras.ts` da la misma lista de extras en Menú, QR, "Nuevo pedido" y "Editar
      pedido"; en Catálogo, el formulario del producto tiene "Extras generales que ofrece", la
      lista de extras dice con qué productos se ofrece cada uno, el combo marca los componentes
      dados de baja y se avisa qué combos se desactivaron.

25. **Fase 2, tarea 4 (12/10/2026): compras y gastos completos (M03).** RF-M03-01, 03, 04, 07,
    13, 19, 30, RN-M03-02, 03, 14, RNF-M03-04, 06, 11, HU-M03-02, 03. Migración
    `2026_10_16_000002` (`cantidad`, `unidad`, `observaciones`, `soporte_path/nombre/mime`,
    `editado_por/at`, `anulado_por/at` en `compras_gastos`); 10 pruebas en
    `ComprasGastosCompletosTest`.
    - Categoría obligatoria y de su tipo: Compra → Alimentos, Bebidas, Empaques, Insumos, Otros;
      Gasto → Transporte, Limpieza, Servicios, Mantenimiento, Papelería, Otros (M03, sección 9).
      Viven en `config/fragua_real.php` (`categorias_compras_gastos`) para ampliarlas sin tocar
      código (RNF-M03-12); `GET /compras-gastos/categorias` las entrega. Un registro viejo con
      otra categoría se puede seguir editando mientras no se cambie.
    - Cantidad y unidad solo informativas (no hay inventario, RN-M03-02) y observaciones.
    - La fecha de la compra puede ser anterior al registro, pero no futura (RN-M03-14); la fecha y
      hora del registro son automáticas (RF-M03-30).
    - Soporte (RF-M03-07, RNF-M03-06/11): `soporte` en `POST /compras-gastos` (multipart) o en
      `POST /compras-gastos/{id}/soporte`. Solo JPG, PNG o PDF, revisando el contenido real del
      archivo, hasta 5 MB (`FRAGUA_REAL_SOPORTE_MAX_KB`). Se guarda en `storage/app/private`
      (disco `local`), nunca en una carpeta pública; se ve con `GET /compras-gastos/{id}/soporte`
      y sesión de Administrador. Reemplazarlo no borra el anterior (queda nombrado en la
      auditoría). El campo suelto `soporte_url` ya no se recibe; los registros viejos lo conservan.
    - Historial (RF-M03-13): `GET /compras-gastos` filtra por `desde`, `hasta`, `tipo`,
      `categoria`, `metodo_pago`, `proveedor` (parte del nombre) y `estado`, y trae `totales` de lo
      filtrado (solo activos).
    - Trazabilidad (RF-M03-19, RNF-M03-04): cada registro guarda quién y cuándo lo corrigió o anuló,
      y la auditoría guarda alta, corrección (antes y después), anulación y soporte.
    - Frontend (Administrador > Compras y gastos): categoría en lista según el tipo, cantidad,
      unidad, observaciones, archivo de soporte y "Ver soporte"; filtros con sus totales y
      paginación; quién corrigió o anuló cada registro.
    - **Para el VPS (Hostinger):** PHP debe aceptar archivos de al menos 6 MB
      (`upload_max_filesize = 6M` y `post_max_size = 8M` en `php.ini`) y Nginx
      `client_max_body_size 8m;`. La carpeta `storage/app/private` debe poder escribirla el usuario
      del servidor web e incluirse en las copias de seguridad.

26. **Fase 2, tarea 5 (12/10/2026): cierre diario completo (M03).** RF-M03-20 a 27, 34,
    RN-M03-08, 15, RN-M06-11, 12, HU-M03-07 a 10. Migración `2026_10_16_000003`
    (`cierres_diarios.detalle` y `caja_turno_id`, tabla `cierre_ajustes`); 7 pruebas en
    `CierreDiarioCompletoTest`. Con esta tarea la Fase 2 quedó completa.
    - Consulta previa (RF-M03-26): `GET /cierres-diarios/previa?fecha=` da el cálculo del día con
      su desglose: ventas por tipo de venta y por forma de pago (el abono de depósitos aparte),
      compras y otros gastos por categoría, compras y gastos por forma de pago, el arqueo de la caja
      del día, y si ya se puede cerrar. El resultado lleva la nota "estimado, no es la utilidad
      contable" (RF-M03-34).
    - Cierre formal (RF-M03-25, HU-M03-08): `POST /cierres-diarios` exige que la caja de ese día ya
      esté cerrada, porque el arqueo alimenta el cierre (RN-M06-11); no acepta días futuros ni un
      segundo cierre. Guarda los totales, el desglose y el turno de caja, y queda en la auditoría.
    - Historial (RF-M03-27): `GET /cierres-diarios` (con cuántas correcciones tuvo cada uno) y
      `GET /cierres-diarios/{id}` (el cierre tal como se hizo, sus ajustes, los valores
      actualizados y el cálculo de hoy).
    - Correcciones después del cierre (RF-M03-20, RN-M03-08/15, HU-M03-09, decisión D7):
      registrar, corregir o anular una compra o gasto de un día cerrado (o moverla a ese día) exige
      `password_confirmacion` del Administrador y un motivo (`motivo_correccion`; al anular, el de
      la anulación). Sin ellos responde 422 con `requiere_autorizacion: true`. El cierre no se
      reescribe: cada corrección queda en `cierre_ajustes` (cuánto cambiaron compras y gastos,
      quién autorizó, cuándo y por qué) y en la auditoría (`ajuste_cierre_diario`).
    - CORREGIDO de paso: el total de compras y gastos de un día se calculaba con `whereBetween`
      sobre la fecha, que en SQLite no encontraba los registros (la fecha se guarda con hora).
    - Frontend: `components/compras/CierreDiarioPanel.vue` en Compras y gastos — cálculo del día
      con todo el desglose, botón "Cerrar el día" (desactivado con el motivo si la caja sigue
      abierta), cierres anteriores, y en un día cerrado sus correcciones y los valores
      actualizados. Al guardar o anular algo de un día cerrado, el formulario pide la contraseña y
      el motivo.
27. **Fase 3, tarea 1 (12/10/2026): consulta de auditoría y eventos de seguridad (M01).**
    RF-M01-017, 024, 025, HU-M01-015, 020, RNF-TRANS-022, 023, RN-USR-12, 13, CA-M01-015/020.
    Migración `2026_10_17_000001` (triggers que impiden cambiar o borrar `auditorias` e índice por
    fecha); 7 pruebas en `AuditoriaConsultaTest`.
    - Eventos de seguridad auditados (`AuthController`): inicio de sesión (`inicio_sesion`), intento
      fallido (`inicio_sesion_fallido`, con el correo usado y el motivo: contraseña incorrecta,
      cuenta inexistente o desactivada; nunca la contraseña), cierre de sesión (uno o todos los
      dispositivos), registro de cliente, cambio de contraseña, solicitud de recuperación (solo si
      la cuenta existe; la respuesta sigue siendo la misma), restablecimiento y restablecimiento
      fallido (enlace no válido o vencido), y actualización del perfil propio (antes y después).
      También el alta de usuarios del personal (`crear_usuario`) y los cambios de configuración
      (`editar_configuracion`, valor de domicilio y depósito con antes y después). Cada registro
      guarda IP y navegador (RF-M01-024).
    - Consulta (RF-M01-025, RNF-TRANS-023): `GET /auditorias` y `GET /auditorias/opciones`, solo
      Administrador y solo lectura. Filtros: días del negocio (`desde`/`hasta`, hora de Colombia),
      usuario que hizo la acción, grupo (seguridad, usuarios, pedidos, caja, compras, catálogo,
      mesas, reportes, configuración), acción, registro afectado (`entidad`, `entidad_id`), IP y
      texto en los detalles (con o sin tildes). Cada registro llega con el texto de la acción, su
      grupo y un resumen del navegador ("Chrome en Android"). `opciones` trae los valores de los
      filtros y el resumen de seguridad de las últimas 24 horas: inicios fallidos y exitosos,
      restablecimientos e IPs con 3 o más intentos fallidos. El catálogo de acciones vive en
      `App\Support\Auditoria\CatalogoAuditoria`; una acción nueva que no esté ahí se muestra igual,
      en el grupo "Otros".
    - Historial protegido (RNF-TRANS-022, RN-USR-13, CA-M01-020.2): además del bloqueo en el modelo
      (Fase 0), la base de datos rechaza cualquier `UPDATE`, `DELETE` o `TRUNCATE` sobre
      `auditorias` (trigger en PostgreSQL; en SQLite para las pruebas). Por eso un usuario con
      registros de auditoría no se puede borrar de la base de datos: el sistema nunca borra
      usuarios, los desactiva.
    - CORREGIDO de paso: la hora de cada registro la ponía la base de datos (`CURRENT_TIMESTAMP`),
      que en PostgreSQL depende de la zona horaria del servidor; ahora la pone la aplicación en
      UTC, como el resto de fechas, para que los filtros por día sean exactos.
    - Frontend: `views/admin/AuditoriaView.vue` (menú "Auditoría") con el resumen de seguridad,
      atajo "Solo seguridad y sesiones", filtros y el detalle legible de cada registro (motivo,
      antes → después, navegador completo). En Usuarios, el botón "Actividad" abre la auditoría
      filtrada por ese usuario, con un atajo para ver también lo que pasó sobre su cuenta.
28. **Fase 3, tarea 2 (12/10/2026): editar un usuario y cambiar su rol (M01).** RF-M01-003, 005,
    011, 021, 022, 023, HU-M01-003, 005, CA-M01-003.1/003.2, CA-M01-005.1/005.2. Sin migración;
    7 pruebas en `EditarUsuarioTest`.
    - `PUT /usuarios/{id}` (solo Administrador, `UpdateUsuarioRequest`): nombre, correo (único,
      sin chocar con el suyo), teléfono (vacío = sin teléfono) y rol. Solo esos campos
      (CA-M01-003.1): la contraseña y el estado activo no se cambian aquí; la contraseña la cambia
      cada persona en su perfil o con la recuperación, y activar/desactivar tiene su propio botón.
    - Rol: solo administrador, mesero o cliente (RF-M01-022; cualquier otro, como "cocina" o
      "domiciliario", se rechaza). Un Cliente puede pasar a Mesero y al revés. El Administrador no
      puede cambiar su propio rol (se quedaría sin acceso a Usuarios): 422 sin guardar nada.
    - Cambiar el rol cierra todas las sesiones de esa cuenta (RF-M01-023): sus permisos cambian y
      ninguna pestaña abierta sigue con el rol anterior. La respuesta trae `sesiones_cerradas`.
    - Auditoría: `editar_usuario` con el antes → después de lo que cambió (guardar sin cambios no
      registra nada) y `cambiar_rol` aparte, con el rol anterior, el nuevo y cuántas sesiones se
      cerraron.
    - Frontend: botón "Editar" en cada usuario de Administrador > Usuarios; modal con nombre,
      correo, teléfono y rol (Administrador, Mesero o Cliente). Si el rol cambia, avisa que se
      cerrarán sus sesiones y el botón dice "Guardar y cambiar rol"; en la cuenta propia el rol
      aparece bloqueado.
29. **Fase 3, tarea 3 (12/10/2026): iniciar sesión con el correo o con un nombre de usuario (M01).**
    RF-M01-007, 011, 016, RNF-M01-004, RN-USR-04, HU-M01-006, 011. Migración
    `2026_10_17_000002` (`users.username`, opcional y único); 5 pruebas en `LoginPorUsuarioTest`.
    - Nombre de usuario (`App\Support\Usuarios\NombreUsuario`, mismas reglas en registro, perfil y
      Usuarios): opcional; de 3 a 30 caracteres; empieza con una letra; solo letras sin tilde,
      números, punto, guion y guion bajo; nunca lleva "@". Se guarda en minúsculas y es único sin
      importar mayúsculas. Las cuentas que ya existían quedan sin nombre de usuario y siguen
      entrando con su correo.
    - `POST /auth/login` recibe `login` (correo o nombre de usuario) y `password`; si lo escrito
      lleva "@" se busca como correo, si no como nombre de usuario. El campo anterior `email` se
      sigue aceptando (con cualquiera de los dos). El error es el mismo en todos los casos y sale
      en el campo que se envió. La auditoría guarda por dónde entró (`via`: correo o usuario) y,
      en los intentos fallidos, el correo o el usuario escrito.
    - Dónde se pone: al registrarse (opcional), en Mi perfil (cada quien lo elige, cambia o quita;
      si el formulario no lo envía, no se toca) y en Usuarios al crear o editar una cuenta (con
      su antes → después en la auditoría). La búsqueda de Usuarios también busca por él.
    - Seeder: las cuentas de prueba tienen `admin`, `mesero` y `cliente` como nombre de usuario
      (solo en una base recién sembrada).
    - Frontend: el login pide "Correo o nombre de usuario" (con autocompletado del navegador);
      Crear cuenta y Mi perfil tienen el campo opcional; Usuarios lo muestra como "@usuario" y
      lo pide al crear y editar. `BaseInput` acepta `autocomplete`.
    - La recuperación de contraseña sigue siendo por correo.
30. **Fase 3, tarea 4 (12/10/2026): sesiones abiertas con su origen y su vencimiento (M01).**
    RF-M01-010, 023, 024, HU-M01-010, RNF-M01-005, 015. Migración `2026_10_17_000003` (IP y
    navegador en `personal_access_tokens`); 7 pruebas en `SesionesActivasTest`. Con esta tarea la
    Fase 3 quedó completa.
    - `SesionService`: cada inicio de sesión o registro abre la sesión guardando IP, navegador y
      su vencimiento máximo (`expires_at`). Política en `config/fragua_real.php` (`sesiones`) y en
      el `.env`: se cierra sola tras `FRAGUA_REAL_SESION_INACTIVIDAD_MINUTOS` sin uso (7 días) y,
      aunque se use, a los `FRAGUA_REAL_SESION_DURACION_MAXIMA_MINUTOS` (30 días). Son valores
      iniciales propuestos; el negocio los ajusta sin tocar código. `AppServiceProvider` le pasa
      la regla de inactividad a Sanctum (`authenticateAccessTokensUsing`): una sesión vencida
      responde 401 y la app vuelve al login.
    - Mis sesiones: `GET /auth/sesiones` (dispositivo, IP, abierta, último uso, vence y cuál es la
      actual, más la política), `DELETE /auth/sesiones/{id}` (cerrar una, solo las propias) y
      `POST /auth/sesiones/cerrar-otras` (todas menos la actual).
    - Administrador: `GET /usuarios/{id}/sesiones` y `DELETE /usuarios/{id}/sesiones/{sesion}`
      (no sobre su propia cuenta; sus sesiones las maneja desde Mi perfil). `GET /usuarios` trae
      `sesiones_activas` de cada cuenta. "Revocar todas" sigue igual.
    - Auditoría: el inicio de sesión guarda el número de la sesión; cerrar una sesión propia
      (`cerrar_sesion_dispositivo`), las demás (`cerrar_otras_sesiones`) o una ajena
      (`revocar_sesion`) queda registrado con el dispositivo y la IP.
    - Limpieza: `php artisan sesiones:limpiar` borra las sesiones vencidas o abandonadas; está
      programado a diario a las 3:30 a. m. (hora de Colombia) en `routes/console.php`. Corre
      solo cuando el cron del VPS ejecute `php artisan schedule:run` cada minuto (Fase 4); sin
      eso las sesiones vencidas igual dejan de servir, solo quedan en la tabla.
    - `App\Support\Navegador` describe el navegador ("Chrome en Android") para la auditoría y las
      sesiones.
    - Frontend: Mi perfil tiene "Sesiones abiertas" (cerrar una o "Cerrar las demás", con la
      política explicada); en Usuarios el botón "Sesiones (n)" reemplaza a "Revocar sesiones" y
      abre la lista de esa cuenta para cerrar una o todas.
31. **Fase 4, tarea 1 (12/10/2026): cola de trabajos y programador de tareas en marcha.** Base de
    RF-M05-016/024 y de todos los avisos de la Fase 4. Migración `2026_10_18_000001` (tablas
    `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks` y `sessions`, cada una solo si no
    existe); 6 pruebas en `ColaYProgramadorTest`.
    - CORREGIDO: el `.env` de ejemplo usa `QUEUE_CONNECTION`, `CACHE_STORE` y `SESSION_DRIVER` en
      `database`, pero esas tablas nunca se habían creado; las pruebas no lo notaban porque usan
      `sync`/`array`. En el servidor, el límite de intentos del login (vive en la caché) y el
      bloqueo del programador habrían fallado.
    - Latido (`EstadoSistemaService`, comando `fragua:latido`, programado cada minuto): deja en la
      caché la hora del programador y encola `App\Jobs\LatidoCola`, que al procesarse deja la
      hora de la cola. `GET /sistema/estado` (solo Administrador) dice si cada proceso está
      activo (latido de hace 3 minutos o menos), los trabajos pendientes y fallidos, y si el
      correo sale de verdad (`MAIL_MAILER` distinto de `log`/`array`).
    - Tareas programadas hoy (`routes/console.php`): `fragua:latido` cada minuto y
      `sesiones:limpiar` a las 3:30 a. m. (hora de Colombia). `php artisan schedule:list` las
      muestra.
    - Despliegue: `backend/deploy/cron-fragua-real` (cron con `schedule:run` cada minuto) y
      `backend/deploy/supervisor-fragua-real-cola.conf` (Supervisor con
      `queue:work database --tries=3 --backoff=60 --max-time=3600`). Pasos en README-ENTREGA,
      "Programador y cola en el VPS".
    - Decisiones del 12/10/2026 para la Fase 4: VPS de Hostinger (D2), correo en V1 y SMS después
      (D3), y no-show 15 min / recordatorio 2 h / mesa Reservada 1 h (D6).
    - Frontend: Configuración > "Tareas automáticas"
      (`components/configuracion/EstadoTareasAutomaticas.vue`).
32. **Fase 4, tarea 2 (12/10/2026): tiempos de las reservas configurables (M05).** RF-M05-017,
    026, RN-M05-07, 08, 13; decisión D6. Sin migración (usa la tabla `configuraciones`); 5 pruebas
    en `TiemposDeReservaTest`.
    - `App\Support\Reservas\PoliticaReservas`: tolerancia antes del no-show
      (`reserva_tolerancia_minutos`, 15 min, rango 5–120), anticipación del recordatorio
      (`reserva_recordatorio_minutos`, 2 h, rango 30 min–48 h) y desde cuándo la mesa se ve
      Reservada (`reserva_ventana_reservada_minutos`, 1 h, rango 15 min–4 h). Lee la tabla
      `configuraciones` y, si la fila no existe, `config/fragua_real.php` (`reservas`).
    - `PUT /configuraciones` acepta las tres claves (opcionales, minutos enteros dentro del rango,
      con mensajes claros) y las audita con su antes → después como el resto. `GET /configuracion`
      (público) las devuelve.
    - Se aplican desde la tarea 3 (no-show automático, mesa Reservada a tiempo y recordatorios);
      por ahora el Cliente ve la tolerancia al reservar ("te esperamos hasta 15 minutos").
    - Frontend: Configuración tiene la tarjeta "Tiempos de las reservas"; Reservar mesa explica la
      tolerancia. CORREGIDO de paso: en Configuración el error de domicilio y depósito salía dos
      veces (BaseInput ya lo muestra).
33. **Fase 4, tarea 3 (13/10/2026): no-show automático, mesa Reservada a tiempo y recordatorio
    (M05).** RF-M05-014, 016, 024, RN-M05-07, 08, 13, 18. Migración `2026_10_18_000002`
    (`reservas.no_show_at`, `no_show_automatico`, `recordatorio_enviado_at` e índice por estado y
    hora); 11 pruebas en `ReservasAutomaticasTest`.
    - `ReservaAutomaticaService`, comando `reservas:procesar` cada minuto (sin solaparse): marca
      no-show las reservas confirmadas cuya hora + tolerancia ya pasó (depósito retenido,
      auditoría `no_show_automatico` sin usuario), pone cada mesa en el estado que le toca y
      encola el recordatorio por correo (`RecordatorioReservaNotification`, D3: correo en V1) N
      minutos antes, una sola vez; no lo envía si la reserva se confirmó ya dentro de ese plazo.
    - `ReservaService::sincronizarMesa()`: una mesa Libre pasa a Reservada solo dentro de la
      ventana previa (1 h) o mientras corre la tolerancia; una Reservada sin reserva cercana vuelve
      a Libre; una Ocupada no se toca. CORREGIDO: antes, confirmar el depósito marcaba la mesa
      Reservada de inmediato aunque la reserva fuera para otro día, e incluso si estaba Ocupada.
      Las mesas que quedaron Reservadas con el criterio anterior se corrigen solas en el primer
      minuto del proceso.
    - No-show manual (`POST /reservas/{id}/no-show`): solo cuando ya venció la tolerancia (422 con
      la hora desde la que se puede); se audita como `marcar_no_show` con quien lo hizo.
    - Llegada: el primer pedido local en una mesa Libre que tiene su reserva dentro de la ventana
      también cuenta como llegada (completa la reserva y abona el depósito), aunque el proceso de
      ese minuto aún no la hubiera marcado Reservada.
    - El envío del recordatorio y su registro de entrega o falla por reserva (RF-M05-027), y los
      avisos de confirmación, cancelación y no-show, son la tarea 4.
    - Frontend: Reservas (Administrador y Mesero) muestra "Si no llega, se libera sola a las …",
      el recordatorio enviado y si el no-show fue automático; "Marcar no-show" solo aparece cuando
      ya pasó la tolerancia.
34. **Fase 4, tarea 4 (13/10/2026): avisos de reserva por correo y cancelación por enlace (M05).**
    RF-M05-018, 023, 025, 027, RN-M05-09, 17, 18, HU-M05-008, 011, 014; decisión D3 (correo en
    V1, SMS después). Migración `2026_10_18_000003` (`reservas.token_publico`, único, y tabla
    `reserva_avisos`); 9 pruebas en `AvisosDeReservaTest`.
    - Cuatro avisos por correo, cada uno con el enlace de la reserva: confirmación (al confirmar
      el depósito en línea o registrar el depósito en el local), recordatorio (tarea 3),
      cancelación (dice si el depósito se devuelve y cómo) y no-show (depósito retenido). Las
      notificaciones comparten `Notifications\Concerns\DescribeReserva` (fecha, hora y valores
      en pesos).
    - `AvisoReservaService::programar()` crea el registro en `reserva_avisos` (tipo, canal
      `correo`, destino, estado `pendiente` → `enviado` | `fallido`, intentos, error, hora de
      envío) y encola `App\Jobs\EnviarAvisoReserva` después del commit. El trabajo hace hasta 3
      intentos con 1 minuto entre uno y otro; si el último falla, el aviso queda `fallido` con el
      error (auditoría `falla_aviso_reserva`) y la reserva sigue normal: un correo que no sale
      nunca rompe la acción que lo generó. Cada envío se audita como `enviar_aviso_reserva`.
    - `POST /reservas/{reserva}/avisos/{aviso}/reintentar` (Administrador y Mesero): vuelve a
      enviar un aviso fallido (422 si ya salió). `GET /reservas` trae los avisos de cada reserva.
    - Enlace público sin sesión (`ReservaPublicaController`, límite 30 por minuto):
      `GET /reservas/enlace/{token}` muestra estado, mesa, fecha, depósito y tolerancia (sin
      correo ni teléfono) y `POST /reservas/enlace/{token}/cancelar` la cancela. El token son 48
      caracteres al azar, se crea con cada reserva (las existentes se rellenaron en la migración)
      y nunca sale en las respuestas del personal (`$hidden`). Solo cancela si la reserva está
      pendiente o confirmada y aún no llega su hora; si el depósito se pagó en efectivo, pide
      llamar o pasar por el local (la devolución en efectivo se hace en caja).
    - `ReservaService::cancelar()` ahora audita `cancelar_reserva` con quien la canceló (o sin
      usuario y `por_enlace` si fue el cliente) y si el depósito se reembolsó.
    - El enlace usa `FRONTEND_URL` (`config/fragua_real.php`): en el VPS debe ser la dirección
      pública del sitio. Para que los correos salgan de verdad hace falta `MAIL_MAILER=smtp` con
      sus credenciales (Configuración > Tareas automáticas avisa si sigue en `log`).
    - Pendiente por la decisión D3: el canal SMS (RF-M05-023/025 quedan a medias hasta tener
      proveedor); la tabla ya guarda el canal de cada aviso.
    - Frontend: página pública `/reserva/:token` (`views/cliente/ReservaEnlaceView.vue`) para ver
      y cancelar; Reservas muestra en cada reserva los avisos ("enviado 3:05 pm", "en cola",
      "falló" con el error) y el botón "Reintentar".
35. **Fase 4, tarea 5 (13/10/2026): aviso «listo para recoger» (M07).** RF-M07-R-012,
    RN-M07-R-03, HU-M07-012; decisión D3. Migración `2026_10_18_000004` (`pedidos.listo_at`,
    `listo_por`, `cliente_invitado_correo` y tabla `pedido_avisos`); 8 pruebas en
    `ListoParaRecogerTest`.
    - No es un estado nuevo (M02 y M07 no tienen LISTO; ambigüedad cerrada en el plan): la
      recogida sigue en PREPARANDO y solo se guarda cuándo y quién la marcó lista. Sigue
      finalizando como siempre, cuando el Mesero confirma la recogida y el pago está completo
      (RN-M07-R-04).
    - `POST /pedidos/{pedido}/listo` (Mesero y Administrador, `PedidoService::marcarListo()`):
      solo para una recogida en preparación, una sola vez; marca todas sus líneas como
      preparadas y programa el aviso por correo. 422 con el motivo en cualquier otro caso.
    - Correo del aviso: el de la cuenta del Cliente o el que deja el invitado, opcional, en el
      Checkout o al registrar la recogida por teléfono (`cliente_invitado_correo`, se guarda en
      minúsculas; solo para invitados de domicilio o recogida). Sin correo no hay aviso y la
      pantalla le pide al Mesero llamar.
    - `AvisoPedidoService` y `App\Jobs\EnviarAvisoPedido` funcionan como los avisos de reserva:
      registro en `pedido_avisos` (pendiente → enviado | fallido, intentos, error), 3 intentos con
      1 minuto entre uno y otro, auditoría `enviar_aviso_pedido` / `falla_aviso_pedido`, y el
      pedido nunca se afecta si el correo falla. `POST /pedidos/{pedido}/avisos/{aviso}/reintentar`
      lo vuelve a enviar. El correo (`ListoParaRecogerNotification`) dice cuánto falta por pagar
      y lleva el enlace de seguimiento del pedido.
    - El historial del pedido muestra "Listo para recoger" con quién lo marcó; `GET /pedidos` y el
      seguimiento por enlace traen `listo_at`.
    - Pendiente por la decisión D3: el SMS (RF-M07-R-012 y RN-M07-R-03 quedan a medias hasta tener
      proveedor).
    - Frontend: `components/pedidos/ListoParaRecoger.vue` en Pedidos del Mesero y del
      Administrador (botón, a qué correo irá el aviso, si salió o falló y "Reintentar aviso");
      el correo del cliente aparece en los datos de entrega; Checkout y Nuevo pedido (recogida)
      piden el correo opcional; Mis pedidos y el seguimiento muestran "Listo para recoger".
36. **Fase 5, tarea 1 (13/10/2026): comprobante de pago (M06, M02).** RF-M06-009, 010, RN-M06-07,
    HU-M06-006, CA-M06-006, RF-M02-L-023, RF-M02-T-013, HU-M02-019. Nueva dependencia
    `barryvdh/laravel-dompdf` ^3.1 (dompdf 3.1). Migración `2026_10_19_000001` (completa la tabla
    `comprobantes`, que existía vacía: `consecutivo`, `detalle` json, `anulado_at`,
    `anulado_motivo`; un pedido puede tener varios comprobantes en su historia); 7 pruebas en
    `ComprobantePagoTest`.
    - `ComprobanteService::sincronizar()` lo llaman los modelos `Pago` y `Pedido` (evento `saved`)
      cada vez que cambia un pago o el total o el estado del pedido. Con el pago completo (pagos
      aprobados y no anulados que suman el total, también si fue mixto) genera el comprobante con
      el siguiente consecutivo (`FR-000001`); si después el pedido deja de estar pagado (pago
      anulado, línea agregada), se cancela o cambian sus cifras, el vigente se anula con el motivo
      (auditoría `anular_comprobante`) y, al volver a estar pagado, sale uno nuevo con otro
      número. Nunca se borra ni se reescribe: `detalle` guarda lo que dice el documento al
      generarse (negocio, pedido, líneas, descuentos, domicilio, total y pagos).
    - "impreso" en pedidos locales: ticket de 80 mm en PDF que se imprime desde el navegador (la
      impresora POS directa es la Fase 6). "digital" en domicilio y recogida: PDF A5, que además
      llega por correo adjunto (`ComprobantePagoNotification`, aviso `comprobante` en
      `pedido_avisos`, con registro y reintento como el de «listo para recoger»).
    - `GET /pedidos/{pedido}/comprobante?formato=ticket|pdf` (personal, o el Cliente dueño) y
      `GET /pedidos/seguimiento/{token}/comprobante` (invitado). Sin formato, el del canal. 404 con
      "Este pedido todavía no tiene comprobante…" si el pago no está completo. Los pedidos traen
      `comprobante` (el vigente: número y tipo; el detalle no viaja en los listados) y el historial
      muestra cada comprobante generado o anulado.
    - Plantillas Blade `resources/views/comprobantes/pdf.blade.php` y `ticket.blade.php` (fuente
      DejaVu, solo las letras usadas: unos 25 KB). Datos del negocio en `config/fragua_real.php`
      (`negocio`): nombre, dirección y WhatsApp del sitio; el NIT se pone en `FRAGUA_REAL_NIT` y,
      vacío, no se imprime. El documento dice que no es factura electrónica (M06 §16).
    - Una mesa con varios pedidos tiene un comprobante por pedido (la tabla es por pedido).
    - CORREGIDO de paso: en este equipo de pruebas faltaba `resources/views/welcome.blade.php`;
      por eso `ExampleTest` fallaba aquí (en el repositorio sí existe). Ahora pasan las 249 pruebas.
    - Frontend: `components/pedidos/BotonComprobante.vue` ("Imprimir comprobante FR-…" o
      "Comprobante FR-… (PDF)", abre el documento en otra pestaña) en Pedidos del Mesero y del
      Administrador, en Cobrar (apenas el cobro completa el pago), en Mis pedidos y en el
      seguimiento del invitado.
37. **Fase 5, tarea 1b (13/10/2026): reimpresión controlada del comprobante.** Decisión del
    usuario del 13/10/2026: todo lo que el sistema imprime se puede volver a imprimir, pero una
    reimpresión no se confunde con el original y queda registrada. Migración `2026_10_19_000002`
    (`comprobantes.impresiones`); 5 pruebas en `ReimpresionComprobanteTest`.
    - Cuando el Mesero o el Administrador abre un comprobante (`ComprobanteService::imprimir()`),
      suma una impresión (con bloqueo de fila) y queda en la auditoría (`imprimir_comprobante`:
      quién, número, qué vez, formato, si estaba anulado). Desde la segunda vez el ticket dice
      "*** REIMPRESIÓN · COPIA n ***" y el PDF "REIMPRESIÓN · copia n", con la fecha y la hora.
    - Lo que abre el Cliente desde Mis pedidos o el invitado con su enlace, y el PDF del correo,
      no cuentan ni se marcan (es su propio comprobante).
    - Solo Administrador (`PedidoPolicy::verComprobantes`): `GET /pedidos/{pedido}/comprobantes`
      (todos, el vigente y los anulados, del más nuevo al más viejo, con motivo e impresiones) y
      `GET /pedidos/{pedido}/comprobantes/{comprobante}` (abre cualquiera; un anulado sale con su
      sello ANULADO y también cuenta como impresión). El Mesero reimprime solo el vigente.
    - La misma regla se aplicará a lo que falta imprimir: cierre diario en PDF, reportes guardados y
      comandas de cocina.
    - Frontend: `BotonComprobante.vue` con `personal` dice "Reimprimir comprobante FR-…" y "Ya se
      imprimió n veces: saldrá marcado «REIMPRESIÓN»"; Administrador > Pedidos tiene
      "Comprobantes" (`ComprobantesPedidoModal.vue`) con la lista y "Abrir"; `utils/documentos.ts`
      abre los PDF en otra pestaña y lee el mensaje de error que llega como Blob.
38. **Fase 5, tarea 2 (13/10/2026): cierre diario impreso y en PDF (M03).** RF-M03-28, 29,
    RNF-M03-09, 10, HU-M03-11, 12. Migración `2026_10_19_000003` (`cierres_diarios.impresiones`);
    5 pruebas en `CierreDiarioPdfTest`.
    - `GET /cierres-diarios/{cierre}/pdf` (solo Administrador, `CierreDiarioService::pdf()`): PDF
      tamaño carta con la plantilla `resources/views/cierres/pdf.blade.php`: negocio, día en
      letras, quién lo cerró y cuándo, los cuatro totales tal como se cerraron, el desglose
      guardado (ventas por tipo y por forma de pago, compras y gastos por categoría y por forma de
      pago), el arqueo de caja, las correcciones posteriores con quién las autorizó y los valores
      actualizados, la nota de resultado no contable (RF-M03-34), líneas de firma ("Cerró" y
      "Revisó") y al pie quién lo imprimió, cuándo y "Página n de m". Un cierre anterior a la Fase 2
      (sin desglose guardado) sale con sus totales y lo explica.
    - Regla de reimpresión: `App\Support\Documentos\RegistroImpresiones` (la usan ahora el
      comprobante y el cierre) suma la impresión con bloqueo de fila, la audita
      (`imprimir_cierre_diario`) y, desde la segunda, el PDF dice "REIMPRESIÓN · copia n".
    - Frontend: en un día cerrado, Compras y gastos > Cierre diario tiene "Imprimir / PDF del
      cierre" (luego "Reimprimir PDF del cierre", con aviso); en un día sin cerrar queda
      "Imprimir vista previa" (impresión del navegador) y la hoja dice que es una vista previa.
39. **Fase 5, tarea 3 (13/10/2026): exportación real de reportes (M08).** RF-M08-011, 012, 014,
    RNF-M08-006, HU-M08-009, 010, CA-M08-009, 010. Nueva dependencia
    `phpoffice/phpspreadsheet` ^5.0. Migración `2026_10_19_000004` (tabla `reporte_exportaciones`);
    7 pruebas en `ExportacionReportesTest` (una solo corre en PostgreSQL).
    - CORREGIDO: el "Excel" era un CSV y el PDF una sola página armada a mano que se cortaba.
    - `ReporteExportService::documento()` arma el documento con el mismo cálculo de la pantalla
      (`ReporteService::calcular()`, que también usan las rutas de cada reporte): título, periodo en
      letras, marca de parcial (RN-M08-05), resumen con nombres en español, la comparación con el
      periodo anterior si la pantalla compara (`comparar`, RF-M08-010) y las tablas del detalle
      (más y menos vendidos con el mismo tope de la pantalla, por categoría, todos los productos,
      por tipo de venta, por método de pago, compras y gastos por categoría, reservas por estado y
      ocupación por mesa). Reemplaza a `ReporteService::aTabla()`.
    - PDF (`resources/views/reportes/pdf.blade.php`, carta): las páginas que haga falta, encabezados
      de tabla repetidos, "Página n de m" (`App\Support\Documentos\PiePagina`, también la usa el
      cierre) y al pie quién lo generó y cuándo.
    - Excel `.xlsx` real: hoja "Resumen" (con la comparación y la nota del resultado operativo) y
      una hoja por tabla, con valores numéricos, formato de pesos, encabezados fijos y filtros.
    - RNF-M08-006: cada archivo se guarda en el disco privado (`storage/app/private/reportes/...`) y
      queda en `reporte_exportaciones` (reporte, formato, periodo, parcial, comparar, archivo,
      quién, descargas). `GET /reportes/exportaciones` las lista y
      `GET /reportes/exportaciones/{id}/descargar` entrega el mismo archivo, sin recalcular; cada
      descarga posterior suma y queda en la auditoría (`descargar_reporte`). Si el archivo ya no
      está en el servidor responde 410 con el motivo. La exportación sigue auditada como
      `exportar_reporte`, ahora con el id del archivo y si comparaba.
    - Sobre la regla de reimpresión: un archivo guardado no se puede marcar "REIMPRESIÓN" sin
      regenerarlo, y RNF-M08-006 pide no regenerarlo. Por eso cada documento dice cuándo y quién lo
      generó, y cada descarga queda registrada.
    - Frontend: Reportes exporta con la comparación de la pantalla y muestra "Exportaciones
      guardadas" con "Descargar"; `utils/documentos.ts` gana `guardarArchivo()`.
40. **Fase 5, tarea 4 (13/10/2026): uso de mesas con pedidos locales (M08).** RF-M08-009,
    HU-M08-007, CA-M08-007. Sin migración; 3 pruebas en `UsoDeMesasTest`. Con esta tarea la Fase 5
    quedó completa.
    - CORREGIDO: la ocupación de cada mesa solo contaba reservas completadas, así que una mesa llena
      de clientes sin reserva salía "sin uso".
    - `ReporteService::usoDeMesas()` cuenta ahora los pedidos locales no cancelados del periodo,
      agrupados en visitas: un pedido abre una visita nueva cuando todos los anteriores de esa mesa
      ya estaban finalizados o cancelados (la mesa se había liberado); los que llegan con la mesa
      ocupada son de la misma visita. Por mesa: visitas, pedidos, ventas (lo finalizado), minutos
      promedio por visita (del primer pedido al cierre del último, solo visitas cerradas) y reservas
      completadas. Lista todas las mesas activas, también las que no se usaron, de la más usada a la
      menos usada. El resumen suma `visitas` y `pedidos_locales`, y `mesas_con_uso` cuenta las
      mesas con visitas o reservas completadas. Las cifras de reservas no cambian (CA-M08-007).
    - La exportación (punto 39) trae la tabla "Ocupación por mesa" con esas columnas.
    - Frontend: Reportes > "Ocupación por mesa" muestra visitas, ventas, minutos promedio y reservas
      de cada mesa, y "Sin uso en el periodo" en las que no se usaron.

41. **Fase 6, tarea 1 (13/10/2026): pasarela Wompi con confirmación automática (M06, M07, M05).**
    Decisión D4: Wompi, desarrollado contra su sandbox, con la aprobación manual de respaldo.
    RF-M06-006, 007, 008, 017, RN-M06-05, RF-M07-013 a 015, RF-M05-029, 030. Migración
    `2026_10_20_000001` (tabla `transacciones_pasarela`; `pagos.confirmado_via` y `confirmado_por`;
    `reservas.deposito_confirmado_via`); 15 pruebas en `PasarelaWompiTest`, con eventos firmados y
    la API de Wompi simulada (`Http::fake`).
    - Interfaz `App\Support\Pasarela\PasarelaPago` y su adaptador `WompiPasarela` (enlazado en
      `AppServiceProvider`). Cambiar de proveedor es escribir otro adaptador.
    - Cobro: el Cliente elige pagar en línea y `PagoService::iniciarPagoEnLinea()` (o
      `ReservaService::iniciarDepositoEnLinea()` para el depósito) deja el pago "pendiente" como
      antes y crea el intento en `transacciones_pasarela`. Ese intento lleva una referencia secreta
      (`FRP-…` o `FRR-…` y 20 caracteres al azar) y vence a los 30 minutos. La respuesta trae
      `checkout.url`: el Web Checkout de Wompi con la firma de integridad (sha256 de referencia +
      monto en centavos + moneda + vencimiento + secreto). Con la pasarela se cobra exactamente lo
      que falta, no lo que mande el navegador.
    - Confirmación: `POST /api/pasarela/wompi/eventos` (público, sin sesión). Solo aplica un evento
      `transaction.updated` cuya firma cuadre: sha256 de los valores de `signature.properties` +
      `timestamp` + el secreto de eventos, y además el encabezado `X-Event-Checksum` si viene.
      Firma mala: 401 y nada cambia. `PasarelaPagoService::aplicar()` es idempotente (un evento
      repetido no hace nada la segunda vez) y aprueba o rechaza el pago o el depósito
      (`confirmado_via = pasarela`, auditado como `confirmar_pago_en_linea` /
      `rechazar_pago_en_linea` / `confirmar_deposito_reserva` con `via`). RN-M06-05 y RF-M06-007
      siguen igual: `PedidoService::confirmar()` no confirma con un pago sin aprobar.
    - Página de retorno: Wompi devuelve al Cliente a `/pago/retorno/{referencia}?id=…`. El frontend
      llama `GET /api/pasarela/transacciones/{referencia}` (público, con límite de intentos), que
      consulta la transacción en Wompi. Un `id` de otra referencia se ignora. La respuesta no trae
      la respuesta cruda de Wompi.
    - RF-M06-008: al reintentar, se pregunta antes por el intento abierto. Si nunca llegó a Wompi
      queda "abandonado" y el pago rechazado; si sigue en proceso (Nequi esperando aprobación) se
      avisa en vez de cobrar dos veces; si ya se aprobó, se dice.
    - Conciliación: `php artisan pasarela:conciliar`, cada 5 minutos con el programador. Pregunta
      por los intentos pendientes de más de 2 minutos, por si un evento se perdió, y cierra como
      abandonados los que vencieron sin pagarse.
    - El sistema no decide solo con el dinero: marca "por revisar" (`requiere_revision`, auditado
      `pago_pasarela_por_revisar`) estos casos:
      - un monto distinto;
      - un aprobado por Wompi que ya estaba rechazado a mano, anulado o abandonado;
      - un rechazo de Wompi a un pago aprobado a mano;
      - un pedido cancelado que ya estaba pagado;
      - un depósito aprobado cuya reserva ya no se puede confirmar (mesa tomada);
      - un reembolso que Wompi no hizo.
      El Administrador los ve en Caja > "Pagos en línea" (`GET /pasarela/estado`,
      `GET /pasarela/transacciones?revision=1`) y los cierra con una nota
      (`POST /pasarela/transacciones/{id}/revisada`, auditado `revisar_pago_pasarela`).
    - Reembolso (RF-M06-017, RN-M05-17): reembolsar un pago en línea en Caja, o cancelar una reserva
      con depósito pagado en línea, se lo pide también a Wompi (`reembolso_pasarela` en la
      auditoría). Wompi solo anula por API los pagos con tarjeta (`POST /transactions/{id}/void`).
      Los de Nequi, botón Bancolombia y PSE quedan "no_soportado", con la indicación de devolverlos
      desde el panel de Wompi. Si la llamada falla, la anulación o la cancelación siguen en pie y
      se reintenta con `POST /pasarela/transacciones/{id}/reembolsar`.
    - Respaldo (D4): sin `PASARELA_PROVEEDOR=wompi` o sin alguna de las cuatro llaves, todo sigue
      como antes: el pago queda en revisión y lo aprueba el Mesero o el Administrador, ahora con
      quién lo hizo en la auditoría. Con la pasarela activa, esa aprobación manual sigue disponible.
    - CORREGIDO (seguridad): `POST /reservas/{id}/deposito/en-linea` aceptaba cualquier número de
      reserva, que es consecutivo. Ahora exige el enlace secreto de la reserva (`token`), que
      `POST /reservas` devuelve una sola vez a quien la crea desde la web. El depósito también se
      paga o se reintenta desde el enlace del correo (`POST /reservas/enlace/{token}/deposito`).
    - Frontend:
      - Checkout, Seguimiento, Mis pedidos, Reservar y Mi reserva mandan al checkout de Wompi
        (`utils/pasarela.ts`).
      - Nueva página `PagoRetornoView.vue`. Se actualiza sola mientras Nequi espera la aprobación y
        lleva al pedido o a la reserva, o a reintentar.
      - Cobrar (Mesero) y Reservas (Administrador) dicen "En proceso en Wompi · se confirma solo" y
        dejan "Aprobar a mano" de respaldo.
      - Caja (Administrador) muestra el estado de la pasarela, la URL de eventos, los pagos por
        revisar (`PagosPasarelaPorRevisar.vue`) y, en cada pago en línea, cómo se aprobó y cómo
        quedó su reembolso.
    - Configuración en el servidor: llaves `WOMPI_*` en `.env` y la URL de eventos en Wompi >
      Desarrolladores (ver README-ENTREGA).
42. **Fase 6, tarea 2 (13/10/2026): comandas de cocina impresas desde el navegador (M02, M07).**
    Decisión D5: en V1 el Mesero imprime el ticket desde el navegador, sin un agente local.
    RF-M02-L-011, RF-M02-T-004 a 007, RN-PED-06, 09, 17 a 19, RNF-M02-001, 004, 005, 011, 014, 016,
    HU-M02-016, 017, RF-M07-020, 022. Migración `2026_10_20_000002` (tabla `comandas`); 7 pruebas
    en `ComandasTest`. Con esta tarea la Fase 6 quedó completa.
    - `ComandaService` arma cada comanda a partir del pedido y la guarda congelada en
      `comandas.contenido` (sin precios). Los tipos son:
      - inicial: al confirmar (RN-PED-06, RF-M07-020). Lleva las líneas, lo que trae cada combo,
        extras y notas.
      - actualización: un cambio con el pedido confirmado (RN-PED-09, RF-M07-022). Lleva solo lo
        que cambió: agregar, quitar o cambiar, con la cantidad anterior.
      - cancelación: se cancela un pedido que la cocina ya tenía.
    - Para no sacar hojas de más:
      - si la cocina todavía no recibió la inicial, un cambio la rehace;
      - si la última hoja de cambios no se ha impreso, el siguiente cambio se suma a ella;
      - si se cancela un pedido que nunca llegó a la cocina, su comanda queda "descartada".
    - Idempotencia (RF-M02-T-007, RNF-M02-004): cada comanda tiene una clave única (`pedido:N:inicial`,
      `pedido:N:modificacion:M`, `pedido:N:cancelacion`). Confirmar dos veces, un doble clic o dos
      pantallas a la vez nunca generan dos comandas.
    - Un fallo nunca deshace el pedido (RN-PED-17, RNF-M02-001/005): si generar la comanda falla, el
      pedido queda confirmado y el error se reporta. `GET /comandas/pendientes` crea la comanda que
      falte a todo pedido confirmado en las últimas 24 h (`asegurarIniciales`).
    - El estado de impresión es independiente del pedido (RN-PED-19, RNF-M02-014):
      - pendiente → impresa al abrir el ticket (`GET /comandas/{id}/ticket`, PDF de 80 mm);
      - "No salió" (`POST /comandas/{id}/no-impresa`, auditado `comanda_no_impresa`) la deja
        "fallida" y la devuelve a pendientes (HU-M02-016);
      - una impresión que salió no se repite sola (RN-PED-18).
    - Regla de reimpresión: la primera impresión que sale es el original. Desde la segunda, el ticket
      dice "REIMPRESIÓN · COPIA n" y cada impresión queda en la auditoría (`imprimir_comanda`, con
      `RegistroImpresiones`). Una que no salió vuelve a imprimirse como original, porque la cocina
      nunca la tuvo.
    - Pantallas:
      - `GET /comandas/pendientes` alimenta la franja "comandas por imprimir" (RNF-M02-011);
      - `GET /pedidos/{id}/comandas` da el historial de comandas de un pedido;
      - el historial del pedido (`historial()`) suma cada comanda enviada e impresa;
      - los pedidos traen sus comandas (`CON_DETALLES`), sin el contenido;
      - `confirmar` responde el pedido con sus comandas.
    - Frontend:
      - Confirmar en Mesero > Pedidos o Administrador > Pedidos abre la comanda para imprimir (la
        pestaña se abre en el clic para que el navegador no la bloquee).
      - Cada pedido tiene "Imprimir comanda" o "Reimprimir comanda" y "¿No salió? Avisar"
        (`BotonComanda.vue`).
      - Todas las pantallas del Mesero, y Administrador > Pedidos, muestran la franja
        `ComandasPendientes.vue`, que se actualiza cada 15 s y al confirmar, editar o cancelar.
      - "Impresión de comandas en este equipo" (`utils/comandas.ts`, guardado en el navegador):
        abrir la comanda al confirmar (prendido) y abrir solas las comandas nuevas (para el equipo
        de la cocina). Con Chrome en modo `--kiosk-printing` salen sin el cuadro de impresión.
    - Lo que queda a medias, por D5: RF-M02-T-006 (reintento automático). En V1, el equipo de la
      cocina con "abrir solas" y Chrome en impresión automática se acerca, pero el navegador no puede
      saber si el papel salió: lo confirma una persona. La impresión 100 % automática necesita un
      agente local junto a la impresora.
43. **Endurecimiento de seguridad (13/10/2026), antes de la prueba del día completo.** Sin
    migración; 5 pruebas en `EndurecimientoTest` y 1 más en `PasarelaWompiTest`. Decisión del usuario
    (13/10/2026): la sesión sigue en `localStorage` del navegador; para cuidarla, la CSP de abajo solo
    deja ejecutar el código propio.
    - Cabeceras de la API: el middleware `App\Http\Middleware\CabecerasSeguridad` (global, solo
      para `api/*`, también en los errores) pone:
      - `X-Content-Type-Options: nosniff`;
      - `X-Frame-Options: DENY`;
      - `Referrer-Policy: no-referrer` (los enlaces secretos no se filtran);
      - `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`;
      - `Permissions-Policy`;
      - `Cache-Control: no-store` (pedidos, pagos y documentos no quedan en cachés);
      - y quita `X-Powered-By`.
    - CORS (`config/cors.php`): antes regía el valor por defecto de Laravel (cualquier origen). Ahora
      solo `FRONTEND_URL` y lo que se agregue en `CORS_ORIGENES`, sin credenciales de navegador.
    - Wompi: un evento con firma válida pero con `timestamp` de más de 72 h
      (`PASARELA_EVENTOS_MAX_HORAS`), o del futuro, no se aplica: responde `vencido`. No se pierde
      nada, porque la conciliación (punto 41) pregunta el estado real a Wompi.
    - Revisión antes de producción (`RevisionProduccionService`):
      - Revisa:
        - `APP_ENV=production` y `APP_DEBUG=false`;
        - `APP_KEY`;
        - `APP_URL` y `FRONTEND_URL` con https;
        - que ninguna cuenta activa del personal o de las de demostración tenga una contraseña de
          prueba (o una guardada sin cifrar);
        - que el correo salga de verdad y la cola no sea `sync`;
        - `LOG_LEVEL`;
        - que la pasarela no siga en sandbox;
        - que CORS no esté abierto.
      - Nunca muestra llaves ni contraseñas.
      - En el servidor: `php artisan fragua:revisar-produccion` (sale con código 1 si hay errores).
      - En la aplicación: Administrador > Configuración > "Revisión antes de producción"
        (`GET /sistema/revision-produccion`, solo Administrador).
    - Nginx para el VPS (`backend/deploy/nginx-fragua-real.conf`):
      - http → https y HSTS;
      - CSP del sitio (`script-src 'self'`, fuentes de Google, imágenes https, `connect-src 'self'`,
        `frame-ancestors 'none'`);
      - nosniff, `X-Frame-Options`, `Referrer-Policy` y `Permissions-Policy`;
      - `server_tokens off`;
      - archivos ocultos (`.env`, `.git`) bloqueados;
      - solo `index.php` se ejecuta;
      - límite de subida de 8 MB;
      - sitio y API en el mismo dominio.
    - Revisado sin cambios:
      - todas las consultas SQL escritas a mano usan parámetros;
      - ninguna pantalla usa `v-html`;
      - toda contraseña se guarda con `Hash::make`;
      - mínimo de 8 caracteres;
      - límites de intentos en inicio de sesión, recuperación y rutas públicas.
    - Dependencias, revisadas por el usuario el 13/10/2026 (desde aquí no hay acceso a Packagist ni
      a npm):
      - `composer audit`: sin alertas.
      - `npm audit`: 8 alertas (6 altas, 2 moderadas), todas de denegación de servicio en
        herramientas de compilación (Tailwind 3 → chokidar, braces, micromatch, fast-glob,
        postcss-nested, postcss-selector-parser; source-map-js). Son `devDependencies`: corren en
        el equipo de desarrollo y no van en el sitio publicado (`frontend/dist`).
      - Se aplica `npm audit fix` sin `--force`. `--force` instalaría Tailwind 4, un cambio mayor
        que rompería los estilos.
      - Comprobación: `npm audit --omit=dev` debe dar 0.
44. **Prueba de un día completo de operación (13/10/2026), cierre del plan.** Sin migración;
    1 prueba de punta a punta en `DiaCompletoTest` (107 comprobaciones) y la guía manual
    `PRUEBA-DIA-COMPLETO.md`, en la raíz del repositorio.
    - Un solo recorrido por la API, en orden, con los tres roles y los cuatro canales:
      - apertura de caja;
      - reserva web con depósito en línea;
      - pedido local en efectivo;
      - pedido por QR con tarjeta;
      - domicilio de invitado contra entrega;
      - recogida de un cliente pagada en línea, con el aviso «listo para recoger»;
      - llegada de la reserva con el abono del depósito y el cobro del resto por la cuenta de la mesa;
      - compras y gastos;
      - un pedido confirmado que se cancela con la clave del Administrador;
      - cierre de caja, cierre del día y reportes.
    - Comprueba que todo cuadra:
      - efectivo esperado = base + cobros en efectivo − compras y gastos en efectivo, sin descuadre;
      - el cierre del día con su desglose por forma de pago y por tipo de venta;
      - ventas totales y resultado operativo iguales al cierre (RNF-M08-002); el resultado operativo
        solo en PostgreSQL;
      - uso de mesas;
      - un comprobante por pedido pagado, con números seguidos;
      - una comanda por pedido confirmado, ninguna perdida;
      - las mesas libres al final;
      - lo esencial en la auditoría.
    - No encontró errores: las piezas de las Fases 0 a 6 encajan. Pasa en SQLite y en PostgreSQL.
    - `PRUEBA-DIA-COMPLETO.md` es el mismo recorrido para hacerlo a mano en el navegador, con los
      precios del sembrado y las cifras que deben salir: ventas $125.000, resultado $90.000 y
      efectivo esperado $142.000.
45. **Revisión manual por rol (13/10/2026), última tarea del plan.** Sin migración. Tres listas de
    revisión a mano, una por rol, con el formato de `QA-ADMINISTRADOR.md`: `QA-CLIENTE.md` (Cliente
    con cuenta, invitado y QR de mesa) y `QA-MESERO.md` (el turno completo del Mesero), en la raíz
    del repositorio; `QA-ADMINISTRADOR.md` se repasó y ahora remite a las otras dos. Al escribirlas
    se revisó cada pantalla contra el código y los requisitos, y se corrigió lo que apareció:
    - CORREGIDO: **el Cliente no podía pedir combos** ni en el Menú web ni por el QR de mesa; solo
      el Mesero los vendía, aunque RF-M04-020 pide el catálogo con combos «para el Cliente» y
      RF-M04-031 los nombra en los canales Local, QR y Domicilio. El servidor ya los aceptaba
      (`GET /combos` y `combo_id` en `POST /pedidos`); faltaba el frontend:
      - pestaña «Combos» en el Menú y en el QR, con lo que incluye cada combo, la nota para cocina
        y la cantidad;
      - el carrito (`stores/carrito.ts`) admite líneas de combo;
      - el Mesero también ve lo que incluye y ahora puede ponerle nota;
      - `utils/combos.ts` arma el texto «1 × Hamburguesa Clásica · 1 × Gaseosa 400ml».
      Prueba: `CombosClienteTest` (4).
    - CORREGIDO: desde «Mis pedidos», el Cliente podía cancelar una reserva con el depósito
      **pagado en efectivo**, y la caja lo daba por devuelto sin que nadie se lo entregara.
      `ReservaPolicy::cancelar()` lo rechaza con el mismo mensaje del enlace de la reserva («llama o
      pasa por el restaurante»), y la pantalla ya no ofrece el botón. Con tarjeta o en línea sigue
      igual. Prueba nueva en `ReservaPersonalTest`.
    - CORREGIDO: el **enlace de seguimiento** devolvía el pedido completo: teléfono y correo del
      cliente, quién del personal hizo cada paso y las notas internas de pagos y revisiones. El
      enlace se puede reenviar, así que `PedidoController::paraEnlacePublico()` deja solo lo que
      usa la pantalla. Aplica a `GET /pedidos/seguimiento/{token}` y a su cancelación. El personal
      sigue viendo todo. Prueba: `EnlaceSeguimientoTest` (3).
    - CORREGIDO: la **lista de pedidos del Mesero** no se actualizaba sola, aunque decía «Cuando
      entre uno nuevo aparecerá aquí»; un pedido de la web o del QR solo salía al recargar. Ahora:
      - se pone al día cada 20 s en «Activos», sin «Cargando…», y al volver a la pestaña;
      - se detiene mientras hay una ventana abierta o una acción en curso;
      - avisa «Llegó 1 pedido nuevo.»;
      - tiene «Actualizar» a mano.
    - CORREGIDO: **mensajes**:
      - Varios mensajes del servidor terminan con la regla («… (RN-M06-03).»). Siguen así en la API
        y en las pruebas; `services/errors.ts` los quita antes de mostrarlos.
      - Los textos de Laravel que no se traducen («Too Many Attempts.», «This action is
        unauthorized.», «Server Error») se cambian por uno en español.
      - Sin conexión, el mensaje lo dice.
      - Se quitaron 10 códigos que se veían en pantallas del Administrador y en Reservas.
      - «Contanos» pasó a «Cuéntanos».
    - CORREGIDO: `.env.example` traía `APP_LOCALE=en`. Un servidor armado desde él mostraba los
      mensajes de validación en inglés, aunque `lang/es/validation.php` existe. Ahora trae `es`,
      `es` y `es_CO`.
    - CORREGIDO: después de crear la cuenta desde el Checkout, el Cliente quedaba en el Inicio.
      Ahora vuelve a terminar su pedido; `?redirect=` pasa entre «Inicia sesión» y «Crear cuenta».
      `utils/redireccion.ts` solo acepta rutas internas: un enlace armado con
      `?redirect=//otro-sitio` no saca a nadie del sitio.

46. **Fase 7, tarea 1 (13/10/2026): reglas del catálogo que estaban a medias (M04).** Sin migración.
    La Fase 7 nace de revisar otra vez, ID por ID, los requisitos de M00 a M08 contra el código
    (ver "Fase 7" en `README-ENTREGA.md`). Esta primera tarea cierra tres reglas del catálogo:
    - CORREGIDO (RF-M04-009, RN-M04-04): un producto con presentaciones (tamaños) se podía pedir
      **sin presentación, al precio base**, que puede ser menor que cualquier tamaño. Además seguía
      en el menú aunque todas sus presentaciones estuvieran apagadas o agotadas. Ahora:
      - `PedidoService` exige la presentación («Elige la presentación de "…"»), también en la
        cotización.
      - `Producto::puedeAgregarseAPedido()` pide al menos una presentación activa y no agotada.
        Con eso el producto y los combos que lo usan salen del menú público y del QR. El personal
        lo sigue viendo para volver a habilitarlo.
      - En pantalla: Nuevo pedido ya no lo ofrece; Catálogo avisa «No se ofrece: ninguna
        presentación está activa y disponible»; la pestaña Menú del Mesero dice que con todos los
        tamaños agotados el producto no se ofrece.
    - CORREGIDO (RF-M04-013): un combo se podía guardar con **un solo producto**. Ahora lleva al
      menos dos unidades (dos productos distintos, o dos del mismo), y cada producto va una sola vez
      con su cantidad.
    - NUEVO (RF-M04-014): Catálogo > Combos muestra la referencia que pide el documento: cuánto
      costarían los productos por separado (a precio base) y el ahorro del cliente, o un aviso si
      el combo no ahorra o cuesta más. Aparece en la lista y mientras se arma el combo. El precio
      sigue siendo el que pone el Administrador (RN-M04-06).
    - Pruebas: `CatalogoReglasFase7Test` (3). Se ajustó `DisponibilidadCatalogoTest`: con su
      única presentación agotada, el producto ya no se ofrece.

47. **Fase 7, tarea 2 (13/10/2026): el «usuario autorizado» de Compras y gastos (M03, M01).**
    RNF-TRANS-012, RF-M03-32, HU-M03-01 a 03. La decisión D7 lo dejaba en V1 solo para el
    Administrador; el 13/10/2026 se decidió que un Mesero con permiso registre, adjunte el soporte
    y vea solo lo suyo.
    - Migración `2026_10_21_000001_agregar_permiso_gastos_a_users`: `users.puede_registrar_gastos`
      (falso para todos al migrar). No es un rol nuevo; `User::puedeRegistrarComprasGastos()` es
      verdadero para el Administrador y para un Mesero con la marca.
    - Usuarios > Editar (solo para un Mesero): casilla «Puede registrar compras y gastos».
      `UsuarioController::update()` deja el cambio en Auditoría («Cambio de permiso de compras y
      gastos», antes → después). No cierra sus sesiones, porque no es un cambio de rol. Si la cuenta
      pasa a otro rol, el permiso se quita.
    - Rutas: registrar, consultar, categorías y el soporte (ver y adjuntar) pasaron al grupo del
      personal. `StoreCompraGastoRequest` y `CompraGastoController` revisan el permiso:
      - el Mesero solo ve lo que él registró, con sus totales;
      - el soporte de otro le responde 404;
      - solo adjunta soporte a sus registros activos;
      - editar, anular, `cierres-diarios` y su PDF siguen siendo solo del Administrador.
    - Un día con cierre formal no lo registra el Mesero: «pídele al Administrador que lo registre»
      (RN-M03-08). Un gasto en efectivo baja el efectivo esperado de la caja, igual que antes.
    - Pantalla nueva `views/mesero/GastosView.vue`, en la pestaña «Gastos» del Mesero. Solo
      aparece con el permiso, y la ruta también lo exige. Tiene:
      - registrar con la foto o el PDF del recibo;
      - la lista de lo que él registró;
      - «Adjuntar foto o PDF del recibo» después.
      
      La barra de pestañas del teléfono se ajusta a 6 pestañas. El permiso se ve en la pestaña al
      volver a cargar la página o al entrar de nuevo.
    - Pruebas: `ComprasGastosMeseroAutorizadoTest` (4).
    - El mapa del salón (RF-M05-022, opcional) quedó para el futuro por decisión del 13/10/2026.

48. **Fase 7, tarea 3 (13/10/2026): fotos de los productos (M04).** RF-M04-004, HU-M04-002,
    RNF-M04-002. Antes el Administrador solo podía pegar una dirección de imagen, y el Menú, el QR y
    Nuevo pedido no la mostraban (solo el Inicio).
    - Migración `2026_10_21_000002_agregar_foto_a_productos`: `productos.imagen_path` (oculta en
      las respuestas).
    - `FotoProductoService`:
      - guarda la foto en el disco privado (`storage/app/private/productos`);
      - si PHP tiene GD, la reduce a 900 px por el lado más largo y la pasa a WebP (o JPEG);
      - al cambiarla o quitarla, borra el archivo anterior;
      - deja el cambio en Auditoría («Cambio de foto de producto»).
    - `imagen_url` queda como `/api/productos/{id}/foto?v=…`. La versión cambia con cada foto.
    - Rutas:
      - `POST` y `DELETE /productos/{id}/foto`: solo Administrador. Acepta JPG, PNG o WebP, de 200
        × 200 px como mínimo y hasta 5 MB (`FRAGUA_REAL_FOTO_PRODUCTO_MAX_KB`).
      - `GET /productos/{id}/foto`: pública, porque el menú la ve sin sesión. Lleva caché larga
        (`public, max-age=31536000, immutable`). `CabecerasSeguridad` deja esa caché solo en esta
        ruta; todo lo demás de la API sigue con `no-store`.
    - Editar el producto ya no pisa la dirección de una foto subida.
    - Pantallas:
      - Catálogo > producto: «Elegir foto», vista previa, «Quitar foto», y una miniatura en la
        lista. La foto se sube al guardar el producto; si falla, el producto queda guardado y lo
        avisa.
      - El Menú, el QR de mesa (lista y ventana del producto) y Nuevo pedido muestran la foto. Sin
        foto sigue el ícono.
    - «Revisión antes de producción» avisa si falta GD (`apt install php-gd`).
    - En el VPS, `storage/app/private/productos` entra en las copias de seguridad.
    - Pruebas: `FotoProductoTest` (3).

49. **Fase 7, tarea 4 (13/10/2026): el Mesero ve el estado de la caja (M06).** RF-M06-023,
    HU-M06-009 («quiero saber si la caja está abierta y cuánto efectivo se espera»). Sin migración.
    Antes el Mesero solo se enteraba de que la caja estaba cerrada cuando un cobro le fallaba.
    - `GET /caja/estado`, para el Administrador y el Mesero, de solo lectura. Devuelve:
      - el estado de la caja de hoy en hora de Colombia (`sin_abrir`, `abierta` o `cerrada`);
      - quién la abrió y a qué hora, o quién la cerró;
      - con la caja abierta, el efectivo esperado.
      
      El detalle del turno (`/caja/turno-actual`, los pagos, el arqueo) sigue siendo solo del
      Administrador.
    - Mesero > Cobrar muestra arriba una franja según el estado:
      - verde: «Caja abierta desde las … · la abrió … · Efectivo esperado $…»;
      - amarilla, sin abrir: «no se puede cobrar hasta que el Administrador la abra»;
      - amarilla, cerrada: «ya se cerró».
      
      Sin caja abierta, los botones de cobro se apagan; el servidor igual lo valida (RN-M06-02).
      La franja se actualiza después de cada cobro.
    - Pruebas: `CajaEstadoMeseroTest` (2).

50. **Fase 7, tarea 5 (13/10/2026): reportes de un mes completo a buena velocidad (M08).**
    RNF-M08-001 («tiempo razonable incluso para periodos amplios, por ejemplo un mes completo»).
    Migración `2026_10_22_000001_indices_de_rendimiento`: solo agrega índices, no cambia datos.
    - Cómo se midió: una base PostgreSQL con un año de historia de prueba: unos 110.000 pedidos,
      330.000 líneas, 99.000 extras, 104.000 pagos y los cierres diarios. Cada reporte se pidió para
      30 días con «Comparar» (el periodo y el anterior).
    - Lo que se encontró: PostgreSQL no crea índices para las llaves foráneas. Para sumar los extras,
      cada línea vendida recorría la tabla entera de extras, y cada reporte leía todos los pedidos de
      la historia.

      | Reporte, 30 días | Antes | Después, con «Comparar» |
      |---|---|---|
      | Ventas por producto | más de 2 minutos (sin comparar) | 0,45 s |
      | Ventas por tipo y método | más de 1 minuto (sin comparar) | 0,2 s |
      | Uso de mesas | 0,66 s | 0,05 s |
      | Resultado operativo (con días sin cierre) | 0,05 s | 0,01 s |
      | Ventas totales y Compras y gastos | menos de 0,03 s | menos de 0,01 s |

      En las dos primeras filas, «Antes» es sin comparar; con «Comparar» era el doble.

      Un año completo de «Ventas por producto» tarda 1,5 s. El tablero de hoy, que usa «Ventas por
      tipo», también se beneficia.
    - Los índices nuevos cubren:
      - lo que leen los reportes: pedidos finalizados por fecha, pedidos por fecha de creación,
        líneas de cada pedido, extras de cada línea, pagos por fecha y reservas por fecha;
      - búsquedas de todos los días que crecen con el tiempo: pedidos de una mesa o de un cliente,
        pagos de un pedido o de un turno, reservas de una mesa y ajustes de un turno.
    - `ReporteService`:
      - `usoDeMesas()` lee filas simples en vez de un modelo por pedido y compara las horas en
        segundos. Con un mes de pedidos, armar los modelos era casi todo el tiempo del reporte.
      - `resultadoOperativo()` limita los días sin cierre a su rango de horas, así usa el índice. La
        fecha de cada pedido se sigue tomando en hora de Colombia.

      Las cifras de los 6 reportes se compararon antes y después con la misma base y son idénticas
      (RNF-M08-002).
    - Caché de periodos cerrados: no hizo falta. Con estos tiempos se prefirió recalcular siempre,
      para que un reporte nunca muestre cifras viejas después de una anulación o un ajuste del
      cierre.
    - Comando `php artisan reportes:medir [--dias=30]`: mide los 6 reportes del último mes con la
      comparación y marca «LENTO» lo que pase de 2 segundos. Solo lee. Sirve para revisar el VPS cuando
      ya tenga historia real.
    - Opcional en el VPS: `ALTER DATABASE nombre_de_tu_base SET jit = off;`. El compilador JIT de PostgreSQL le
      sumaba unos 40 ms a cada consulta grande de los reportes.
    - Pruebas: `ReportesRendimientoTest` (3). Revisan los índices, el resultado operativo con días
      sin cierre (un pedido de las 11:30 p. m. cuenta en su día de Colombia) y el comando. Las dos
      últimas corren solo en PostgreSQL.

51. **Puesta en el VPS (16/10/2026).** Sin migración. Revisión del proyecto antes de desplegarlo en el
    VPS de Hostinger (decisión D2).
    - `backend/deploy/nginx-fragua-real-inicial.conf` (nuevo): solo el puerto 80, para sacar el
      certificado la primera vez con `certbot certonly --webroot`. `nginx-fragua-real.conf` no carga
      sin el certificado; ahora trae las líneas `ssl_certificate` activas y deja abierta por http la
      carpeta de Let's Encrypt, para que la renovación automática funcione.
    - `backend/deploy/respaldo-fragua-real.sh` (nuevo): copia diaria de la base (`pg_dump`) y de
      `storage/app/private`, con 14 días de historia; lo corre el cron (`cron-fragua-real`).
    - `backend/deploy/logrotate-fragua-real` (nuevo): rota `storage/logs/cola.log`, el log del
      trabajador de la cola. Los logs de Laravel rotan con `LOG_STACK=daily`.
    - `frontend/public/favicon.svg` (nuevo): `index.html` lo pedía y no existía.
    - `README-ENTREGA.md`, «Ponerlo en el servidor»: reescrito en 10 pasos (clonar primero, paquetes y
      firewall, base de datos propia, permisos con `www-data`, `.env` y sembrado inicial una sola vez,
      Nginx en dos tiempos, cron/cola/logs, respaldos, Wompi y revisión final).
    - `.gitignore` en la raíz: `Claude outputs/` (tenía una copia de un `.env` con claves).

52. **Extras generales solo donde se asocian (16/10/2026, M04).** RF-M04-011, CA-M04-004. Migración
    `2026_10_23_000001_asociar_extras_generales_a_las_comidas`. En producción, a una Limonada se le
    podía pedir «Extra queso»: un extra general sin productos asociados se ofrecía con todo el menú.
    - `Extra::aplicaA()` y `CatalogoService::conExtrasDisponibles()`: un general solo se ofrece y se
      acepta con los productos a los que el Administrador lo asoció; sin asociar, con ninguno.
    - La migración asoció los generales que estaban sueltos a todos los productos de tipo Alimento,
      para no quitárselos de golpe a las comidas. El sembrado hace lo mismo.
    - Catálogo dice «no se ofrece con ningún producto: márcalo en los productos que lo llevan».
    - Pruebas: `CatalogoCoherenteTest` (sin asociar no se ofrece; una bebida con un extra asociado a
      mano; la migración), y las que usaban generales sueltos ahora los asocian.

53. **Descripción y foto de cada presentación (16/10/2026, M04).** RF-M04-008. Migración
    `2026_10_23_000002_descripcion_y_foto_de_variantes` (`variantes.descripcion`, `imagen_url`,
    `imagen_path`).
    - `FotoProductoService` sirve para productos y presentaciones (misma reducción a 900 px y WebP,
      carpeta `storage/app/private/variantes`, auditoría `cambiar_foto_variante`).
    - Rutas: `GET` (pública), `POST` y `DELETE /variantes/{variante}/foto`.
    - Menú y QR: al elegir la presentación se ve su foto (sin foto propia, la del producto) y cada
      opción muestra lo que trae. El Mesero también ve la descripción.

54. **«Poner en oferta» (16/10/2026, M04).** RF-M04-033 a 035. Migración
    `2026_10_23_000003_ofertas_por_presentacion` (`promociones.variante_id`, `promociones.es_oferta`).
    - `OfertaService` y `POST`/`DELETE /productos/{producto}/oferta`: precio de oferta (el sistema
      calcula la rebaja) o porcentaje, fecha límite y, si se quiere, una sola presentación. Es una
      promoción automática de producto marcada `es_oferta`; un producto tiene una oferta a la vez.
    - `PromocionService`: una promoción con `variante_id` solo rebaja esa presentación. Las fotos de
      reglas guardadas antes en un pedido no traen el campo y aplican a todas, como entonces.
    - `GET /productos` trae `variante_id` en cada promoción vigente y, para el personal, la `oferta`
      del producto. El botón «Agregar» del Menú y del QR muestra el precio con la oferta.
    - Pruebas: `VariantesYOfertasTest` (9).

55. **Resumen de Caja (16/10/2026, M06).** RN-M06-08, RN-M06-11. Sin migración.
    - `CajaService::resumen()`: ingresos del turno por método (los pagos en línea, por el día),
      desglose del efectivo esperado (base + cobros + depósitos − gastos − devoluciones), depósitos de
      reserva del día por método (también los pagados en línea) y anulados aparte.
    - `GET /caja/turno-actual` y `GET /caja/turnos/{turno}` traen `resumen`; el detalle trae además
      `depositos_en_linea`, que antes no salían en Caja.
    - Pantalla: «Resumen del día» con botón «Actualizar», y el mismo resumen en el detalle de cada
      turno del historial.

56. **Depósitos de reserva en el cierre y en los reportes (16/10/2026, M03, M05, M08).** RN-M05-18,
    RN-M06-11. Sin migración.
    - `CierreDiarioService::calcular()`: `anticipos_reserva` (depósitos recibidos en el día, por método;
      todavía no son venta) y `depositos_retenidos` (no-show marcado en el día y no devuelto: ingreso
      del día). El resultado operativo es ventas + retenidos − compras − gastos.
    - `ReporteService::resultadoOperativo()`: `otros_ingresos` con los retenidos del periodo, calculados
      con las reservas (también en días cerrados antes de este cambio). El PDF del cierre y la
      exportación del reporte los muestran.
    - Pruebas: `CajaResumenYDepositosTest` (4; la del reporte solo corre en PostgreSQL).

57. **Importación masiva de productos (16/10/2026, M04).** RF-M04-004, 008, 022, 023, 042,
    RN-M04-10, 14. Sin migración.
    - `ImportacionProductosService` e `ImportacionProductosController`:
      - `GET /productos/importacion/plantilla`: Excel con las columnas (Categoría, Nombre, Descripción,
        Precio base, Tipo de venta, Presentaciones, Disponible), listas desplegables y una hoja de
        instrucciones;
      - `POST /productos/importacion/vista-previa`: dice qué haría cada fila (crear, actualizar, sin
        cambios o error) sin guardar nada;
      - `POST /productos/importacion`: vuelve a revisar el archivo y, sin errores, crea y actualiza todo
        en una transacción; con algún error, no guarda nada (422).
    - Reglas: el nombre identifica el producto (sin importar mayúsculas ni tildes); con «Actualizar los
      que ya existen» cambian los datos que trae la fila y las celdas vacías no borran nada; las
      presentaciones (`Personal:18000 | Familiar:32000`) se crean o cambian de precio, y las que no
      vienen no se tocan; una categoría nueva se crea. Excel o CSV (también con `;` y precios como
      «18.000»), hasta 2 MB y 500 filas. Fotos, extras y combos siguen en Catálogo.
    - Auditoría: cada categoría, producto y presentación como si se hubiera hecho a mano (con
      `origen: importacion`), más un registro `importar_productos` con el resumen.
    - Pantalla: Catálogo > Productos > «Importar» (plantilla, archivo, vista previa e importar).
    - Pruebas: `ImportacionProductosTest` (7).

58. **Ajustes de la web del cliente (16/10/2026).** Solo frontend, sin cambios en la API.
    - Carrito: un producto igual (misma presentación, extras y nota) se suma a la línea que ya está;
      se guarda en el navegador por 24 horas y, al volver, se actualiza con el menú de hoy (quita lo
      que ya no está, con aviso). La web y el QR de cada mesa tienen carritos separados. Nuevo diseño
      con foto, presentación, extras, precio por unidad, rebaja por línea y resumen.
    - Botón flotante del pedido (`CarritoFlotante.vue`) en lugar de la barra fija de abajo, con un
      aviso breve al agregar; en el Menú solo en celular y tablet.
    - Menú y Reservar: el pie de página ya no sube mientras cargan; Reservar con pasos numerados,
      mesas como botones y panel del depósito. El contenido ocupa al menos el alto de la pantalla en
      todas las páginas del cliente.
    - Menú hamburguesa con «Ingresar» arriba; en tablet se usa el menú hamburguesa (desde 1024 px, el
      menú completo); pie de página rediseñado para celular.
    - Administrador: pestañas «Mesas | Reservas» (`PestanasSalon.vue`) para ver todas las reservas sin
      entrar por una mesa; textos del resumen de Caja más oscuros.

59. **Revisión antes de producción: nivel de log (16/10/2026).** Sin migración. Con `LOG_CHANNEL=stack`
    y `LOG_STACK=daily`, `RevisionProduccionService` leía el nivel del canal «stack», que no tiene, y
    avisaba «LOG_LEVEL=debug» aunque el `.env` dijera `warning`. Ahora sigue el stack hasta los canales
    que escriben y dice cuál revisó («LOG_LEVEL=warning (canal daily)»). Prueba nueva en
    `EndurecimientoTest`.

60. **App instalable en el teléfono, PWA (16/10/2026).** Solo frontend y Nginx, sin cambios en la API.
    Decisión: la «aplicación móvil» de la Idea de Negocio se entrega como PWA, no como APK.
    - `frontend/public/manifest.webmanifest`: nombre, colores, íconos (192, 512 y adaptable), accesos
      directos y `start_url` `/?origen=app`; el guard de rutas lleva al Mesero y al Administrador a su
      panel y quita la marca de la dirección.
    - `frontend/public/sw.js`: guarda los archivos con huella, los íconos y las fotos públicas del menú;
      nunca guarda el resto de la API; páginas primero de la red y, sin conexión, `offline.html`. Se
      registra solo en la versión compilada (`main.ts`).
    - `useInstalarApp.ts` y `BotonInstalarApp.vue`: «Instalar app» en el menú del cliente y en la barra
      lateral del personal; en iPhone muestra los pasos de Safari.
    - `index.html`: manifiesto, ícono del iPhone y modo pantalla completa.
    - `nginx-fragua-real.conf`: `sw.js` y el manifiesto sin caché del navegador.
    - Detalle e instrucciones de instalación: `README-ENTREGA.md`, «App instalable en el teléfono».
61. **Documentación oficial al día (16/10/2026).** Sin cambios de código. Los requisitos de los puntos
    51 a 60 quedaron formalizados en la carpeta «Documentacion Fragua Real» (versión 1.1, o 2.1 en M03
    y M07), con su trazabilidad completa:
    - M00: sección 6 nueva, app instalable (RNF-TRANS-030 a 034).
    - M02: RF-M02-L-029 a 032, RNF-M02-017, RN-PED-33, HU-M02-023 (carrito guardado).
    - M03: RF-M03-35 a 37, RN-M03-16/17, HU-M03-13; la fórmula del resultado operativo suma los
      depósitos retenidos.
    - M04: P14/P15, RF-M04-044 a 054, RNF-M04-009/010, RN-M04-27 a 31, HU-M04-016 a 018
      (presentaciones con descripción y foto, ofertas, importación).
    - M05: P14, RF-M05-037/038, RN-M05-20, HU-M05-016 (faltaba en el documento) y HU-M05-017; la
      HU-M05-015 pasa a completa.
    - M06: P09, RF-M06-025 a 028, RN-M06-13/14, HU-M06-012 (resumen del turno).
    - M07: RF-M07-032, RNF-M07-002, RN-M07-10, HU-M07-014. M08: RF-M08-016, RN-M08-08.
    - Diagrama entidad-relación (`variantes` y `promociones` con sus columnas nuevas y la llave
      `promociones.variante_id`), Cronograma (hoja «Mejoras en producción»), Catálogo de Pantallas
      (historias por pantalla) y Flujos por rol (34 páginas: app instalable, carrito, Reportes y
      Catálogo corregidos, ofertas, importación y caja del turno).
62. **Correcciones de reservas y mesas (19/10/2026, M05).** Migración `2026_10_26_000001`.
    - Horario de reservas: de 4:00 p. m. a 11:00 p. m. (hora de Colombia, la última a las 11:00 en
      punto) y con al menos 3 horas de anticipación, en la web y por teléfono.
      `PoliticaReservas::motivoHoraNoPermitida()` lo valida en `StoreReservaRequest` (422 en
      `fecha_hora`); los valores salen de `config/fragua_real.php` (`reservas.hora_inicio`,
      `hora_fin`, `anticipacion_minutos`, con variables `FRAGUA_REAL_RESERVA_*`) y se publican en
      `GET /configuracion`. Reservar y «Nueva reserva» ofrecen las horas cada 30 minutos y marcan
      «no disponible» las que no cumplen la anticipación (`utils/horarioReservas.ts`).
    - `reservas.descripcion` (hasta 500 caracteres, opcional): la escribe el cliente al reservar o el
      personal al registrarla; se ve en Reservas, en el enlace de la reserva y en el correo de
      confirmación.
    - Corregido: `MesaController::index()` solo filtraba las mesas inactivas para el Cliente, así que
      el Mesero seguía viendo (y podía abrir o pedir en) las mesas dadas de baja. Ahora solo el
      Administrador las ve. Mesero > Mesas y Nuevo pedido también las descartan.
    - `ReservaHorarioYMesasTest` (6 pruebas). `DiaCompletoTest`: la reserva pasa de la 1:00 p. m. a
      las 4:00 p. m.
    - Documentación oficial: M05 versión 1.2 (RF-M05-039 a 042, RN-M05-21 a 23, HU-M05-018), diagrama
      entidad-relación (`reservas.descripcion`, 60 migraciones), Cronograma, Catálogo de Pantallas,
      Estructura técnica y Flujos por rol (Cliente, Flujo 5).
63. **Datos del negocio editables (19/10/2026, M01).** Sin migración (tabla `configuraciones`).
    - `NegocioService`: dirección (`negocio_direccion`), teléfono (`negocio_telefono`) y logo
      (`negocio_logo_path`); sin valor guardado, los de `config/fragua_real.php` (`negocio`). El logo
      se reduce a 512 px y se guarda en PNG en `storage/app/private/negocio`; se sirve por
      `GET /negocio/logo` con `?v=…` y el service worker lo guarda como las fotos del menú
      (`VERSION` pasa a `fragua-real-v2`). Los PDF lo reciben como data URI (`datos()['logo']`); la
      copia guardada de cada comprobante no lo incluye.
    - `NegocioController`: `PUT /negocio`, `POST` y `DELETE /negocio/logo` (Administrador) y
      `GET /negocio/logo` (pública). `GET /configuracion` trae `negocio` (con `whatsapp_url`; un número
      de 10 dígitos se toma como de Colombia). Cada cambio queda en Auditoría (`editar_datos_negocio`,
      `cambiar_logo_negocio`, `quitar_logo_negocio`).
    - Frontend: `stores/negocio.ts` y `LogoNegocio.vue` (logo o «FR») en el encabezado, el menú del
      celular y el pie de página del cliente, Ingresar, el QR de mesa y la barra lateral del personal;
      Inicio, pie de página y Reservar toman dirección, teléfono y WhatsApp del servidor. Configuración
      tiene la tarjeta «Datos del negocio» (`components/configuracion/DatosNegocio.vue`).
    - `DatosNegocioTest` (5 pruebas).
64. **Rol SuperAdmin (20/10/2026, M09).** Migración `2026_10_26_000002`.
    - `users.role` acepta `superadmin`. `User::esSuperAdmin()`; `esAdministrador()` también es verdadero
      para el SuperAdmin, así entra a todo lo del Administrador sin tocar cada Policy o Controller.
      `EnsureUserHasRole` deja pasar al SuperAdmin donde la ruta pide `administrador`.
    - `GestionCuentas`: las cuentas de Administrador solo las gestiona el SuperAdmin; la del SuperAdmin no
      se gestiona desde Usuarios; cada quien corrige sus propios datos. Lo aplican `UsuarioController`
      (crear, editar, activar, desactivar, sesiones) y `UpdateUsuarioRequest`; `GET /usuarios` trae
      `gestionable`.
    - `SuperAdminService` y el comando `fragua:crear-superadmin {correo} {--nombre=} {--cambiar-clave}`
      (routes/console.php): crea la cuenta o vuelve SuperAdmin una existente, cierra sus sesiones y deja
      `crear_superadmin` o `asignar_superadmin` en Auditoría.
    - `NegocioService`: `negocio_nombre` y `negocio_nit` (solo el SuperAdmin, `PUT /negocio`).
    - `RevisionProduccionService`: aviso «Cuenta de SuperAdmin» si no hay uno activo.
    - Una reserva del SuperAdmin queda con `creado_por = administrador`; la clave del SuperAdmin también
      autoriza cancelar un pedido en preparación.
    - Sembrado local: cuenta `superadmin` / `password`.
    - Frontend: `Rol` incluye `superadmin`; el store de sesión tiene `esSuperAdmin`, `esAdministrador` y
      `rolDeZona` (las guardas de ruta usan este); Usuarios muestra «Solo la gestiona el SuperAdmin» o
      «Se cambia en el servidor» y solo el SuperAdmin ve la opción Administrador; Configuración muestra
      nombre y NIT solo al SuperAdmin.
    - `SuperAdminTest` (9 pruebas); `EditarUsuarioTest` ajustada (el Administrador ya no asciende a
      Administrador). Documentación oficial: M09 nuevo, M00 (RNF-TRANS-011, mapa de módulos), M01
      (RN-USR-19/20, matriz de permisos), Catálogo de Pantallas, Estructura técnica, Cronograma,
      diagrama entidad-relación y Flujos por rol (permisos por rol con la columna SuperAdmin y el
      Flujo 12 del Administrador; 35 páginas).
65. **Permisos de menú por cuenta (20/10/2026, M09).** Migración `2026_10_27_000001`.
    - `users.menus_permitidos` (json; null = todos los menús del rol). `MenusPorRol` tiene el catálogo
      de menús por rol, los fijos (Dashboard del Administrador, Pedidos del Mesero) y los efectivos de
      cada cuenta; `User` los expone como `menus` (en `/auth/me` y `/usuarios`).
    - Middleware `menu` (`EnsureMenuPermitido`) en 112 rutas del personal de `routes/api.php`, más
      `POST /reservas` del personal y cancelar un pedido; el SuperAdmin y el Cliente no se filtran.
      Las claves del Administrador y del Mesero no se cruzan, así una ruta compartida lista las de los
      dos (por ejemplo `menu:compras_gastos,gastos`). Reservas también se permite con Mesas, porque el
      Mesero confirma llegadas desde sus mesas.
    - `UsuarioController::menus()` y `actualizarMenus()` (`GET`/`PUT /usuarios/{usuario}/menus`, solo
      SuperAdmin y solo de Administrador o Mesero); con todos marcados se guarda null; al cambiar de rol
      se borra. Auditoría `cambiar_menus` con los quitados y los agregados.
    - Frontend: el menú lateral filtra por `menus`; las rutas llevan `meta.menu` y la guarda manda al
      inicio si no está habilitado; `auth.tieneMenu()` esconde los botones que llevan a otro menú
      (Cobrar en Mesas, Ver reservas, Actividad en Usuarios) y, en el Dashboard, los accesos rápidos sin
      permiso se ven bloqueados (gris, candado y «Sin permiso») y no abren nada; la cuenta se vuelve a pedir al navegar,
      como mucho cada minuto. Usuarios tiene el botón «Permisos» y su ventana; las acciones de cada
      cuenta bajan a otra línea en tablet.
    - `MenusPorCuentaTest` (6 pruebas). Documentación oficial: M09 v1.1 (RF-M09-011 a 015, RN-M09-08
      a 10, HU-M09-008/009), M00 (RNF-TRANS-013), M01 (RN-USR-21 y matriz de permisos), Catálogo de
      Pantallas, Estructura técnica, Cronograma, diagrama entidad-relación y Flujos por rol.

66. **Ubicación del local en el mapa (20/10/2026, M09).** Sin migración (tabla `configuraciones`).
    - `NegocioService`: claves `negocio_latitud` y `negocio_longitud` (7 decimales; las dos o ninguna).
      `publico()` agrega `latitud`, `longitud` y `mapa_url` («Cómo llegar»: Google Maps en el punto o,
      sin punto, buscando la dirección). `coordenadasDeEnlace()` lee el punto de un enlace de Google
      Maps (`!3d…!4d…`, `?q=`/`query=`/`ll=`, `@lat,lng` o «lat, lng»); los enlaces cortos
      (`maps.app.goo.gl`, `goo.gl`) se siguen con un tiempo límite de 8 s y solo mientras las
      redirecciones vayan a hosts de Google.
    - `PUT /negocio` acepta `latitud` y `longitud` solo del SuperAdmin (403 al Administrador).
      `POST /negocio/ubicacion-desde-enlace` (SuperAdmin, 20 por minuto) devuelve el punto sin guardar.
      Queda en Auditoría dentro de `editar_datos_negocio`.
    - Frontend: `MapaUbicacion.vue` con Leaflet y teselas de OpenStreetMap, cargado aparte (import
      dinámico; el Inicio sin punto no lo descarga). En Configuración el SuperAdmin toca el mapa, arrastra
      el marcador o pega un enlace; el Administrador lo ve sin poder moverlo. El Inicio muestra el mapa
      pequeño en Contacto; «Cómo llegar» del Inicio y del pie de página usa `mapa_url`. No se usa la
      ubicación del teléfono (Permissions-Policy la bloquea) ni ningún servicio externo desde el navegador.
    - `UbicacionNegocioTest` (3 pruebas). Además, `follow-redirects` (dependencia de axios) subió a
      1.16.1 por un aviso de seguridad de npm. Documentación oficial: M09 (RF-M09-016 a 018, RN-M09-11,
      HU-M09-010), Catálogo de Pantallas, Estructura técnica y Cronograma.

67. **Rol Soporte, solo consulta (20/10/2026, M10).** Migración `2026_10_28_000001`.
    - `users.role` acepta `soporte`. `User::esSoporte()`; `esAdministrador()` es verdadero también para
      Soporte (ROLES_PANEL_ADMINISTRADOR), así ve lo mismo que el Administrador; no está en
      `ROLES_ADMINISTRACION`, así que no recibe los avisos del negocio ni su contraseña autoriza al Mesero.
      `EnsureUserHasRole` lo deja entrar donde entra `administrador`; `MenusPorRol` le da todos los menús.
    - `SoporteSoloConsulta`, agregado al grupo `api` en `bootstrap/app.php` (corre en toda la API y pide
      la sesión a Sanctum, porque las rutas públicas de pedidos y reservas también la reconocen): 403
      «La cuenta de Soporte solo puede consultar.» a toda escritura, salvo `/auth/*`, `/perfil`,
      `/perfil/password`, `POST /reportes/exportar` y `POST /pedidos/cotizacion`; también bloquea
      `GET /comandas/{comanda}/ticket`, que marca la comanda como impresa.
    - `RegistroImpresiones::registrar()`: con Soporte no suma impresión, audita `consultar_documento` y
      devuelve la marca `consulta`; las plantillas del comprobante (ticket y PDF) y del cierre dicen
      «COPIA DE CONSULTA · SOPORTE».
    - Cuentas: `GestionCuentas::CUENTA_SOPORTE` y `motivoNoEdita()` (nadie la edita ni le cambia el rol;
      `UpdateUsuarioRequest` lo usa); solo el SuperAdmin la activa, la desactiva o le cierra sesiones;
      `UsuarioController::index()` se la esconde al Administrador; Soporte ve las sesiones de cualquier
      cuenta sin cerrarlas. `SoporteService::crearOActualizar()` y el comando
      `fragua:crear-soporte {correo} {--nombre=} {--cambiar-clave}` (no convierte cuentas del negocio ni
      cambia `activo`); auditoría `crear_soporte` y `actualizar_soporte`. La revisión antes de producción
      informa la cuenta de Soporte y la incluye en las contraseñas de prueba. Sembrado:
      `soporte@fraguareal.com` (usuario `soporte`).
    - Frontend: rol `soporte` en `types/models.ts`; `auth.esSoporte` (zona del Administrador y todos los
      menús); franja «Modo soporte: solo consulta» en `AdminLayout`; directiva global `v-escritura`
      (`directives/escritura.ts`) que esconde a Soporte los botones que cambian algo en Usuarios,
      Catálogo, Mesas, Reservas, Caja, Pedidos, Compras y gastos, Configuración y sus componentes;
      `SesionesLista` con `solo-ver`; en Usuarios, la cuenta de Soporte sale con «Se cambia en el
      servidor», Sesiones y Desactivar o Activar (solo para el SuperAdmin).
    - `SoporteTest` (7 pruebas). Documentación oficial: M10 nuevo (RF-M10-001 a 010, RNF-M10-001 a 005,
      RN-M10-01 a 07, HU-M10-001 a 006), M00 v1.3 (RNF-TRANS-011 y mapa de módulos), M01 (RN-USR-22 y
      matriz de permisos), M09 v1.2 (RN-M09-12), Catálogo de Pantallas, Estructura técnica, Cronograma,
      diagrama entidad-relación y Flujos por rol (columna Soporte en Permisos y Flujo 13; 36 páginas).

68. **Notificaciones del personal (21/10/2026, M11).** Migración `2026_10_29_000001`.
    - Tabla `notificaciones` y modelo `Notificacion`. `NotificacionService` tiene el catálogo de tipos
      (categoría, roles y menús que la reciben) y `notificar()`, que guarda una fila por destinatario
      después del commit de la acción (`DB::afterCommit`, con `insertOrIgnore` sobre `user_id` + `clave`
      para no repetir) y nunca rompe la acción: un error solo se reporta. No le llega a quien hizo la
      acción, a cuentas inactivas ni a quien no tiene el menú (`MenusPorRol::permite`); Soporte solo
      recibe las del sistema.
    - Eventos: `PedidoService` (pedido nuevo por la web o el QR, listo para recoger, cancelado por el
      cliente), `PagoService` (pago en línea aprobado o rechazado, pago anulado), `PasarelaPagoService`
      (pago por revisar), `ReservaService` (nueva, depósito pagado en línea o a mano, cancelada por el
      cliente, no-show automático), `ComandaService` (comanda que no salió) y `CatalogoController`
      (producto o presentación agotada).
    - `notificaciones:revisar` cada minuto: reserva que empieza en 30 min, caja abierta desde las
      23:30, cierre diario de ayer pendiente desde las 10:00, cola detenida o con trabajos fallidos y la
      revisión antes de producción a las 07:00. `notificaciones:limpiar` a las 3:40 a. m. borra las de
      más de 30 días. El programador detenido lo detecta `GET /notificaciones/contador`. Horas en
      `config/fragua_real.php` (`notificaciones`, `FRAGUA_REAL_NOTIF_*`).
    - `NotificacionController`: `GET /notificaciones`, `GET /notificaciones/contador`,
      `POST /notificaciones/{notificacion}/leida` y `POST /notificaciones/leidas` (personal; Soporte
      también puede marcarlas).
    - Frontend: `notificacion.service.ts`, store `notificaciones` (pregunta cada 20 s y al volver a la
      pestaña), `BotonNotificaciones` (barra del teléfono y menú lateral) y `PanelNotificaciones` (lista,
      ir a la pantalla, marcar todo).
    - `NotificacionesTest` (6 pruebas). Documentación oficial: M11 nuevo (RF-M11-001 a 012,
      RNF-M11-001 a 005, RN-M11-01 a 08, HU-M11-001 a 007), M00 v1.4, notas en M02 a M07, M09 y M10,
      Catálogo de Pantallas, Estructura técnica, Cronograma, diagrama entidad-relación y Flujos por rol
      (página «Notificaciones del personal» y fila en Permisos; 37 páginas).

69. **Contraseña segura y bloqueo por intentos (21/10/2026, M01).** Sin migración.
    - Regla común `Password::defaults()` en `AppServiceProvider` (`config/fragua_real.php`, «seguridad»):
      8 caracteres como mínimo, con al menos una mayúscula, una minúscula y un número; en producción, `uncompromised()` (con `NotPwnedVerifier` de 5 s). La usan
      `RegisterClienteRequest`, `StoreUsuarioRequest`, `CambiarPasswordRequest`,
      `RestablecerContrasenaRequest`, `SuperAdminService` y `SoporteService`. Las contraseñas que ya
      existían siguen sirviendo hasta que se cambien. Mensaje de contraseña filtrada en
      `lang/es/validation.php` corregido.
    - `BloqueoInicioSesionService`: cuenta los fallos por cuenta (5) y por IP (20) con `RateLimiter`; al
      llegar al límite guarda el bloqueo en la caché por 15 minutos y reinicia el conteo.
      `AuthController::login()` revisa el bloqueo antes de la contraseña (429 con `message` y
      `bloqueado_minutos`), limpia el conteo al entrar y `restablecerContrasena()` quita el bloqueo.
      Auditoría `bloqueo_inicio_sesion` e `inicio_sesion_bloqueado`; notificación `cuenta_bloqueada`
      (M11) al Administrador y al SuperAdmin con el menú Usuarios o Auditoría, si la cuenta existe.
    - `GET /configuracion` trae `clave` (`minimo`, `mezcla`). Frontend: `RequisitosContrasena.vue` (los
      requisitos se marcan mientras se escribe) en Registro, Restablecer contraseña, Mi perfil y la
      ventana de cuenta nueva de Usuarios; `mensajeDeError()` muestra el mensaje del bloqueo (429).
    - `SeguridadClavesTest` (6 pruebas); `SesionesSegurasTest` y las pruebas que creaban cuentas por la
      API con contraseñas débiles, ajustadas. Documentación oficial: M01 (RF-M01-029 a 032,
      RNF-M01-016, RN-USR-23/24, HU-M01-022/023), M00 v1.5, M09, M10, M11 v1.1, Catálogo de Pantallas,
      Estructura técnica, Cronograma y Flujos por rol (página 8, Acceso, rehecha).

70. **Inicio: ofertas y combos de hoy (21/10/2026, M04).** Solo frontend.
    - `components/inicio/OfertasDeHoy.vue`: los productos con `promociones_vigentes` (precio tachado y
      rebajado con `precioConPromocion`; con tamaños, «Desde» el más barato y lleva al Menú) y los
      combos de `GET /combos` (que ya filtra vigencia, franja, día y componentes). «Agregar» usa el
      carrito de siempre; en el teléfono las tarjetas se deslizan y sale `CarritoFlotante`. Hasta 8; sin
      ofertas ni combos, no se muestra.
    - `HomeView.vue`: carga los combos, elige los destacados entre los disponibles (primero con foto,
      platos antes que bebidas, sin repetir las ofertas) y corrige el precio: llegaba como texto
      (`"5000.00"`) y se mostraba sin formato; ahora `Number()` y «Desde» solo con tamaños.

71. **Pedido que vuelve a cobro tras anular un pago (22/10/2026, M06, RN-M06-15).** Sin migración.
    - Cierra la decisión que quedaba pendiente en `PagoService::anular()`: con tipo «anulación», si el
      pedido estaba finalizado y queda sin pagar completo, `reabrirSiQuedoConSaldo()` lo vuelve a
      `preparando` (borra `finalizado_*`; la entrega o la recogida siguen confirmadas), audita
      `reabrir_pedido` y avisa en la campana (`pedido_reabierto`, M11) al personal con Caja, Cobrar o
      Pedidos, salvo a quien anuló. Al cobrar el saldo, `intentarFinalizar()` lo finaliza de nuevo. Un
      reembolso no lo reabre. La respuesta trae `pedido_reabierto` con el saldo.
    - La mesa no se vuelve a ocupar (el cliente pudo haberse ido): el saldo se cobra desde Pedidos.
    - Caja: la ventana de anular explica la diferencia entre anulación y reembolso, y el detalle del
      turno muestra «El pedido #… volvió a cobro: le falta …».
    - `ReabrirPedidoAnulacionTest` (2 pruebas). Documentación oficial: M06 v1.2 (RF-M06-029, RN-M06-15,
      HU-M06-013), M02, M11, Estructura técnica y Cronograma.

72. **Pago directo sin Wompi (22/10/2026, M06, RF-M06-030 a 035, RN-M06-16 a 19).** Migración
    `2026_10_30_000001_pagos_directos`.
    - Mientras Wompi no esté activo, el Cliente transfiere a las cuentas del negocio y sube la captura.
      `PagoDirectoService` guarda las cuentas en `configuraciones` (`pago_nequi_numero`/`_titular`,
      `pago_bancolombia_tipo`/`_numero`/`_titular`/`_documento`, `pago_breb_llave`/`_titular`), dice
      qué medios están completos (`mediosDisponibles()`) y si el modo está activo (`activo()`: sin Wompi
      y con al menos un medio). `GET /configuracion` trae `pagos_directos` con solo las cuentas listas.
    - Las cambia solo el SuperAdmin (`PUT /negocio/pagos-directos`, 403 al Administrador), con
      auditoría `editar_pagos_directos` (antes y después). Configuración muestra la tarjeta «Cuentas
      para pagos en línea»; el Administrador la ve sin poder editar.
    - `pagos` gana `comprobante_path`, `comprobante_mime`, `referencia_cliente` y `rechazo_motivo`;
      `reservas`, `deposito_comprobante_path`, `deposito_comprobante_mime`, `deposito_referencia` y
      `deposito_rechazo_motivo`. El medio acepta `breb` (en PostgreSQL cambia `pagos_pasarela_check` y
      `reservas_deposito_pasarela_check`). Las rutas de los archivos no viajan en el JSON
      (`tiene_comprobante`, `deposito_tiene_comprobante`).
    - `RegistrarPagoEnLineaRequest`, `ConfirmarDepositoReservaRequest` y el depósito por enlace exigen
      la captura (JPG, PNG, WebP o PDF, hasta 5 MB) cuando el modo está activo, y rechazan un medio sin
      configurar (`errorDeMedio()`); con Wompi, Bre-B no se acepta. El monto lo fija el servidor (el
      saldo del pedido o el depósito), ya no el Cliente.
    - La captura se guarda en `storage/app/private/comprobantes-pago/AAAA-MM/` (entra en el respaldo
      diario) y la abre solo el personal: `GET /pagos/{pago}/comprobante` y
      `GET /reservas/{reserva}/deposito/comprobante`, con `Cache-Control: no-store`.
    - Al llegar una captura se avisa en la campana (M11): `pago_por_confirmar` (Pedidos, Caja o Cobrar)
      y `deposito_por_confirmar` (Mesas o Reservas). Rechazar acepta `motivo`, que el Cliente ve en el
      seguimiento, en «Mis pedidos» y en el enlace de su reserva; puede volver a enviar el pago.
    - Frontend: `PagoEnLinea.vue` (medio, datos con «Copiar», valor exacto, captura con vista previa y
      referencia; con Wompi, los botones de siempre) en Checkout, Seguimiento, Mis pedidos, Reservar y
      Mi reserva; `RevisarComprobanteModal.vue` (ver la captura, aprobar o rechazar con motivo) en
      Cobrar, Pedidos y Reservas; `PagosDirectos.vue` en Configuración; `usePagosEnLinea()` lee
      `GET /configuracion` una vez. Sin Wompi ni cuentas, el pago en línea no se ofrece.
    - `PagoDirectoTest` (7 pruebas) y el ayudante `Tests\Concerns\PagoDirectoDePrueba` en las pruebas que
      ya pagaban en línea. Documentación oficial: M06 v1.3, notas en M00, M05, M07, M09 y M11,
      Catálogo de Pantallas, Estructura técnica, Cronograma, diagrama entidad-relación y Flujos por rol.

73. **Formato de todos los campos (22/10/2026, M00, RNF-TRANS-040 a 044).** Sin migración.
    - Frontend: `utils/formatos.ts` define cada formato (`celular`, `persona`, `nombre`, `documento`,
      `cuenta`, `entero`, `pesos`, `porcentaje`, `cantidad`, `correo`, `usuario`, `referencia`,
      `direccion`, `llave`, `ip`, `codigo`, `texto`, `busqueda`): qué caracteres acepta, su largo y el
      teclado del teléfono. Se usa con `<BaseInput formato="…">`, con la directiva global
      `v-formato="'…'"` en un `<input>` o `<textarea>` y, para dinero, con `<CampoPesos>` (modelo numérico,
      se ve «20.000»). Lo que no corresponde se descarta al escribir o pegar y el cursor se queda donde
      estaba; el largo no va como `maxlength` para que el navegador no corte lo pegado antes de limpiarlo.
      Aplicado a los 127 campos de todas las pantallas (también los de porcentaje o pesos según el tipo
      de descuento).
    - Backend: `App\Support\Validacion\Formatos` con las mismas reglas y sus mensajes. Los teléfonos
      se normalizan antes de validar («+57 300 123-4567» → «3001234567») y deben tener 10 dígitos
      (celular 3xx o fijo 60x) en registro, perfil, Usuarios, pedidos, reservas y datos del negocio; los
      nombres de personas (cuentas, invitado, reserva, titulares) no llevan números; el NIT se guarda sin
      puntos (`900123456-7`); la cuenta Bancolombia tiene 11 dígitos; la llave Bre-B es un @alias, un
      celular, una cédula o NIT o un correo; los valores en pesos tienen tope de 99.999.999 y la
      descripción del producto, de 1.000 caracteres.
    - `FormatosCamposTest` (6 pruebas, con los casos que salían en Configuración); 4 pruebas ajustadas
      porque el teléfono y el NIT ahora se guardan limpios. Documentación oficial: M00 v1.6
      (RNF-TRANS-040 a 044), notas en M01, M05, M06 y M07, Estructura técnica, Cronograma y Flujos por rol.

74. **Eliminar mesas, productos y usuarios (24/10/2026, RF-M05-043, RF-M04-055, RF-M01-033).** Migración
    `2026_10_31_000001_eliminar_mesas_productos_usuarios`.
    - Decisión del negocio: lo que nunca se usó se borra de verdad; lo que tiene historial se archiva.
      `mesas`, `productos` y `users` ganan `eliminado_at`, `eliminado_por` y `eliminado_motivo`.
    - `EliminacionService`: `vistaPrevia()` dice si se borra o se archiva y por qué (una mesa con pedidos o
      reservas, un producto vendido o en un combo, una cuenta con acciones en la auditoría o pedidos), o
      qué lo impide ahora (mesa ocupada, con pedidos activos o reservas próximas; la propia cuenta; una
      cuenta que quien elimina no gestiona, M09). `eliminar()` pide la contraseña de quien lo hace y un
      motivo, borra (si la base se opone por un vínculo no contado, archiva) o archiva: desactiva la mesa,
      el producto (y sus combos, como al darlo de baja) o la cuenta (y cierra sus sesiones). `restaurar()`
      lo devuelve desactivado. Auditoría: `eliminar_*`, `archivar_*` y `restaurar_*`.
    - `EliminacionController`: `GET /{mesas|productos|usuarios}/{id}/eliminacion`, `POST …/eliminar`
      (`password`, `motivo`) y `POST …/restaurar`, solo Administrador y SuperAdmin.
    - Lo archivado no sale en `GET /mesas`, `GET /productos` ni `GET /usuarios`; con `eliminadas=1` /
      `eliminados=1` salen solo los archivados. No se edita ni se reactiva sin restaurarlo antes. Su nombre
      o correo sigue ocupado: `Support\Validacion\Eliminados` responde «restáurala desde Eliminados».
    - Frontend: `EliminarModal.vue` (qué va a pasar, motivo y contraseña) y el filtro «Eliminadas» /
      «Eliminados» con «Restaurar» en Mesas, Catálogo › Productos y Usuarios.
    - `EliminarRegistrosTest` (7 pruebas). Documentación oficial: M05 v1.3, M04 v1.2, M01, notas en M00 y
      M09, Catálogo de Pantallas, Estructura técnica, Cronograma, diagrama entidad-relación y Flujos por rol.

75. **M12 — Impresoras (24/10/2026, RF-M12-001 a 014).** Migración `2026_11_01_000001_crear_equipos_impresion`.
    - El servidor no ve la impresora: imprime el navegador de un equipo del local (D5). Tabla
      `equipos_impresion`: nombre, `clave` (identifica al navegador; solo se entrega una vez),
      `codigo_vinculacion` de 6 números (30 min, una vez), qué imprime, `al_confirmar`, `automatico`,
      última señal (y de quién) y última prueba (resultado y de quién).
    - `ImpresionService`: registrar el equipo desde el que se mira o crearlo con código; vincular;
      señal (se guarda como mucho cada 30 s); ajustes; ticket de prueba (`resources/views/impresion/prueba`);
      resultado de la prueba; formato en `configuraciones` (`impresion_ticket_logo`, `_ticket_letra`,
      `_ticket_mensaje_pie`, `_comanda_letra_grande`, `_comanda_mostrar_hora`, `_comanda_mostrar_mesero`,
      `_copias_comprobante`, `_copias_comanda`); vista previa con datos de muestra sin guardar; estado
      (comandas por imprimir e historial desde la auditoría) y `revisarDesconectados()`.
    - La clave viaja en la cabecera `X-Equipo-Impresion` (frontend `services/api.ts`):
      `RegistroImpresiones` guarda en la auditoría el `equipo` de cada impresión.
    - Tickets: `ComandaService::ticketPdf()` y `ComprobanteService::ticketPdf()` aplican el formato
      (letra, logo, mensaje al pie, hora, quién atiende y copias en hojas separadas). La comanda guarda
      `atiende` (quien confirmó). `ComandaService::descartar()` + `POST /comandas/{comanda}/descartar`.
    - Rutas: del equipo (personal) `POST /impresion/equipos/vincular`, `POST /impresion/equipos/senal`,
      `GET /impresion/prueba`, `POST /impresion/equipos/prueba`; del Administrador (menú `impresoras`)
      `GET/POST /impresion/equipos`, `PUT/DELETE /impresion/equipos/{equipo}`,
      `POST /impresion/equipos/{equipo}/codigo`, `GET/PUT /impresion/formato`, `POST /impresion/vista-previa`
      (permitida a Soporte) y `GET /impresion/estado`.
    - M11: aviso `equipo_desconectado` (operación, menú Impresoras) desde `notificaciones:revisar` cuando
      la caja está abierta y un equipo de comandas lleva más de 10 minutos sin señal.
    - Configuración: `fragua_real.impresion` (`FRAGUA_REAL_IMPRESION_MINUTOS_EN_LINEA`,
      `_MINUTOS_DESCONECTADO`, `_MINUTOS_CODIGO`). `MenusPorRol` suma `impresoras` al Administrador.
    - Frontend: `ImpresorasView` (pestañas Equipos, Formato del ticket, Estado y Guía) con
      `components/impresion/*`; store `equipoImpresion` (señal cada minuto desde AdminLayout y
      MeseroLayout; sus ajustes pasan a la franja de comandas); `ComandasPendientes` (vincular con código
      y prueba).
    - `ImpresorasTest` (7 pruebas); `MenusPorCuentaTest` ajustada al menú nuevo. Documentación oficial:
      M12 nuevo, M00 v1.7, notas en M02, M06, M09, M10 y M11, Catálogo de Pantallas, Estructura técnica,
      Idea de negocio, Cronograma, diagrama entidad-relación y Flujos por rol (página 37, 38 páginas).

76. **Mejoras de operación (26/10/2026).** Migración `2026_11_02_000001_mejoras_operacion_26_10`
    (`pedidos.cuenta`, `ubicacion_lat`, `ubicacion_lng`, `paga_con`; `categorias.permite_quitar_ingredientes`).
    - Cerrar caja: `CajaService::pedidosSinFinalizar()` (pendientes, confirmados o en preparación, de
      cualquier día) bloquea `cerrar()` con la lista; `GET /caja/turno-actual` la trae como
      `pedidos_sin_finalizar` y la ventana «Cerrar turno» la muestra y no deja confirmar.
    - Cuentas separadas: `PedidoService::cuentaDelPedido()` (mesa libre → 1; ocupada → una cuenta de la
      visita o la siguiente), `Mesa::cuentasDeLaVisita()`, `MesaCuentaService::porCuenta()` (en el resumen
      y en `GET /mesas`) y `cobrar(..., $cuenta)`. La comanda dice «Mesa 3 · Cuenta 2» cuando hay varias.
      Frontend: «Nuevo pedido» pregunta «Continuar la cuenta N» o «Nueva cuenta separada»;
      `CuentaMesaModal` muestra las cuentas por separado y cobra una o toda la mesa.
    - Foto del comprobante: `RegistrarCobroRequest` acepta `comprobante` (obligatorio en transferencia) en
      `POST /pedidos/{pedido}/cobrar` y `POST /mesas/{mesa}/cobrar`; se guarda con
      `PagoDirectoService::guardarComprobante()` en `pagos.comprobante_path` y se ve con
      `GET /pagos/{pago}/comprobante` (Caja > detalle del turno > «Ver foto»). Frontend: `FotoComprobante`
      (cámara trasera) y `utils/imagen.ts` (reduce la foto a 1600 px antes de subirla).
    - Domicilio: `ubicacion_lat/lng` (botón «Usar mi ubicación actual») y `paga_con` (Pago exacto, billetes
      o «Otro valor»; 422 si no alcanza para el total). `DatosEntregaPedido` muestra «Ver la ubicación en
      el mapa» y el cambio que debe llevar el domiciliario.
    - CORREGIDO (26/10/2026): la ubicación salía en otro lugar (en un computador o sin GPS el navegador la
      calcula por la red, y se aceptaba una lectura de hasta un minuto). `utils/ubicacion.ts` escucha hasta
      12 s sin datos guardados y se queda con la lectura más precisa (termina antes con 30 m o menos); el
      Checkout muestra el punto en `MapaUbicacion` (nuevo `centroInicial`: abre en el local) para
      confirmarlo o moverlo, avisa si el error pasa de 100 m y permite «Marcar en el mapa» sin GPS. Además
      `deploy/nginx-fragua-real.conf` decía `Permissions-Policy … geolocation=()`, que bloqueaba la
      ubicación en el VPS: ahora `geolocation=(self)`.
    - Catálogo: la categoría guarda `permite_quitar_ingredientes`; Bebidas quedó apagada. El menú no
      pregunta qué ingrediente quitar en esas categorías.
    - Frontend: medios de pago en línea como tarjetas; `AvisoDestacado` para las notas que el Cliente debe
      leer; el seguimiento y «Mis pedidos» se ponen al día cada 10 s y al volver a la pestaña; el detalle
      de la auditoría empieza por «Hecho por»; en la barra lateral del teléfono la lista se desplaza
      (`min-h-0`), el pie respeta la barra de gestos y «Instalar app» aparece en todo teléfono (sin el
      aviso del navegador muestra los pasos), también arriba para el Mesero.
    - `MejorasOperacionTest` (11 pruebas); `CajaCuadreTest`, `AuditoriaBaseTest` y
      `AbonoDepositoReservaTest` ajustadas a la regla de cerrar caja.

