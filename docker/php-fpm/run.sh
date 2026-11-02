#!/bin/bash

if [ ! -z "$XDEBUG_ENABLE" ] && [ "$XDEBUG_ENABLE" -eq 1 ]
then
    yes | pecl install xdebug
    rm -rf /tmp/pear
    echo "zend_extension=$(find /usr/local/lib/php/extensions/ -name 'xdebug.so')" > /usr/local/etc/php/conf.d/xdebug.ini;
    echo "xdebug.remote_port=9000" >> /usr/local/etc/php/conf.d/xdebug.ini;
    echo "xdebug.idekey=PHPSTORM" >> /usr/local/etc/php/conf.d/xdebug.ini;
    echo "xdebug.remote_autostart=off" >> /usr/local/etc/php/conf.d/xdebug.ini;
    echo "xdebug.remote_host=$(/sbin/ip route|awk '/default/ { print $3 }')" >> /usr/local/etc/php/conf.d/xdebug.ini;
    echo "xdebug.remote_enable=on" >> /usr/local/etc/php/conf.d/xdebug.ini;
    echo "xdebug.remote_connect_back=on" >> /usr/local/etc/php/conf.d/xdebug.ini;
    echo "xdebug.profiler_enable=1" >> /usr/local/etc/php/conf.d/xdebug.ini;
else
    rm /usr/local/etc/php/conf.d/xdebug.ini;
fi

php-fpm
