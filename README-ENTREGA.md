# Fragua Real Burger — Entrega y puesta en marcha

Este documento es para quien instala, despliega o recibe el sistema: cómo correrlo, cómo ponerlo en el
servidor, qué decidió el negocio, qué queda pendiente y qué cambió en cada fase. Para saber qué es el
sistema y dónde está cada documento, empieza por [`README.md`](README.md); la arquitectura del
backend está en [`README-BACKEND.md`](README-BACKEND.md), la base de datos en
[`BASE-DE-DATOS.md`](BASE-DE-DATOS.md) y los endpoints en [`API.md`](API.md).

**Estado al 27/10/2026**: plan de implementación completo (Fases 0 a 7), mejoras del 16/10/2026, correcciones y datos del negocio del 19/10/2026 el rol SuperAdmin con permisos de menú (M09), el rol Soporte de solo consulta (M10) las notificaciones del personal (M11) la contraseña segura con bloqueo por intentos (21/10/2026) el pago en línea por transferencia con captura mientras no haya Wompi y el formato de todos los campos (22/10/2026), eliminar mesas, productos y usuarios y el menú Impresoras (M12, 24/10/2026), las mejoras de operación del 26/10/2026 (cuentas separadas, cerrar caja con todo finalizado, foto del comprobante, ubicación y billete del domicilio, pagar la reserva desde «Mis reservas» y el cambio al cobrar en efectivo), eliminar categorías, combos, extras y promociones, la comanda automática del pedido del Mesero, el cierre automático de la caja y el pedido en el historial del cliente al cobrar (27/10/2026), 459 pruebas automáticas que
pasan en SQLite y PostgreSQL. Falta solo lo que no es código: la cuenta comercial de Wompi, el
proveedor de SMS y el agente local de impresión (ver «Qué falta a propósito»).

## Contenido

- [Qué es](#qué-es)
- [Correrlo en el equipo de desarrollo](#correrlo-en-el-equipo-de-desarrollo)
- [Ponerlo en el servidor (VPS)](#ponerlo-en-el-servidor-vps)
- [Después de cada despliegue](#después-de-cada-despliegue)
- [App instalable en el teléfono (PWA)](#app-instalable-en-el-teléfono-pwa)
- [Decisiones del negocio](#decisiones-del-negocio)
- [Qué falta a propósito](#qué-falta-a-propósito)
- [Cambios de comportamiento por fase](#cambios-de-comportamiento-por-fase)
- [Datos de prueba en la base de desarrollo](#datos-de-prueba-en-la-base-de-desarrollo)

## Qué es

Sistema de restaurante de una sede (sin multi-tenant) para Fragua Real Burger: pedidos locales (en la
mesa o por QR), a domicilio y para recoger; reservas con depósito obligatorio; caja y cobros; catálogo
de productos, combos y promociones; compras y gastos con cierre diario; reportes; y gestión de
usuarios con auditoría. Cubre los 12 módulos (M00 a M11) de los documentos oficiales de requisitos, con
tres roles: **Cliente** (web pública, con o sin cuenta), **Mesero** y **Administrador**, y el
**SuperAdmin** (M09), dueño del sistema, que puede todo lo del Administrador y es el único que gestiona
las cuentas de Administrador y el nombre y NIT del negocio. Aparte, **Soporte** (M10) es la cuenta
técnica del desarrollador: ve todo el panel sin cambiar nada del negocio.

- **Backend**: PHP 8.3, Laravel 13, Sanctum (token), PostgreSQL, dompdf, PhpSpreadsheet.
- **Frontend**: Vue 3 con TypeScript, Vite, Pinia, Tailwind y Leaflet (mapa del local con OpenStreetMap).

## Correrlo en el equipo de desarrollo

Requisitos: PHP 8.3 con `pdo_pgsql`, `mbstring`, `xml`, `gd` y `fileinfo`; Composer; PostgreSQL;
Node.js 20 o superior.

```bash
# Backend
cd backend
composer install
cp .env.example .env            # poner DB_DATABASE, DB_USERNAME y DB_PASSWORD de PostgreSQL
php artisan key:generate
php artisan migrate --seed      # tablas y datos de prueba
php artisan serve               # API en http://localhost:8000

# Frontend (otra terminal)
cd frontend
npm install
cp .env.example .env            # VITE_API_BASE_URL=/api
npm run dev                     # sitio en http://localhost:5173
```

Vite pasa `/api` al backend (ver `frontend/vite.config.ts`), así que no hace falta configurar CORS en
desarrollo. Para probar las tareas automáticas en el equipo, en dos terminales más:
`php artisan schedule:work` y `php artisan queue:work`.

Con `MAIL_MAILER=log` los correos no salen: quedan en `storage/logs/laravel.log`. Con
`PASARELA_PROVEEDOR=manual` los pagos en línea quedan en revisión y los aprueba el personal.

### Cuentas de prueba (seeder)

| Rol | Correo | Usuario | Contraseña |
|---|---|---|---|
| Administrador | admin@fraguareal.com | admin | password |
| Mesero | mesero@fraguareal.com | mesero | password |
| Cliente | cliente@fraguareal.com | cliente | password |

El nombre de usuario solo existe en una base sembrada después del 12/10/2026; en una base anterior se
entra con el correo o se le pone uno desde Usuarios. El Cliente también puede pedir y reservar como
invitado, sin cuenta.

## Ponerlo en el servidor (VPS)

El sistema corre en un VPS de Hostinger (decisión D2) con Nginx, PHP-FPM, PostgreSQL, cron y
Supervisor. Los archivos de ejemplo están en `backend/deploy/` y suponen Ubuntu 24.04, el código en
`/var/www/fragua-real`, el dominio `fraguareal.co` y el usuario `www-data`; ajústalos si en el servidor
son otros.

**Primero el código**: lo primero que se hace en el VPS es clonar el repositorio; después se instala y
se configura todo alrededor, con los archivos de `backend/deploy/` ya en el servidor.

**Un solo dueño**: desde el paso 4, todo `/var/www/fragua-real` es de `www-data` (el usuario de PHP-FPM,
la cola y el cron) y todos los comandos del proyecto (`git`, `composer`, `npm`, `php artisan`) se corren
como él con `sudo -u www-data -H`. Así nunca queda un archivo de caché o de log que PHP no pueda
escribir. Nunca correr `php artisan` como root.

### 1. Clonar el repositorio

```bash
sudo apt update && sudo apt install -y git
sudo git clone https://github.com/Fabian08-Developer/FRAGUA-REAL---SISTEMA-WEB-Y-APK.git /var/www/fragua-real
```

Se clona con `sudo` porque `/var/www` es de root; en el paso 4 la carpeta pasa a ser de `www-data`.

### 2. Paquetes, acceso y firewall

```bash
sudo apt upgrade -y
sudo apt install -y nginx postgresql supervisor certbot composer unzip fail2ban ufw \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-gd php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
```

- PHP 8.3 con FPM y las extensiones `pgsql`, `mbstring`, `xml`, `gd` (fotos de productos; la revisión
  antes de producción avisa si falta), `curl`, `zip`, `intl` y `bcmath`.
- Certbot sin el complemento de Nginx (paso 6). `fail2ban` bloquea las IP que prueban contraseñas por
  SSH.
- Node.js 20 o superior para compilar el sitio (desde NodeSource: el `nodejs` de apt en Ubuntu 24.04
  es el 18).

Subir el límite de archivos (soportes de compras y fotos de productos de hasta 5 MB; Nginx ya trae
`client_max_body_size 8m`):

```bash
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 6M/; s/^post_max_size.*/post_max_size = 8M/' /etc/php/8.3/fpm/php.ini
sudo systemctl restart php8.3-fpm
```

Acceso y firewall:

```bash
sudo adduser fragua && sudo usermod -aG sudo fragua    # usuario propio para entrar; no trabajar como root
# Desde tu equipo: ssh-copy-id fragua@<ip-del-vps>, y comprobar que entra con la llave.
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'          # 80 y 443
sudo ufw enable
```

En `/etc/ssh/sshd_config`: `PermitRootLogin no` y `PasswordAuthentication no` (solo después de
comprobar que la llave funciona), y `sudo systemctl restart ssh`. PostgreSQL (5432) no se abre: solo lo
usa Laravel desde el mismo servidor. Si Hostinger tiene firewall propio en su panel, dejar ahí también
solo 22, 80 y 443.

### 3. Base de datos

Un usuario y una base propios, nunca el usuario `postgres` en el `.env`:

```bash
sudo -u postgres psql -c "CREATE USER fragua WITH PASSWORD '<contraseña larga y nueva>';"
sudo -u postgres psql -c "CREATE DATABASE fragua_real OWNER fragua;"
```

PostgreSQL en Ubuntu solo escucha en `localhost`: dejarlo así.

### 4. Permisos y dependencias

```bash
sudo mkdir -p /var/www/.cache /var/www/.config /var/www/.npm
sudo chown -R www-data:www-data /var/www/fragua-real /var/www/.cache /var/www/.config /var/www/.npm

cd /var/www/fragua-real/backend
sudo -u www-data -H composer install --no-dev --optimize-autoloader
cd ../frontend
sudo -u www-data -H npm ci && sudo -u www-data -H npm run build   # Nginx sirve frontend/dist

# Solo PHP escribe en storage y bootstrap/cache; el resto no lo puede tocar nadie más.
cd /var/www/fragua-real
sudo chmod -R o-rwx backend/storage backend/bootstrap/cache
sudo chmod -R ug+rwX backend/storage backend/bootstrap/cache
```

(`/var/www/.cache`, `.config` y `.npm` son las carpetas de caché de Composer y npm para `www-data`.)
Desde aquí, `git pull` también se corre como `www-data` (ver «Después de cada despliegue»); como root,
Git lo rechaza por ser de otro dueño.

### 5. Archivo `.env` del backend, tablas y datos iniciales

```bash
cd /var/www/fragua-real/backend
sudo -u www-data cp .env.example .env
sudo chmod 640 .env
sudo -u www-data php artisan key:generate     # APP_KEY nueva, propia de este servidor
```

En el `.env` poner:

- `APP_NAME="Fragua Real Burger"`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` y
  `FRONTEND_URL` con `https://` y el dominio;
- `APP_LOCALE=es` (ya viene así en `.env.example`; con `en`, los errores de los formularios salen en
  inglés);
- `LOG_STACK=daily` y `LOG_LEVEL=warning`: un archivo de log por día y se borran solos los de más de
  14 días (`LOG_DAILY_DAYS`). Con `single`, `laravel.log` crece sin fin;
- los datos de PostgreSQL del paso 3 (`DB_DATABASE=fragua_real`, `DB_USERNAME=fragua`, `DB_PASSWORD`);
- `QUEUE_CONNECTION=database` y `CACHE_STORE=database`;
- correo real: `MAIL_MAILER=smtp` con su servidor, usuario y contraseña, y `MAIL_FROM_ADDRESS`;
- `CORS_ORIGENES` vacío (sitio y API en el mismo dominio);
- `FRAGUA_REAL_NIT` cuando lo tengan, para que salga en el comprobante;
- Wompi, cuando esté la cuenta (paso 9).

Ninguna contraseña ni llave de este `.env` se copia de otro archivo ni se sube al repositorio.

Después, **una sola vez**, crear las tablas y cargar los datos iniciales (catálogo, mesas,
configuración y las cuentas del Administrador, el Mesero y el Cliente de demostración):

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache
```

Las cuentas sembradas tienen la contraseña `password`. En producción, crear de inmediato la cuenta del
dueño con `sudo -u www-data php artisan fragua:crear-superadmin correo-del-dueño@dominio.com` (pide la
contraseña sin mostrarla); entrar con ella y, desde Usuarios, cambiar la del Administrador y la del
Mesero (o crear las cuentas reales) y desactivar la del Cliente de demostración. La cuenta de prueba
`superadmin` del sembrado no se gestiona desde la pantalla: volver SuperAdmin la cuenta real y dejar la
de prueba sin uso (o cambiar su contraseña con el mismo comando y `--cambiar-clave`). La cuenta
`soporte` del sembrado (M10) tampoco se gestiona desde la pantalla: crear la real del desarrollador con
`sudo -u www-data php artisan fragua:crear-soporte correo-del-desarrollador@dominio.com` y pedirle al
SuperAdmin que desactive la de prueba desde Usuarios. `db:seed` no se vuelve a correr en producción.

### 6. Nginx y HTTPS

`nginx-fragua-real.conf` no carga sin el certificado (el bloque de https lo exige), así que va en dos
tiempos. No usar `certbot --nginx`: reescribe la configuración.

1. **Sacar el certificado** con la configuración inicial, que solo atiende el puerto 80:

   ```bash
   cd /var/www/fragua-real/backend/deploy
   sudo mkdir -p /var/www/certbot
   sudo cp nginx-fragua-real-inicial.conf /etc/nginx/sites-available/fragua-real
   sudo ln -s /etc/nginx/sites-available/fragua-real /etc/nginx/sites-enabled/fragua-real
   sudo rm -f /etc/nginx/sites-enabled/default
   sudo nginx -t && sudo systemctl reload nginx
   sudo certbot certonly --webroot -w /var/www/certbot -d fraguareal.co -d www.fraguareal.co \
        --deploy-hook "systemctl reload nginx"
   ```

   El dominio (y `www`) ya debe apuntar a la IP del VPS.
2. **Poner la configuración completa**:

   ```bash
   sudo cp nginx-fragua-real.conf /etc/nginx/sites-available/fragua-real
   sudo nginx -t && sudo systemctl reload nginx
   sudo certbot renew --dry-run      # comprueba que la renovación automática va a funcionar
   ```

Cambiar el dominio, las rutas y la versión de PHP-FPM en los dos archivos si no son estos. La
configuración completa trae https obligatorio, HSTS, la política de contenido (CSP) y las demás
cabeceras, y deja abierta por http solo la carpeta con la que Let's Encrypt renueva el certificado.

La sesión del navegador se guarda en `localStorage` (decisión del 13/10/2026). Por eso la CSP solo deja
ejecutar el código del propio sitio: no agregar scripts de terceros (analítica, chats) sin revisar antes
la CSP.

### 7. Programador de tareas, cola y logs

El sistema hace tareas solo: no-show y recordatorios de reservas, avisos por correo, conciliación de
pagos, limpieza de sesiones y la copia de seguridad diaria (paso 8).

1. **Programador (cron)**: copiar `backend/deploy/cron-fragua-real` a `/etc/cron.d/fragua-real`
   (dueño root, permisos 644). Ejecuta `php artisan schedule:run` cada minuto y el respaldo a las 3:00.
2. **Trabajador de la cola (Supervisor)**: copiar `backend/deploy/supervisor-fragua-real-cola.conf` a
   `/etc/supervisor/conf.d/` y correr `sudo supervisorctl reread && sudo supervisorctl update`. Ejecuta
   `php artisan queue:work database` y lo reinicia si se cae.
3. **Rotación del log de la cola**: copiar `backend/deploy/logrotate-fragua-real` a
   `/etc/logrotate.d/fragua-real` (dueño root, permisos 644). Supervisor escribe en
   `storage/logs/cola.log` y nunca lo corta; los logs de Laravel ya rotan solos con `LOG_STACK=daily`.

Comprobación: Administrador > Configuración > **Tareas automáticas** muestra si el programador y la
cola están activos (cada uno deja un latido cada minuto), los trabajos pendientes y fallidos, y si el
correo sale de verdad.

### 8. Copias de seguridad

`backend/deploy/respaldo-fragua-real.sh` guarda cada día en `/var/backups/fragua-real` la base de datos
(`base-<fecha>.dump`) y `backend/storage/app/private` (`archivos-<fecha>.tar.gz`: soportes de compras,
fotos de productos y reportes exportados), y borra los de más de 14 días. El cron del paso 7 ya lo
corre a las 3:00; probarlo a mano una vez:

```bash
sudo bash /var/www/fragua-real/backend/deploy/respaldo-fragua-real.sh && sudo ls -lh /var/backups/fragua-real
```

Un respaldo que solo está en el VPS se pierde con el VPS: copiar esa carpeta a otro lugar (otro
servidor, un almacenamiento en la nube con `rclone`, o el equipo del negocio con `rsync`/`scp`) al menos
una vez por semana. Activar también, si el plan de Hostinger lo trae, las copias automáticas del VPS.

Para restaurar (sobre una base vacía o reemplazando la actual):

```bash
sudo -u postgres pg_restore --clean --if-exists --no-owner --role=fragua -d fragua_real /var/backups/fragua-real/base-<fecha>.dump
sudo tar -xzf /var/backups/fragua-real/archivos-<fecha>.tar.gz -C /var/www/fragua-real/backend/storage/app
sudo chown -R www-data:www-data /var/www/fragua-real/backend/storage/app/private
```

### 9. Pasarela de pago Wompi

Decisión D4: los pagos en línea (pedidos y depósitos de reserva) van por Wompi y se confirman solos.
Mientras no estén las llaves, el sistema usa el **pago directo** (decisión del 22/10/2026): el Cliente
transfiere a las cuentas del negocio y sube la captura, y el Mesero o el Administrador la revisa.

**Poner las cuentas (sin Wompi).** Entrar como SuperAdmin > Configuración > «Cuentas para pagos en
línea» y llenar los medios que se van a recibir: Nequi (número y a nombre de quién), cuenta
Bancolombia (tipo, número, titular y, opcional, cédula o NIT) y llave Bre-B (llave y titular). Un medio
incompleto no se le muestra al Cliente; si no hay ninguno, el pago en línea no aparece y solo se paga
al recibir o en el local. Cada cambio queda en Auditoría. Antes de aprobar un pago, hay que mirar en la
app del banco que el dinero llegó: la captura sola no basta. Las capturas quedan en
`storage/app/private/comprobantes-pago` y entran en la copia de seguridad del paso 8.

Cuando esté la cuenta de Wompi, se siguen los pasos de abajo; con Wompi activo las cuentas dejan de
mostrarse y el pago vuelve a ser automático (Bre-B no se ofrece por Wompi).

1. En Wompi > Desarrolladores, copiar las cuatro llaves del ambiente (sandbox para probar, producción
   cuando la cuenta comercial esté aprobada) al `.env`: `WOMPI_LLAVE_PUBLICA` (pub_…),
   `WOMPI_LLAVE_PRIVADA` (prv_…), `WOMPI_SECRETO_EVENTOS` (…events…), `WOMPI_SECRETO_INTEGRIDAD`
   (…integrity…), y `WOMPI_AMBIENTE=sandbox` o `produccion`.
2. Poner `PASARELA_PROVEEDOR=wompi`. Wompi devuelve al cliente a `FRONTEND_URL/pago/retorno/...`.
3. En Wompi > Desarrolladores > **URL de eventos**, poner `https://<dominio>/api/pasarela/wompi/eventos`
   (la misma que muestra Administrador > Caja > «Pagos en línea»).
4. `sudo -u www-data php artisan config:cache`. El programador corre `pasarela:conciliar` cada 5 minutos
   por si un aviso de Wompi se pierde.

Comprobación: Administrador > Caja > «Pagos en línea» dice «Wompi activo (pruebas)». En el sandbox se
paga con los datos de prueba de Wompi (Nequi aprobado o rechazado); el pedido o la reserva deben
confirmarse solos y aparecer en Caja como «Confirmado por Wompi».

### 10. Antes de abrir al público

1. Confirmar que las cuentas sembradas ya no usan `password` (paso 5): cambiadas o desactivadas.
2. Correr `sudo -u www-data php artisan fragua:revisar-produccion`: no debe quedar ningún `[ERROR]`. Lo
   mismo se ve en Administrador > Configuración > «Revisión antes de producción».
3. Revisar dependencias:
   - `composer audit` en `backend` (el 13/10/2026 no encontró nada);
   - `npm audit --omit=dev` en `frontend` debe dar 0: eso es lo que llega al navegador;
   - el `npm audit` completo del 13/10/2026 mostró 8 alertas, todas en herramientas de compilación que
     no van en `dist` (Tailwind 3 y sus dependencias, source-map-js). Se corrige lo seguro con
     `npm audit fix`, **sin** `--force`: `--force` instala Tailwind 4, que rompería los estilos.
4. Comprobar que el respaldo del paso 8 se generó y que ya hay una copia fuera del VPS.
5. Recorrer a mano, con el sistema ya en el servidor, `PRUEBA-DIA-COMPLETO.md` y las tres listas por
   rol: `QA-CLIENTE.md` (desde un teléfono, como lo usará el cliente), `QA-MESERO.md` (en la tableta o
   el teléfono del Mesero) y `QA-ADMINISTRADOR.md`.
6. Cuando ya haya historia real, `sudo -u www-data php artisan reportes:medir`: todos los reportes
   deben decir «OK».
7. **Impresoras (M12):** en el equipo de la caja, Administrador > Impresoras > «Registrar este equipo»;
   para la tablet de la cocina (con la sesión del Mesero), «Agregar equipo con código» y escribir el
   código en Pedidos > «Impresión de comandas en este equipo». En cada uno: seguir la pestaña «Guía»
   (impresora predeterminada, papel de 80 mm y el acceso directo de Chrome con `--kiosk-printing`),
   hacer la impresión de prueba y responder «Salió bien». Ajustar el formato del ticket y dejar «Abre
   solas las comandas nuevas» prendido solo en el equipo de la cocina.

## Después de cada despliegue

```bash
cd /var/www/fragua-real
sudo -u www-data -H git pull
cd backend
sudo -u www-data -H composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache
sudo -u www-data php artisan queue:restart        # Supervisor levanta el trabajador con el código nuevo
sudo -u www-data php artisan fragua:revisar-produccion
cd ../frontend && sudo -u www-data -H npm ci && sudo -u www-data -H npm run build
```

Si cambió algún archivo de `backend/deploy/`, volver a copiarlo a su lugar (pasos 6 y 7).

## App instalable en el teléfono (PWA)

Decisión del 16/10/2026: la «aplicación móvil» de la Idea de Negocio se entrega como **app instalable
desde el navegador** (PWA), no como un archivo APK. Es el mismo sistema, con el mismo código y la misma
dirección; no hay que publicarla en Play Store ni en App Store, y cada despliegue la actualiza sola.

### Qué obtiene quien la instala

- Un ícono de Fragua Real en la pantalla de inicio (o en el menú de inicio del computador) y la app
  abre en pantalla completa, sin la barra del navegador.
- Accesos directos al mantener presionado el ícono (Android): Ver el menú, Reservar mesa, Mi pedido.
- Al abrirla, el Mesero y el Administrador con sesión entran directo a su panel; el Cliente, al inicio.
- Carga más rápida: el código de la app y las fotos del menú quedan guardados en el teléfono.
- Sin internet, muestra «Sin conexión» con un botón para reintentar. Pedir, cobrar o reservar necesita
  conexión (no hay modo sin internet: los precios, la caja y la disponibilidad salen del servidor).

### Cómo se instala

| Dónde | Pasos |
|---|---|
| Android (Chrome, Edge, Samsung Internet) | Abrir el sitio, menú hamburguesa > **Instalar app** (o el aviso «Instalar» del navegador, o ⋮ > *Instalar app*). |
| iPhone o iPad (Safari) | Menú hamburguesa > **Instalar app** muestra los pasos: Compartir > *Agregar a inicio* > *Agregar*. |
| Computador (Chrome o Edge) | Ícono de instalar en la barra de direcciones, o ⋮ > *Instalar Fragua Real Burger*. |
| Tableta o teléfono del personal | Iniciar sesión y, en la barra lateral, **Instalar app**. |

El botón «Instalar app» solo aparece cuando el navegador permite instalarla y la app todavía no está
instalada. Necesita **HTTPS** (el VPS ya lo tiene); en `http` el navegador no ofrece instalarla.

### Qué guarda en el teléfono (y qué no)

`frontend/public/sw.js` (service worker):

- **Sí guarda:** los archivos de la app (`/assets/*`, con huella en el nombre), los íconos, la página
  «Sin conexión» y las fotos públicas del menú (`/api/productos/{id}/foto`, `/api/variantes/{id}/foto`,
  hasta 150).
- **Nunca guarda:** el resto de la API (pedidos, sesiones, caja, reportes, datos personales); esas
  peticiones siempre van al servidor.
- Las páginas se piden primero al servidor, así siempre se ve la última versión.

### Actualizaciones

Cada despliegue (`npm run build`) genera archivos con nombres nuevos y la app los toma la siguiente vez
que se abre; no hay que reinstalar nada. Si se cambia `sw.js`, subir el número de `VERSION` dentro del
archivo para que se borren las copias anteriores en los teléfonos.

### En el servidor

`backend/deploy/nginx-fragua-real.conf` sirve `sw.js` y `manifest.webmanifest` sin caché del navegador,
para que una versión nueva llegue enseguida. **Después de este cambio hay que volver a copiar ese
archivo** al VPS (paso 6, «Poner la configuración completa»), cambiar el dominio y PHP 8.4 como la
primera vez, y recargar Nginx.

### Archivos

| Archivo | Para qué |
|---|---|
| `frontend/public/manifest.webmanifest` | Nombre, colores, íconos, accesos directos y dirección de inicio (`/?origen=app`) |
| `frontend/public/sw.js` | Service worker: qué se guarda en el teléfono |
| `frontend/public/offline.html` | Página «Sin conexión» |
| `frontend/public/iconos/` | Íconos de 192 y 512 px, el adaptable de Android y el del iPhone |
| `frontend/src/composables/useInstalarApp.ts`, `components/common/BotonInstalarApp.vue` | Botón «Instalar app» y pasos para iPhone |

Si más adelante se quiere un APK para Play Store, la misma app se puede empaquetar con una
*Trusted Web Activity* (Bubblewrap) sin reescribir nada; hoy no hace falta.

## Decisiones del negocio

| # | Decisión | Qué se decidió | Fecha |
|---|---|---|---|
| D1 | Fecha de entrega | Sin fecha fija; dejó de frenar porque el plan se terminó completo | — |
| D2 | Dónde corre el sistema | VPS de Hostinger con HTTPS, cron y Supervisor | 12/10/2026 |
| D3 | Proveedor de SMS | Correo en V1; el SMS queda para cuando haya proveedor y presupuesto | 12/10/2026 |
| D4 | Pasarela de pago | Wompi (Nequi y botón Bancolombia), desarrollado contra el sandbox; la aprobación manual queda de respaldo. Mientras no haya cuenta de Wompi, transferencia directa a Nequi, Bancolombia o Bre-B con captura del comprobante (22/10/2026) | 13/10 y 22/10/2026 |
| D5 | Impresora POS | Comandas y comprobantes de 80 mm desde el navegador del Mesero; el agente local, después de V1 | 13/10/2026 |
| D6 | Tiempos de las reservas | No-show a los 15 min, recordatorio 2 h antes, mesa Reservada 1 h antes; editables en Configuración | 12/10/2026 |
| D7 | Reglas contables | Abono del depósito como pago de tipo depósito; correcciones como ajustes sin reescribir el cierre; un Mesero con permiso registra compras y gastos (revisada en la Fase 7) | 12/10 y 13/10/2026 |

Otras decisiones: la sesión del navegador queda en `localStorage` (13/10/2026); el depósito de reserva
inicial es $20.000 (06/10/2026, RN-M05-14); toda reimpresión sale marcada y queda en Auditoría
(13/10/2026); el mapa del salón no se hará (decisión del 22/10/2026; antes había quedado para el futuro el 13/10/2026).

## Qué falta a propósito

- **Cuenta comercial de Wompi**: la confirmación automática ya funciona con el sandbox; faltan la
  cuenta de producción y sus llaves. Mientras tanto se cobra por transferencia directa con captura
  (Configuración > «Cuentas para pagos en línea», solo el SuperAdmin).
- **SMS** (D3): los avisos de reserva y de «listo para recoger» salen por correo y cada uno guarda su
  canal, listo para sumar el SMS cuando haya proveedor.
- **Impresión sin una persona de por medio** (D5): un servidor en la nube no ve la impresora del local;
  hace falta un agente pequeño junto a ella. Mientras tanto, el equipo de la cocina puede abrir e
  imprimir solas las comandas con Chrome en modo de impresión automática (ver Fase 6).
- **Mapa simple del salón** (RF-M05-022, opcional): descartado por el negocio el 22/10/2026; no se hará.
  El estado de las mesas se sigue viendo en la lista de Mesas.
- **Fuera del alcance** de los documentos oficiales: factura electrónica (el comprobante lo dice),
  inventario y recetas, roles de Cocina y Domiciliario, GPS y reportes por canal.
- Cliente > Mis pedidos muestra los 20 pedidos más recientes, sin paginación.

## Cambios de comportamiento por fase

Lo que cambió para quien usa el sistema, fase por fase. Sirve para saber qué probar después de
actualizar y para explicar el cambio a quien opera el local. El detalle técnico de cada punto está en
`README-BACKEND.md`.


### Fase 0: corrección de errores y seguridad (09/10/2026)

- Un pedido ya no se finaliza solo con el pago: tiene que estar en preparación y, si es
  domicilio o recogida, el Mesero confirma la entrega o la recogida (botones en Mesero >
  Pedidos; en Cobrar, el domicilio contra entrega pide confirmar la entrega antes de cobrar).
  Un local pagado antes de prepararse se finaliza con el botón "Finalizar pedido".
- Un pago en línea aprobado ya no finaliza el pedido: el Mesero lo confirma como siempre.
- El invitado puede pagar en línea; reintentar el pago no crea otro pedido.
- El efectivo esperado de la caja descuenta las compras y gastos pagados en efectivo.
- Anular o reembolsar un pago de un día con la caja cerrada pide la contraseña del
  Administrador y queda como ajuste extraordinario.
- Una cuenta desactivada pierde sus sesiones y no entra a ninguna pantalla.

### Fase 1: operación completa del Mesero (10 y 11/10/2026)

- El Mesero y el Administrador editan un pedido PENDIENTE (agregar, cambiar cantidad u
  observaciones, quitar) con el botón "Editar pedido". Un pedido CONFIRMADO también se edita,
  pero solo en las líneas que no se han marcado como preparadas.
- Cada pedido tiene "Ver historial": quién lo creó, confirmó, cambió o cobró, y a qué hora.
- "Nuevo pedido" del Mesero ahora pregunta primero el canal: Mesa, Domicilio o Recogida. Para
  domicilio y recogida pide los datos del cliente (los pedidos por teléfono o WhatsApp).
- Un domicilio exige barrio y punto de referencia, además de la dirección. El Cliente con cuenta
  confirma su teléfono en el Checkout, y todos pueden dejar indicaciones para la entrega.
- Pedidos (Mesero y Administrador) muestra los datos de entrega, el teléfono para llamar y cómo
  dijo el cliente que iba a pagar (contra entrega, al recoger o en línea con Nequi o Bancolombia).
- Mesero > Mesas muestra cuánto lleva la cuenta de cada mesa ocupada y cuánto debe; "Ver
  cuenta" muestra todos sus pedidos. En Mesero > Cobrar, los pedidos de mesa aparecen agrupados
  por mesa y se cobran juntos con "Cobrar mesa" (también por partes, para pagos mixtos).
- El Mesero tiene su pestaña Reservas: registra reservas por teléfono, su depósito en efectivo,
  tarjeta o transferencia, y confirma la llegada del cliente. El Administrador tiene el mismo
  botón "Nueva reserva". Al elegir fecha y hora solo se ofrecen las mesas libres a esa hora, y
  dos reservas de la misma mesa quedan separadas al menos 2 horas.
- El depósito manual ya no ofrece "En línea": ese es el pago del Cliente por Nequi o Bancolombia.
- El Mesero tiene la pestaña Menú para marcar agotado o disponible un producto o un tamaño, con
  buscador; "Nuevo pedido" también tiene buscador. El Administrador llega a la misma pantalla
  desde Catálogo > "Agotados y disponibilidad".
- El Mesero cancela solo pedidos pendientes; uno ya confirmado necesita que un Administrador
  escriba su contraseña en el mismo modal. El Cliente ahora también puede cancelar su recogida
  pendiente. El Administrador puede cambiar el nombre de una mesa sin que cambie su QR.
- El invitado recibe al terminar el Checkout un enlace privado (/seguimiento/...) para ver el
  estado de su pedido, pagarlo en línea o cancelarlo mientras esté pendiente.


### Fase 2: dinero, catálogo y cierre diario (11 y 12/10/2026)

- Las promociones automáticas que el Administrador crea en Catálogo ya se aplican solas a los
  pedidos de los tres canales, dentro de su fecha, horario y días. Varias vigentes se suman. Un
  pedido conserva las promociones con las que se creó aunque después cambien.
- El menú, el QR de mesa y "Nuevo pedido" del Mesero marcan los productos en promoción; el
  carrito, el Checkout, el QR y "Nuevo pedido" muestran cuánto rebaja cada promoción y el total.
- En Pedidos, el Mesero y el Administrador tienen el botón "Descuento": porcentaje, valor fijo o
  una promoción de tipo "manual", siempre con motivo. Reemplaza a las promociones automáticas
  del pedido (no se suman) y se puede quitar. Queda en el historial y en la auditoría.
- El valor del domicilio nunca se rebaja. El descuento no puede dejar el total por debajo de lo
  ya pagado.
- Los horarios de promociones y combos ahora se cuentan en la hora de Colombia (antes, en la
  del servidor, cinco horas adelante).
- Los reportes de ventas por producto y por tipo de venta ya restan los descuentos.
- Al confirmar la llegada de una reserva, o con el primer pedido en una mesa reservada, el
  depósito se abona a la cuenta de la mesa: el saldo baja y la cuenta dice cuánto es depósito. Si
  la visita gasta menos, lo que sobra se usa en los pedidos siguientes de esa visita.
- Registrar un depósito presencial ahora exige la caja abierta, y el depósito en efectivo suma al
  efectivo esperado de ese día. Cancelar una reserva con depósito en efectivo (devolverlo) también
  exige la caja abierta y lo resta. El detalle del turno en Caja lista los depósitos.
- En Reportes > Ventas por método, el abono del depósito sale como "Depósito de reserva".
- En Catálogo, cada producto elige qué extras generales ofrece; un extra general sin productos
  elegidos se ofrecía con todo el menú (corregido el 16/10/2026: ahora no se ofrece con ninguno, y
  los que estaban sueltos quedaron asociados a los productos de tipo Alimento). El Menú, el QR de mesa y el pedido del Mesero muestran
  ahora los mismos extras (antes los generales no salían en ninguno).
- Dar de baja un producto desactiva los combos que lo usan, y el combo no se puede volver a
  activar hasta quitar o reemplazar ese producto. Desactivar una categoría oculta sus productos
  y sus combos en todos los canales.
- Precios en cero se rechazan, y un nombre de producto o categoría repetido muestra un mensaje
  claro. Cada alta o cambio del catálogo (incluido el precio) queda en la auditoría.
- En Compras y gastos la categoría es obligatoria y se elige de una lista según el tipo; se
  pueden anotar cantidad, unidad y observaciones, y adjuntar la foto o el PDF de la factura
  (antes había que pegar un enlace). El soporte solo lo abre el Administrador con su sesión.
- El historial de compras y gastos se filtra por fechas, categoría, forma de pago, proveedor y
  estado, con los totales de lo filtrado, y muestra quién corrigió o anuló cada registro.
- Una compra no puede registrarse con fecha futura.
- El cierre diario muestra primero el cálculo del día con todo su desglose (ventas por tipo y por
  forma de pago, compras y gastos por categoría y por forma de pago, y el arqueo de la caja). Solo
  se puede cerrar el día después de cerrar la caja de ese día. Los cierres anteriores quedan en
  una lista para consultarlos.
- Registrar, corregir o anular una compra o un gasto de un día ya cerrado pide la contraseña del
  Administrador y un motivo. El cierre no cambia: la corrección se ve debajo, con los valores
  actualizados.

### Fase 3: usuarios y auditoría (12/10/2026)

- Nueva pantalla Administrador > Auditoría: quién hizo cada acción crítica, cuándo, sobre qué
  registro, desde qué IP y con qué navegador, con filtros por fechas, usuario, grupo, acción,
  registro, IP y texto. Es solo de consulta. Arriba muestra los inicios de sesión fallidos de las
  últimas 24 horas y avisa si una misma IP acumula 3 o más intentos.
- Ahora quedan registrados los inicios de sesión (también los fallidos, con el motivo), los
  cierres de sesión, el registro de clientes, los cambios y restablecimientos de contraseña, el
  alta de usuarios del personal y los cambios de configuración (domicilio y depósito).
- En Usuarios, cada usuario tiene el botón "Actividad", que abre la auditoría filtrada por él.
- En Usuarios, el botón "Editar" corrige nombre, correo y teléfono y cambia el rol (Administrador,
  Mesero o Cliente). Cambiar el rol cierra las sesiones de esa persona: debe volver a entrar. Nadie
  cambia su propio rol, y la contraseña no se cambia desde ahí.
- Se puede iniciar sesión con el correo o con un nombre de usuario. El nombre de usuario es
  opcional: se elige al crear la cuenta, en Mi perfil o lo pone el Administrador en Usuarios. Las
  cuentas que ya existían siguen entrando con su correo hasta que se les ponga uno.
- Mi perfil muestra las sesiones abiertas (dispositivo, IP, último uso y cuándo vence) y permite
  cerrar una o todas las demás. En Usuarios, "Sesiones (n)" muestra las de cada cuenta y cierra
  una o todas. Una sesión se cierra sola tras 7 días sin uso o a los 30 días; después hay que
  volver a entrar. Las sesiones abiertas antes de esta versión no tienen IP ni dispositivo.
- El historial de auditoría ya no se puede cambiar ni borrar ni siquiera desde la base de datos.
  Consecuencia: un usuario con historial no se puede borrar de la base de datos (se desactiva,
  como siempre).

### Fase 4: tareas programadas y avisos (12 y 13/10/2026)

- Configuración tiene la tarjeta "Tareas automáticas": dice si el programador de tareas y la cola
  de avisos están corriendo en el servidor, cuántos trabajos hay pendientes o fallidos y si el
  correo sale de verdad o solo se registra.
- Configuración tiene "Tiempos de las reservas": tolerancia antes del no-show (15 min),
  anticipación del recordatorio (2 h) y desde cuándo la mesa se ve Reservada (1 h). Se usan desde
  la tarea 3 de la Fase 4. En Reservar mesa, el Cliente ve cuántos minutos se le espera.
- Las reservas se manejan solas (con el programador corriendo): la mesa se ve Reservada solo
  desde 1 h antes (antes quedaba Reservada desde que se pagaba el depósito, aunque fuera para otro
  día); si el cliente no llega en 15 min, la reserva pasa sola a "No asistió" y el depósito se
  retiene; y 2 h antes le llega un correo de recordatorio. "Marcar no-show" a mano solo aparece
  cuando ya pasó la tolerancia.
- Cada reserva avisa al cliente por correo cuando se confirma el depósito, cuando se cancela
  (dice si el depósito se devuelve) y cuando se marca "No asistió", además del recordatorio.
  Cada correo trae un enlace para ver la reserva y cancelarla sin cuenta, mientras no haya
  llegado su hora; si el depósito se pagó en efectivo, el enlace pide llamar o pasar por el
  local. En Reservas, cada reserva muestra sus avisos ("enviado", "en cola" o "falló" con el
  motivo) y un botón "Reintentar" para los que fallaron. Si el correo falla, la reserva sigue
  normal. Los avisos por SMS quedan para después (decisión D3).
- Las recogidas tienen el botón "Listo para recoger" (Mesero y Administrador): el pedido sigue en
  Preparando, sus líneas quedan preparadas y el cliente recibe un correo con lo que falta por
  pagar y el enlace de su pedido. El invitado puede dejar un correo opcional en el Checkout (y el
  Mesero al registrar una recogida por teléfono); sin correo, la pantalla pide llamarlo. Mis
  pedidos y el seguimiento muestran "Listo para recoger".
- Se crearon las tablas que el `.env` ya esperaba (`jobs`, `failed_jobs`, `cache`,
  `cache_locks`, `sessions`…). Sin ellas, con `CACHE_STORE=database`, el límite de intentos del
  inicio de sesión y el programador fallaban en el servidor.

### Fase 5: comprobantes, cierres y exportaciones (13/10/2026)

- Todo pedido pagado por completo tiene su comprobante de pago con número consecutivo (FR-000001,
  FR-000002…): ticket de 80 mm para imprimir en los pedidos del local y PDF en domicilio y
  recogida, que además le llega al cliente por correo si hay a dónde. Se abre desde Pedidos, desde
  Cobrar apenas se completa el pago, desde Mis pedidos y desde el enlace de seguimiento.
- Si un pedido deja de estar pagado (se anula un pago, se agrega un producto) o se cancela, su
  comprobante queda anulado con el motivo y, al volver a pagarse, sale uno nuevo con otro número.
  Ningún comprobante se borra.
- El comprobante no es una factura electrónica y lo dice. Una mesa con varios pedidos tiene un
  comprobante por pedido.
- Reimpresión: el personal puede volver a imprimir un comprobante cuando quiera; desde la segunda
  vez sale marcado «REIMPRESIÓN · copia n» con la fecha y la hora, y cada impresión queda en
  Auditoría con quién la hizo. Lo que descarga el cliente no se marca. El Administrador ve en
  Pedidos > "Comprobantes" todos los del pedido, también los anulados, y abre cualquiera (el anulado
  sale con su sello).
- El cierre diario de un día cerrado se imprime o se guarda en PDF (tamaño carta) con el día, los
  totales, el desglose, el arqueo, las correcciones posteriores, quién cerró y quién imprime, y
  líneas de firma. Sigue la misma regla de reimpresión. Un día sin cerrar se imprime solo como
  vista previa y la hoja lo dice.
- Los reportes se exportan a Excel de verdad (`.xlsx`, con una hoja por tabla y los valores como
  números en pesos) y a PDF de las páginas que haga falta, con la comparación si la pantalla
  compara. Cada archivo queda en "Exportaciones guardadas" y se vuelve a descargar tal cual, sin
  regenerarlo; cada descarga queda en Auditoría. En el VPS, `storage/app/private/reportes` debe
  entrar en las copias de seguridad.
- "Uso de mesas" cuenta ahora las visitas con pedidos en el local (no solo las reservas
  completadas): por mesa, visitas, ventas, minutos promedio y reservas, y las mesas que no se
  usaron aparecen como "Sin uso en el periodo".

### Fase 6: pasarela de pago y comandas (13/10/2026)

- Con Wompi activo, el Cliente que elige pagar en línea (en el Checkout, en el seguimiento, en Mis
  pedidos, al reservar o desde el enlace de su reserva) pasa a la página de pago de Wompi y vuelve a
  "Tu pago": la página se actualiza sola mientras Nequi espera que lo apruebe en la app. El pedido o
  la reserva se confirman solos al llegar el pago; ya no hay que aprobarlos a mano.
- Si el pago se rechaza o el Cliente no lo termina, puede intentarlo de nuevo. El sistema pregunta
  antes a Wompi por el intento anterior para no cobrar dos veces.
- Mesero > Cobrar y Administrador > Reservas muestran los pagos en curso como "En proceso en Wompi
  · se confirma solo"; "Aprobar a mano" y "Rechazar a mano" quedan de respaldo y la Auditoría dice
  quién lo hizo.
- Administrador > Caja muestra si Wompi está activo y los "pagos por revisar": los casos en que el
  sistema no decide solo con el dinero (monto distinto, pago aprobado después de rechazado a mano,
  pedido cancelado ya pagado, reembolso que Wompi no hizo…). Se cierran con una nota de lo que se
  hizo.
- Reembolsar un pago en línea en Caja, o cancelar una reserva con depósito pagado en línea, pide
  la devolución a Wompi. Wompi solo la hace sola con tarjeta; los pagos con Nequi o el botón
  Bancolombia se devuelven desde el panel de Wompi y quedan en "pagos por revisar" hasta
  anotarlo.
- Seguridad: para pagar el depósito de una reserva ya no basta su número; hace falta el enlace
  secreto de la reserva.
- En el servidor: `php artisan migrate`, las variables `PASARELA_*` y `WOMPI_*` del
  `.env.example`, y la URL de eventos en Wompi.
- Comandas de cocina (D5): al confirmar un pedido, sale su comanda (sin precios) en otra pestaña,
  lista para imprimir en la impresora de 80 mm. Si después se agrega, cambia o quita algo, sale una
  hoja de "Cambios al pedido" con solo eso. Si se cancela un pedido que la cocina ya tenía, sale
  "Pedido cancelado".
- Arriba de todas las pantallas del Mesero (y en Administrador > Pedidos), una franja amarilla
  dice cuántas comandas faltan por imprimir. Si una no salió (sin papel, impresora apagada),
  "¿No salió?" la devuelve a la lista. Un problema de impresión nunca cambia ni cancela el pedido.
- Volver a imprimir una comanda que ya salió la marca «REIMPRESIÓN · COPIA n» y queda en
  Auditoría.
- Para el equipo de la cocina: en "Impresión de comandas en este equipo", prender "Abrir solas las
  comandas nuevas", permitir las ventanas emergentes del sitio y abrir Chrome con impresión
  automática (`--kiosk-printing`) con la impresora de 80 mm como predeterminada. Así salen solas.
  La impresión sin nadie que mire (un agente local junto a la impresora) queda para después de V1.

### Revisión manual por rol (13/10/2026)

Al escribir `QA-CLIENTE.md` y `QA-MESERO.md` se cruzó cada pantalla con el código y se corrigió:

- El Cliente ya puede pedir **combos** en el Menú web y por el QR de mesa; cada combo dice lo que
  incluye y acepta una nota para la cocina.
- Un Cliente ya no puede cancelar desde «Mis pedidos» una reserva con el depósito pagado en
  **efectivo**: ese dinero se devuelve en el local.
- El **enlace de seguimiento** ya no trae el teléfono ni el correo del cliente, ni quién del personal
  hizo cada paso.
- La lista de **pedidos del Mesero se actualiza sola** cada 20 segundos y avisa cuando llega un pedido
  nuevo de la web o del QR.
- Los **mensajes** ya no muestran códigos de reglas ni textos en inglés, y sin internet lo dicen.
- Después de crear la cuenta o iniciar sesión desde el Checkout, el Cliente **vuelve a su pedido**.

### Fase 7: lo que quedó a medias (13/10/2026)

- Un producto con presentaciones (por ejemplo, Personal y Familiar) se pide siempre con una de
  ellas. Si todas están apagadas o agotadas, el producto deja de ofrecerse en el Menú, el QR y
  Nuevo pedido, junto con los combos que lo usan. En Catálogo aparece el aviso «No se ofrece».
- Un combo lleva al menos dos productos: dos distintos, o dos del mismo.
- Catálogo > Combos muestra cuánto costarían los productos por separado y el ahorro del cliente.
  Si el combo no ahorra o cuesta más, lo avisa en amarillo.
- Usuarios > Editar, en un Mesero, tiene la casilla «Puede registrar compras y gastos». Con ella,
  el Mesero ve la pestaña «Gastos» (al recargar la página o volver a entrar). Allí:
  - registra lo que paga para el negocio, con la foto o el PDF del recibo;
  - ve solo lo que él registró.
  
  Corregir, anular y el cierre del día siguen siendo del Administrador. Un día ya cerrado tampoco
  lo registra el Mesero. Un gasto en efectivo se descuenta de la caja del día. Dar o quitar el
  permiso queda en Auditoría.
- En Catálogo, cada producto tiene «Elegir foto» (JPG, PNG o WebP). El servidor la achica para que
  el menú cargue rápido en el celular. La foto se ve en el Menú, el QR de mesa, el Inicio y Nuevo
  pedido, y como miniatura en la lista de productos. «Quitar foto» la borra. Cada cambio queda en
  Auditoría.
- Mesero > Cobrar muestra arriba si la caja de hoy está abierta, quién la abrió y el efectivo
  esperado. Si no está abierta, o ya se cerró, lo dice y apaga los botones de cobro.
- Los reportes de un mes (o más) cargan rápido aunque la base ya tenga mucha historia. Las cifras
  son las mismas de antes.
- En el servidor (además de lo de «Ponerlo en el servidor»):
  - `php artisan migrate`: tres migraciones nuevas, `2026_10_21_000001` y `000002` y
    `2026_10_22_000001` (solo índices, no cambia datos);
  - cuando ya haya historia, `php artisan reportes:medir` dice cuánto tarda cada reporte del último
    mes y marca «LENTO» lo que pase de 2 segundos;
  - la extensión GD de PHP (`sudo apt install php-gd`; la «Revisión antes de producción» avisa si
    falta);
  - incluir `storage/app/private/productos` en las copias de seguridad.

### Mejoras en producción (16/10/2026)

- Menú hamburguesa del celular: «Ingresar» va arriba, como botón, y el menú ya no queda tapado por
  la barra del navegador.
- Extras generales según M04 (RF-M04-011, CA-M04-004): se ofrecen solo con los productos a los que
  se asocian. Antes, uno sin asociar salía con todo el menú (a una Limonada se le podía pedir
  «Extra queso»). La migración `2026_10_23_000001` asoció los que estaban sueltos a los productos de
  tipo Alimento.
- Cada presentación tiene descripción («para 3 o 4 personas») y foto propias. En el Menú, el QR y el
  pedido del Mesero se ve qué trae cada una; al elegirla, el Menú y el QR muestran su foto (sin foto
  propia, la del producto).
- «Poner en oferta» en Catálogo > Productos: precio de oferta o porcentaje, hasta qué día y, si se
  quiere, una sola presentación (la «Familiar»). Es una promoción automática más: se ve con precio
  tachado, se combina con las demás y un descuento manual la reemplaza. Un producto tiene una oferta a
  la vez. El precio del botón «Agregar» del Menú y del QR ya trae la oferta.
- Caja: el turno de hoy y el detalle de cada turno muestran los ingresos del día por método
  (efectivo, tarjeta, transferencia y en línea), de dónde sale el efectivo esperado (base + cobros +
  depósitos − gastos − devoluciones), los depósitos de reserva del día (también los pagados en línea,
  que antes no salían en Caja) y los pagos anulados aparte. Botón «Actualizar» para ver los cobros
  nuevos sin recargar.
- Cierre diario: muestra los anticipos de reservas recibidos en el día (todavía no son venta) y los
  depósitos retenidos por no-show, que cuentan como ingreso del día en que se marca el no-show
  (RN-M05-18) y suman al resultado operativo. El reporte «Resultado operativo» los muestra como
  «Depósitos retenidos (no-show)». Los cierres hechos antes de este cambio no los traen.
- Importar productos desde Excel: Catálogo > Productos > «Importar». Se descarga la plantilla
  (Categoría, Nombre, Descripción, Precio base, Tipo de venta, Presentaciones, Disponible), se sube el
  archivo y se ve qué pasará con cada fila (nuevo, se actualiza, sin cambios o error) antes de guardar.
  Con un solo error no se guarda nada. Con «Actualizar los que ya existen» sirve para cambiar los
  precios de todo el menú de una vez (las celdas vacías no borran nada). Una categoría que no existe se
  crea. Acepta Excel y CSV (también el de Excel en español, con «;»), hasta 500 productos. Fotos,
  extras y combos se siguen manejando en Catálogo.
- Carrito del cliente: lo que se agrega dos veces igual se suma en una sola línea; se guarda en el
  navegador por 24 horas (ya no se pierde al recargar) y, al volver, se actualiza con el menú de hoy,
  avisando si se quitó algo. Nuevo diseño con foto, presentación, extras, precio por unidad y resumen.
- Botón flotante del pedido con la cantidad, en lugar de la barra fija de abajo, y un aviso breve al
  agregar («Agregado: Jugo en Agua · Ver pedido»).
- Web del cliente: el pie de página ya no sube mientras cargan las páginas; Reservar con pasos
  numerados, mesas como botones y el depósito a la vista; pie de página rediseñado para celular; en
  tablet, menú hamburguesa.
- Administrador: pestañas «Mesas | Reservas» para ver todas las reservas sin entrar por una mesa.
- App instalable en el teléfono (PWA): botón «Instalar app», ícono en la pantalla de inicio, pantalla
  completa y página «Sin conexión» (ver «App instalable en el teléfono (PWA)»). Hay que volver a copiar
  `nginx-fragua-real.conf` al VPS.
- 26/10/2026: hay que volver a copiar `nginx-fragua-real.conf` al VPS y recargar nginx
  (`sudo nginx -t && sudo systemctl reload nginx`). Ahora permite la ubicación del navegador
  (`Permissions-Policy … geolocation=(self)`); sin esto «Usar mi ubicación actual» del domicilio no funciona.
- Revisión antes de producción: ya no avisa «LOG_LEVEL=debug» cuando el `.env` dice `warning` con
  `LOG_STACK=daily` (leía el canal equivocado).
- En el servidor: `php artisan migrate --force` (tres migraciones `2026_10_23_*`); las fotos de las
  presentaciones quedan en `storage/app/private/variantes`, dentro del respaldo diario.

### Correcciones del 19/10/2026

- Reservas solo de 4:00 p. m. a 11:00 p. m. y con al menos 3 horas de anticipación, también las que
  registra el personal por teléfono. La pantalla Reservar ofrece las horas cada 30 minutos y marca
  «no disponible» las que ya no alcanzan; si hoy no queda ninguna, el primer día es mañana. Para
  cambiar el horario: `FRAGUA_REAL_RESERVA_HORA_INICIO`, `FRAGUA_REAL_RESERVA_HORA_FIN` y
  `FRAGUA_REAL_RESERVA_ANTICIPACION_MINUTOS` en el `.env` (y `php artisan config:cache`).
- Descripción opcional en la reserva («es un cumpleaños», «silla para bebé»): el personal la ve en
  Reservas y al cliente le llega en el correo de confirmación.
- El Mesero ya no ve las mesas dadas de baja (en Mesas, en Nuevo pedido ni en Reservas).
- En el servidor: `php artisan migrate --force` (migración `2026_10_26_000001`).
- **Rol SuperAdmin (M09, 20/10/2026)**: el dueño del sistema. Puede todo lo del Administrador y es el
  único que crea, edita, cambia de rol o desactiva cuentas de Administrador y que cambia el nombre y el
  NIT del negocio. Su cuenta no se crea desde la aplicación. En el VPS, después de desplegar:

  ```bash
  sudo -u www-data php artisan migrate --force
  sudo -u www-data php artisan fragua:crear-superadmin correo-del-dueño@dominio.com
  ```

  Si el correo ya es de una cuenta (por ejemplo, el Administrador de siempre), pide confirmación y la
  vuelve SuperAdmin conservando su contraseña. `--cambiar-clave` pone una contraseña nueva. La
  revisión antes de producción avisa si no hay SuperAdmin.
- **Permisos de menú por cuenta (M09, 20/10/2026)**: en Usuarios, el SuperAdmin abre «Permisos» de un
  Administrador o un Mesero y marca los menús que verá. Dashboard (Administrador) y Pedidos (Mesero) no se
  quitan. Lo quitado desaparece del menú lateral (en el Dashboard su acceso rápido sale bloqueado, con
  candado y «Sin permiso»), la dirección escrita a mano lleva al inicio de la
  cuenta y el servidor rechaza esas acciones. Por defecto toda cuenta ve todos los menús de su rol; al
  cambiar de rol vuelve a ver todos. La cuenta ve el cambio al navegar (se revisa cada minuto) o al
  volver a entrar. Hay una migración nueva: `php artisan migrate --force`.
- Datos del negocio: en Configuración > «Datos del negocio» el Administrador cambia la dirección, el
  teléfono (con él se arma el botón de WhatsApp) y el logo. Salen en el Inicio, el pie de página,
  Reservar, los encabezados de las pantallas, el menú del personal y los PDF (comprobante, cierre
  diario y reportes; el ticket térmico no lleva logo). Sin logo se ve el círculo «FR» de siempre.
  El logo queda en `storage/app/private/negocio`, dentro del respaldo diario. Mientras no se cambien,
  dirección y teléfono salen de `FRAGUA_REAL_DIRECCION` y `FRAGUA_REAL_TELEFONO` del `.env`.
- Ubicación del local (M09, 20/10/2026): en la misma tarjeta, el SuperAdmin marca el punto exacto en un
  mapa (tocándolo, arrastrando el marcador o pegando el enlace de «Compartir» de Google Maps). El Inicio
  muestra un mapa pequeño y «Cómo llegar» abre Google Maps en ese punto. El mapa usa OpenStreetMap: no
  necesita clave de Google ni cuesta nada, y la política de seguridad de nginx ya lo permite (son
  imágenes). Para leer un enlace corto, el servidor consulta solo páginas de Google; si el VPS no tiene
  salida a internet, se pueden pegar las coordenadas («1.6144, -75.6062»). Sin migración.
- **Rol Soporte (M10, 20/10/2026)**: la cuenta técnica del desarrollador. Entra al panel del
  Administrador con la franja «Modo soporte: solo consulta» y ve todo (también Revisión antes de
  producción, tareas automáticas y Auditoría), pero el servidor le rechaza cualquier cambio del negocio.
  Abrir un comprobante o un cierre no cuenta como impresión (sale «COPIA DE CONSULTA · SOPORTE») y no
  puede imprimir comandas. Se crea solo en el servidor:

  ```bash
  sudo -u www-data php artisan migrate --force
  sudo -u www-data php artisan fragua:crear-soporte correo-del-desarrollador@dominio.com --nombre="Nombre"
  ```

  El comando no convierte en Soporte una cuenta del negocio; con el correo de la cuenta de Soporte
  cambia el nombre o, con `--cambiar-clave`, la contraseña. En Usuarios solo la ve el SuperAdmin, que
  puede desactivarla (sus sesiones se cierran al momento), reactivarla y cerrar sus sesiones; el
  Administrador no la ve.
- **Contraseña segura y bloqueo por intentos (21/10/2026)**: toda contraseña nueva debe tener 8 caracteres como mínimo, con al menos una mayúscula, una minúscula y un número;
  las pantallas muestran los requisitos mientras se escribe. En producción también se rechazan las
  contraseñas filtradas (el servidor necesita salida a internet para eso; si no la tiene, no bloquea).
  Cinco intentos fallidos seguidos de una cuenta, o veinte desde una IP, bloquean el inicio de sesión
  15 minutos; el Administrador y el SuperAdmin reciben el aviso y recuperar la contraseña lo quita. Se
  ajusta con `FRAGUA_REAL_CLAVE_*` y `FRAGUA_REAL_LOGIN_*` en el `.env`. Las contraseñas de las cuentas
  que ya existen siguen sirviendo; al crear las cuentas reales en el VPS ya se exige la nueva regla.
- **Notificaciones del personal (M11, 21/10/2026)**: una campana en el panel del Mesero y del
  Administrador (en el teléfono, en la barra de arriba) con el contador de no leídas. Avisa de pedidos
  nuevos por la web o el QR, pedidos listos o cancelados por el cliente, pagos en línea, reservas
  (nueva, depósito pagado, cancelada, empieza en 30 min, no-show), caja y dinero (pago por revisar o
  anulado, caja abierta a las 11:30 p. m., cierre diario de ayer pendiente a las 10:00 a. m.),
  agotados, comandas que no salieron y avisos del sistema (tareas detenidas, trabajos fallidos,
  revisión antes de producción con errores; estos también le llegan a Soporte). Cada aviso le llega a
  quien le toca según su rol y sus menús, nunca a quien hizo la acción. La campana pregunta cada 20 s;
  no hace falta configurar nada nuevo en nginx. En el servidor: `php artisan migrate --force`; las
  tareas `notificaciones:revisar` (cada minuto) y `notificaciones:limpiar` (cada noche) ya entran con
  el cron de siempre. Las horas se cambian con `FRAGUA_REAL_NOTIF_*` en el `.env`.

## Datos de prueba en la base de desarrollo

Quedaron de las sesiones de prueba en la base del equipo de desarrollo. No son errores, pero hay que
limpiarlos (o empezar con una base nueva y `php artisan migrate --seed`) antes de usar el sistema con
datos reales:

- un turno de caja del 29/09/2026 que quedó abierto (no afecta la regla de un turno por día, que se
  evalúa por fecha);
- dos cuentas Cliente de prueba (`qa.cliente.cierre@fraguareal.test` y la de prueba de SMTP, ya
  desactivada);
- pedidos de prueba (#2 a #20) con sus cobros.
