# QA del Mesero — lista de pruebas por pantalla

Esta lista cubre a mano todo lo que hace el Mesero en un turno:

- tomar y atender pedidos de los tres canales;
- la cocina (comandas);
- las mesas y las reservas;
- el menú del día;
- los cobros y el estado de la caja;
- con permiso, las compras y gastos.

Es la pareja de `QA-ADMINISTRADOR.md` y `QA-CLIENTE.md`, con el mismo formato: marca cada casilla al probarla y anota la fecha. Cada sección cita el requisito oficial en el que se apoya.

Al preparar la lista se revisó el código de cada pantalla. Se corrigieron dos cosas que el Mesero notaría en el servicio (ver «Revisión por rol» en `README-BACKEND.md`, punto 45):

- La lista de pedidos no se actualizaba sola: un pedido de la web o del QR solo aparecía al recargar.
- Un combo no mostraba lo que incluye ni aceptaba una nota para la cocina.

## 0. Antes de empezar

- [ ] Prepara el entorno:
  - Backend y frontend corriendo.
  - Base de datos con `php artisan migrate --seed`.
  - La cola (`php artisan queue:work`) y el programador (`php artisan schedule:work`).
- [ ] Abre tres sesiones:
  - **Mesero**: `mesero@fraguareal.com` (o el usuario `mesero`), contraseña `password`. Si puedes, en un teléfono o una tableta, que es donde se usa.
  - **Administrador**: otra ventana con `admin`, para abrir la caja y autorizar cancelaciones.
  - **Cliente**: una ventana privada, para pedir por la web o por el QR de una mesa.
- [ ] El Administrador abre la caja del día (Caja > "Abrir turno"). La sección 8 empieza probando sin caja.
- [ ] Permite las ventanas emergentes del sitio, para que se abran las comandas y los comprobantes.
- [ ] Precios del sembrado:

  | Producto | Precio |
  |---|---|
  | Hamburguesa Clásica Personal | $18.000 |
  | Gaseosa 400ml | $5.000 |
  | Combo Clásico | $21.000 |
  | Domicilio | $2.000 |
  | Depósito de reserva | $20.000 |

## 1. Entrada y navegación (M01 — RF-M01-007/009/010)

- [ ] Iniciar sesión con `mesero` (o `MESERO`) lleva directo a Pedidos, no al sitio del Cliente.
- [ ] El menú tiene Pedidos, Mesas, Reservas, Menú y Cobrar (y Gastos, con permiso; sección 8b):
  - en el teléfono, como barra de pestañas abajo;
  - en la tableta, como riel a la izquierda;
  - en el PC, como barra lateral.
- [ ] El último elemento de cada lista no queda tapado por la barra de abajo.
- [ ] "Mi perfil" abre el perfil (datos, contraseña, sesiones) con el botón "Mi panel" para volver.
- [ ] "Cerrar sesión" lleva al inicio de sesión, y el botón atrás del navegador no vuelve a abrir el panel.
- [ ] Con la sesión del Mesero:
  - `/admin`, `/admin/reportes` y `/admin/usuarios` no se abren (lleva al Inicio);
  - `/api/usuarios`, `/api/reportes/ventas-totales`, `/api/auditorias` y `/api/caja/abrir` responden 403.
- [ ] Si el Administrador desactiva la cuenta del Mesero o le cierra las sesiones, en su siguiente acción la app lo saca al inicio de sesión.

## 2. Pedidos (M02 y M07 — RF-M02-L-003 a 012, RF-M07-011 a 025, RN-PED-06 a 11, RN-M07-05)

- [ ] Filtros:
  - "Activos" (con el número), "Finalizados" y "Cancelados";
  - por canal: Todos, Local, Domicilio y Recogida.
  
  Sin pedidos: "No hay pedidos en este filtro".
- [ ] Cada tarjeta muestra a quién es (mesa o cliente), el número, el canal, la hora, las líneas, el estado y el total. Al tocarla se abre el detalle.
- [ ] **Corregido en esta revisión.** Con Pedidos abierto y sin tocar nada, el Cliente hace un pedido por la web o por el QR:
  - en máximo 20 segundos aparece en Activos, sin "Cargando…";
  - sale el aviso azul "Llegó 1 pedido nuevo." con "Entendido".
- [ ] Mientras está abierta la ventana de editar, descuento, historial o cancelar, la lista no se mueve; al cerrarla se pone al día.
- [ ] "Actualizar" recarga la lista a mano.
- [ ] Con un corte de internet, la actualización automática no muestra errores; vuelve sola al regresar la conexión.
- [ ] **Crear pedido** desde Nuevo pedido (27/10/2026): el pedido queda Confirmado de una vez, la lista dice
      «Pedido #N creado: la comanda ya va para la cocina» y la comanda sale en la impresora de la cocina
      (o se abre en este equipo si tiene «Abre la comanda al confirmar»).
- [ ] **Confirmar pedido** (pendiente → confirmado), para los que llegan por el QR o la web: se abre la comanda de cocina (sección 4).
  - Un domicilio o una recogida con pago en línea sin aprobar no se deja confirmar, con el mensaje en español y sin códigos como "(RN-M06-05)".
- [ ] **Marcar preparando**: cada línea muestra una casilla para marcarla como preparada.
  - Una línea preparada ya no se puede cambiar ni quitar al editar.
  - "Para finalizar falta: …" dice qué falta (cobrar $…, confirmar la entrega o confirmar la recogida).
- [ ] **Domicilio**: "Confirmar entrega" en preparación. Luego se cobra en Cobrar. Al pagarlo completo, se finaliza solo.
- [ ] **Recogida**:
  - "Listo para recoger" avisa al cliente por correo y dice "Aviso enviado a … a las …". Sin correo, dice que hay que avisarle por teléfono.
  - "Confirmar recogida" finaliza el pedido si ya estaba pagado.
- [ ] **Local**: al quedar pagado en Cobrar, se finaliza solo. "Finalizar pedido" aparece cuando no falta nada.
- [ ] **Editar pedido** (solo pendiente o confirmado):
  - agregar un producto o un combo, cambiar una cantidad y quitar una línea, con el total recalculado;
  - quitar la única línea pide cancelar el pedido con motivo.
- [ ] **Descuento**:
  - 10 % con motivo baja el total y queda "Descuento manual 10 %" con el motivo y quién lo aplicó;
  - sin motivo, en 0, negativo o con 120 % no deja aplicar;
  - "Ver descuento" en un pedido que ya lo tiene.
- [ ] **Cancelar pedido**:
  - Pendiente: solo pide el motivo.
  - Confirmado o en preparación: además pide la contraseña de un Administrador ("El pedido ya está confirmado: para cancelarlo hace falta la autorización de un Administrador"). Con la clave equivocada no se cancela. Sin escribirla: "Pide a un Administrador que escriba su contraseña.".
- [ ] **Ver historial**: creación, confirmación, comandas enviadas e impresas, cambios con quién y la hora, descuento, pagos y comprobante.
- [ ] **Datos de entrega**:
  - un domicilio muestra nombre, teléfono, dirección, barrio, referencia e indicaciones;
  - una recogida, nombre, teléfono y correo;
  - un pedido web pagado en línea dice "Paga: En línea (Nequi)".
- [ ] **Comprobante**:
  - en un pedido pagado, "Imprimir comprobante FR-…" abre el ticket de 80 mm (local) o el PDF (domicilio o recogida);
  - la segunda vez dice "Reimprimir comprobante" y avisa que saldrá como REIMPRESIÓN.

## 3. Nuevo pedido (M02 y M07 — RF-M02-L-001/002, RF-M07-001, RF-M07-R-001, RF-M04-020/035, RN-M04-05/11)

- [ ] "Nuevo pedido" abre la pantalla completa con tres canales: **Mesa**, **Domicilio** y **Recogida**.
- [ ] **Mesa**: muestra las mesas con su estado. Al elegir una, aparece el menú y "Cambiar" para elegir otra.
- [ ] **Domicilio**:
  - Pide nombre, teléfono, dirección, barrio y punto de referencia. Sin alguno, "Continuar con los productos" no avanza y marca el campo.
  - Un teléfono de menos de 7 dígitos se rechaza.
  - El total suma el domicilio vigente.
- [ ] **Recogida**: pide nombre y teléfono, con el correo opcional ("le avisamos cuando esté listo"). Un correo mal escrito no deja continuar. No suma domicilio.
- [ ] Los productos con foto la muestran en su tarjeta (Fase 7); sin foto, el ícono.
- [ ] "Buscar producto en todo el menú" encuentra sin importar tildes ni mayúsculas ("clasica" encuentra "Clásica"). Sin resultados: "Ningún producto disponible coincide con la búsqueda.".
- [ ] Un producto con tamaños pide la "Presentación". Un tamaño agotado no aparece. Con todos sus
      tamaños agotados (en Menú), el producto ya no sale en Nuevo pedido (Fase 7).
- [ ] Extras:
  - los generales salen con todos los productos que los ofrecen;
  - el específico, solo con su producto.
- [ ] **Corregido en esta revisión.** Pestaña "Combos":
  - cada combo dice lo que incluye ("1 × Hamburguesa Clásica · 1 × Gaseosa 400ml");
  - el panel lo muestra en "Incluye" y deja escribir "Observaciones para cocina";
  - en el carrito, la línea lleva lo que incluye.
- [ ] Un combo con un producto agotado no aparece ("No hay combos disponibles hoy." si no queda ninguno).
- [ ] Agregar la misma configuración dos veces suma la cantidad en una sola línea. Con otra nota, queda en otra línea.
- [ ] El carrito ("Pedido — Mesa 2"):
  - − / + y quitar;
  - la promoción vigente y el domicilio;
  - el Total.
  
  "Crear pedido" vuelve a Pedidos con el pedido pendiente.
- [ ] Doble toque en "Crear pedido" crea un solo pedido.
- [ ] Una mesa Libre pasa a Ocupada con el primer pedido.

## 4. Comandas de cocina (M02 — RN-PED-06/09/17 a 19, RF-M02-T-004 a 007, HU-M02-016/017; detalle en QA-ADMINISTRADOR 9b)

- [ ] Confirmar un pedido abre la comanda de 80 mm en otra pestaña:
  - pedido, mesa o canal, hora y líneas;
  - lo que trae cada combo y las notas;
  - sin precios.
- [ ] Un combo pedido por el QR con nota: la comanda trae "Nota: …" debajo del combo.
- [ ] La franja amarilla de arriba ("N comandas por imprimir") se ve en todas las pantallas del Mesero, menos en Nuevo pedido. "Imprimir" abre cada una y la franja se vacía.
- [ ] "Reimprimir comanda": la copia dice "*** REIMPRESIÓN · COPIA 2 ***".
- [ ] "¿No salió de la impresora? Avisar" la devuelve a la franja como no impresa.
- [ ] "Impresión de comandas en este equipo":
  - apagar "Abrir la comanda al confirmar" deja la comanda en la franja;
  - "Abrir solas las comandas nuevas" (para el equipo de la cocina) abre en menos de 15 segundos las que se confirman desde otro equipo.
- [ ] Editar un pedido con la comanda ya impresa saca la hoja "Cambios al pedido" solo con lo que cambió. Cancelar uno con la comanda impresa saca "PEDIDO CANCELADO".

## 5. Mesas (M05 — RF-M05-003 a 011, RF-M02-L-019/020, RN-M05-04)

- [ ] Arriba dice cuántas mesas hay libres, ocupadas y reservadas. Cada mesa muestra su estado con color.
- [ ] Una mesa ocupada muestra "Cuenta $…" y "Debe $…", o "Pagada".
- [ ] **Abrir mesa** (libre → ocupada, sin pedido) y **Tomar pedido** abren Nuevo pedido con la mesa ya elegida.
- [ ] **Ver cuenta**: lista todos los pedidos de la mesa desde que se ocupó, con el abono del depósito si vino de una reserva y lo que falta por cobrar.
- [ ] **Liberar mesa**:
  - con pedidos activos, se rechaza con un mensaje claro;
  - sin ellos, la mesa queda Libre.
- [ ] **Forzar liberación…**:
  - pide el motivo; sin motivo no deja;
  - "Liberar de todas formas" la libera aunque tenga pedidos activos;
  - queda en la Auditoría con el nombre del Mesero.
- [ ] Una mesa Reservada muestra "Ver reservas · confirmar llegada", que lleva a Reservas.
- [ ] Una mesa liberada y vuelta a ocupar no trae en "Ver cuenta" los pedidos del cliente anterior.

## 6. Reservas (M05 — RF-M05-012 a 016, RF-M05-028 a 034, RN-M05-06/07/09/15 a 19)

- [ ] El listado filtra por estado y por mesa, con páginas "Anterior / Siguiente".
- [ ] **Nueva reserva** (por teléfono):
  - fecha, hora, una mesa libre a esa hora, nombre, teléfono y correo;
  - queda "Registrada por" el Mesero y se abre el depósito manual.
- [ ] Otra reserva de la misma mesa una hora después se rechaza; dos horas y media después, se acepta.
- [ ] **Registrar depósito manual**: solo ofrece efectivo, tarjeta o transferencia.
  - Con la caja cerrada se rechaza ("el depósito entra a la caja del día").
  - Con correo, al cliente le llega la confirmación.
- [ ] **Depósito en línea** de una reserva web: "Confirmar depósito" la confirma; "Rechazar" deja que el cliente lo reintente desde su enlace.
- [ ] **Confirmar llegada**: la reserva queda completada y la mesa Ocupada. El primer pedido de esa mesa trae el abono del depósito.
- [ ] **Marcar no-show**: solo aparece después de la hora más la tolerancia (15 min). El depósito queda retenido.
- [ ] **Cancelar**: pide confirmar. Un depósito pagado se devuelve; si fue en efectivo, sale de la caja del día y pide la caja abierta.
- [ ] El Mesero no ve el botón "Depósitos retenidos" (es del Administrador). `/api/reservas/depositos-retenidos` responde 403.
- [ ] Ningún texto de esta pantalla muestra códigos como "(RN-M05-16)" (corregido en esta revisión).

## 7. Menú del día: agotados y disponibles (M04 — RF-M04-016 a 018/025, RN-M04-11)

- [ ] Busca por nombre, filtra por categoría ("Todas" o una) y usa "Solo agotados" (con el número).
- [ ] Marcar un producto como "Agotado":
  - el aviso dice que quedó marcado como agotado;
  - el Cliente ya no lo ve en el Menú ni en el QR;
  - un pedido con él se rechaza.
- [ ] Agotar solo un tamaño (p. ej. Clásica Familiar): el Cliente sigue viendo la Personal.
- [ ] Agotar los dos tamaños de la Clásica: la pantalla dice "Con todos los tamaños agotados, el
      producto no se ofrece", y la Clásica y el Combo Clásico desaparecen del Menú y del QR (Fase 7).
- [ ] Con el producto agotado, la pantalla dice "Con el producto agotado no se vende ningún tamaño.".
- [ ] Agotar la Gaseosa oculta el Combo Clásico en el Menú, el QR y Nuevo pedido. Al volver a "Disponible", reaparece.
- [ ] Cada cambio queda en la Auditoría con el nombre del Mesero.

## 8. Cobrar (M06 — RF-M06-003 a 005/009 a 011, RN-M06-02 a 06, RF-M02-L-019/020)

- [ ] **Estado de la caja** (Fase 7, tarea 4 — RF-M06-023, HU-M06-009):
      - sin abrir, la franja amarilla dice "La caja de hoy no está abierta" y los botones "Cobrar" y
        "Cobrar mesa" están apagados;
      - cuando el Administrador la abre, al volver a entrar a Cobrar la franja pasa a verde con
        "Caja abierta desde las … · la abrió …" y el efectivo esperado;
      - después de un cobro en efectivo, el efectivo esperado sube en ese valor;
      - con la caja cerrada, la franja dice "ya se cerró".
- [ ] Si de todas formas se intenta cobrar sin caja (por ejemplo, con otra pestaña abierta desde
      antes), el mensaje dice "No hay un turno de caja abierto para registrar el cobro.", sin el
      código de la regla.
- [ ] **Pagos en línea por revisar** (modo manual): cada pago muestra el pedido, Nequi o Bancolombia y el monto.
  - "Aprobar pago" deja confirmar el pedido. "Rechazar" deja que el cliente lo reintente.
  - Con Wompi dice "En proceso en Wompi · se confirma solo", con "Aprobar a mano" y "Rechazar a mano" de respaldo.
- [ ] **Mesas por cobrar**: cada mesa con pedidos por pagar muestra cuántos pedidos y el saldo. "Cobrar mesa" con una parte en efectivo y el resto con tarjeta:
  - deja la mesa pagada y libre;
  - los pedidos quedan finalizados;
  - cada uno tiene su comprobante.
- [ ] **Domicilios y recogidas por cobrar**: un domicilio en preparación muestra "Confirmar entrega" antes del cobro; antes de la preparación dice que se cobra al confirmar la entrega.
- [ ] Ventana "Registrar cobro":
  - saldo pendiente y método (Efectivo, Tarjeta o Transferencia);
  - el monto viene con el saldo;
  - en 0, "Ingresa un monto mayor a 0.".
- [ ] Un monto mayor que el saldo se rechaza con "La suma de los pagos superaría el total del pedido.".
- [ ] Un pago mixto (parte en efectivo y parte en tarjeta) deja dos pagos, y el pedido queda pagado con el segundo.
- [ ] Al pagar completo, el aviso dice "Cobro registrado. … quedó finalizado." y aparece "Imprimir comprobante FR-…". Si falta algo (p. ej. confirmar la recogida), dice "Pago completo. … Hazlo en Pedidos.".
- [ ] Sin nada pendiente: "No hay pedidos pendientes de cobro · Todo al día por ahora.".
- [ ] El Mesero no puede abrir ni cerrar la caja, ni anular pagos: eso es del Administrador.

## 8b. Gastos, con permiso (M03 — Fase 7, tarea 2: RNF-TRANS-012, RF-M03-32, HU-M03-01 a 03)

Solo para un Mesero al que el Administrador le marcó «Puede registrar compras y gastos» en
Usuarios.

- [ ] Sin el permiso no hay pestaña «Gastos», y `/mesero/gastos` lleva a Pedidos. En la pestaña
      Red, `/api/compras-gastos` responde 403.
- [ ] Con el permiso, al recargar la página aparece «Gastos». En el teléfono, la barra de abajo
      tiene 6 pestañas sin cortarse.
- [ ] "Registrar" un gasto "Transporte" de $12.000 en efectivo, con la foto del recibo:
      - aparece en la lista con "Ver soporte";
      - el efectivo esperado de la caja baja $12.000 (verlo con el Administrador en Caja).
- [ ] Registrar uno sin recibo y luego "Adjuntar foto o PDF del recibo": queda con "Ver soporte".
      Un archivo Word se rechaza con un mensaje claro.
- [ ] La lista muestra solo lo que registró este Mesero, no lo del Administrador ni lo de otros.
- [ ] No hay botones para corregir ni anular. Si el Administrador anula uno, sale "Anulado por el
      Administrador: <motivo>".
- [ ] Con una fecha de un día ya cerrado: "ya tiene cierre: pídele al Administrador que lo
      registre". Una fecha futura no se puede elegir.
- [ ] En la pestaña Red, `/api/cierres-diarios` y anular (`/api/compras-gastos/{id}/anular`)
      responden 403.

## 8b-bis. Permisos de menú (M09, 20/10/2026)

- [ ] Si el SuperAdmin le quitó menús a esta cuenta, solo aparecen los habilitados (Pedidos siempre) y
      los botones que llevan a los quitados no se muestran (por ejemplo, cobrar desde Mesas).

## 8b-ter. Notificaciones (M11, 21/10/2026)

- [ ] En la barra de arriba está la campana. Un pedido nuevo por el QR o la web, una reserva nueva o
      que empieza en 30 min, un pedido listo para recoger, un producto agotado por otro y una comanda que
      no salió aparecen ahí con el contador. Tocar uno abre su pantalla (Pedidos, Reservas o Menú).
- [ ] Lo que hace este Mesero (por ejemplo, marcar un agotado) no le llega a él mismo. Los avisos de
      caja y del sistema no le llegan.

## 8b-quater. Revisar un pago por transferencia (22/10/2026)

- [ ] Cuando un cliente sube la captura, la campana dice «Comprobante por revisar · pedido #…». En Cobrar,
      el pago sale con «Captura adjunta» y el botón «Revisar comprobante».
- [ ] La ventana muestra el valor, el medio, la referencia y la captura (o el PDF). Antes de aprobar,
      revisar en la app del banco que el dinero llegó.
- [ ] «Rechazar» pide el motivo (hay motivos rápidos); sin motivo no deja. El cliente lo ve en su pedido.
- [ ] En Reservas, el depósito dice «comprobante por revisar» y se revisa igual («Confirmar depósito»).

## 8b-quinquies. Impresión en este equipo (M12, 24/10/2026)

- [ ] En Pedidos, «Impresión de comandas en este equipo»: con el código que da el Administrador en
      Impresoras, «Vincular»; ahí sale el nombre del equipo y sus ajustes (ya no se cambian aquí).
- [ ] «Imprimir prueba» y responder cómo salió.

## 8b-sexies. Mejoras del 26/10/2026

- [ ] Nuevo pedido en una mesa ocupada: sale «Continuar la cuenta 1» o «Nueva cuenta separada (cuenta 2)».
      Con la cuenta 2, la barra dice «Mesa X · Cuenta 2» y la comanda también.
- [ ] Ver cuenta de esa mesa: los pedidos salen por cuenta; «¿Qué cuenta paga?» cobra solo la elegida (o
      «Toda la mesa»). La mesa se libera cuando todas quedan finalizadas.
- [ ] Cobrar por transferencia sin foto: «Toma la foto del comprobante de la transferencia». «Tomar foto del
      comprobante» abre la cámara trasera; con la foto, el cobro se registra. En tarjeta la foto es opcional.
- [ ] Un domicilio contra entrega muestra con cuánto paga el cliente y el cambio que hay que llevar, y «Ver
      la ubicación en el mapa» si la compartió.
- [ ] En el teléfono, el botón de descarga de arriba instala la app (o muestra los pasos del navegador).
- [ ] Cobrar en efectivo (un pedido o la cuenta de la mesa): «¿Con cuánto paga el cliente?» con «Exacto» y
      billetes sugeridos; al tocar uno (o escribir el valor) se ve a cobrar, recibe y el cambio. Con menos
      del monto dice cuánto falta y no deja registrar. Al registrar, el aviso dice el cambio a entregar.
- [ ] Reservas > «Registrar depósito manual»: en efectivo hay que indicar con cuánto pagó (sin eso no
      registra) y se ve el cambio; en transferencia hay que tomar la foto; en tarjeta es opcional. Después,
      la reserva tiene «Ver comprobante».

## 8c. Correcciones del 19/10/2026

- [ ] Con una mesa dada de baja por el Administrador: no aparece en Mesas, ni en Nuevo pedido, ni en
      el filtro de Reservas.
- [ ] «Nueva reserva»: la hora es una lista de 4:00 p. m. a 11:00 p. m.; las que están a menos de 3
      horas salen «no disponible». Hay campo «Descripción (opcional)».
- [ ] La descripción que dejó el cliente se ve en la tarjeta de su reserva.

## 9. Un turno completo

- [ ] Hacer el recorrido de `PRUEBA-DIA-COMPLETO.md` con el Mesero en el teléfono o la tableta. Al final, el efectivo esperado de la caja ($142.000) y las ventas del día ($125.000) deben cuadrar.

## 10. Transversal

- [ ] Cada pantalla en el teléfono (~390 px) y en la tableta (~800 px), en vertical y horizontal:
  - sin scroll horizontal;
  - botones fáciles de tocar ("Crear pedido", "Registrar cobro" y "Confirmar pedido" siempre visibles).
- [ ] Ningún mensaje muestra códigos de requisitos ni textos en inglés:
  - "Too Many Attempts." ahora dice "Hiciste demasiados intentos seguidos…";
  - "This action is unauthorized." ahora dice "No tienes permiso para hacer esto.".
- [ ] Sin internet, las acciones dicen "No pudimos conectarnos. Revisa tu conexión a internet e intenta de nuevo.".
- [ ] Con dos Meseros a la vez, en dos equipos:
  - el pedido que confirma uno le aparece confirmado al otro en la siguiente actualización (20 segundos);
  - si el otro intenta confirmarlo de nuevo, recibe un mensaje claro, no un error en blanco.
