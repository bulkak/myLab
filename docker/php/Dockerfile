FROM php:8.3-fpm-alpine

# Установка системных зависимостей (включая Tesseract и PDF tools)
RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    oniguruma-dev \
    tesseract-ocr \
    poppler-utils \
    ghostscript \
    imagemagick \
    ttf-dejavu \
    font-freefont \
    font-liberation \
    $PHPIZE_DEPS

# Установка PHP расширений
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    gd \
    zip \
    mbstring \
    opcache

# Установка Redis расширения
RUN pecl install redis && \
    docker-php-ext-enable redis

# Установка AMQP расширения для RabbitMQ
RUN apk add --no-cache rabbitmq-c-dev && \
    pecl install amqp && \
    docker-php-ext-enable amqp

# Настройка OPcache для production
RUN { \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Настройка рабочей директории
WORKDIR /var/www/html

# Создание директории для загрузок и сессий
RUN mkdir -p /var/www/uploads /var/www/html/var/sessions/dev /var/www/html/var/sessions/prod && \
    chmod 777 /var/www/uploads /var/www/html/var /var/www/html/var/sessions /var/www/html/var/sessions/dev /var/www/html/var/sessions/prod && \
    chown -R 1000:1000 /var/www/uploads /var/www/html/var

# Настройка PHP для Symfony
RUN { \
    echo 'upload_max_filesize=50M'; \
    echo 'post_max_size=50M'; \
    echo 'memory_limit=512M'; \
    echo 'max_execution_time=300'; \
    } > /usr/local/etc/php/conf.d/custom.ini

EXPOSE 9000
CMD ["php-fpm"]
