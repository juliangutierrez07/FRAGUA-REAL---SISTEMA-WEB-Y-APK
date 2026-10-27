# Prueba de un día completo de operación — Fragua Real Burger

Cierre del plan de implementación (13/10/2026). Es un recorrido de un día del restaurante, en orden,
con los tres roles y los cuatro canales (local, QR de mesa, domicilio y recogida), más una reserva,
compras y gastos y los dos cierres. Sirve para comprobar que todo encaja y que **las cifras cuadran
de punta a punta**.

El mismo recorrido está automatizado en `backend/tests/Feature/DiaCompletoTest.php` (con otros precios)
y pasa en SQLite y en PostgreSQL. Esta guía es para hacerlo a mano en el navegador antes de abrir al
público.

## Antes de empezar

- Usar una **base de datos aparte** para no mezclar las cifras con los datos que ya tienes. Por ejemplo,
  crear una base `fragua_prueba` en PostgreSQL, ponerla en `DB_DATABASE` del `.env` del backend y
  correr `php artisan migrate --seed`. Al terminar, volver a poner tu base de siempre.
- Correr el backend (`php artisan serve`), la cola y el programador
  (`php artisan queue:work` y `php artisan schedule:work`) y el frontend (`npm run dev`).
- Cuentas del sembrado (contraseña `password`): `admin`, `mesero` y `cliente`. Conviene abrir cada
  rol en un navegador o ventana privada distinta.
- Sin promociones automáticas activas (el sembrado no trae ninguna). Domicilio $2.000 y depósito de
  reserva $20.000 (valores del sembrado).
- Permitir las ventanas emergentes del sitio, para que se abran las comandas y los comprobantes.
- Precios que se usan: **Hamburguesa Clásica (Personal) $18.000** y **Gaseosa 400ml $5.000**.

## Las cifras que deben salir al final

| Movimiento | Canal y forma de pago | Valor |
|---|---|---|
| Mesa 1: 2 Clásicas + 1 Gaseosa | Local, efectivo | $41.000 |
| Mesa 3 por QR: 1 Clásica | QR, tarjeta | $18.000 |
| Domicilio de invitado: 1 Clásica + domicilio | Domicilio, efectivo contra entrega | $20.000 |
| Recogida del cliente: 2 Gaseosas | Recogida, en línea | $10.000 |
| Mesa 2 (reserva): 2 Clásicas | Local: $20.000 del depósito + $16.000 en efectivo | $36.000 |
| **Ventas del día** | | **$125.000** |
| Compra (Alimentos) | Efectivo | $30.000 |
| Gasto (Transporte) | Efectivo | $5.000 |
| **Resultado operativo** | 125.000 − 30.000 − 5.000 | **$90.000** |
| **Efectivo esperado en caja** | 100.000 + 41.000 + 20.000 + 16.000 − 30.000 − 5.000 | **$142.000** |

Ventas por forma de pago: efectivo $77.000 · tarjeta $18.000 · en línea $10.000 · depósito de
reserva $20.000. Por tipo: alimentos $108.000 · bebidas $15.000 · domicilio $2.000.

## El recorrido

### 1. Apertura (Administrador)
- [ ] Caja > "Abrir turno" con base inicial **$100.000**. Un segundo intento de abrir dice que ya hay
      un turno abierto.
- [ ] Configuración > "Tareas automáticas": programador y cola activos.

### 2. Reserva web con depósito (Cliente, sin cuenta)
- [ ] Reservar mesa: Mesa 2, hoy, una hora más tarde que la actual, con nombre, teléfono y correo.
      Queda "Pendiente de pago" y muestra el depósito de $20.000.
- [ ] "Pagar depósito con Nequi". En modo manual dice que el pago quedó registrado y se está revisando.
- [ ] Con el Mesero, en Reservas, "Confirmar depósito": la reserva queda confirmada y al correo de la
      clienta le llega la confirmación con el enlace de su reserva.

### 3. Pedido local en la Mesa 1 (Mesero)
- [ ] Nuevo pedido > Mesa 1 > 2 Hamburguesas Clásicas (Personal) y 1 Gaseosa. Total **$41.000**. La
      Mesa 1 pasa a Ocupada.
- [ ] Al crearlo queda Confirmado (desde el 27/10/2026): la comanda de cocina (sin precios) sale sola en la
      impresora de la cocina, o se imprime desde la franja «Comandas por imprimir».
- [ ] "Marcar preparando" y, en Cobrar, cobrar **$41.000 en efectivo**: el pedido queda Finalizado y
      sale "Imprimir comprobante FR-000001" (ticket de 80 mm).

### 4. Pedido por QR en la Mesa 3 (Cliente en la mesa)
- [ ] Administrador > Mesas > Mesa 3 > QR: abrir el enlace del QR en otra ventana (sin sesión), pedir
      1 Clásica (Personal) y enviarlo. Queda pendiente.
- [ ] Con el Mesero: confirmar (sale la comanda), "Marcar preparando" y cobrar **$18.000 con tarjeta**.
      Comprobante FR-000002.

### 5. Domicilio de un invitado, contra entrega (Cliente, sin cuenta)
- [ ] Menú > 1 Clásica (Personal) > Checkout > Domicilio, con nombre, teléfono, correo, dirección,
      barrio y referencia; pago contra entrega. Total **$20.000** (con los $2.000 del domicilio).
      Guardar el enlace de seguimiento.
- [ ] Con el Mesero: confirmar (comanda), "Marcar preparando". En Cobrar, el domicilio pide primero
      confirmar la entrega.
- [ ] "Confirmar entrega" y cobrar **$20.000 en efectivo**: finalizado. Al correo del invitado le
      llega el comprobante en PDF (FR-000003), y también se descarga desde su enlace de seguimiento.

### 6. Recogida de un cliente con cuenta, pagada en línea (Cliente)
- [ ] Entrar como `cliente`. Menú > 2 Gaseosas > Checkout > Recogida, pago en línea con Nequi. Total
      **$10.000**. En modo manual queda "en revisión".
- [ ] Con el Mesero: "Confirmar pedido" no deja confirmar todavía (falta aprobar el pago). En Cobrar,
      "Aprobar pago". Ahora sí confirmar (comanda) y "Marcar preparando".
- [ ] "Listo para recoger": al correo del cliente le llega el aviso. En Mis pedidos se ve "Listo para
      recoger".
- [ ] "Confirmar recogida": el pedido se finaliza solo, porque ya estaba pagado. Comprobante FR-000004
      en Mis pedidos.

### 7. Llega la reserva (Mesero)
- [ ] Reservas > la de la Mesa 2 > "Confirmar llegada": la reserva queda completada y la Mesa 2 ocupada.
- [ ] Nuevo pedido > Mesa 2 > 2 Clásicas (Personal) = $36.000. Sale confirmado con su comanda; "Marcar preparando".
- [ ] Mesas > Mesa 2 > "Ver cuenta": dice $36.000 con un abono de depósito de $20.000, y **$16.000 por
      cobrar**. Cobrar $16.000 en efectivo: el pedido se finaliza (comprobante FR-000005) y la mesa
      queda libre.

### 8. Compras y gastos (Administrador)
- [ ] Compras y gastos > nueva **compra** "Alimentos", "Carne y pan", **$30.000 en efectivo**, fecha de hoy.
- [ ] Nuevo **gasto** "Transporte", "Domiciliario", **$5.000 en efectivo**.

### 9. Un pedido que se cancela (Mesero y Administrador)
- [ ] Nuevo pedido > Mesa 1 > 1 Gaseosa. Sale confirmado (comanda impresa); "Marcar preparando".
- [ ] Cancelar con el motivo "El cliente se fue": como ya está en preparación, pide la contraseña de un
      Administrador. Con ella, queda cancelado y la franja amarilla muestra la comanda de cancelación
      por imprimir. Imprimirla: dice "PEDIDO CANCELADO".
- [ ] Este pedido no suma en ventas ni en caja, y las tres mesas quedan libres.

### 10. Cierre de caja (Administrador)
- [ ] Caja > turno de hoy: el efectivo esperado es **$142.000**.
- [ ] Cerrar la caja contando $142.000: cierra sin descuadre. Con otra cifra, pide justificar la
      diferencia (puedes probarlo en otra pasada).

### 11. Cierre del día (Administrador)
- [ ] Compras y gastos > Cierre diario (hoy), en la vista previa:
  - ventas $125.000;
  - compras $30.000;
  - gastos $5.000;
  - resultado operativo $90.000;
  - por forma de pago: efectivo $77.000, tarjeta $18.000, en línea $10.000, depósito de reserva
    $20.000.
- [ ] "Cerrar el día" y "Imprimir / PDF del cierre": el PDF trae los mismos totales y el arqueo
      ($142.000 esperado, $142.000 contado).

### 12. Reportes y revisión final (Administrador)
- [ ] Reportes con el periodo de hoy:
  - Ventas totales: $125.000 en 5 pedidos.
  - Ventas por producto: $123.000, porque los $2.000 del domicilio no son un producto.
  - Resultado operativo: $90.000.
  - Uso de mesas: 3 visitas.
- [ ] Exportar Ventas totales a Excel y a PDF: mismas cifras.
- [ ] Dashboard: las ventas del día coinciden.
- [ ] Pedidos > cualquier pedido > "Ver historial": creación, confirmación, comanda enviada e impresa,
      pagos y comprobante, con quién y cuándo.
- [ ] Auditoría (hoy):
  - apertura y cierre de caja;
  - confirmación del depósito y del pago en línea;
  - impresiones de comandas y comprobantes;
  - la cancelación con el Administrador que autorizó;
  - el cierre del día.
- [ ] La franja de comandas por imprimir quedó vacía. Pagos en línea por revisar: ninguno.

## Si algo no cuadra

Anota en qué paso, qué cifra esperabas y cuál salió. El historial del pedido y la Auditoría casi
siempre muestran de dónde viene la diferencia (un pago anulado, un descuento, un pedido cancelado
después de cobrado).
