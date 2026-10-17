# Local Blesta 6 for developing the P2Flux gateway. Not for production.
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends \
        libgmp-dev libc-client-dev libkrb5-dev libldap2-dev libpng-dev libzip-dev libxml2-dev libicu-dev wget cron \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install pdo_mysql gmp imap ldap gd zip soap intl bcmath \
    && pecl install mailparse && docker-php-ext-enable mailparse \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*
# ionCube Loader (Blesta 6 needs v15+).
RUN wget -q -O /tmp/ioncube.tar.gz https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz \
    && tar -xzf /tmp/ioncube.tar.gz -C /tmp \
    && cp /tmp/ioncube/ioncube_loader_lin_8.3.so "$(php -r 'echo ini_get("extension_dir");')/" \
    && echo "zend_extension=ioncube_loader_lin_8.3.so" > /usr/local/etc/php/conf.d/00-ioncube.ini \
    && rm -rf /tmp/ioncube /tmp/ioncube.tar.gz
RUN { echo "memory_limit=512M"; echo "max_input_vars=10000"; echo "date.timezone=UTC"; } > /usr/local/etc/php/conf.d/blesta.ini
