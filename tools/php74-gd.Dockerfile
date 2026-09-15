# PHP 7.4 with ext-gd, for verifying the generated 7.4 build's compositing path.
#
# The base php:7.4-cli image has no gd, and gd is the half of this package that a
# syntax check cannot exercise at all — it is where the code actually draws.
FROM php:7.4-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/*
