FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        default-jdk \
        g++ \
        gcc \
        nodejs \
        npm \
        python3 \
        unzip \
        zip \
    && docker-php-ext-install pdo_mysql mysqli \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

RUN mkdir -p runtime/code-execution uploads web-client/uploads \
    && chown -R www-data:www-data runtime uploads web-client/uploads \
    && chmod -R 775 runtime uploads web-client/uploads

EXPOSE 80

CMD ["apache2-foreground"]
