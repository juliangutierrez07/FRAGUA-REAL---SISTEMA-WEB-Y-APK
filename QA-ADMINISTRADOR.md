# QA del Administrador — lista de pruebas por pantalla

Revisión manual, pantalla por pantalla, de todo lo que ve y controla el Administrador. Marca cada
casilla al probarla y anota la fecha. Cada punto cita el requisito oficial en el que se apoya.

Antes de empezar: backend y frontend corriendo (o el sistema ya en el servidor), base de datos con
`php artisan migrate --seed` y sesión iniciada como `admin@fraguareal.com`. Para los puntos que cruzan
roles, ten abierta otra ventana con el Mesero.

Listas hermanas: `QA-CLIENTE.md` (Cliente con cuenta, invitado y QR de mesa) y `QA-MESERO.md` (todo el
turno del Mesero). Lo que cruza roles se prueba en detalle en la lista de quien lo hace; aquí queda lo
que ve y controla el Administrador. La prueba de un día completo, con las cifras que deben salir, está
en `PRUEBA-DIA-COMPLETO.md`.

## 0. Configuración (RF-M07-030, RF-M05-028)

La pantalla Administrador > Configuración se construyó el 06/10/2026 (antes el backend ya
permitía editar los valores, pero no había dónde hacerlo).

- [ ] Se abre desde el menú ("Configuración") y muestra $2.000 de domicilio y $20.000 de depósito en una base recién sembrada.
- [ ] Cambiar el domicilio y guardar: un domicilio nuevo en Checkout cobra el valor nuevo; uno ya creado conserva el anterior.
- [ ] Cambiar el depósito y guardar: Reservar mesa pide el valor nuevo; una reserva ya creada conserva el anterior.
- [ ] Valor vacío o con letras: error bajo el campo, sin llamar al servidor.
- [ ] "Guardar cambios" queda desactivado mientras no haya cambios.
- [ ] Un Mesero no puede abrir `/admin/configuracion` ni llamar `PUT /configuraciones` (403).
- [ ] "Tiempos de las reservas" (Fase 4, tarea 2 — RF-M05-017/026): muestra 15, 120 y 60 en una
      base nueva, con su texto ("se avisa al cliente 2 horas antes"). Valores fuera de rango (0,
      3000, letras) muestran el error bajo el campo sin guardar. Al guardar, Reservar mesa del
      Cliente dice la nueva tolerancia y Auditoría registra el cambio.
- [ ] "Tareas automáticas" (Fase 4, tarea 1): sin cron ni trabajador, dice "detenido" en ambos.
      Con `php artisan schedule:work` y `php artisan queue:work` corriendo (o el cron y
      Supervisor en el VPS), al minuto ambos dicen "activo". Muestra pendientes, fallidos y si el
      correo se envía de verdad. El botón de recargar vuelve a consultar.

- [ ] "Revisión antes de producción" (endurecimiento, 13/10/2026): en el equipo de desarrollo
      muestra errores en rojo (entorno, depuración, HTTPS, correo) y la cuenta `admin@fraguareal.com`
      con contraseña de prueba. Cambiar esa contraseña en Mi perfil y volver a revisar: ese punto
      pasa a verde. Nunca aparecen llaves ni contraseñas. Con el Mesero, `/api/sistema/revision-produccion`
      responde 403.
- [ ] "Fotos de productos livianas" sale en verde si PHP tiene la extensión GD; sin ella, en
      amarillo con la instrucción para instalarla (Fase 7).
- [ ] En el servidor, `php artisan fragua:revisar-produccion` muestra la misma lista y termina en
      error mientras haya puntos en rojo.
- [ ] Seguridad de la API: en las herramientas del navegador (pestaña Red), cualquier respuesta de
      `/api/...` trae `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` y
      `Cache-Control: no-store`.

## 1. Dashboard
- [ ] Muestra ventas del día, pedidos activos por canal y mesas ocupadas.
- [ ] Con un pedido finalizado hoy, las ventas coinciden con Reportes > Ventas totales del mismo día (RNF-M08-002).
- [ ] Con la base vacía no falla (ceros, no errores).

## 2. Usuarios (M01)
- [ ] Listar con búsqueda por nombre/correo y filtro por rol.
- [ ] Crear Mesero y Administrador (RF-M01-001); correo repetido → mensaje claro.
- [ ] Desactivar una cuenta: esa persona ya no puede iniciar sesión.
- [ ] Activar de nuevo: vuelve a entrar.
- [ ] Revocar sesiones: el token abierto en otra ventana deja de funcionar (RF-M01-010/023).
- [ ] Un Mesero no puede abrir `/admin` ni llamar `/usuarios` (403).
- [ ] "Editar" (Fase 3, tarea 2 — RF-M01-003/005): cambiar nombre, correo y teléfono de un
      Mesero; el correo de otra cuenta muestra "Ese correo ya lo usa otra cuenta". Su sesión
      sigue abierta.
- [ ] Cambiar el rol de un Mesero a Administrador: el modal avisa del cierre de sesiones; al
      guardar, la ventana donde él estaba conectado lo saca al login, y al entrar ve el panel del
      Administrador. En Auditoría aparecen "Edición de datos de usuario" y "Cambio de rol de
      usuario" con el antes → después.
- [ ] Un Cliente puede pasar a Mesero. En tu propia cuenta el rol aparece bloqueado. La
      contraseña no aparece en el formulario.
- [ ] Nombre de usuario (Fase 3, tarea 3 — RF-M01-007/011): ponerle "mario.m" a un Mesero al
      crearlo o editarlo; la lista lo muestra como "@mario.m" y la búsqueda lo encuentra. Uno
      repetido (también con mayúsculas, "Mario.M") o con tilde, espacio o "@" se rechaza con un
      mensaje claro. Dejarlo vacío lo quita.
- [ ] En el login, "mario.m" (o "MARIO.M") y la contraseña entran; su correo también. Usuario o
      contraseña equivocados muestran el mismo mensaje. En Auditoría el inicio de sesión dice por
      dónde entró.
- [ ] Mi perfil: elegir o cambiar el propio nombre de usuario y entrar con él.
- [ ] Sesiones (Fase 3, tarea 4 — RF-M01-010/024): entrar como Mesero en el celular y en el PC.
      En Usuarios, su botón dice "Sesiones (2)"; la ventana muestra cada dispositivo con IP,
      último uso y vencimiento. "Cerrar" en el celular: en el celular la app vuelve al login y el
      PC sigue. "Cerrar todas sus sesiones" lo saca de todos. Queda en Auditoría.
- [ ] Mi perfil > Sesiones abiertas: la actual dice "Esta sesión"; "Cerrar las demás" deja solo
      la actual; cambiar la contraseña también cierra las demás y la lista se actualiza.
- [ ] "Actividad" en un usuario abre Auditoría filtrada por él; el aviso azul permite ver también
      lo que pasó sobre su cuenta (intentos fallidos con su correo, desactivación…).

## 2b. Auditoría (M01 — Fase 3, tarea 1)
RF-M01-017/024/025, HU-M01-015/020, RNF-TRANS-022/023, RN-USR-12/13.
- [ ] Se abre desde el menú ("Auditoría") y muestra los últimos 7 días, del más reciente al más
      antiguo.
- [ ] En otra ventana, intentar entrar con una contraseña mala: aparece "Intento de inicio de
      sesión fallido" en rojo, sin usuario, con el correo usado, el motivo y la IP; la tarjeta
      "Inicios fallidos (24 h)" sube. La contraseña escrita no aparece en ningún lado.
- [ ] 3 intentos fallidos desde el mismo equipo: aparece el aviso de la IP y "Ver intentos" la
      filtra.
- [ ] Entrar bien, cerrar sesión, cambiar la contraseña en Mi perfil y recuperar la contraseña
      por correo: cada paso aparece con su texto (inicio, cierre, cambio, solicitud y
      restablecimiento).
- [ ] Crear un Mesero y cambiar el valor del domicilio en Configuración: aparecen "Alta de usuario
      del personal" y "Cambio de configuración" con el antes → después.
- [ ] Filtros: fechas, usuario, grupo (la lista de acciones se reduce al grupo), acción, "Sobre
      qué" + número (p. ej. Pedido 12), IP y texto (prueba un motivo con tilde). "Limpiar" vuelve
      a los últimos 7 días. "Solo seguridad y sesiones" deja solo ese grupo.
- [ ] Abrir un registro muestra sus detalles legibles (motivo, antes → después, navegador
      completo) y el número del registro de auditoría.
- [ ] No hay botones para editar ni borrar registros. Un Mesero no puede abrir
      `/admin/auditoria` ni llamar `GET /auditorias` (403).
- [ ] En el teléfono (~390 px) la pantalla no tiene scroll horizontal.

## 3. Catálogo (M04)
- [ ] Crear, editar y desactivar categoría y producto.
- [ ] Variantes: crear con precio propio; el Cliente la ve en el menú.
- [ ] Extras: uno general y uno específico de producto (RN-M04-05).
- [ ] Un extra general sin productos elegidos aparece en el Menú, el QR y "Nuevo pedido" del
      Mesero con cualquier producto; al marcarlo en "Extras generales que ofrece" de un producto,
      solo aparece con ese producto (RF-M04-010/011).
- [ ] Dar de baja un producto que está en un combo: aparece el aviso del combo desactivado y el
      combo ya no se ofrece; activarlo de nuevo con ese producto se rechaza; reemplazándolo, sí (RN-M04-12).
- [ ] Desactivar una categoría: sus productos y los combos que los usan desaparecen del Menú y del
      QR; al reactivarla vuelven (RF-M04-003).
- [ ] Precio en cero o un nombre de producto o categoría repetido: mensaje claro bajo el campo.
- [ ] Cambiar el precio de un producto queda en la auditoría con el valor anterior y el nuevo (RF-M04-022).
- [ ] Combos: crear con vigencia; se oculta si un componente está agotado (RN-M04-11).
- [ ] Fase 7, tarea 3 (RF-M04-004, HU-M04-002): editar la Hamburguesa Clásica > "Elegir foto"
      (una foto grande del celular): se ve la vista previa y, al guardar, la miniatura en la lista.
      La foto sale en el Menú, el QR, el Inicio y Nuevo pedido del Mesero, y carga rápido (en la
      pestaña Red pesa mucho menos que la original). "Cambiar foto" la reemplaza. "Quitar foto" la
      borra y vuelve el ícono. Un PDF o una foto de menos de 200 × 200 px se rechaza con un mensaje
      claro. Auditoría muestra "Cambio de foto de producto".
- [ ] Un producto nuevo con foto: se crea y la foto queda en el mismo guardado.
- [ ] Fase 7, tarea 1 (RF-M04-013/014): un combo con un solo producto (1 unidad) no se guarda
      ("Un combo lleva al menos dos productos"); con 2 del mismo producto, sí. Al armarlo y en la
      lista se ve "Por separado $… · ahorro $… (n %)"; si el precio del combo es igual o mayor que
      la suma, el aviso sale en amarillo.
- [ ] Fase 7, tarea 1 (RF-M04-009, RN-M04-04): apagar (desmarcar "Activa") todas las
      presentaciones de la Hamburguesa Clásica: el producto dice "No se ofrece: ninguna presentación
      está activa y disponible", desaparece del Menú, del QR y de Nuevo pedido, y el Combo Clásico
      también. Al activar una presentación, vuelven. Un pedido armado a mano sin presentación
      (por la API) se rechaza con "Elige la presentación de …".
- [ ] Un combo activo aparece en la pestaña "Combos" del Menú del Cliente, del QR de mesa y de
      "Nuevo pedido" del Mesero, con lo que incluye ("1 × Hamburguesa Clásica · 1 × Gaseosa
      400ml"); fuera de su vigencia no aparece en ninguno (RF-M04-020/031 — antes solo el Mesero
      podía venderlo; ver QA-CLIENTE, secciones 2 y 7).
- [ ] Promociones: crear, editar y desactivar; cada cambio queda en la auditoría (RF-M04-039/040).
- [ ] Promoción automática de un producto con 20 %: el menú la marca "−20 %" con el precio tachado,
      y un pedido nuevo de ese producto sale con el descuento (RF-M04-035).
- [ ] Una promoción de almuerzo (12:00 a 15:00) no se aplica a las 9:00 a. m. y sí a la 1:00 p. m.
      (hora de Colombia); una de "jueves" solo los jueves.
- [ ] Dos promociones vigentes a la vez (una de producto y una al total) se suman en el mismo
      pedido; el domicilio no se rebaja (RF-M04-038, RN-M04-20).
- [ ] Una promoción de tipo "Manual" no se aplica sola: aparece en el botón "Descuento" del pedido.
- [ ] Alternar agotado/disponible y verlo reflejado de inmediato en el menú del Cliente (RF-M04-016/017).
- [ ] Tipo de venta Bebida/Alimento/Otro queda guardado y aparece en Reportes.
- [ ] Catálogo > "Agotados y disponibilidad": buscar por nombre, filtrar por categoría y "Solo
      agotados"; agotar un tamaño (variante): el Cliente ya no lo ve y un pedido con él se rechaza
      (RF-M04-016/018/025). Volver a disponible lo devuelve al menú (RF-M04-017).
- [ ] Con el Mesero: la pestaña Menú hace lo mismo; cada cambio aparece en la auditoría.

## 4. Mesas (M05)
- [ ] Crear mesa: queda Libre con su QR; nombre repetido → error (CA-M05-001).
- [ ] "Cambiar nombre": el QR sigue siendo el mismo; un nombre de otra mesa se rechaza (RF-M05-002).
- [ ] Desactivar (con historial no se borra) y reactivar; todo queda en la auditoría (RF-M05-021).
- [ ] Regenerar QR: el anterior deja de funcionar (CA-M05-004).
- [ ] Ver QR de una mesa: se ve la imagen del QR; "Descargar PDF" baja el PDF con el nombre de la
      mesa y "Imprimir" abre la impresión, sin depender de internet (librerías locales).
- [ ] Liberar mesa con pedidos activos: bloqueado.
- [ ] Liberación forzada: exige motivo y deja registro en auditoría (RF-M05-011). Probar también con el Mesero, que tiene el botón "Forzar liberación…" en Mesero > Mesas (decidido el 06/10).

## 5. Reservas (M05)
- [ ] Listado con filtro por estado.
- [ ] Reserva web nueva queda en pendiente de pago hasta confirmar el depósito (RN-M05-15).
- [ ] Confirmar y rechazar un depósito en línea; el rechazo permite reintentar.
- [ ] Registrar depósito manual en una reserva por teléfono (RN-M05-16).
- [ ] Cancelar antes de la hora: el depósito queda reembolsado (RN-M05-17).
- [ ] Marcar no-show: el botón solo aparece cuando ya pasó la hora + la tolerancia (15 min); el
      depósito se retiene y aparece en "depósitos retenidos" (RN-M05-07/18, HU-M05-015).
- [ ] Confirmar llegada (cliente llegó): la mesa pasa a Ocupada; al tomar el primer pedido, la
      cuenta de la mesa ya descuenta el depósito ("Incluye $20.000 del depósito de la reserva")
      y Reservas dice "abonado $20.000 a la cuenta" (RF-M05-034, RN-M05-19).
- [ ] Sin pulsar "Confirmar llegada": el primer pedido en la mesa reservada completa la reserva y
      abona el depósito.
- [ ] Un pedido de $10.000 con un depósito de $20.000: se abonan $10.000 y Reservas dice que
      quedan $10.000; el pedido siguiente de esa mesa recibe el resto.
- [ ] Bajar la cantidad de un pedido cubierto por el depósito: el abono baja solo y el historial
      del pedido muestra el ajuste; cancelar ese pedido pasa su abono a otro pedido de la mesa.
- [ ] Registrar un depósito presencial con la caja cerrada: rechazado con un mensaje claro.
- [ ] "Nueva reserva": al elegir fecha y hora, el selector solo muestra mesas libres a esa hora;
      la reserva queda "Registrada por" tu nombre y se abre el depósito manual (RF-M05-013).
- [ ] Otra reserva de la misma mesa una hora después: rechazada; dos horas y media después: aceptada.
- [ ] El depósito manual solo ofrece efectivo, tarjeta y transferencia (RN-M05-16).
- [ ] Con el Mesero: la pestaña Reservas hace lo mismo, sin el botón "Depósitos retenidos" (RF-M05-035).

### 5b. Reservas automáticas (Fase 4, tarea 3 — RF-M05-014/016/024, RN-M05-07)
Requiere el programador corriendo (`php artisan schedule:work` en desarrollo, o el cron en el
VPS) y, para el correo, la cola (`php artisan queue:work`). Configuración > Tareas automáticas
debe decir "activo".
- [ ] Confirmar el depósito de una reserva para mañana: la mesa sigue Libre (antes quedaba
      Reservada de inmediato). Cada reserva confirmada dice "Si no llega, se libera sola a las …".
- [ ] Una reserva para dentro de 50 minutos: en el siguiente minuto la mesa pasa a Reservada.
- [ ] Una mesa Ocupada con una reserva cercana sigue Ocupada; al liberarla, en el siguiente
      minuto pasa a Reservada.
- [ ] Sin llegar el cliente: a la hora + 15 min la reserva pasa sola a "No asistió", el depósito
      queda retenido, la mesa vuelve a Libre y Auditoría muestra el no-show automático sin usuario.
- [ ] Recordatorio: una reserva confirmada con más de 2 h de anticipación recibe el correo 2 h
      antes (una sola vez) y Reservas dice "Recordatorio enviado a las …". Una reserva hecha 1 h
      antes no recibe recordatorio.
- [ ] Si el cliente pide en una mesa que aún está Libre pero tiene su reserva dentro de la hora
      previa, ese primer pedido cuenta como su llegada y se abona el depósito.
- [ ] Cambiar la tolerancia a 30 en Configuración: la siguiente reserva se libera a los 30 min.

### 5c. Avisos de reserva y cancelación por enlace (Fase 4, tarea 4 — RF-M05-018/023/025/027)
Requiere la cola corriendo y un `MAIL_MAILER` que envíe (con `log` los correos quedan en
`storage/logs/laravel.log`). Usar un correo de cliente al que tengas acceso.
- [ ] Confirmar el depósito de una reserva web: llega "Reserva confirmada" con fecha, hora, mesa,
      depósito y el botón "Ver o cancelar mi reserva". En Reservas la reserva dice
      "Confirmación · enviado h:mm".
- [ ] Registrar un depósito en el local (reserva por teléfono con correo): también llega la
      confirmación.
- [ ] Cancelar una reserva desde Reservas: llega "Reserva cancelada" y dice cómo se devuelve el
      depósito. Auditoría muestra "Cancelación de reserva" con tu nombre.
- [ ] Un no-show (manual o automático): llega el aviso de "No asistió" con el depósito retenido.
- [ ] Con el correo mal configurado (p. ej. contraseña SMTP errada): la reserva igual se confirma;
      tras 3 intentos el aviso dice "falló" con el motivo. Corregir el SMTP y pulsar "Reintentar":
      pasa a "enviado". Auditoría muestra la falla y el envío.
- [ ] Abrir el enlace del correo en una ventana privada (sin sesión): muestra estado, fecha, mesa,
      depósito y tolerancia, sin datos de contacto. "Cancelar reserva" pide confirmar, la deja
      Cancelada con el depósito reembolsado y Auditoría la muestra "por enlace", sin usuario.
- [ ] El mismo enlace después de la hora de la reserva: ya no ofrece cancelar.
- [ ] Una reserva con depósito en efectivo: el enlace no la cancela y pide llamar o pasar por el
      local.
- [ ] Un enlace inventado o incompleto: "No encontramos esa reserva".

## 6. Caja (M06)
- [ ] Abrir turno (un solo turno por día, RN-M06-03); intentar abrir otro → rechazado.
- [ ] Sin turno abierto, la pantalla dice "sin turno" y el Mesero no puede cobrar (bug del 01/10).
      En Mesero > Cobrar, la franja amarilla lo dice; al abrir el turno, pasa a verde con el
      efectivo esperado (Fase 7, tarea 4).
- [ ] Un depósito de reserva en efectivo sube el efectivo esperado del día; el detalle del turno
      lo lista en "Depósitos de reserva". Cancelar esa reserva (devolver el depósito) lo resta.
- [ ] El día de la visita, el abono del depósito no vuelve a sumar al esperado: solo suma lo que
      el Mesero cobra encima (RN-M06-08).
- [ ] Con turno abierto, el Mesero cobra efectivo, tarjeta, transferencia y pago mixto.
- [ ] El Administrador **no** puede registrar un cobro (RF-M06-003/004).
- [ ] Anular un pago con motivo; el pedido recupera su saldo (RF-M06-017).
- [ ] Registrar un reembolso (no anulación) y ver la marca "Reembolsado" (RF-M06-017).
- [ ] Registrar un gasto en efectivo: el efectivo esperado del turno baja en ese valor (RF-M06-013).
- [ ] Cerrar turno: cifras de efectivo y ventas por método cuadran con los cobros hechos.
- [ ] Con el turno ya cerrado, anular un pago pide tu contraseña y aparece en "Ajustes
      extraordinarios"; el esperado y la diferencia del turno no cambian (RF-M06-019).
- [ ] Un pago en línea del día aparece en "Pagos en línea del día" del detalle del turno y se puede anular.
- [ ] Con el Mesero: dos pedidos en la misma mesa; Mesas muestra la suma y "Ver cuenta" lista ambos
      (RF-M02-L-019/020). En Cobrar, "Cobrar mesa" con una parte en efectivo y el resto con tarjeta
      deja tres pagos y la mesa libre; el turno suma el efectivo correcto.
- [ ] La cuenta de una mesa liberada y vuelta a ocupar no trae los pedidos del cliente anterior.
- [ ] Historial de turnos incluye el turno cerrado.

### 6b. Comprobante de pago (Fase 5, tarea 1 — RF-M06-009/010, RN-M06-07, HU-M02-019)
Antes de probar: `composer update barryvdh/laravel-dompdf --with-all-dependencies` y
`php artisan migrate` en `backend`.
- [ ] Con el Mesero, un pedido local de $40.000: cobrar $15.000 no da comprobante; cobrar el resto
      con tarjeta muestra "Imprimir comprobante FR-000001" en Cobrar y en Pedidos.
- [ ] El botón abre en otra pestaña un ticket de 80 mm con el negocio, el número, la mesa, las
      líneas, el subtotal, los descuentos, el total y los dos pagos (efectivo y tarjeta); se imprime
      con Ctrl+P (en la impresora POS, papel de 80 mm). Dice "No es factura electrónica".
- [ ] El siguiente pedido pagado toma el número FR-000002 (consecutivo sin saltos).
- [ ] Anular el pago de un pedido con comprobante: el botón desaparece, "Ver historial" dice
      "Comprobante FR-… anulado: El pedido ya no está pagado por completo." y Auditoría muestra
      "Comprobante de pago anulado". Volver a cobrar saca un comprobante con un número nuevo.
- [ ] Un domicilio o una recogida pagados: "Comprobante FR-… (PDF)" abre un PDF media página con
      cliente, teléfono, dirección de entrega, domicilio y pagos. Si el cliente tiene correo
      (cuenta o correo opcional del invitado), le llega el PDF adjunto.
- [ ] Con un pago en línea: mientras está "en revisión" no hay comprobante; al aprobarlo, sí.
- [ ] El Cliente con cuenta lo descarga desde Mis pedidos (detalle del pedido) y el invitado desde
      su enlace de seguimiento. Otro cliente no puede descargarlo.
- [ ] Con `FRAGUA_REAL_NIT` en el `.env` del servidor, el comprobante muestra el NIT; vacío, no.
- [ ] Reimpresión: la primera vez el ticket sale normal; el botón cambia a "Reimprimir comprobante"
      y avisa "Ya se imprimió 1 vez". La segunda vez el ticket dice "*** REIMPRESIÓN · COPIA 2 ***"
      con la hora. Auditoría muestra las dos impresiones con quién las hizo.
- [ ] El comprobante que descarga el Cliente desde Mis pedidos (o el invitado con su enlace) nunca
      dice REIMPRESIÓN y no suma impresiones.
- [ ] Administrador > Pedidos > "Comprobantes" de un pedido al que se le anuló un pago: lista el
      vigente y el anulado con su motivo y cuántas veces se imprimió cada uno; "Abrir" en el anulado
      muestra el sello ANULADO. Con el Mesero no aparece esa lista.

### 6c. Pago en línea con Wompi (Fase 6, tarea 1 — RF-M06-006/007/008/017, RN-M06-05, RF-M05-030)
Antes de probar: `php artisan migrate`. Poner en el `.env` del backend las llaves del sandbox de
Wompi (`WOMPI_*`), `PASARELA_PROVEEDOR=wompi` y `FRONTEND_URL`, y correr `php artisan config:clear`.
Para que lleguen los eventos al equipo de desarrollo hace falta una URL pública (por ejemplo un
túnel); sin ella, la página de retorno y `php artisan pasarela:conciliar` igual confirman el pago.
- [ ] Sin llaves (o con `PASARELA_PROVEEDOR=manual`): Caja > "Pagos en línea" dice "Aprobación
      manual" y el pago en línea queda en revisión como antes; el Mesero lo aprueba en Cobrar y
      Auditoría dice "a mano" con su nombre.
- [ ] Con las llaves: Caja > "Pagos en línea" dice "Wompi activo (pruebas)" y muestra la URL de
      eventos, que es la que se pone en Wompi > Desarrolladores.
- [ ] Como invitado, una recogida pagada en línea con Nequi: el Checkout lleva a la página de pago
      de Wompi con el monto del pedido y los datos del invitado ya puestos.
- [ ] Pagar con el Nequi de prueba que Wompi aprueba: se vuelve a "Pago aprobado", con la
      referencia FRP-…. El pedido queda con el pago aprobado sin que nadie lo apruebe, ya se
      puede confirmar y tiene su comprobante en PDF.
- [ ] En Caja, ese pago dice "Confirmado por Wompi · Nequi · ref. FRP-…". En Auditoría aparece
      "Pago en línea aprobado" sin usuario.
- [ ] Pagar con el Nequi de prueba que Wompi rechaza: "El pago no se completó" con "Intentar de
      nuevo". Desde el seguimiento se paga otra vez sobre el mismo pedido (no se crea otro).
- [ ] Abrir el pago y cerrar la página de Wompi sin pagar: en el seguimiento sale "¿No terminaste
      el pago? Puedes intentarlo de nuevo" y el reintento abre otro pago. El anterior queda "Sin
      pagar (vencido)".
- [ ] Mientras Nequi espera la aprobación, la página "Tu pago está en proceso" se actualiza sola.
      En Cobrar el pago dice "En proceso en Wompi · se confirma solo", con "Aprobar a mano" de
      respaldo.
- [ ] Reserva web: después de crearla, "Pagar depósito" lleva a Wompi; al aprobarse, la reserva
      queda confirmada y llega el correo. En Reservas dice "Wompi: Aprobado · ref. FRR-…".
- [ ] Depósito rechazado: la página de retorno ofrece volver a la reserva y "Mi reserva" (el
      enlace) tiene "Pagar el depósito" para reintentar.
- [ ] Reembolsar en Caja un pago aprobado con tarjeta de prueba: queda "Reembolso solicitado a la
      pasarela" y en Wompi aparece anulado. Con Nequi: "Devolverlo desde el panel de la pasarela"
      y aparece en "Pagos en línea por revisar". Hacerlo en Wompi y "Marcar como revisado" con la
      nota de lo que se hizo.
- [ ] Cancelar una reserva con depósito pagado por Wompi: se pide la devolución (con Nequi queda
      por revisar).
- [ ] Cancelar un pedido ya pagado por Wompi: aparece en "Pagos en línea por revisar" con
      "reembólsalo desde Caja".
- [ ] Rechazar a mano en Cobrar un pago que luego Wompi aprueba: queda por revisar ("ya estaba
      rechazado").
- [ ] Enviar al webhook un evento con la firma cambiada (por ejemplo con Postman): responde 401 y
      no cambia nada.
- [ ] Un Mesero no puede abrir `GET /pasarela/estado` ni `/pasarela/transacciones` (403).

## 7. Compras y gastos (M03)
- [ ] Fase 7, tarea 2 (RNF-TRANS-012, RF-M03-32): en Usuarios > Editar de un Mesero, marcar
      «Puede registrar compras y gastos». La lista lo muestra con la etiqueta "Compras y gastos" y
      Auditoría registra "Cambio de permiso de compras y gastos" (antes → después). Sus sesiones
      siguen abiertas. Al pasarlo a Cliente o Administrador, la casilla desaparece y el permiso se
      quita. En un Administrador o un Cliente la casilla no aparece.
- [ ] Lo que registra ese Mesero aparece aquí con "registrado por …"; tú lo puedes corregir o
      anular como cualquier otro registro.
- [ ] Registrar una compra y un gasto; editar uno activo (RF-M03-14).
- [ ] Anular: nunca se borra y un anulado ya no se puede editar (RN-M03-04).
- [ ] La categoría se elige de la lista según el tipo (Compra: Alimentos, Bebidas, Empaques,
      Insumos, Otros; Gasto: Transporte, Limpieza, Servicios, Mantenimiento, Papelería, Otros);
      sin categoría no deja guardar.
- [ ] Anotar cantidad y unidad (por ejemplo "5 kilos") y observaciones; se ven en la lista.
- [ ] Adjuntar una foto JPG, una PNG y un PDF; "Ver soporte" los abre. Un Word o un archivo de
      más de 5 MB se rechaza con un mensaje claro (RF-M03-07, RNF-M03-06).
- [ ] Copiar la dirección del soporte y abrirla en una ventana sin sesión: no se ve (RNF-M03-11).
- [ ] Una fecha de compra futura se rechaza; una anterior a hoy sí se acepta.
- [ ] Filtros: por fechas, categoría, forma de pago, proveedor y estado; los totales de arriba de
      la lista cambian con el filtro y no cuentan los anulados (RF-M03-13).
- [ ] Corregir el valor de un registro: la lista dice quién lo corrigió y la auditoría guarda el
      valor anterior y el nuevo; anular muestra quién y cuándo (RF-M03-19).
- [ ] "Ver cálculo" de hoy: ventas, compras, otros gastos y resultado, con el desglose de ventas
      por tipo y por forma de pago, compras y gastos por categoría y por forma de pago, y el arqueo
      de la caja (RF-M03-21 a 26, RN-M06-12). Dice que no es la utilidad contable (RF-M03-34).
- [ ] Con la caja del día abierta, "Cerrar el día" está desactivado y lo explica (RN-M06-11);
      después de cerrar la caja, se cierra el día y los totales coinciden con lo registrado.
- [ ] No se puede cerrar dos veces el mismo día ni un día futuro.
- [ ] "Cierres anteriores" lista el cierre; al abrirlo se ve igual que al cerrarlo (RF-M03-27).
- [ ] Registrar un gasto con la fecha de un día cerrado: el formulario pide la contraseña y el
      motivo; con una contraseña equivocada no se guarda; con la correcta, el cierre muestra la
      corrección y los valores actualizados, y el cierre original no cambia (RF-M03-20, HU-M03-09).
- [ ] Corregir el valor y anular un registro de un día cerrado también piden la contraseña.

### 7b. Cierre diario impreso y en PDF (Fase 5, tarea 2 — RF-M03-28/29, RNF-M03-09/10, HU-M03-11/12)
- [ ] En un día cerrado, "Imprimir / PDF del cierre" abre en otra pestaña un PDF tamaño carta con
      el negocio, el día ("Martes 13 de octubre de 2026"), quién lo cerró y a qué hora, los cuatro
      totales, ventas por tipo y por forma de pago, compras y gastos por categoría y por forma de
      pago, el arqueo de caja, la nota de que no es la utilidad contable, las líneas de firma
      ("Cerró" y "Revisó") y al pie quién lo imprimió y "Página 1 de 1". Se imprime con Ctrl+P o se
      guarda.
- [ ] Un día con correcciones después del cierre: el PDF trae el cuadro "Correcciones después del
      cierre" (fecha, quién autorizó, qué cambió, motivo y montos) y los valores actualizados; los
      totales del cierre original no cambian.
- [ ] La segunda vez el botón dice "Reimprimir PDF del cierre" y avisa que ya se imprimió; el PDF
      dice "REIMPRESIÓN · copia 2". Auditoría muestra cada impresión ("Impresión o reimpresión del
      cierre diario") con quién la hizo.
- [ ] Un día sin cerrar solo ofrece "Imprimir vista previa": la hoja impresa dice "VISTA PREVIA —
      este día todavía no está cerrado".
- [ ] Abrir un cierre viejo desde "Cierres anteriores" y sacar su PDF también funciona.

## 8. Reportes (M08)
- [ ] Los 6 reportes cargan con la semana actual por defecto (RN-M08-03).
- [ ] Cambiar a mes y a rango personalizado.
- [ ] Comparación contra el periodo anterior equivalente (RF-M08-010).
- [ ] Periodo en curso aparece marcado como parcial (RN-M08-05).
- [ ] Resultado operativo = ventas − compras − gastos (RN-M08-06), con y sin cierre diario del día.
- [ ] Exportar a Excel y PDF; la exportación queda en auditoría (RF-M08-011/012/014).

### 8b. Exportación real (Fase 5, tarea 3 — RF-M08-011/012, RNF-M08-006, HU-M08-009/010)
Antes de probar: `composer update phpoffice/phpspreadsheet --with-all-dependencies` y
`php artisan migrate` en `backend`.
- [ ] "Exportar Excel" descarga un `.xlsx` (no un CSV) que Excel abre sin avisos: hoja "Resumen"
      con el título, el periodo en letras, quién lo generó y, si es la semana o el mes en curso,
      "Periodo en curso: las cifras son parciales"; y una hoja por tabla (por ejemplo "Por tipo de
      venta" y "Por método de pago"), con encabezados fijos, filtros y los valores como números en
      pesos (se pueden sumar).
- [ ] "Exportar PDF" de "Ventas por producto" con muchos productos: el PDF tiene las páginas que
      haga falta (no se corta), repite los encabezados de la tabla en cada página y dice "Página n
      de m" al pie.
- [ ] Con "Comparar con el periodo anterior" marcado, el PDF y el Excel traen la tabla de
      comparación (este periodo, anterior, variación y %), igual que la pantalla (CA-M08-009/010).
- [ ] Debajo de los reportes aparece "Exportaciones guardadas" con cada archivo (reporte, formato,
      periodo, "con comparación", "parcial", quién y cuándo). "Descargar" baja el mismo archivo
      aunque después haya ventas nuevas: no se regenera (RNF-M08-006). Auditoría muestra
      "Descarga de un reporte ya exportado".

### 8c. Uso de mesas con pedidos locales (Fase 5, tarea 4 — RF-M08-009, HU-M08-007)
- [ ] Atender una mesa sin reserva con dos pedidos (el segundo con la mesa todavía ocupada),
      cobrar, finalizar y liberarla; más tarde, otro pedido en la misma mesa. En Reportes >
      "Ocupación por mesa" esa mesa dice "2 visitas", las ventas y los minutos promedio.
- [ ] Una mesa con una reserva completada suma "1 reserva"; una mesa que no se usó dice "Sin uso
      en el periodo".
- [ ] Un pedido cancelado no cuenta como visita. Las cifras de reservas (creadas, canceladas,
      no-show) no cambian.
- [ ] Exportar "Uso de mesas" a Excel: la hoja "Ocupación por mesa" trae Mesa, Visitas, Pedidos,
      Ventas, Minutos promedio por visita y Reservas completadas.
- [ ] Un Mesero no puede abrir Reportes (403).

### 8d. Velocidad con un mes completo (Fase 7, tarea 5 — RNF-M08-001)
Antes de probar: `php artisan migrate` en `backend` (migración `2026_10_22_000001`, solo índices).
- [ ] Elegir "Mes" (o un rango personalizado de 30 días) con "Comparar con el periodo anterior"
      marcado: cada uno de los 6 reportes aparece en menos de 2 segundos.
- [ ] Las cifras son las mismas que antes de la migración: por ejemplo, Ventas totales del mes
      coincide con la suma de los cierres diarios del mes más el día de hoy (RNF-M08-002).
- [ ] En el servidor, con historia real: `php artisan reportes:medir` muestra una tabla con los 6
      reportes del último mes y todos dicen "OK". Si alguno dice "LENTO", revisar que
      `php artisan migrate` esté al día.

## 9. Pedidos (supervisión)
- [ ] Filtros por estado y canal; paginación con más de 20 pedidos.
- [ ] Confirmar, marcar preparando y cancelar con motivo (el Administrador cancela también lo confirmado).
- [ ] Con el Mesero: cancelar un pedido confirmado pide la contraseña de un Administrador; con una
      clave equivocada no se cancela (RN-M07-05).
- [ ] No hay botón de cobrar.
- [ ] El Administrador no ve "Confirmar entrega", "Confirmar recogida" ni "Finalizar pedido"
      (son del Mesero, RF-M07-025, RF-M02-T-016).
- [ ] Editar un pedido PENDIENTE: agregar un producto, cambiar una cantidad y quitar una línea;
      el total se recalcula en cada paso (RN-PED-07, RF-M02-L-004/005).
- [ ] Editar un pedido CONFIRMADO con una línea marcada como preparada: esa línea no se puede
      cambiar ni quitar; las demás sí (RN-PED-08, RN-PED-10).
- [ ] Un pedido en PREPARANDO, FINALIZADO o CANCELADO no muestra "Editar pedido".
- [ ] Quitar la única línea de un pedido: el sistema pide cancelarlo con motivo.
- [ ] Bajar la cantidad de un pedido ya pagado por debajo de lo pagado: rechazado con un mensaje claro.
- [ ] "Ver historial" muestra creación, confirmación, cada cambio con quién y la hora, y los pagos (HU-M02-020).
- [ ] Un domicilio muestra nombre, teléfono, dirección, barrio, referencia e indicaciones; una
      recogida, nombre y teléfono (RF-M07-003 a 007, RF-M07-010).
- [ ] Un pedido web pagado en línea muestra "Paga: En línea (Nequi)" o "(Bancolombia)"; uno
      contra entrega o por teléfono, "Contra entrega" o "Al recoger" (RF-M07-013/015).
- [ ] Con el Mesero: "Nuevo pedido" → Domicilio, sin barrio o sin referencia no deja continuar;
      con los datos completos crea el pedido con el valor de domicilio vigente (RF-M07-001).
- [ ] Con el Mesero: "Nuevo pedido" → Recogida crea el pedido sin costo de domicilio (RF-M07-R-001).
- [ ] "Descuento" en un pedido activo: 10 % con motivo; el total baja y aparece "Descuento manual
      10 %" con el motivo y quién lo aplicó (RF-M04-036, HU-M04-015).
- [ ] Sin motivo, con 0, con un valor negativo o con 120 % no deja aplicar (RN-M04-22/23).
- [ ] En un pedido con promociones automáticas, el descuento manual las reemplaza (no se suman);
      "Quitar descuento" las devuelve (RF-M04-037, RN-M04-21).
- [ ] Un descuento que dejaría el total por debajo de lo ya pagado se rechaza con un mensaje claro.
- [ ] El descuento aparece en "Ver historial" y en la auditoría con usuario, motivo y monto (RF-M04-039).
- [ ] Con el Mesero: el mismo botón "Descuento" funciona igual; "Nuevo pedido" muestra las
      promociones vigentes y el total con descuento antes de crear el pedido.
- [ ] Reportes > Ventas por producto: un pedido con descuento suma lo cobrado, no el precio lleno.
- [ ] Reportes > Ventas por tipo y método: el abono de un depósito sale como "Depósito de reserva".

### 9a. Listo para recoger (Fase 4, tarea 5 — RF-M07-R-012, RN-M07-R-03, HU-M07-012)
Requiere la cola corriendo y un `MAIL_MAILER` que envíe (con `log` el correo queda en
`storage/logs/laravel.log`). Usar un correo al que tengas acceso.
- [ ] Una recogida web de invitado con correo, confirmada y en preparación: los datos del cliente
      muestran el correo y aparece "¿Ya está listo? Al marcarlo le avisamos por correo a …".
- [ ] Pulsar "Listo para recoger": el pedido sigue en Preparando, todas sus líneas quedan marcadas
      como preparadas y el recuadro dice "Listo para recoger desde las …" y "Aviso enviado a … a las …".
- [ ] Llega el correo "Tu pedido #N está listo para recoger", con lo que falta por pagar (o que ya
      está pagado) y el botón "Ver mi pedido"; el enlace abre el seguimiento con el paso
      "Listo para recoger" (RN-M07-R-03).
- [ ] Una recogida sin correo (invitado que no lo dejó): se marca lista y la pantalla dice
      "Sin correo: avísale al cliente por teléfono".
- [ ] Una recogida de un Cliente con cuenta: el aviso llega al correo de su cuenta y Mis pedidos
      muestra "Listo para recoger".
- [ ] Con el correo mal configurado: tras 3 intentos el aviso dice que no se pudo enviar, con el
      motivo; corregir el SMTP y pulsar "Reintentar aviso": pasa a enviado. Auditoría muestra la
      falla y el envío ("Aviso de pedido enviado al cliente").
- [ ] Un domicilio o un pedido local no muestran el botón; tampoco una recogida ya marcada lista.
- [ ] Cobrar y "Confirmar recogida" (Mesero) finalizan el pedido como siempre; "Ver historial"
      muestra "Listo para recoger" con quién lo marcó.
- [ ] Con el Mesero: "Nuevo pedido" → Recogida pide el correo opcional; con un correo mal escrito
      no deja continuar.

### 9b. Comandas de cocina (Fase 6, tarea 2 — RN-PED-06/09/17 a 19, RF-M02-T-004 a 007, HU-M02-016/017)
Antes de probar: `php artisan migrate`. Permitir las ventanas emergentes del sitio en el navegador.
- [ ] Con el Mesero, un pedido local con 2 Clásicas "sin cebolla" y un combo: "Confirmar pedido"
      abre en otra pestaña la comanda de 80 mm (pedido, mesa, hora, líneas, lo que trae el combo,
      la nota). No lleva precios. Se imprime con Ctrl+P.
- [ ] El pedido muestra "Reimprimir comanda 12-1"; reimprimirla dice "*** REIMPRESIÓN · COPIA 2 ***".
      Auditoría muestra las dos impresiones con quién las hizo.
- [ ] Debajo dice "¿No salió de la impresora? Avisar": al avisar, la franja amarilla de arriba
      muestra "1 comanda por imprimir · 1 no salió" y al imprimirla de nuevo sale como original.
- [ ] Apagar "Abrir la comanda al confirmar" (en "Impresión de comandas en este equipo"):
      confirmar ya no abre nada y la franja dice "1 comanda por imprimir"; "Imprimir" desde la
      franja la abre y la franja desaparece.
- [ ] Con el pedido confirmado y su comanda impresa, editar: agregar 2 Papas y cambiar la
      Clásica a 3. Sale una sola hoja "Cambios al pedido" (hoja 2) con "[AGREGAR] 2 × Papas" y
      "[CAMBIAR] 3 × Clásica (antes 2)", no las líneas que no cambiaron.
- [ ] Editar un pedido confirmado cuya comanda todavía no se imprimió: no sale hoja de cambios;
      la comanda inicial sale ya con el cambio.
- [ ] Cancelar (con contraseña de Administrador) un pedido cuya comanda ya salió: sale "PEDIDO
      CANCELADO" con el motivo. Cancelar uno cuya comanda no se imprimió: no sale nada y la franja
      ya no la muestra.
- [ ] Un domicilio web confirmado: la comanda dice "DOMICILIO" y el nombre del cliente.
- [ ] "Ver historial" del pedido muestra "Comanda 12-1 enviada a la cocina" e "impresa" con quién.
- [ ] Equipo de la cocina: prender "Abrir solas las comandas nuevas" y, desde otro equipo,
      confirmar un pedido. En menos de 15 s se abre sola la comanda. Con Chrome abierto con
      `--kiosk-printing` sale sin el cuadro de impresión.
- [ ] Un Cliente no puede abrir `/api/comandas/pendientes` ni el ticket de una comanda (403).

### 9c. Invitado (sin cuenta)
Resumen; la lista completa del invitado está en `QA-CLIENTE.md` (secciones 4 a 8).
- [ ] Pedir una recogida sin iniciar sesión: el Checkout muestra el enlace de seguimiento.
- [ ] El enlace muestra el pedido "Recibido"; al confirmarlo el Mesero, en máximo 30 segundos pasa a
      "Confirmado" sin recargar (RF-M07-027).
- [ ] Desde el enlace: pagar en línea queda "en revisión"; cancelar solo aparece mientras esté pendiente.
- [ ] Un enlace inventado muestra "No encontramos ese pedido".
- [ ] Con una promoción vigente, el carrito y el Checkout muestran la rebaja y el mismo total que
      tendrá el pedido; el seguimiento muestra la promoción aplicada.
- [ ] Reservar mesa sin cuenta y pagar el depósito en línea: al confirmarlo el personal, el correo
      trae el enlace de la reserva y desde ahí se puede cancelar antes de la hora (punto 5c).
- [ ] En el Checkout de una recogida, el correo es opcional ("Te avisamos por correo cuando tu
      pedido esté listo para recoger"); un correo mal escrito muestra "Escribe un correo válido o
      déjalo vacío". Cuando el local lo marca listo, llega el correo y el seguimiento cambia solo a
      "¡Tu pedido está listo! Pasa a recogerlo en el local." (punto 9a).

## 10. Transversal
- [ ] Cerrar sesión y volver a entrar; el token anterior ya no sirve.
- [ ] Desactivar un Mesero con sesión abierta: en su siguiente acción la app lo saca (RF-M01-023).
- [ ] Usuarios: cada desactivación queda registrada y se ve en Auditoría (sección 2b).
- [ ] Cada pantalla en teléfono (ancho ~390 px): sin scroll horizontal ni botones cortados.
- [ ] Errores del servidor (422/500) muestran un mensaje legible, no una pantalla en blanco.
- [ ] Ningún mensaje ni texto de pantalla muestra códigos de requisitos ("(RN-M06-03)") ni el
      texto en inglés de Laravel ("Too Many Attempts.", "This action is unauthorized."); un 500
      muestra el mensaje genérico de la pantalla (revisión por rol, 13/10/2026).
- [ ] Con `APP_LOCALE=es` en el `.env` del servidor (ya viene así en `.env.example`), un
      formulario con un campo obligatorio vacío dice el error en español.

## 11. Mejoras del 16/10/2026

- [ ] Catálogo > Extras: un extra general sin productos marcados dice «no se ofrece con ningún
      producto»; en el Menú no aparece con ninguno. Marcado en una Limonada, sí aparece en ella.
- [ ] Catálogo > editar un producto con presentaciones: cada una tiene «Qué trae» y «Subir foto».
      En el Menú, al elegir esa presentación se ve su foto y su descripción.
- [ ] Catálogo > Productos > «Poner en oferta»: con precio de oferta y una presentación, el Menú
      tacha el precio solo de esa presentación y el botón «Agregar» suma el precio de oferta. Con una
      fecha límite, al día siguiente ya no se aplica. «Quitar oferta» la desactiva (queda en Promociones
      como inactiva, con la etiqueta «Oferta de producto»).
- [ ] Catálogo > Productos > «Importar»: descargar la plantilla, llenar 3 filas (una nueva, una que
      ya existe con otro precio y una sin nombre) y subirla. La vista previa marca «Nuevo», «Se
      actualiza» con sus cambios y «Error»; el botón «Importar» queda apagado. Al quitar la fila con
      error e importar, aparecen el producto nuevo y el precio nuevo, y Auditoría muestra
      «importar_productos».
- [ ] Caja: el turno de hoy muestra «Resumen del día» con efectivo, tarjeta, transferencia y en línea,
      el desglose del efectivo esperado y los depósitos del día. Cobrar algo en tarjeta y tocar
      «Actualizar»: sube Tarjeta y el efectivo esperado no cambia.
- [ ] Compras y gastos > cierre del día: con una reserva marcada como no-show hoy, «Depósitos
      retenidos (no-show)» muestra su depósito y suma al resultado operativo; Reportes > Resultado
      operativo lo muestra igual.
- [ ] Mesas y reservas: las pestañas «Mesas | Reservas» llevan a todas las reservas; entrando por
      «Ver reservas» de una mesa y tocando la pestaña «Reservas», la lista vuelve a mostrar todas.

## 12. Datos del negocio (19/10/2026)

- [ ] Configuración > «Datos del negocio»: cambiar la dirección y el teléfono y guardar. El Inicio y el
      pie de página muestran los nuevos y el botón de WhatsApp abre el número nuevo.
- [ ] Un teléfono con letras o con menos de 7 dígitos se rechaza con un mensaje claro.
- [ ] «Subir logo» con un PNG cuadrado: se ve en la tarjeta, en el encabezado y el pie de página del
      sitio, en Ingresar, en el QR de mesa y en la barra lateral del personal.
- [ ] Un PDF exportado de Reportes, un comprobante en PDF y el PDF del cierre diario llevan el logo
      arriba a la izquierda.
- [ ] Un archivo que no es imagen se rechaza. «Quitar logo» vuelve al círculo «FR».
- [ ] Auditoría muestra «editar_datos_negocio», «cambiar_logo_negocio» y «quitar_logo_negocio».

## 13. SuperAdmin (M09, 20/10/2026)

- [ ] En el servidor, `php artisan fragua:crear-superadmin correo@…` crea la cuenta (pide la contraseña
      dos veces); con el correo de una cuenta existente, pide confirmación y la vuelve SuperAdmin.
- [ ] Con el SuperAdmin: el menú dice «SuperAdmin» y entra a todas las pantallas del Administrador.
- [ ] Usuarios (SuperAdmin): «Nuevo usuario» ofrece Mesero y Administrador; puede editar, cambiar de rol,
      desactivar y cerrar sesiones de un Administrador; su propia cuenta no deja cambiar el rol.
- [ ] Usuarios (Administrador): «Nuevo usuario» solo ofrece Mesero; las cuentas de Administrador dicen
      «Solo la gestiona el SuperAdmin» y la del SuperAdmin «Se cambia en el servidor», sin botones.
- [ ] Configuración > Datos del negocio: el SuperAdmin cambia nombre y NIT (un NIT con letras se
      rechaza) y salen en el sitio y en los PDF nuevos; el Administrador los ve sin poder cambiarlos.
- [ ] Revisión antes de producción: «Cuenta de SuperAdmin» en verde; si se desactiva, sale el aviso.
- [ ] Auditoría muestra «crear_superadmin» o «asignar_superadmin» y las acciones del SuperAdmin a su nombre.
- [ ] Permisos de menú: como SuperAdmin, en Usuarios > «Permisos» de un Mesero, quitar Reservas y Cobrar
      y guardar. Con ese Mesero: el menú muestra Pedidos, Mesas y Menú; /mesero/caja lleva a Pedidos; en
      Mesas no aparece cobrar la cuenta ni «Ver reservas».
- [ ] Con un Administrador al que se le quitó Reportes: no aparece en el menú y /admin/reportes lleva al
      Dashboard. Dashboard (Administrador) y Pedidos (Mesero) salen marcados y no se pueden quitar.
- [ ] Un Administrador no ve el botón «Permisos». Auditoría muestra «cambiar_menus» con lo quitado.
- [ ] Dashboard de un Administrador con menús limitados: los accesos rápidos que no tiene salen en gris,
      con candado y «Sin permiso»; al tocarlos no pasa nada. Los que sí tiene abren normalmente.
- [ ] Ubicación del local (SuperAdmin): en Configuración > Datos del negocio, «Ubicación en el mapa».
      Tocar el mapa pone el marcador; arrastrarlo lo mueve; abajo dice «Punto: … · sin guardar» hasta
      «Guardar datos del negocio». El Inicio muestra el mapa y «Cómo llegar» abre Google Maps en ese punto.
- [ ] Pegar en «Usar enlace» el enlace de «Compartir» de Google Maps (`maps.app.goo.gl/…`), uno largo
      del navegador o «1.6144, -75.6062»: el marcador va a ese punto. Un enlace de otra página dice «No
      encontramos la ubicación en ese enlace…».
- [ ] «Quitar punto» y guardar: el mapa del Inicio desaparece y «Cómo llegar» vuelve a buscar la dirección.
- [ ] Con un Administrador: ve el mapa sin poder moverlo, con «Solo el SuperAdmin cambia el punto del
      local», y sin el campo del enlace.

## 14. Soporte técnico (M10, 20/10/2026)

- [ ] En el servidor, `php artisan fragua:crear-soporte dev@dominio.com --nombre="Dev"` pide la contraseña
      dos veces y crea la cuenta. Con el correo de una cuenta del negocio (por ejemplo, el del
      Administrador) dice «Ese correo ya es de una cuenta de administrador…» y no cambia nada.
- [ ] Entrar con la cuenta de Soporte (en local, `soporte` / contraseña de prueba): llega al panel del
      Administrador, el menú dice «Soporte» y arriba de cada pantalla sale «Modo soporte: solo consulta».
- [ ] Recorrer Pedidos, Catálogo, Mesas, Reservas, Caja, Compras y gastos, Reportes, Usuarios, Auditoría y
      Configuración: se ve todo y no aparecen botones de crear, editar, cobrar, anular, guardar ni
      desactivar. Reportes sí exporta a PDF y Excel.
- [ ] Desde la consola del navegador, un `PUT /api/configuraciones` (o cualquier otra escritura) responde
      403 «La cuenta de Soporte solo puede consultar.».
- [ ] Abrir el comprobante de un pedido cobrado: dice «COPIA DE CONSULTA · SOPORTE». Luego, con el
      Mesero, el primero que imprime sale como original (no como reimpresión).
- [ ] Con el Administrador: la cuenta de Soporte no aparece en Usuarios.
- [ ] Con el SuperAdmin: en Usuarios aparece con «Se cambia en el servidor», Sesiones y Desactivar.
      Al desactivarla, la sesión de Soporte se cierra; al reactivarla puede volver a entrar. «Editar» no
      aparece. Auditoría muestra lo de Soporte a su nombre con el rol «Soporte».
- [ ] Revisión antes de producción: «Cuenta de Soporte» informa si hay una activa; con la contraseña de
      prueba, «Sin cuentas activas con contraseña de prueba» la nombra.

## 15. Notificaciones del personal (M11, 21/10/2026)

- [ ] En el menú lateral (y en el teléfono, en la barra de arriba) está la campana «Notificaciones».
- [ ] Hacer un pedido por el QR de una mesa (o a domicilio desde la web): en menos de 20 s la campana
      muestra el contador; al abrirla sale «Pedido nuevo #…» con el canal, el cliente y el total.
      Tocarlo lo marca como leído y abre Pedidos.
- [ ] Un Administrador al que el SuperAdmin le quitó Pedidos no recibe ese aviso.
- [ ] Con el Mesero, marcar un producto agotado: el Administrador recibe «Producto agotado»; el Mesero
      que lo marcó no.
- [ ] Reservar desde la web: llega «Reserva nueva». Registrar el depósito: a los demás les llega
      «Depósito pagado: reserva confirmada». Cancelarla con el enlace del correo: llega «El cliente
      canceló su reserva». Treinta minutos antes de una reserva confirmada llega «Reserva en … min».
- [ ] Anular un pago en Caja: a los otros Administradores les llega «Pago anulado».
- [ ] Con la caja abierta después de las 11:30 p. m. llega «La caja sigue abierta». Al otro día, desde
      las 10:00 a. m., si no se hizo el cierre llega «Falta el cierre diario de ayer».
- [ ] Si se detiene el cron del servidor, al Administrador (y a Soporte) les llega «Las tareas
      automáticas están detenidas». Si la revisión antes de producción tiene errores, llega a las 7:00 a. m.
- [ ] «Marcar todo como leído» deja el contador en cero. Un aviso no se repite.

## 15b. Anular un pago de un pedido finalizado (22/10/2026)

- [ ] Cobrar un pedido local en dos pagos (por ejemplo, efectivo y tarjeta) hasta que quede finalizado.
      En Caja, anular el de tarjeta como «Anulación»: el detalle del turno dice «El pedido #… volvió a
      cobro: le falta …», el pedido aparece otra vez en Pedidos y el Mesero recibe la notificación.
      Al cobrar lo que falta, el pedido se finaliza solo.
- [ ] Con «Reembolso» en vez de «Anulación», el pedido sigue finalizado.

## 15c. Cuentas para pagos en línea sin Wompi (22/10/2026)

- [ ] Como SuperAdmin, Configuración > «Cuentas para pagos en línea»: llenar Nequi y guardar. Nequi dice
      «Se ofrece»; Bancolombia sin número dice «No se ofrece». Auditoría registra el cambio con el antes y
      el después.
- [ ] Como Administrador, la tarjeta se ve con los campos bloqueados y el aviso «Solo el SuperAdmin cambia
      las cuentas donde se recibe el dinero».
- [ ] En Pedidos, un pedido con captura sale con «Pago por revisar»; al abrirlo, «Revisar comprobante»
      muestra la captura y deja aprobar o rechazar con motivo. En Reservas, igual con el depósito.

## 15d. Formato de los campos (22/10/2026)

- [ ] Configuración: en el teléfono, el NIT, el número de Nequi, la cuenta Bancolombia y la cédula o NIT no
      entra ninguna letra; en «A nombre de» no entran números; «Valor del domicilio» y «Depósito» se ven con
      punto de miles (20.000) y no aceptan letras; los tiempos de reserva solo aceptan números.
- [ ] Pegar «NIT: 900.123.456-7» en el NIT deja «900123456-7»; pegar «+57 322 725-9965» en el teléfono deja
      «3227259965».
- [ ] Catálogo, Caja, Compras y gastos: los precios y valores se escriben con punto de miles; la cantidad
      acepta decimales (2,5); en Usuarios, el teléfono solo acepta 10 números y el nombre, solo letras.

## 15e. Eliminar mesas, productos y usuarios (24/10/2026; definitivo desde el 27/10/2026)

- [ ] Mesas, Catálogo y Usuarios no tienen filtro «Eliminadas» / «Eliminados» ni «Restaurar».
- [ ] Mesas: crear «Mesa prueba» y eliminarla. La ventana dice «Nunca se usó: se borra por completo» y
      pide solo la contraseña (sin ella no deja; con una mala lo dice). Desaparece y Auditoría lo registra.
- [ ] Eliminar una mesa con pedidos viejos: la ventana dice que desaparece de las listas pero los reportes
      la siguen mostrando. Sale de la lista para siempre; se puede crear otra mesa con el mismo nombre.
- [ ] Una mesa ocupada o con una reserva próxima no se puede eliminar: la ventana dice por qué.
- [ ] Catálogo › Productos: eliminar un producto vendido lo quita del catálogo y del menú, y el combo que lo
      traía queda inactivo. Crear otro con el mismo nombre funciona.
- [ ] Usuarios: eliminar una cuenta pide motivo además de la contraseña. Un Mesero que ya trabajó ya no
      puede entrar y su actividad sigue en Auditoría; se puede crear una cuenta nueva con su correo. Un Administrador no puede eliminar a otro Administrador ni a sí mismo; el SuperAdmin sí al
      primero. Las cuentas de SuperAdmin no se eliminan desde la pantalla.

## 15f. Impresoras (M12, 24/10/2026)

- [ ] En el PC de la caja: Impresoras > «Registrar este equipo» con el nombre «Caja». Queda «En línea».
- [ ] «Agregar equipo con código» con el nombre «Cocina»: sale un código de 6 números. En la tablet de la
      cocina (sesión del Mesero), Pedidos > «Impresión de comandas en este equipo» > escribirlo: «Cocina»
      pasa a «En línea». El mismo código no sirve otra vez.
- [ ] «Imprimir prueba» en cada equipo: sale el ticket de 80 mm con logo, tildes y la regla completa;
      responder «Salió bien» y verlo como «Última prueba».
- [ ] Prender «Abre solas las comandas nuevas» para «Cocina»: en menos de un minuto la tablet lo muestra
      con ✓ y abre sola la siguiente comanda.
- [ ] Formato del ticket: letra grande, mensaje al pie y 2 copias; la vista previa cambia sola; al guardar,
      el siguiente comprobante sale así (dos hojas).
- [ ] Estado: una comanda que no salió aparece con «Imprimir» y «Descartar» (pide motivo); el historial
      dice en qué equipo se imprimió cada cosa.
- [ ] Con la caja abierta, apagar la tablet de la cocina más de 10 minutos: la campana avisa «El equipo
      «Cocina» está desconectado» (una vez).

## 15g. Mejoras del 26/10/2026

- [ ] Con un pedido sin finalizar (pendiente, confirmado o en preparación, pagado o no), «Cerrar turno»
      muestra la lista con lo que debe cada uno y el botón queda apagado. Al finalizarlo o cancelarlo, cierra.
- [ ] Caja > detalle de un turno: un cobro por transferencia del Mesero tiene «Ver foto».
- [ ] Catálogo > Categorías > Bebidas: «Se pueden quitar ingredientes» apagado. En el menú, una bebida no
      pregunta qué ingrediente quitar; una hamburguesa sí.
- [ ] Auditoría: al abrir un registro, el detalle empieza por «Hecho por» (nombre, rol y correo) y «Cuándo».
- [ ] En el teléfono, el menú lateral con todas las opciones: la lista se desplaza y el perfil y «Cerrar
      sesión» quedan a la vista; «Instalar app» aparece aunque el navegador no lo ofrezca (muestra los pasos).
- [ ] Reservas > «Registrar depósito manual» en efectivo con $50.000 para $20.000: dice «Entrega $30.000 de
      cambio»; en Auditoría el registro muestra «Entregó el cliente» y «Cambio entregado». Por transferencia
      pide la foto y la reserva queda con «Ver comprobante».

## 15h. Eliminar del catálogo (27/10/2026)

- [ ] Catálogo > Categorías, Combos, Extras generales y Promociones: cada fila tiene «Eliminar» (sin
      filtro «Eliminados»). La ventana pide solo la contraseña.
- [ ] «Eliminar» en una categoría con productos dice que hay que moverlos o eliminarlos antes. En una
      categoría nueva y vacía dice «Nunca se usó: se borra por completo» y desaparece.
- [ ] «Eliminar» en un combo o un extra que ya se vendió: desaparece de la lista y ya no se puede pedir; los
      pedidos de antes lo siguen mostrando.
- [ ] Una promoción que nunca se aplicó se borra; una que ya se aplicó desaparece y deja de descontar.
- [ ] Auditoría registra cada eliminación («… eliminado» o «… eliminado (se guarda su historial)»).

## 15i. Orden de la barra lateral (27/10/2026)

- [ ] En el computador, la barra lateral tiene tres grupos con título: OPERACIÓN (Dashboard, Pedidos, Mesas
      y Reservas, Caja), NEGOCIO (Catálogo, Compras y Gastos, Reportes) y ADMINISTRACIÓN (Usuarios,
      Auditoría, Impresoras, Configuración). En el menú del teléfono, igual.
- [ ] En la tableta (solo íconos) los grupos se separan con una línea.
- [ ] Una cuenta a la que el SuperAdmin le quitó menús solo ve los suyos, en el mismo orden, sin títulos
      de grupos vacíos.

## 15j. Cierre automático de la caja (27/10/2026)

- [ ] Configuración > «Cierre automático de la caja»: hora de cierre 11:30 p. m. y tolerancia 60 dicen «La
      caja se cierra sola a las 12:30 a. m.». Al cambiar la tolerancia, la hora cambia. Una tolerancia de
      más de 360 no se guarda.
- [ ] Caja, con el turno abierto: «Si nadie la cierra, se cierra sola a las 12:30 a. m., sin contar el
      efectivo».
- [ ] A la hora de cierre llega a la campana «La caja sigue abierta», con la hora en que se cierra sola.
- [ ] Sin cerrar la caja, pasada la tolerancia: el turno queda «Cerrada» con «Cierre automático, sin
      contar», aunque haya pedidos sin finalizar. La campana avisa «La caja del dd/mm se cerró sola» con
      esos pedidos, que se cobran con la caja del día siguiente.
- [ ] «Registrar conteo»: con el efectivo esperado se guarda sin justificación; con otro valor muestra la
      diferencia y pide justificación. Después el turno dice «Cierre automático» y el descuadre, y el
      aviso de arriba desaparece.
- [ ] Auditoría registra «Cierre automático de caja, sin contar» y «Conteo de caja tras el cierre
      automático».

## 15k. Documento del cliente (27/10/2026)

- [ ] Usuarios > editar un Cliente: aparece «Número de documento»; uno que ya tiene otro cliente dice «Ese
      número de documento ya lo tiene otro cliente». En la lista se ve «doc. …».
- [ ] Auditoría registra «Pedido asignado a un cliente al cobrar» cuando el Mesero asigna un pedido.

## 16. Contraseña segura y bloqueo por intentos (21/10/2026)

- [ ] Usuarios › Nuevo usuario: debajo de la contraseña salen los requisitos y se marcan al cumplirse; con
      «mesero123» el servidor responde que falta una mayúscula. Lo mismo en Mi perfil › Cambiar contraseña.
- [ ] En el servidor, `php artisan fragua:crear-soporte` con una contraseña débil dice qué le falta y no crea nada.
- [ ] Equivocarse 5 veces seguidas con la contraseña de un Mesero: el quinto intento dice que espere 15
      minutos; la campana del Administrador y del SuperAdmin muestra «Cuenta bloqueada por intentos
      fallidos» y Auditoría (Seguridad y sesiones) lo registra.
- [ ] Recuperar la contraseña de esa cuenta: puede entrar de inmediato con la nueva.
