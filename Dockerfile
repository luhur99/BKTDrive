FROM php:8.2-apache

# Install dependensi sistem
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install ekstensi PHP
RUN docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        zip \
        gd \
        curl \
        ftp \
        mbstring \
        opcache

# Aktifkan Apache mod_rewrite
RUN a2enmod rewrite

# Konfigurasi Apache untuk AllowOverride
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# PHP production settings
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "upload_max_filesize = 200M" >> "$PHP_INI_DIR/php.ini" \
    && echo "post_max_size = 210M"       >> "$PHP_INI_DIR/php.ini" \
    && echo "max_execution_time = 300"   >> "$PHP_INI_DIR/php.ini" \
    && echo "memory_limit = 256M"        >> "$PHP_INI_DIR/php.ini"

# Salin semua file aplikasi
WORKDIR /var/www/html
COPY . .

# Hapus file sensitif yang tidak perlu ada di container
RUN rm -f setup.php .env.example

# Buat folder storage dan set permission
RUN mkdir -p storage \
    && chown -R www-data:www-data storage \
    && chmod -R 755 storage \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
