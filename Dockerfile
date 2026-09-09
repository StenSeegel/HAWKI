# =====================================================
# HAWKI application image
# =====================================================
# Single source of truth for every HAWKI container image. Built by
# .github/workflows/build-docker-image.yml (staging/prod, pushed to GHCR) and
# by _docker/deploy-dev.sh (dev, local live-mounted code).
#
# Targets:
#   node_builder  compiles the Vite frontend; VITE_* build args are baked in
#   app_dev       PHP-FPM + xdebug + composer, UID/GID remapped to the host user
#   app_prod      code + prod dependencies, regenerates .env from the environment
#   app_staging   code + prod dependencies, .env is bind-mounted by compose;
#                 declares public/ as a VOLUME for nginx (volumes_from). This is
#                 the target CI builds for BOTH staging and prod.
# =====================================================

# -----------------------------------------------------
# NODE - ROOT
# -----------------------------------------------------
FROM node:23-bookworm AS node_root

ARG APP_ENV=prod

ENV APP_ENV=${APP_ENV}

ARG DOCKER_RUNTIME=docker

ARG DOCKER_GID=1000

ARG DOCKER_UID=1000

# Proxy configuration for build
ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY
ENV HTTP_PROXY=${HTTP_PROXY}
ENV HTTPS_PROXY=${HTTPS_PROXY}
ENV NO_PROXY=${NO_PROXY}

WORKDIR /var/www/html


# -----------------------------------------------------
# NODE - BUILDER
# -----------------------------------------------------
FROM node_root AS node_builder

# Build timestamp to invalidate cache and force rebuild
# MUST be before COPY to invalidate the copy layer
ARG CACHEBUST=1
RUN echo "Building frontend assets (cache bust: $CACHEBUST)"

# Build args for Vite frontend
ARG VITE_APP_NAME=HAWKI2
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https

# Pass to environment for npm build
ENV VITE_APP_NAME=${VITE_APP_NAME}
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV VITE_REVERB_HOST=${VITE_REVERB_HOST}
ENV VITE_REVERB_PORT=${VITE_REVERB_PORT}
ENV VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

RUN chown node:node /var/www/html

# Add the app sources
COPY --chown=node:node . .

USER node

RUN rm -rf ./.env
RUN npm install && npm run build


# =====================================================
# APP service
# =====================================================
# APP - ROOT
# -----------------------------------------------------
FROM php:8.4-fpm-bookworm AS app_root

LABEL org.opencontainers.image.authors="HAWKI Team <ki@hawk.de>"
LABEL org.opencontainers.image.description="The HAWKI application image"

ARG APP_ENV=prod

ENV APP_ENV=${APP_ENV}

ARG DOCKER_RUNTIME=docker

ARG DOCKER_GID=1000

ARG DOCKER_UID=1000

# Proxy configuration for build
ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY
ENV HTTP_PROXY=${HTTP_PROXY}
ENV HTTPS_PROXY=${HTTPS_PROXY}
ENV NO_PROXY=${NO_PROXY}

WORKDIR /var/www/html

RUN --mount=type=cache,id=apt-cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,id=apt-lib,target=/var/lib/apt,sharing=locked\
    apt-get update && apt-get upgrade -y && apt-get install -y \
    bash \
    curl \
    ca-certificates \
    openssl \
    openssh-client \
    git \
    libxml2-dev \
    tzdata \
    libicu-dev \
    openntpd \
    libedit-dev \
    libzip-dev \
    supervisor \
    libwebp-dev \
    # Install fcgi for healthcheck
    libfcgi-bin \
    # LDAP dependencies for Debian Bookworm
    libldap-dev \
    libldap-2.5-0 \
    libldap-common \
    # MySQL client for database backups (mysqldump)
    default-mysql-client \
    unzip \
    && apt-get clean

RUN --mount=type=cache,id=apt-cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,id=apt-lib,target=/var/lib/apt,sharing=locked\
    --mount=type=bind,from=mlocati/php-extension-installer:latest,source=/usr/bin/install-php-extensions,target=/usr/local/bin/install-php-extensions \
    install-php-extensions \
        apcu \
        bcmath \
        bz2 \
        exif \
        gd \
        intl \
        opcache \
        pdo_mysql \
        xmlrpc \
        zip \
        redis \
        pcntl \
        ldap

# LDAP installation with dynamic architecture detection
RUN docker-php-ext-configure ldap --with-libdir=lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH) \
    && docker-php-ext-install ldap \
    && docker-php-ext-enable ldap

# Verify LDAP installation
RUN php -m | grep ldap || echo "WARNING: LDAP extension not loaded"

# Add additional port for reverb
EXPOSE 8080

COPY docker/php/config/fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/config/php.common.ini /usr/local/etc/php/conf.d/zzz.app.common.ini
COPY docker/php/config/php.prod.ini /usr/local/etc/php/conf.d/zzz.app.prod.ini
COPY docker/php/config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# The entrypoint sources /user/bin/app/boot.local.sh when present, which is how
# each target below hooks in its own boot steps.
COPY --chown=1000:1000 --chmod=+x docker/php/bin /user/bin/app

ENTRYPOINT ["/user/bin/app/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# -----------------------------------------------------
# APP - DEV
# -----------------------------------------------------
FROM app_root AS app_dev

ENV DOCKER_RUNTIME=${DOCKER_RUNTIME:-docker}

ENV APP_ENV=dev

# Add utilities for dev
RUN --mount=type=cache,id=apt-cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,id=apt-lib,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get upgrade -y && apt-get install -y \
    sudo

# Install xdebug
RUN --mount=type=cache,id=apt-cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,id=apt-lib,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get upgrade -y \
    && apt-get install -y $PHPIZE_DEPS \
    && pecl install xdebug-3.4.1 \
    && docker-php-ext-enable xdebug

# Add Composer
COPY --from=index.docker.io/library/composer:latest /usr/bin/composer /usr/bin/composer

# Because we inherit from the prod image, we don't actually want the prod settings
COPY docker/php/config/php.dev.ini /usr/local/etc/php/conf.d/zzz.app.dev.ini
RUN rm -rf /usr/local/etc/php/conf.d/zzz.app.prod.ini

# Recreate the www-data user and group with the current users id
RUN groupdel -f www-data || true && \
    userdel -r www-data || true && \
    # Check if group with target GID already exists and rename it
    EXISTING_GROUP=$(getent group ${DOCKER_GID} | cut -d: -f1) && \
    if [ ! -z "$EXISTING_GROUP" ]; then \
        groupmod -g 9999 "$EXISTING_GROUP" || true; \
    fi && \
    groupadd -g ${DOCKER_GID} www-data && \
    useradd -u ${DOCKER_UID} -g www-data www-data && \
    mkdir -p /home/www-data && \
    chown -R www-data:www-data /home/www-data && \
    usermod -aG sudo www-data && \
    echo "www-data ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/www-data && \
    chmod 0440 /etc/sudoers.d/www-data

COPY --chmod=+x docker/php/php.entrypoint.dev.sh /user/bin/app/boot.local.sh

USER www-data


# -----------------------------------------------------
# APP - PROD
# -----------------------------------------------------
FROM app_root AS app_prod

RUN echo "umask 000" >> /root/.bashrc

USER www-data

# Add the app sources
COPY --chown=www-data:www-data . .
COPY --from=node_builder --chown=www-data:www-data /var/www/html/public/build /var/www/html/public/build
RUN rm -rf /var/www/html/hot

# Record what was built; the admin panel shows the commit. .git is not part of
# the build context, so CI passes these in (see build-docker-image.yml).
ARG GIT_COMMIT=unknown
ARG GIT_REF=unknown
RUN printf '{"commit":"%s","ref":"%s","built_at":"%s"}\n' "$GIT_COMMIT" "$GIT_REF" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > /var/www/html/build_info.json

# Install the composer dependencies, without running any scripts, this allows us to install the dependencies
# in a single layer and caching them even if the source files are changed
RUN --mount=type=cache,id=composer-cache,target=/var/www/html/.composer-cache \
    --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    export COMPOSER_CACHE_DIR="/var/www/html/.composer-cache" \
    && composer install --no-dev --no-progress --no-interaction --verbose --no-autoloader --no-scripts --no-plugins

# Dump the autoload file and run the matching scripts, after all the project files are in the image
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer dump-autoload --no-dev --optimize --no-interaction --verbose --no-cache

# Create the script that prepares the env variables when the container boots
COPY docker/php/prepareEnvVariables.php /var/www/prepareEnvVariables.php
COPY --chmod=+x docker/php/php.entrypoint.prod.sh /user/bin/app/boot.local.sh

USER root


# -----------------------------------------------------
# APP - STAGING
# -----------------------------------------------------
# Production-like image (no xdebug, prod dependencies) that gets its .env
# bind-mounted by compose instead of regenerating it on boot. Debug mode is a
# matter of APP_DEBUG in that .env. Deployed to both staging and prod.
FROM app_root AS app_staging

# Switch to www-data to copy code
USER www-data

# Add the app sources
COPY --chown=www-data:www-data . .
COPY --from=node_builder --chown=www-data:www-data /var/www/html/public/build /var/www/html/public/build
RUN rm -rf /var/www/html/hot

# Record what was built; the admin panel shows the commit. .git is not part of
# the build context, so CI passes these in (see build-docker-image.yml).
ARG GIT_COMMIT=unknown
ARG GIT_REF=unknown
RUN printf '{"commit":"%s","ref":"%s","built_at":"%s"}\n' "$GIT_COMMIT" "$GIT_REF" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > /var/www/html/build_info.json

# nginx serves public/ straight from this container via volumes_from, which
# only works for paths declared as a volume.
VOLUME /var/www/html/public

# Install the composer dependencies WITHOUT dev dependencies
RUN --mount=type=cache,id=composer-cache,target=/var/www/html/.composer-cache \
    --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    export COMPOSER_CACHE_DIR="/var/www/html/.composer-cache" \
    && composer install --no-dev --no-progress --no-interaction --verbose --no-autoloader --no-scripts --no-plugins

# Dump the autoload file
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction --verbose --no-cache

USER root

COPY --chmod=+x docker/php/php.entrypoint.staging.sh /user/bin/app/boot.local.sh
