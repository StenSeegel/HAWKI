#!/bin/bash
# HAWKI Development Commands Helper
set -e

show_help() {
    echo "HAWKI Development Commands:"
    echo "  artisan <cmd>  - Run artisan command"
    echo "  tinker         - Start Laravel Tinker"
    echo "  test           - Run PHPUnit tests"
    echo "  migrate        - Run database migrations"
    echo "  seed           - Run database seeders"
    echo "  optimize       - Clear and optimize caches"
    echo "  queue          - Start queue worker"
    echo "  reverb         - Start Reverb WebSocket server"
}

case "${1:-help}" in
    artisan) shift; php artisan "$@" ;;
    tinker) php artisan tinker ;;
    test) shift; php artisan test "$@" ;;
    migrate) php artisan migrate --force ;;
    seed) php artisan db:seed --force ;;
    optimize) php artisan optimize:clear; php artisan config:cache ;;
    queue) php artisan queue:work -vv --queue=default,mails,message_broadcast ;;
    reverb) php artisan reverb:start --debug ;;
    *) show_help ;;
esac
