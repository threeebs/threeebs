FROM node:22-bookworm-slim AS monaco-assets

RUN npm install --prefix /opt/monaco --ignore-scripts --no-audit --no-fund \
    monaco-editor@0.56.0

FROM node:22-bookworm-slim AS realtime-client-assets

WORKDIR /opt/realtime
COPY apps/realtime/package.json apps/realtime/client.js \
    apps/realtime/build-client.mjs apps/realtime/monaco-shim.js ./
RUN npm install --ignore-scripts --legacy-peer-deps --no-audit --no-fund \
    && npm run build:client

FROM php:8.3-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
ENV MAKEFLAGS="-j1"

RUN set -eux; \
    docker-php-ext-install -j1 pdo_mysql; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    a2enmod alias headers rewrite proxy proxy_http proxy_wstunnel

COPY --from=monaco-assets /opt/monaco/node_modules/monaco-editor/min/vs \
    /opt/threeebs/monaco/vs
COPY --from=monaco-assets /opt/monaco/node_modules/monaco-editor/LICENSE \
    /opt/threeebs/monaco/LICENSE.txt
COPY --from=realtime-client-assets /opt/realtime/dist/realtime-client.js \
    /opt/threeebs/realtime/realtime-client.js

COPY infrastructure/apache/threeebs-server-name.conf \
    /etc/apache2/conf-enabled/threeebs-server-name.conf
COPY infrastructure/apache/threeebs-entrypoint.sh \
    /usr/local/bin/threeebs-entrypoint

RUN chmod 0755 /usr/local/bin/threeebs-entrypoint

WORKDIR /var/www/app

ENTRYPOINT ["threeebs-entrypoint"]
CMD ["apache2-foreground"]
