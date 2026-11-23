# Image chạy LMS (Laravel 11) trên Render / bất kỳ nền tảng Docker nào
FROM php:8.3-apache

# Thư viện hệ thống + extension PHP cần cho Laravel, dompdf, maatwebsite/excel
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libxml2-dev libicu-dev \
        unzip git ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip intl bcmath exif \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Cấu hình PHP: tắt output buffering để AI chat stream (SSE) chạy mượt
RUN { \
      echo 'output_buffering=Off'; \
      echo 'zlib.output_compression=Off'; \
      echo 'upload_max_filesize=20M'; \
      echo 'post_max_size=25M'; \
      echo 'memory_limit=256M'; \
      echo 'max_execution_time=300'; \
    } > /usr/local/etc/php/conf.d/lms.ini

# Apache trỏ vào thư mục public/ của Laravel
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Cài thư viện PHP trước (tận dụng cache khi build lại)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

# Thư mục storage/ bị .gitignore nên phải tự tạo
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && sed -i 's/\r$//' docker/start.sh \
    && chmod +x docker/start.sh

EXPOSE 10000
CMD ["docker/start.sh"]
