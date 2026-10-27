# QA del Cliente y del invitado — lista de pruebas por pantalla

Esta lista revisa a mano todo lo que ve una persona que pide o reserva:

- con cuenta de Cliente;
- sin cuenta (invitado);
- desde el QR de una mesa.

Es la pareja de `QA-ADMINISTRADOR.md` y `QA-MESERO.md`, con el mismo formato: marca cada casilla al probarla y anota la fecha. Cada sección cita el requisito oficial en el que se apoya.

Al preparar la lista se revisó el código pantalla por pantalla y aparecieron cinco problemas que ya quedaron corregidos (ver «Revisión por rol» en `README-BACKEND.md`, punto 45):

- El Cliente no podía pedir combos ni en la web ni por QR.
- Desde Mis pedidos se podía cancelar una reserva con depósito en efectivo.
- El enlace de seguimiento traía el teléfono y el correo del cliente.
- Algunos mensajes salían en inglés o con el código de la regla al final.
- Después de crear la cuenta desde el Checkout, el Cliente no volvía a su pedido.

## 0. Antes de empezar

- [ ] Prepara el entorno:
  - Backend y frontend corriendo.
  - Base de datos con `php artisan migrate --seed`.
  - La cola (`php artisan queue:work`) y el programador (`php artisan schedule:work`).
- [ ] Abre tres ventanas:
  - **Invitado**: una ventana privada, sin sesión.
  - **Cliente**: `cliente@fraguareal.com` (o el usuario `cliente`), contraseña `password`.
  - **Personal**: otra ventana con el Mesero (`mesero`) y, cuando haga falta, el Administrador (`admin`).
- [ ] El Administrador abre la caja del día (Caja > "Abrir turno"). Sin caja abierta, el Mesero no puede cobrar.
- [ ] Para los correos, usa direcciones a las que tengas acceso. Con `MAIL_MAILER=log`, los correos quedan en `backend/storage/logs/laravel.log`.
- [ ] Permite las ventanas emergentes del sitio, para que se abran los comprobantes.
- [ ] Precios del sembrado:

  | Producto | Precio |
  |---|---|
  | Hamburguesa Clásica Personal | $18.000 |
  | Hamburguesa Clásica Familiar | $32.000 |
  | Gaseosa 400ml | $5.000 |
  | Combo Clásico (Clásica + Gaseosa) | $21.000 |
  | Domicilio | $2.000 |
  | Depósito de reserva | $20.000 |

## 1. Inicio y navegación (M00, RF-M04-020)

- [ ] El Inicio carga con:
  - el texto principal;
  - "Ver el menú" y "Reservar mesa";
  - las categorías y algunos productos;
  - la dirección, el teléfono y "Escribir por WhatsApp" (abre WhatsApp con el número del local);
  - si el SuperAdmin marcó el punto del local (M09), un mapa pequeño con el marcador dorado debajo del
    contacto. El mapa no se mueve al pasar el dedo para bajar la página; con los botones + y − se acerca.
- [ ] **Ofertas y combos de hoy (21/10/2026).** Si hay promociones vigentes o combos que se pueden pedir hoy, debajo del encabezado sale «Aprovecha hoy.» con cada uno: la etiqueta (−15 %, Combo), el precio de antes tachado y el de hoy. «Agregar» lo pone en el pedido (el botón dice «Listo» y sube el número de «Mi pedido»); un producto con tamaños dice «Elegir» y lleva al Menú. En el teléfono las tarjetas se deslizan de lado. Sin ofertas ni combos, la sección no aparece.
- [ ] Los precios de «Empieza por un buen antojo» se leen como «$ 28.000» (antes salía «Desde 5000.00»); «Desde» solo aparece si el producto tiene tamaños, con el precio del más barato. Los destacados son productos disponibles, primero los que tienen foto.
- [ ] «Cómo llegar» (en Contacto y en el pie de página) abre Google Maps justo en el punto marcado; sin
      punto, busca la dirección escrita.
- [ ] El menú de arriba lleva a Inicio, Menú y Reservar. "Contacto" baja a la sección de contacto.
- [ ] En el teléfono (~390 px) el menú se abre con el botón de las tres rayas y no hay scroll horizontal en ninguna pantalla.
- [ ] Tocar una categoría del Inicio abre el Menú en esa categoría.
- [ ] Una dirección que no existe (p. ej. `/no-existe`) muestra "Por aquí no hay antojo" con "Volver al inicio".
- [ ] Todo el texto está en español de Colombia: nada de "Contanos" ni de mensajes en inglés.

## 2. Menú (M04 — RF-M04-016 a 020, RF-M04-031/035, RN-M04-05/08/11/16)

- [ ] Las categorías salen como pestañas. Cada una muestra sus productos con "Desde $…".
- [ ] Fase 7: un producto con foto (Administrador > Catálogo) la muestra en su tarjeta y en "Tu
      elección". Sin foto, sale el ícono. En el teléfono las fotos cargan rápido y no deforman la
      tarjeta.
- [ ] Un producto agotado o de una categoría desactivada no aparece. Con el Mesero, en Menú, agota la Gaseosa: al recargar, el Cliente ya no la ve.
- [ ] Con el Mesero, agota los dos tamaños de la Hamburguesa Clásica: al recargar, la Clásica y el
      Combo Clásico ya no aparecen (Fase 7). Vuelve a dejarlos disponibles.
- [ ] Elegir la Hamburguesa Clásica abre "Tu elección" (en el teléfono, un panel que sube desde abajo):
  - aparecen Personal y Familiar;
  - cambiar a Familiar cambia el precio del botón;
  - cerrar con la X no agrega nada.
- [ ] Extras: marcar "Extra queso" suma $2.000 al precio del botón. La Salchipapa Especial ofrece además "Doble carne desmechada" (extra propio); los otros productos no.
- [ ] La nota ("¿Quieres quitar algún ingrediente?") y la cantidad (− / +) se guardan con la línea. La cantidad no baja de 1.
- [ ] "Agregar a mi pedido" muestra la barra de abajo: "Mi pedido · N productos", el total y "Ver mi pedido →".
- [ ] **Combos (corregido en esta revisión).** Hay una pestaña "Combos" al final de las categorías:
  - el Combo Clásico dice "Incluye 1 × Hamburguesa Clásica · 1 × Gaseosa 400ml" y $21.000;
  - al elegirlo, el panel muestra lo que incluye, la nota y la cantidad, sin tamaños ni extras;
  - se agrega al pedido como una línea.
- [ ] `/menu?categoria=combos` abre directamente la pestaña Combos.
- [ ] Con el Mesero, agota la Gaseosa: el Combo Clásico desaparece de la pestaña. Al ponerla disponible de nuevo, vuelve.
- [ ] Un combo fuera de su vigencia (fechas, franja u horario que pone el Administrador en Catálogo) no aparece.
- [ ] Con una promoción automática vigente sobre un producto (Administrador > Catálogo > Promociones):
  - la tarjeta muestra la etiqueta (p. ej. "−20 %");
  - se ve el precio tachado y el precio rebajado.

## 3. Carrito (M02 — RF-M02-L-001, RF-M04-035)

- [ ] Sin nada agregado: "Aún no has agregado nada" con "Ver el menú".
- [ ] Cada línea muestra el nombre, el tamaño, los extras, la nota entre comillas y su valor. Un combo además dice lo que incluye.
- [ ] − / + cambia la cantidad y el valor de la línea. "Quitar" la elimina.
- [ ] Con una promoción vigente, aparece la línea de la promoción con el descuento, y el Total ya la descuenta.
- [ ] "Continuar" lleva al Checkout.
- [ ] Recargar la página vacía el carrito. Esto es esperado: el carrito no se guarda en el navegador.

## 4. Checkout del invitado (M07 — RF-M07-001 a 010, RF-M07-R-001, RN-M07-02, RNF-M02-006)

En la ventana del invitado:

- [ ] Arriba dice "Pides como invitado" con el enlace "Inicia sesión".
- [ ] **Domicilio**:
  - Pide dirección, barrio y punto de referencia, además de nombre y teléfono. Sin alguno de ellos, el campo se marca y no deja confirmar.
  - El resumen suma el domicilio ($2.000).
- [ ] **Recogida**:
  - No pide dirección.
  - El correo es opcional y dice "Te avisamos por correo cuando tu pedido esté listo para recoger".
  - Un correo mal escrito muestra el error. Dejarlo vacío se acepta.
- [ ] Un teléfono con letras o muy corto se rechaza con un mensaje claro.
- [ ] Las indicaciones (opcionales) aceptan texto como "apartamento 302, llamar al llegar". Con más de 500 caracteres se rechazan.
- [ ] El resumen (subtotal, promoción, domicilio y total) coincide con el carrito.
- [ ] Con "Pago contra entrega" ("Pago al recoger" en una recogida), "Confirmar pedido" muestra:
  - "¡Tu pedido quedó registrado!" con el número, el canal y el total;
  - el **enlace de seguimiento**, con el aviso "Guárdalo: sin cuenta, es la única forma de ver el estado o cancelarlo".
- [ ] Copia el enlace y ábrelo en otra pestaña: muestra el pedido (sección 5).
- [ ] Con el Mesero: el pedido aparece en Pedidos > Activos **sin recargar** (en máximo 20 segundos) con el aviso "Llegó 1 pedido nuevo." (corregido en esta revisión).
- [ ] Doble clic rápido en "Confirmar pedido" crea un solo pedido (el botón queda en "Confirmando…").
- [ ] Un pedido con un producto que se agotó mientras estaba en el carrito: el Checkout muestra el error en español y no crea el pedido.

## 5. Seguimiento del invitado (M07 — RF-M07-027, RN-M07-R-03, RF-M06-008/010)

- [ ] Recién creado dice "Recibimos tu pedido…" y la barra de avance está en el primer paso.
- [ ] Con el Mesero, confirma el pedido. En máximo 30 segundos y **sin recargar**, pasa a "Tu pedido está confirmado y entra a cocina". Luego sigue con "Marcar preparando".
- [ ] Domicilio en preparación: "Estamos preparando tu pedido; te lo llevamos pronto".
- [ ] Recogida:
  - en preparación, dice "Te avisamos cuando esté listo para recoger";
  - cuando el Mesero pulsa "Listo para recoger", dice "¡Tu pedido está listo! Pasa a recogerlo en el local." y llega el correo (si lo dejó).
- [ ] Al finalizar: "¡Gracias! Tu pedido está completo", y aparece el botón del comprobante en PDF.
- [ ] El PDF trae los datos del pedido, la dirección (en domicilio) y los pagos. No dice "REIMPRESIÓN". Descargarlo otra vez no suma impresiones.
- [ ] "Cancelar mi pedido":
  - solo aparece mientras está pendiente;
  - pide el motivo (sin motivo no deja cancelar);
  - queda "Este pedido fue cancelado: <motivo>".
- [ ] Ya confirmado, el botón de cancelar no aparece.
- [ ] Un enlace inventado o cortado muestra "No encontramos ese pedido".
- [ ] **Corregido en esta revisión.** El seguimiento no trae el teléfono ni el correo del cliente, ni
      quién del personal hizo cada paso: tampoco en la pestaña Red (`/api/pedidos/seguimiento/…`). El
      enlace se puede reenviar a quien pasa a recoger sin exponer esos datos.

## 6. Pago en línea (M06 — RF-M06-006 a 008, RN-M06-05; Wompi en el punto 6c de QA-ADMINISTRADOR)

**Modo manual** (sin llaves de Wompi o con `PASARELA_PROVEEDOR=manual`):

- [ ] Checkout > Recogida > "Pago en línea", con Nequi: "Ya registramos tu pago…".
- [ ] En el seguimiento dice "Tu pago en línea está en revisión".
- [ ] Con el Mesero, "Confirmar pedido" no deja confirmar todavía. En Cobrar > "Pagos en línea por revisar", "Aprobar pago": ya se puede confirmar y el pedido tiene comprobante.
- [ ] Si el Mesero lo rechaza: en el seguimiento aparece "Pagar en línea" para intentarlo de nuevo sobre el mismo pedido, o "También puedes pagar al recoger".

**Con Wompi en pruebas** (llaves del sandbox):

- [ ] El Checkout lleva a la página de Wompi y, al terminar, vuelve a `/pago/retorno/FRP-…`.
- [ ] Pago aprobado: "Pago aprobado", con el monto, el medio y la referencia, y "Ver mi pedido".
- [ ] Pago rechazado: "La pasarela no aprobó el pago. No se te cobró nada" e "Intentar de nuevo".
- [ ] En proceso (Nequi esperando): "Tu pago está en proceso". La página se actualiza sola.
- [ ] Una referencia inventada en `/pago/retorno/…` muestra "No encontramos ese pago".

## 7. Pedido desde el QR de la mesa (M02 — RF-M02-L-001/002, RF-M05-003, RN-M05-04, RN-PED-02)

Con el Administrador: Mesas > Mesa 3 > QR. Abre el enlace del QR en el teléfono o en la ventana del invitado.

- [ ] La página es simple: logo, "Mesa 3" arriba, sin menú del sitio ni inicio de sesión.
- [ ] Un QR viejo (regenerado en Mesas) o inventado muestra "No pudimos abrir tu mesa" y "Este código QR ya no es válido. Pide ayuda a un mesero."
- [ ] Los productos con foto la muestran en la lista y en la ventana del producto (Fase 7).
- [ ] "¿Qué te provoca?" muestra las categorías. Tocar el + de un producto abre la ventana con:
  - tamaño, extras y la nota "¿Algo que quitar o pedir especial?";
  - la cantidad;
  - "Agregar" con el precio.
- [ ] **Combos (corregido en esta revisión).** Hay una pestaña "Combos":
  - el Combo Clásico muestra lo que incluye;
  - se agrega con su nota;
  - en "Tu pedido" se ve como una línea con lo que incluye.
- [ ] "Ver mi pedido · N" y luego "Enviar pedido a la cocina" muestran:
  - "¡Pedido enviado! Pedido #N para Mesa 3";
  - "Un mesero va a confirmarlo en un momento… Pagas al final".
- [ ] El QR no pide nombre, teléfono ni pago.
- [ ] Con el Mesero:
  - el pedido aparece solo en Pedidos con el aviso de pedido nuevo;
  - la Mesa 3 pasa a Ocupada;
  - al confirmarlo sale la comanda, y la de un combo trae lo que incluye y la nota.
- [ ] "Pedir algo más" vuelve al menú con el carrito vacío. El segundo pedido queda en la misma cuenta de la mesa (Mesero > Mesas > "Ver cuenta").
- [ ] Escanear el QR con algo en el carrito de la web: el carrito empieza vacío (no se mezclan).

## 8. Reservar mesa sin cuenta (M05 — RF-M05-012 a 018, RF-M05-028 a 033, RN-M05-06/09/12/15 a 17)

En la ventana del invitado, Reservar:

- [ ] El texto explica:
  - el depósito de $20.000, que se descuenta de la cuenta el día de la visita;
  - que si cancela a tiempo se le devuelve completo;
  - los 15 minutos de tolerancia, y que si no llega el depósito no se devuelve.
- [ ] Fecha: no deja elegir un día pasado. Al elegir fecha y hora, el selector solo muestra "Mesas libres a la hora que elegiste". Si no hay, dice "No hay mesas libres a esa hora".
- [ ] Sin nombre, teléfono o correo, el campo se marca y no deja continuar.
- [ ] "Continuar y pagar depósito": "Reserva #N creada", con Nequi o Bancolombia y "Pagar depósito con …".
  - En modo manual: "¡Ya casi! Tu depósito quedó registrado".
  - Con Wompi: va a la pasarela.
- [ ] Con el Mesero, en Reservas, "Confirmar depósito" (o con Wompi, al aprobarse solo): llega el correo "Reserva confirmada" con el botón "Ver o cancelar mi reserva".
- [ ] El enlace del correo (en otra ventana privada) muestra:
  - estado, fecha, mesa y depósito ("pagado; se descuenta de tu cuenta el día de tu visita");
  - la tolerancia;
  - nada de los datos de contacto.
- [ ] "Cancelar mi reserva" pide confirmar ("No, mantenerla" / "Sí, cancelar"). Al cancelar:
  - la pantalla dice "Tu reserva quedó cancelada";
  - el depósito dice "se te devuelve completo";
  - llega el correo de cancelación.
- [ ] Una reserva con el depósito pagado en efectivo en el local: el enlace no ofrece cancelar y dice "llama o pasa por el restaurante".
- [ ] El enlace después de la hora de la reserva ya no ofrece cancelar. Un enlace inventado dice "No encontramos esa reserva".
- [ ] Depósito rechazado: el enlace ofrece "Pagar el depósito" ("El pago anterior no se aprobó").

## 9. Cuenta: registro, inicio de sesión y contraseña (M01 — RF-M01-007/011 a 014, RN-USR-04)

- [ ] **Crear cuenta**:
  - Nombre, correo y contraseña son obligatorios; teléfono y nombre de usuario, opcionales.
  - Una contraseña de menos de 8 caracteres, contraseñas distintas o un correo ya usado muestran el error bajo el campo, en español.
  - **Contraseña segura (21/10/2026).** Debajo de la contraseña aparecen los requisitos (8 caracteres, mayúscula, minúscula, número y que coincidan) y se marcan en verde al cumplirse. «hamburguesa2» se rechaza con «debe contener al menos una mayúscula y una minúscula»; «Hamburguesa2026» se acepta.
- [ ] Al crear la cuenta, entra solo y queda en el Inicio. La cuenta es de Cliente (nunca de Mesero).
- [ ] **Corregido en esta revisión.** Desde el Checkout:
  1. pulsa "Inicia sesión" y luego "Crear cuenta";
  2. al terminar de crearla vuelve al Checkout con el carrito intacto.

  Lo mismo con "Inicia sesión": vuelve al Checkout.
- [ ] **Iniciar sesión** con el correo o con el nombre de usuario, que no distingue mayúsculas (`CLIENTE`):
  - Con la contraseña mala: "Usuario o contraseña incorrectos.", el mismo mensaje sea cual sea el error.
  - "Continuar como invitado" lleva al menú sin sesión.
- [ ] **Corregido en esta revisión.** Más de 10 intentos en un minuto muestran "Hiciste demasiados intentos seguidos. Espera un minuto e intenta de nuevo." (antes "Too Many Attempts.").
- [ ] **Bloqueo por intentos (21/10/2026).** Con una cuenta real, 5 contraseñas equivocadas seguidas muestran "Hiciste demasiados intentos fallidos. Por seguridad, espera 15 minutos o usa «¿Olvidaste tu contraseña?»."; durante esos minutos ni la contraseña correcta entra. Recuperar la contraseña por correo quita el bloqueo.
- [ ] Un enlace de inicio de sesión armado con `?redirect=https://otro-sitio.com` o `?redirect=//otro-sitio.com` no saca del sitio: entra al Inicio.
- [ ] **Recuperar contraseña**:
  - Con un correo que existe y con uno que no, el mensaje es el mismo; no revela si la cuenta existe.
  - Llega el correo con el enlace. "Restablecer contraseña" con la nueva lleva al inicio de sesión con "Tu contraseña quedó actualizada".
  - El enlace usado o vencido dice "El token no es válido o expiró.".
- [ ] Con sesión abierta, `/login` y `/registro` llevan al Inicio.

## 10. Cliente con cuenta (M01, M02, M05, M07)

- [ ] **Checkout**:
  - No pide nombre ni correo (los toma de la cuenta).
  - El teléfono viene prellenado con el de la cuenta y se puede cambiar para este pedido.
  - No muestra el enlace de seguimiento, sino "Ver mis pedidos".
- [ ] **Mis pedidos y reservas** (menú de arriba), pestaña Pedidos:
  - Cada pedido muestra número, canal, fecha, estado y total. Al abrirlo, sus líneas (con combos), la dirección y el botón del comprobante si está pagado.
  - Una recogida marcada lista dice "Listo para recoger".
  - Un domicilio o una recogida pendientes muestran "Cancelar pedido". Pide el motivo ("Cuéntanos brevemente por qué cancelas este pedido.").
  - Un pago en línea rechazado o a medias muestra "Pagar con Nequi" o "Pagar con Bancolombia" para reintentarlo sobre el mismo pedido.
- [ ] Pestaña Reservas (las hechas con el correo de la cuenta):
  - estado, mesa, fecha y depósito (con "(reembolsado)" si aplica);
  - "Cancelar reserva" antes de la hora, con "El depósito se reembolsa por completo".
- [ ] **Corregido en esta revisión.** Una reserva registrada por el Mesero con el correo de la cuenta y el depósito en **efectivo**:
  - no muestra "Cancelar reserva", sino "Pagaste el depósito en el local: para cancelarla, llama o pasa por el restaurante.";
  - el servidor también lo rechaza (antes la cancelaba y la caja daba el dinero por devuelto);
  - con el depósito por tarjeta, sí se puede cancelar.
- [ ] Sin pedidos ni reservas: "Todavía no has pedido nada" y "Todavía no tienes reservas", con su botón.
- [ ] **Mi perfil**:
  - Cambiar nombre, teléfono y nombre de usuario: "Tus datos quedaron guardados". Un nombre de usuario repetido o con tilde se rechaza.
  - "Cambiar contraseña" con la actual equivocada se rechaza. Con la correcta: "Tu contraseña se actualizó correctamente" y se cierran las demás sesiones.
  - "Sesiones abiertas": entra también desde el teléfono. La lista muestra los dos dispositivos y "Esta sesión". "Cerrar las demás" saca al teléfono al inicio de sesión.
- [ ] "Cerrar sesión" vuelve al sitio sin sesión. "Mis pedidos" pide iniciar sesión y, al entrar, vuelve a Mis pedidos.

## 11. Lo que el Cliente no puede hacer (RNF-M02-006, RNF-TRANS-010, RF-M01-009)

- [ ] Con la sesión del Cliente, `/admin` y `/mesero` llevan al Inicio. En la pestaña Red, `/api/pedidos/{id}/confirmar` y `/api/usuarios` responden 403.
- [ ] `/api/pedidos/{id}` de un pedido de otro cliente responde 403. Su comprobante tampoco se descarga.
- [ ] Los precios los pone el servidor: editar en la pestaña Red el precio o el total enviado no cambia el total del pedido.
- [ ] Ningún mensaje muestra códigos como "(RN-M06-03)" ni textos técnicos. Un error del servidor (500) dice "Ocurrió un error. Intenta de nuevo." (o el mensaje de esa pantalla).
- [ ] **Corregido en esta revisión.** Sin internet (modo avión en el teléfono), confirmar un pedido dice "No pudimos conectarnos. Revisa tu conexión a internet e intenta de nuevo.".

## 12. Transversal

- [ ] Todas las pantallas de esta lista en el teléfono (~390 px), en vertical: sin scroll horizontal, y los botones "Agregar", "Confirmar pedido" y "Enviar pedido a la cocina" siempre visibles.
- [ ] En el teléfono, el panel de "Tu elección" se cierra con la X o tocando el fondo oscuro.
- [ ] Los montos se ven en pesos colombianos sin decimales ($21.000).
- [ ] Las horas salen en hora de Colombia.

## 13. Mejoras del 16/10/2026

- [ ] Menú en el teléfono: el menú hamburguesa muestra «Ingresar» arriba, como botón.
- [ ] Agregar dos veces el mismo producto sin cambios: el carrito muestra una sola línea con cantidad
      2. Con otra presentación o una nota distinta, son líneas aparte.
- [ ] Recargar la página con productos en el carrito: siguen ahí. En el carrito se ven la foto, la
      presentación, los extras, el precio por unidad y el resumen.
- [ ] Al agregar un producto aparece arriba «Agregado: …» y el botón redondo de abajo muestra la
      cantidad; se esconde mientras el panel del producto está abierto.
- [ ] Una Limonada o un postre no ofrecen «Extra queso» ni otros extras de comida.
- [ ] Reservar: en pasos (cuándo, mesa, datos); las mesas son botones en orden y el depósito con sus
      condiciones se ve al lado (en el teléfono, debajo).
- [ ] Con conexión lenta, el Menú y Reservar muestran la forma de la página mientras cargan y el pie
      de página no sube a la mitad de la pantalla.
- [ ] App instalable (Android, Chrome): menú hamburguesa > «Instalar app» la instala; el ícono de
      Fragua Real queda en la pantalla de inicio y abre en pantalla completa. Al mantenerlo presionado
      aparecen «Ver el menú», «Reservar mesa» y «Mi pedido».
- [ ] App instalable (iPhone, Safari): «Instalar app» muestra los pasos (Compartir > Agregar a inicio)
      y, siguiéndolos, el ícono abre la app en pantalla completa.
- [ ] Con la app instalada y sin internet (modo avión), abrirla muestra «Sin conexión» con
      «Reintentar»; con internet, vuelve a funcionar.
- [ ] El Mesero con sesión abre la app desde el ícono y entra directo a Pedidos.

## 13b. Pagar en línea por transferencia (sin Wompi, 22/10/2026)

Antes: el SuperAdmin puso al menos una cuenta en Configuración > «Cuentas para pagos en línea».

- [ ] Checkout: «Pago en línea» dice los medios configurados (por ejemplo «Nequi, Bancolombia o Bre-B»).
      Al confirmar, la pantalla muestra las cuentas: elegir el medio, «Copiar» copia el número (o la
      llave) y el valor exacto. Sin captura, «Enviar comprobante» pide subirla.
- [ ] Subir un .txt o un archivo de más de 5 MB: dice que debe ser una foto o un PDF de hasta 5 MB.
- [ ] Subir la foto de la transferencia (y, si se quiere, la referencia): dice «Recibimos tu
      comprobante». En el seguimiento (o en «Mis pedidos») se lee «Estamos verificando la transferencia».
- [ ] Si el personal lo rechaza, el seguimiento muestra «Tu pago anterior no se aprobó: …» con el motivo
      y deja volver a enviarlo.
- [ ] Reservar mesa: el depósito se paga igual; en el enlace de la reserva se ve «Recibimos tu
      comprobante» y, si se rechaza, el motivo.
- [ ] Sin ninguna cuenta configurada (y sin Wompi), el Checkout no ofrece pago en línea.

## 13c. Formato de los campos (22/10/2026)

- [ ] Registro, Checkout y Reservar: el teléfono solo acepta 10 números (con el teclado numérico en el
      celular); el nombre no acepta números; el correo no acepta espacios. Pegar «300 123 4567» deja
      «3001234567».

## 13d. Mejoras del 26/10/2026

- [ ] Domicilio: «Usar mi ubicación actual» pide permiso, busca unos segundos y muestra el punto en un mapa;
      si es aproximado (más de 100 m) lo avisa. Tocar el mapa o arrastrar el pin lo corrige («Punto marcado
      por ti»); «Marcar en el mapa» sirve sin GPS. Sin permiso explica qué hacer. La dirección sigue siendo
      obligatoria. Probarlo en el teléfono con el GPS encendido y en el VPS (HTTPS).
- [ ] Pago contra entrega: «¿Con cuánto vas a pagar?» con «Pago exacto», billetes mayores al total y «Otro
      valor»; dice cuánto cambio llevan. Un valor menor al total no deja confirmar.
- [ ] Pago en línea: Nequi, Bancolombia (y Bre-B si está) son tarjetas con ícono y check.
- [ ] Las notas importantes («Pides como invitado…», «Guárdalo…», «Revisamos la transferencia…», el horario
      de las reservas) se ven resaltadas.
- [ ] Seguimiento: al confirmar o preparar el pedido en el panel, la página cambia en unos 10 segundos (o
      apenas se vuelve a la pestaña). «Mis pedidos» también.
- [ ] Mis pedidos y reservas > Reservas: una reserva sin pagar tiene «Pagar el depósito de $…»; al enviar la
      captura queda «Pago en revisión». Si el personal la rechaza, se ve el motivo y se puede pagar otra vez.
      Sin cuentas de pago configuradas, dice que se escriba por WhatsApp.

## 14. Correcciones del 19/10/2026 (reservas)

- [ ] Reservar: la hora es una lista de 4:00 p. m. a 11:00 p. m., cada 30 minutos; debajo dice «Las
      reservas son de 4:00 p. m. a 11:00 p. m., con al menos 3 horas de anticipación».
- [ ] Para hoy, las horas a menos de 3 horas salen «no disponible» y no se pueden elegir; tarde en la
      noche, el primer día que deja elegir es mañana.
- [ ] La «Descripción (opcional)» admite hasta 500 caracteres; aparece en el resumen de la reserva, en
      el enlace de la reserva y en el correo de confirmación. Sin descripción también se puede reservar.

