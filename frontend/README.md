# Frontend — Fragua Real Burger

Aplicación web (SPA) en Vue 3 con TypeScript y Vite que usa la API de Laravel de `../backend`. Tiene
tres zonas, una por rol: la web pública del Cliente, el panel del Mesero y el del Administrador.

**Tecnología**: Vue 3 (`<script setup>`), TypeScript, Vite 6, Vue Router 4, Pinia, Tailwind CSS 3
(paleta de marca `brand`, `ink` y `cream`; títulos en Playfair Display y texto en DM Sans, desde Google Fonts), íconos Lucide,
Axios, `qrcode` y `jspdf` para el QR imprimible de las mesas, y Leaflet con OpenStreetMap para los mapas (ubicación del negocio y del domicilio).

## Correrlo

```bash
npm install
cp .env.example .env    # VITE_API_BASE_URL=/api
npm run dev             # http://localhost:5173
```

En desarrollo, Vite pasa `/api` a `http://localhost:8000` (ver `vite.config.ts`), donde corre
`php artisan serve`.

| Comando | Qué hace |
|---|---|
| `npm run dev` | Servidor de desarrollo |
| `npm run build` | Revisa los tipos (`vue-tsc`) y compila a `dist/`, que es lo que sirve Nginx |
| `npm run type-check` | Solo revisa los tipos |

## Sesión

El inicio de sesión devuelve un token de Sanctum que se guarda en `localStorage` (`stores/auth.ts`) y
viaja en cada petición (`services/api.ts`). Si la API responde 401 (sesión vencida o
cerrada por el Administrador), se borra el token y la aplicación pide entrar de nuevo. El router (`router/index.ts`) protege cada zona por rol y cada pantalla del personal por
su permiso de menú (`meta.menu`, M09: el SuperAdmin puede quitarle menús a una cuenta); la pestaña
Gastos del Mesero además pide el permiso `puede_registrar_gastos`. El SuperAdmin y la cuenta de Soporte
(solo consulta) usan el panel del Administrador.

## Estructura de `src/`

| Carpeta | Contenido |
|---|---|
| `views/cliente/` | Menú, Carrito, Checkout, Mis pedidos, Perfil, Reservar, Pedido por QR de mesa, Seguimiento del invitado, Reserva por enlace y retorno del pago |
| `views/mesero/` | Pedidos, Nuevo pedido, Mesas, Cobrar, Menú (agotados) y Gastos |
| `views/admin/` | Tablero, Pedidos, Catálogo, Mesas, Reservas, Caja, Compras y gastos, Reportes, Usuarios, Auditoría, Configuración e Impresoras |
| `views/auth/` | Iniciar sesión, Registro, Recuperar y Restablecer contraseña |
| `views/` | Inicio y página no encontrada |
| `layouts/` | Un layout por zona: Cliente, Mesero, Administrador y acceso |
| `components/common/` | Botones, campos (`CampoPesos` para dinero), modales, encabezado, barra lateral, QR de mesa, sesiones, botón flotante del pedido (`CarritoFlotante`), pestañas Mesas/Reservas (`PestanasSalon`), eliminar con motivo, logo del negocio, requisitos de la contraseña, campana de notificaciones, «Instalar app», mapa (`MapaUbicacion`) y notas resaltadas (`AvisoDestacado`) |
| `components/pedidos/` | Editar, historial, descuento, cuenta de la mesa (por cuentas separadas), comanda, comandas pendientes, comprobante, totales, datos de entrega (mapa y cambio), listo para recoger |
| `components/pagos/` | Pago en línea (transferencia con captura o Wompi), revisar un comprobante, foto del comprobante con la cámara (`FotoComprobante`) y con cuánto paga el cliente en efectivo y el cambio (`EfectivoRecibido`) |
| `components/impresion/`, `inicio/` | Impresoras (equipos, estado, formato del ticket, guía y prueba) y las ofertas de hoy de la página de inicio |
| `components/caja/`, `compras/`, `configuracion/` | Pagos por revisar, resumen del turno (`ResumenTurno`), cierre diario, datos del negocio, cuentas para pagos en línea, tareas automáticas y revisión antes de producción |
| `services/` | Una llamada a la API por módulo (`pedido.service.ts`, `caja.service.ts`…) y el manejo de errores en español (`errors.ts`) |
| `stores/` | `auth.ts` (sesión), `carrito.ts` (carrito con productos y combos; se guarda en el navegador 24 horas y une las líneas iguales), `negocio.ts` (datos del negocio), `notificaciones.ts` (la campana) y `equipoImpresion.ts` (este equipo en Impresoras) |
| `composables/` | `useAuth`, `useCotizacion` (total con promociones antes de pedir), `useInstalarApp` (botón «Instalar app») y `usePagosEnLinea` (si se puede pagar en línea y cómo) |
| `utils/` | Comandas, comprobantes, documentos, combos, extras, promociones, pasarela, auditoría, formatos de los campos (`formatos.ts`), horario de reservas, ícono de categoría, equipo de impresión, tiempo relativo, textos de pedidos (`pedidoUi.ts`), ubicación del domicilio (`ubicacion.ts`), fotos comprimidas (`imagen.ts`) y redirección segura |
| `types/models.ts` | Tipos que reflejan las respuestas del backend |
| `assets/` | Estilos de Tailwind, mascota, foto del local y fotos de platos |
| `public/` | Ícono del sitio y la app instalable (PWA): `manifest.webmanifest`, `sw.js` (qué se guarda en el teléfono; nunca la API), `offline.html` e `iconos/` |

Las vistas del Administrador y del Mesero que comparten pantalla (Reservas y la de agotados) usan el
mismo componente con permisos distintos.

## Rutas

| Ruta | Pantalla | Quién |
|---|---|---|
| `/`, `/menu`, `/carrito`, `/checkout`, `/reservar` | Web pública | Todos |
| `/mesa/:qr_code` | Pedido desde la mesa | Todos, sin cuenta |
| `/seguimiento/:token`, `/reserva/:token`, `/pago/retorno/:referencia` | Enlaces privados | Quien tenga el enlace |
| `/pedidos` | Mis pedidos y reservas | Cliente |
| `/perfil` | Perfil y sesiones | Cualquier rol con sesión |
| `/login`, `/registro`, `/recuperar-password`, `/restablecer-password` | Acceso | Sin sesión |
| `/mesero/...` | Pedidos, nuevo pedido, mesas, cobrar, reservas, menú, gastos | Mesero (según sus menús) |
| `/admin/...` | Tablero y las 12 pantallas del Administrador (con Menú de agotados) | Administrador, SuperAdmin y Soporte (según sus menús) |

## Documentación

El catálogo de pantallas con lo que cubre cada una está en la carpeta «Documentacion Fragua Real»; el
resto, en la raíz del repositorio (`README.md`).

## App instalable (PWA)

El service worker solo se registra en la versión compilada (`npm run build`). Para probarlo en el
equipo: `npm run build && npx vite preview`, abrir `http://localhost:4173` y revisar en las
herramientas del navegador > Aplicación el manifiesto y el service worker. Instrucciones de instalación
y qué se guarda en el teléfono: `README-ENTREGA.md`, «App instalable en el teléfono (PWA)».

