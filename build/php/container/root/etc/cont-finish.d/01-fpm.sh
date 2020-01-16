#!/bin/bash

# To produce an orderly stop, drain connections from the reverse proxy.
# Once the proxy has no active connections, plug can be pulled,
# as there are no more active connections.

echo "[finish php-fpm] starting graceful shutdown"
echo "[finish php-fpm] shutting down"

# TODO: bypass FPM's clunky ungraceful shutdown, which breaks stdout and outputs ugly warnings
# @see https://stackoverflow.com/questions/36564074/nginx-php-fpm-graceful-stop-sigquit-not-so-graceful
# pkill -QUIT -o php-fpm

