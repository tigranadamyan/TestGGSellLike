#!/bin/sh
set -e

echo "Running migrations..."
php artisan migrate --force

echo "Starting scheduler in background..."
php artisan schedule:work &

echo "Starting Horizon..."
php artisan horizon
