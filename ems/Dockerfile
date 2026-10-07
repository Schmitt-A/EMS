FROM node:22-bookworm AS assets
WORKDIR /src
COPY package.json package-lock.json* ./
RUN npm install
COPY tailwind.config.js ./
COPY app ./app
COPY bin/copy-assets.mjs ./bin/copy-assets.mjs
RUN npm run build

FROM php:8.3-fpm-bookworm
RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx libsqlite3-dev \
 && docker-php-ext-install pdo_sqlite \
 && rm -rf /var/lib/apt/lists/* \
 && echo 'date.timezone=Europe/Berlin' > /usr/local/etc/php/conf.d/timezone.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY app /opt/ems/app
COPY bin /opt/ems/bin
COPY --from=assets /src/app/public/assets /opt/ems/app/public/assets
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh /opt/ems/bin/recorder.php
WORKDIR /opt/ems
EXPOSE 8099
CMD ["/start.sh"]
