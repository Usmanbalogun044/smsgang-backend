FROM ubuntu:22.04

LABEL maintainer="NaijaDaily"

ARG WWWGROUP=1000
ARG NODE_VERSION=18
ARG POSTGRES_VERSION=15

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND noninteractive
ENV TZ=UTC

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update \
    && apt-get install -y gnupg gosu curl ca-certificates zip unzip git supervisor sqlite3 libcap2-bin libpng-dev python2 dnsutils librsvg2-bin nginx netcat-openbsd \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c' | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y php8.4-fpm php8.4-cli php8.4-dev \
       php8.4-pgsql php8.4-sqlite3 php8.4-gd php8.4-imagick \
       php8.4-curl \
       php8.4-imap php8.4-mysql php8.4-mbstring \
       php8.4-xml php8.4-zip php8.4-bcmath php8.4-soap \
       php8.4-intl php8.4-readline \
       php8.4-ldap \
       php8.4-msgpack php8.4-igbinary php8.4-redis php8.4-swoole \
       php8.4-memcached php8.4-pcov php8.4-xdebug \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && curl -sLS https://deb.nodesource.com/setup_$NODE_VERSION.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm \
    && curl -sS https://dl.yarnpkg.com/debian/pubkey.gpg | gpg --dearmor | tee /etc/apt/keyrings/yarn.gpg >/dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/yarn.gpg] https://dl.yarnpkg.com/debian/ stable main" > /etc/apt/sources.list.d/yarn.list \
    && curl -sS https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor | tee /etc/apt/keyrings/pgdg.gpg >/dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt jammy-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y yarn \
    && apt-get install -y mysql-client \
    && apt-get install -y postgresql-client-$POSTGRES_VERSION \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/tmp/*

RUN mkdir -p /tmp && chmod 1777 /tmp /var/tmp

RUN chown -R www-data:www-data /var/www/html

RUN npm install sails -g

RUN setcap "cap_net_bind_service=+ep" /usr/bin/php8.4

RUN groupadd --force -g $WWWGROUP sail
RUN useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1337 sail

COPY ./server/docker/start-container /usr/local/bin/start-container
COPY ./server/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./server/docker/php.ini /etc/php/8.4/fpm/conf.d/99-sail.ini
COPY ./server/docker/php.ini /etc/php/8.4/cli/conf.d/99-sail.ini
COPY ./server/docker/nginx.conf /etc/nginx/nginx.conf
RUN chmod +x /usr/local/bin/start-container

# Copy application files
COPY . .

# Install dependencies with update to lock file
RUN composer install --no-ansi --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage \
    && mkdir -p /var/www/html/storage/logs \
    && mkdir -p /var/www/html/storage/framework/views \
    && mkdir -p /var/www/html/storage/framework/cache \
    && touch /var/www/html/storage/logs/laravel.log \
    && chmod 664 /var/www/html/storage/logs/laravel.log \
    && chmod -R 777 /var/www/html/storage/framework

RUN chmod -R 777 /var/www/html/bootstrap \
    && mkdir -p /var/www/html/bootstrap/cache \
    && chmod -R 777 /var/www/html/bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["start-container"]
