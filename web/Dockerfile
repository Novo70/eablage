FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        locales \
    && docker-php-ext-install mysqli pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN echo "de_DE.UTF-8 UTF-8" > /etc/locale.gen \
    && locale-gen

ENV LANG=de_DE.UTF-8
ENV LC_ALL=de_DE.UTF-8
ENV TZ=Europe/Berlin

RUN a2enmod rewrite

# The bounded two-attempt Ollama request can take longer than Apache's default.
RUN echo 'Timeout 340' > /etc/apache2/conf-available/eablage-timeouts.conf \
    && a2enconf eablage-timeouts

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

COPY . /var/www/html/

RUN find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;
