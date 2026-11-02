FROM php:8.4-fpm

RUN apt-get update && apt-get upgrade -y

RUN apt-get update && apt-get install -y --fix-missing wget libicu-dev libpq-dev libonig-dev git ssh libgd3 libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev zip xdg-utils xvfb optipng pngquant libgbm-dev libssl-dev
RUN apt-get update && apt-get install -y libmagickwand-dev libmagickcore-dev --no-install-recommends
RUN curl -sL https://deb.nodesource.com/setup_20.x | bash -
RUN apt-get update && apt-get install -y gnupg nodejs build-essential
RUN npm install -g yarn gulp-cli
RUN docker-php-ext-configure gd --enable-gd --with-freetype --with-jpeg --with-webp
RUN docker-php-ext-install gd intl pdo pdo_mysql gd zip calendar exif opcache zip ftp
RUN rm /etc/apt/preferences.d/no-debian-php && apt-get update -yqq && apt-get install -y --fix-missing libxml2-dev
RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
RUN sh -c 'echo "deb https://packages.sury.org/php/ bullseye main" > /etc/apt/sources.list.d/php.list'

# Composer
RUN curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER 1
RUN echo "export PATH=$HOME/.composer/vendor/bin:$PATH" >> /root/.bashrc

RUN git clone https://github.com/Imagick/imagick.git --depth 1 /tmp/imagick && \
    cd /tmp/imagick && \
    git fetch origin master && \
    git switch master && \
    cd /tmp/imagick && \
    phpize && \
    ./configure && \
    make && \
    make install && \
    docker-php-ext-enable imagick

COPY php.ini /usr/local/etc/php/

# Clean
RUN apt-get purge -y \
    && apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

ADD run.sh /
RUN chmod +x /run.sh

CMD ["/run.sh"]

