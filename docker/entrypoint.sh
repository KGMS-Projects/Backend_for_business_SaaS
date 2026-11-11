#!/bin/sh
set -e

# Copy .env if not present
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Ensure composer dependencies are installed
if [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
fi

# Generate application key if missing
php artisan key:generate --force

# Run migrations and seed sample data
php artisan migrate --force --seed

# Execute container command (php artisan serve)
exec "$@"
