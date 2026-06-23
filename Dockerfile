FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        default-jdk \
        g++ \
        gcc \
        gnupg \
        libcurl4-openssl-dev \
        libsqlite3-dev \
        nodejs \
        npm \
        python3 \
        sqlite3 \
        unzip \
        wget \
        zip \
    && install -d -m 0755 /etc/apt/keyrings \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /etc/apt/keyrings/microsoft.gpg \
    && chmod go+r /etc/apt/keyrings/microsoft.gpg \
    && echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/microsoft.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/microsoft-prod.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends dotnet-sdk-8.0 \
    && docker-php-ext-install curl pdo_mysql mysqli pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

RUN npm install --omit=dev

RUN mkdir -p runtime/code-execution uploads web-client/uploads \
    && chown -R www-data:www-data runtime uploads web-client/uploads \
    && chmod -R 775 runtime uploads web-client/uploads

EXPOSE 8080

CMD ["sh", "-c", "php /var/www/html/scripts/migrate.php || true; npm start"]
