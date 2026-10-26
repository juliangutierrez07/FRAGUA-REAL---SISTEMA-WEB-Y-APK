# Fragua Real Burger — Sistema web

Sistema de un restaurante de una sola sede: pedidos en la mesa (también por QR), a domicilio y para
recoger; reservas con depósito; caja y cobros; catálogo con combos y promociones; compras, gastos y
cierre diario; reportes; usuarios y auditoría. Lo usan tres roles: **Cliente** (en la web, con cuenta
o como invitado), **Mesero** y **Administrador**, y por encima de ellos el **SuperAdmin**, el dueño del
sistema (M09): puede todo lo del Administrador y es el único que gestiona las cuentas de Administrador.
Aparte está **Soporte** (M10), la cuenta técnica del desarrollador: ve todo el panel del Administrador
sin poder cambiar nada del negocio.

Cubre los 12 módulos de los documentos oficiales de requisitos (M00 a M11). Este repositorio tiene el
backend (API en Laravel) y el frontend (aplicación web en Vue).

## Estado al 22/10/2026

- El plan de implementación está completo: Fases 0 a 7, más el endurecimiento de seguridad, la prueba
  de un día completo de operación y las listas de revisión manual por rol.
- 432 pruebas automáticas, que pasan en SQLite y en PostgreSQL.
- Pago en línea sin Wompi (22/10/2026): el Cliente transfiere a Nequi, a la cuenta Bancolombia o a la
  llave Bre-B del negocio y sube la captura; el personal la revisa y aprueba o rechaza con motivo. Las
  cuentas las pone el SuperAdmin en Configuración; al activar Wompi vuelve el pago automático.
- Formato en todos los campos (22/10/2026): mientras se escribe o se pega, cada campo descarta lo que
  no le corresponde (letras en un teléfono, números en un nombre…), los pesos se ven con punto de miles
  y el servidor valida con las mismas reglas (teléfonos de 10 dígitos, cédula o NIT, cuenta de 11 dígitos).
- Eliminar mesas, productos y usuarios (24/10/2026): lo que nunca se usó se borra; lo que tiene
  historial se archiva (sale de las listas, los reportes lo siguen nombrando) y se restaura desde
  «Eliminados». Administrador y SuperAdmin, con contraseña y motivo.
- Menú «Impresoras» (M12, 24/10/2026): los equipos del local que imprimen (registrados o vinculados con
  un código), su señal y su última prueba, la impresión de prueba de 80 mm, el formato del ticket con
  vista previa, lo que falta por imprimir y una guía para dejar un equipo listo. Sin instalar nada.
- Mejoras de operación (26/10/2026): la caja solo cierra con todos los pedidos finalizados o cancelados;
  cuentas separadas en una mesa ocupada; foto del comprobante con la cámara al cobrar por transferencia
  (obligatoria) o tarjeta; en el domicilio, «Usar mi ubicación actual» y con qué billete paga contra
  entrega; las bebidas no preguntan qué ingrediente quitar; el seguimiento se pone al día cada 10 s; la
  auditoría dice quién hizo cada cosa también en el detalle; y la barra lateral del teléfono corregida.
- Contraseña segura en toda contraseña nueva (8 caracteres como mínimo, con al menos una mayúscula, una minúscula y un número) y bloqueo de 15 minutos del inicio de sesión
  tras 5 intentos fallidos de una cuenta o 20 desde una IP (21/10/2026).
- Campana de notificaciones para el personal (M11, 21/10/2026): pedidos nuevos por la web o el QR,
  reservas, caja y dinero, agotados, comandas que no salieron y avisos del sistema, según el rol y los
  menús de cada cuenta.
- Se instala en el teléfono como app desde el navegador (PWA): ícono en la pantalla de inicio y pantalla
  completa, sin Play Store (ver «App instalable» en `README-ENTREGA.md`).
- Mejoras del 16/10/2026 después de la revisión en producción: guía de despliegue en el VPS, extras
  según M04, presentaciones con descripción y foto, ofertas, resumen de Caja, depósitos en el cierre,
  importación de productos desde Excel y ajustes de la web del cliente (ver «Mejoras en producción» en
  `README-ENTREGA.md`).
- Queda fuera del código:
  - la cuenta comercial de Wompi (los pagos en línea ya funcionan en el ambiente de pruebas);
  - el proveedor de SMS (los avisos salen por correo, decisión D3);
  - el agente local para imprimir sin una persona de por medio (hoy se imprime desde el navegador,
    decisión D5).
- El mapa del salón (RF-M05-022, opcional) no se hará: lo descartó el negocio el 22/10/2026.

## Qué hace cada rol

- **Cliente**: ve el menú con fotos y promociones, pide a domicilio o para recoger y paga contra
  entrega o en línea (Nequi o Bancolombia por Wompi). También pide desde la mesa escaneando el QR y
  reserva mesa pagando el depósito. Con cuenta ve su historial; sin cuenta sigue su pedido o su reserva
  con un enlace privado.
- **Mesero**: toma pedidos en la mesa y por teléfono, los confirma (sale la comanda de cocina), los
  edita y cancela según su estado, cobra (también la cuenta completa de una mesa), confirma entregas y
  recogidas, registra reservas y depósitos, marca productos agotados y, si tiene el permiso, registra
  compras y gastos.
- **Administrador**: abre y cierra la caja, autoriza anulaciones, maneja el catálogo, las mesas y sus
  QR, los usuarios y la configuración; registra compras y gastos, cierra el día, consulta los reportes
  y los exporta, y revisa la auditoría.

## Tecnología

| Parte | Tecnología |
|---|---|
| Backend | PHP 8.3, Laravel 13, Laravel Sanctum (token), PostgreSQL, dompdf (PDF), PhpSpreadsheet (Excel), GD (fotos) |
| Frontend | Vue 3 con TypeScript, Vite 6, Vue Router, Pinia, Tailwind CSS 3, Lucide, Leaflet (mapa con OpenStreetMap); app instalable (PWA) con manifiesto y service worker propios |
| Servidor | VPS de Hostinger con Nginx, PHP-FPM, cron (programador de tareas) y Supervisor (cola) |
| Pagos en línea | Wompi (Nequi y botón Bancolombia), con aprobación manual de respaldo |

## Estructura del repositorio

```
FRAGUA-REAL---SISTEMA-WEB-Y-APK/
├── backend/                 API en Laravel (app, rutas, migraciones, pruebas, deploy/)
├── frontend/                Aplicación web en Vue (src/views por rol, services, stores)
├── README.md                Este archivo: qué es el sistema y dónde está cada cosa
├── README-ENTREGA.md        Cómo correrlo, cómo ponerlo en el servidor y qué cambió por fase
├── README-BACKEND.md        Arquitectura del backend e historial técnico de cambios
├── BASE-DE-DATOS.md         Tablas, relaciones, migraciones y decisiones de diseño
├── API.md                   Los 195 endpoints, por módulo, con quién puede usarlos
├── PRUEBA-DIA-COMPLETO.md   Recorrido a mano de un día de operación, con las cifras esperadas
└── QA-CLIENTE.md, QA-MESERO.md, QA-ADMINISTRADOR.md
                             Listas de revisión manual por rol
```

## Arranque rápido (equipo de desarrollo)

Necesitas PHP 8.3 con las extensiones `pdo_pgsql`, `mbstring`, `xml`, `gd` y `fileinfo`, Composer,
PostgreSQL y Node.js 20 o superior.

```bash
# Backend
cd backend
composer install
cp .env.example .env          # completa los datos de PostgreSQL
php artisan key:generate
php artisan migrate --seed
php artisan serve             # http://localhost:8000

# Frontend, en otra terminal
cd frontend
npm install
cp .env.example .env
npm run dev                   # http://localhost:5173
```

Cuentas de prueba: `admin@fraguareal.com`, `mesero@fraguareal.com` y `cliente@fraguareal.com`, todas
con la contraseña `password`. El detalle, la puesta en producción y la configuración de Wompi están en
[`README-ENTREGA.md`](README-ENTREGA.md).

## Pruebas

```bash
cd backend
php artisan test              # 432 pruebas (SQLite en memoria)
```

Cada prueba lleva en su nombre o en su comentario el requisito que cubre. `DiaCompletoTest` recorre un
día completo de operación por la API. Para revisar a mano: `PRUEBA-DIA-COMPLETO.md` y las tres listas
QA por rol.

## Documentación

**En este repositorio**

| Documento | Para qué sirve |
|---|---|
| [`README-ENTREGA.md`](README-ENTREGA.md) | Instalar, poner en producción en el VPS, decisiones del negocio y cambios de comportamiento por fase |
| [`README-BACKEND.md`](README-BACKEND.md) | Cómo está armado el backend (capas, módulos, tareas programadas, configuración) y el historial de los 60 cambios |
| [`BASE-DE-DATOS.md`](BASE-DE-DATOS.md) | Las 41 tablas, sus relaciones, las 68 migraciones y las decisiones de diseño (ADR) |
| [`API.md`](API.md) | Los 195 endpoints con rol, uso y límite de peticiones |
| [`PRUEBA-DIA-COMPLETO.md`](PRUEBA-DIA-COMPLETO.md) | Prueba manual de un día completo |
| [`QA-CLIENTE.md`](QA-CLIENTE.md), [`QA-MESERO.md`](QA-MESERO.md), [`QA-ADMINISTRADOR.md`](QA-ADMINISTRADOR.md) | Revisión manual pantalla por pantalla |
| [`backend/README.md`](backend/README.md), [`frontend/README.md`](frontend/README.md) | Notas de cada proyecto |

**En la carpeta «Documentacion Fragua Real»**

| Documento | Para qué sirve |
|---|---|
| M00 a M11 y Idea de Negocio | Requisitos oficiales (RF, RNF, reglas, historias, criterios y trazabilidad, también los de las mejoras del 16/10/2026); al final de cada uno, el estado de la implementación |
| Catálogo de pantallas | Cada pantalla construida, con su ruta, su rol y lo que cubre |
| Estructura técnica (docx) | Resumen técnico del proyecto para entregar |
| Flujos por rol (PDF) | Diagramas de los flujos de cada rol y sus reglas (37 páginas, con la app instalable, las notificaciones, el SuperAdmin, Soporte, el carrito, las ofertas, la importación y la caja del turno) |
| Cronograma (xlsx) | Tareas, fechas y estado de cada fase; la hoja «Mejoras en producción» tiene el despliegue y las mejoras del 15 y 16/10/2026 |
| `er_diagram.png` y `er_diagram.pdf` | Diagrama completo de la base de datos (68 migraciones) |

El documento **«Plan de implementación — Fragua Real Burger»** (en Claude) tiene el plan por fases, las
decisiones D1 a D7 y el estado de cada tarea.

## Forma de trabajo

- Todo el trabajo va en la rama `main`.
- Los textos de la aplicación y la documentación están en español de Colombia.
- El código cita en sus comentarios el requisito oficial que aplica (por ejemplo `RN-PED-32`), para
  poder ir del documento al código y al revés.
- Cada cambio llega con su prueba automática y con la documentación al día.
