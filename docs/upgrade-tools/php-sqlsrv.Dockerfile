# PHP CLI image with the Microsoft SQL Server drivers, for running Snipe-IT's
# migrations, test suite and a throwaway web server against SQL Server.
# Not a production image. See docs/upgrading-from-upstream.md.
#
#   docker build -t snipeit-php-mssql -f docs/upgrade-tools/php-sqlsrv.Dockerfile docs/upgrade-tools
#
# Match the PHP minor version to what production runs.
FROM php:8.3-cli-bookworm

ENV ACCEPT_EULA=Y DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends gnupg curl ca-certificates unzip git \
      libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libldap2-dev libicu-dev libxml2-dev libonig-dev unixodbc-dev \
 && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
 && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list \
 && apt-get update && apt-get install -y msodbcsql18 mssql-tools18 \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd zip ldap intl bcmath exif pcntl mbstring xml \
 && pecl install sqlsrv pdo_sqlsrv && docker-php-ext-enable sqlsrv pdo_sqlsrv \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN echo "memory_limit=-1" > /usr/local/etc/php/conf.d/zz-mem.ini

ENV PATH="/opt/mssql-tools18/bin:${PATH}"

WORKDIR /app
