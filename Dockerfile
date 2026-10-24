# syntax=docker/dockerfile:1.7
# Fragua Real Burger: imagen única para Dokploy (Vue + Laravel + cola + programador).

# ---------- 1. Frontend (Vue) ----------
FROM node:22-alpine AS frontend
WORKDIR /app
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN echo "VITE_API_BASE_URL=/api" > .env && npm run build

# ---------- 2. Dependencias PHP ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/ ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --ignore-platform-reqs

# ---------- 3. Imagen final ----------
FROM php:8.3-fpm-alpine

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN apk add --no-cache nginx supervisor \
 && install-php-extensions pdo_pgsql pgsql gd zip intl bcmath opcache pcntl \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf "upload_max_filesize=6M\npost_max_size=8M\nmemory_limit=256M\nexpose_php=Off\n" > "$PHP_INI_DIR/conf.d/zz-fragua.ini"

WORKDIR /var/www/backend
COPY --from=vendor /app /var/www/backend
COPY --from=frontend /app/dist /var/www/frontend/dist
RUN php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache

# Nginx: el sitio Vue en / y la API de Laravel en /api (igual que backend/deploy/nginx-fragua-real.conf).
COPY <<'EOF' /etc/nginx/http.d/default.conf
server {
    listen 80 default_server;
    server_tokens off;
    client_max_body_size 8m;
    root /var/www/frontend/dist;
    index index.html;

    location ~ /\.(?!well-known) { deny all; }

    location ^~ /api {
        root /var/www/backend/public;
        try_files $uri /index.php?$query_string;
    }

    location = /index.php {
        root /var/www/backend/public;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        # Traefik (Dokploy) termina el HTTPS: Laravel debe saber que el sitio va por https.
        fastcgi_param HTTPS on;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60s;
    }

    location ~ \.php$ { return 404; }

    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable" always;
        try_files $uri =404;
    }

    location = /sw.js {
        add_header Cache-Control "no-cache" always;
        try_files $uri =404;
    }

    location = /manifest.webmanifest {
        default_type application/manifest+json;
        add_header Cache-Control "no-cache" always;
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
EOF

# Supervisor: Nginx, PHP-FPM, la cola y el programador de tareas en el mismo contenedor.
COPY <<'EOF' /etc/supervisord.conf
[supervisord]
nodaemon=true
user=root
logfile=/dev/null
logfile_maxbytes=0
pidfile=/run/supervisord.pid

[program:php-fpm]
command=php-fpm -F
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true

[program:nginx]
command=nginx -g "daemon off;"
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true

[program:cola]
command=php /var/www/backend/artisan queue:work database --sleep=3 --tries=3 --backoff=60 --max-time=3600
directory=/var/www/backend
user=www-data
autorestart=true
stopwaitsecs=60
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true

[program:programador]
command=php /var/www/backend/artisan schedule:work
directory=/var/www/backend
user=www-data
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
EOF

# Arranque: espera la base, migra, siembra solo la primera vez y levanta todo.
COPY --chmod=0755 <<'EOF' /usr/local/bin/iniciar-fragua
#!/bin/sh
set -e
cd /var/www/backend

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/private bootstrap/cache

# APP_KEY: si no viene en las variables, se crea una y se guarda en el volumen (no cambia entre despliegues).
if [ -z "$APP_KEY" ]; then
  [ -f storage/app/.appkey ] || echo "base64:$(head -c 32 /dev/urandom | base64)" > storage/app/.appkey
  export APP_KEY="$(cat storage/app/.appkey)"
fi

until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 5432).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Exception $e) { exit(1); }'; do
  echo "Esperando PostgreSQL..."
  sleep 2
done

php artisan migrate --force
if [ ! -f storage/app/.sembrado ]; then
  php artisan db:seed --force
  touch storage/app/.sembrado
fi
php artisan config:cache
php artisan route:cache

chown -R www-data:www-data storage bootstrap/cache
exec /usr/bin/supervisord -c /etc/supervisord.conf
EOF

EXPOSE 80
CMD ["iniciar-fragua"]
