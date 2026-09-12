FROM php:8.3-apache-bookworm@sha256:fa8852a2e01747ffe8c8768bfd6bbc2f296f974aa0de90ee157a66664996d263

ENV APACHE_RUN_DIR=/tmp/apache2 \
    APACHE_LOCK_DIR=/tmp/apache2/lock \
    APACHE_PID_FILE=/tmp/apache2/apache2.pid \
    APACHE_LOG_DIR=/tmp/apache2/log \
    PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d

RUN set -eux; \
    docker-php-ext-install -j1 pdo_mysql opcache; \
    a2dismod -f autoindex status; \
    sed -ri 's!Listen 80!Listen 8080!' /etc/apache2/ports.conf; \
    rm -f /etc/apache2/sites-enabled/000-default.conf; \
    install -d -o www-data -g www-data /var/www/project

COPY infrastructure/runtime/php/runtime-vhost.conf /etc/apache2/sites-enabled/000-runtime.conf
COPY infrastructure/runtime/php/php-security.ini /usr/local/etc/php/conf.d/zz-threeebs-security.ini
COPY infrastructure/runtime/php/runtime-entrypoint.sh /usr/local/bin/threeebs-runtime-entrypoint

RUN chmod 0755 /usr/local/bin/threeebs-runtime-entrypoint

USER www-data:www-data
WORKDIR /var/www/project
EXPOSE 8080

HEALTHCHECK --interval=5s --timeout=2s --start-period=5s --retries=6 \
    CMD ["php", "-r", "$s=@fsockopen('127.0.0.1',8080,$e,$m,1); exit(is_resource($s)?0:1);"]

ENTRYPOINT ["threeebs-runtime-entrypoint"]
CMD ["apache2-foreground"]
