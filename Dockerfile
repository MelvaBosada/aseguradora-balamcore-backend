# syntax=docker/dockerfile:1.4
# PHP 8.2 para igualar tu entorno local y composer.lock
FROM php:8.2-fpm-alpine

# Instalar dependencias del sistema y extensiones de PHP necesarias para Laravel y PostgreSQL
# (gd con soporte JPEG/WebP/FreeType para los reportes fotográficos; exif para girar fotos de celular)
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) pdo pdo_pgsql bcmath gd exif

# Instalar Composer globalmente
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar el directorio de trabajo
WORKDIR /var/www/html

# Copiar los archivos del proyecto al contenedor
COPY . .

# Instalar las dependencias de producción de Laravel
RUN apk add --no-cache libzip-dev && docker-php-ext-install zip
RUN composer install --no-dev --optimize-autoloader --no-interaction

# <-- NUEVO: límites de subida de PHP
RUN { \
      echo "upload_max_filesize=50M"; \
      echo "post_max_size=55M"; \
      echo "memory_limit=256M"; \
      echo "max_execution_time=120"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# <-- NUEVO: crear carpetas de almacenamiento y las temporales de Nginx con permisos correctos
RUN mkdir -p \
        /var/www/html/storage/app/private/documentos \
        /var/www/html/storage/app/private/evidencias \
        /var/www/html/storage/app/private/polizas \
        /var/www/html/storage/app/public \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
        /tmp/nginx/client_body \
        /tmp/nginx/fastcgi \
        /tmp/nginx/proxy \
        /tmp/nginx/uwsgi \
        /tmp/nginx/scgi \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /tmp/nginx \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 777 /tmp/nginx

# ==========================================
# CREACIÓN DE ARCHIVOS DE CONFIGURACIÓN
# ==========================================

# 1. Crear el archivo de configuración de Nginx (puerto 8000)
COPY <<-"EOF" /etc/nginx/nginx.conf
user www-data;
worker_processes auto;
pid /run/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    sendfile on;
    keepalive_timeout 65;

    # NUEVO: tamaño máximo de subida (evita el 413)
    client_max_body_size 50M;

    # NUEVO: carpetas temporales con permisos para www-data (evita el 500 al subir archivos)
    client_body_temp_path /tmp/nginx/client_body;
    fastcgi_temp_path     /tmp/nginx/fastcgi;
    proxy_temp_path       /tmp/nginx/proxy;
    uwsgi_temp_path       /tmp/nginx/uwsgi;
    scgi_temp_path        /tmp/nginx/scgi;

    server {
        listen 8000;
        server_name localhost;
        root /var/www/html/public;

        index index.php index.html;
        charset utf-8;

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location = /favicon.ico { access_log off; log_not_found off; }
        location = /robots.txt  { access_log off; log_not_found off; }

        error_page 404 /index.php;

        location ~ \.php$ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
            include fastcgi_params;
            fastcgi_read_timeout 120;   # NUEVO
        }

        location ~ /\.ht {
            deny all;
        }
    }
}
EOF

# 2. Crear el archivo de configuración de Supervisor
COPY <<-"EOF" /etc/supervisor/conf.d/supervisord.conf
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/run/supervisord.pid

[program:php-fpm]
command=php-fpm
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=nginx -g "daemon off;"
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
EOF

# ==========================================
# ARRANQUE DEL SERVIDOR
# ==========================================

# Exponer el puerto 8000 (debe coincidir con el "Port" configurado en Render)
EXPOSE 8000

# Iniciar Supervisor para controlar Nginx y PHP-FPM juntos
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]