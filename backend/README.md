# Backend — Fragua Real Burger

API REST en Laravel 13 (PHP 8.3) con PostgreSQL y autenticación por token con Laravel Sanctum. La
usa la aplicación web de `../frontend`.

## Correrlo

```bash
composer install
cp .env.example .env          # datos de PostgreSQL
php artisan key:generate
php artisan migrate --seed    # tablas y datos de prueba
php artisan serve             # http://localhost:8000
```

Para las tareas automáticas en el equipo: `php artisan schedule:work` y `php artisan queue:work` en
otras dos terminales.

## Pruebas

```bash
php artisan test              # 444 pruebas, SQLite en memoria
```

## Dónde está cada cosa

| Carpeta | Contenido |
|---|---|
| `routes/api.php` | Los 201 endpoints, agrupados por rol |
| `routes/console.php` | Comandos y tareas programadas |
| `app/Http/Controllers/Api` | 18 controladores, uno por área |
| `app/Http/Requests/Api` | Validación de cada petición (mensajes en español) |
| `app/Services` | Reglas de negocio (25 servicios) |
| `app/Policies` | Autorización sobre un registro (Pedido, Pago, CajaTurno, Reserva) |
| `app/Models` | 26 modelos, uno por tabla |
| `app/Support` | Pasarela Wompi, periodos de reportes, reimpresiones, política de reservas |
| `app/Jobs`, `app/Notifications` | Correos que salen por la cola |
| `resources/views` | Plantillas de PDF y tickets (comprobante, comanda, cierre, reportes) |
| `database/migrations` | 70 migraciones |
| `database/seeders` | Cuentas, menú y mesas de prueba |
| `deploy/` | Archivos para el VPS: Nginx, cron y Supervisor |
| `tests/Feature` | Pruebas por requisito, incluida la de un día completo |

## Documentación

En la raíz del repositorio:

- [`README-BACKEND.md`](../README-BACKEND.md): arquitectura, módulos, seguridad, configuración e
  historial de cambios;
- [`API.md`](../API.md): todos los endpoints con su rol;
- [`BASE-DE-DATOS.md`](../BASE-DE-DATOS.md): tablas, relaciones y migraciones;
- [`README-ENTREGA.md`](../README-ENTREGA.md): instalación y puesta en producción.
