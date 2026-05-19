#!/bin/sh

composer install
php bin/console doctrine:migrations:migrate --no-interaction
php-fpm