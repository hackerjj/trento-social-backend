#!/bin/sh
set -e

echo "Trento Social Backend - Starting..."

# Debug: verify extensions are loaded
echo "PHP Extensions loaded:"
php -m | grep -iE "mongodb|redis|opcache"
echo "---"

# Write runtime env vars to .env so Laravel can read them
echo "Writing .env from runtime environment..."
env | grep -E '^(APP_|DB_|MONGO_|REDIS_|JWT_|CORS_|PORT|CACHE_|SESSION_|QUEUE_|LOG_|TRENTO_|GEO_)' | while read line; do
    key=$(echo "$line" | cut -d= -f1)
    value=$(echo "$line" | cut -d= -f2-)
    # Wrap value in quotes to handle spaces
    echo "$key=\"$value\""
done > .env

echo "DB_CONNECTION=mongodb" >> .env
echo "MONGO_DSN=${MONGO_URI}" >> .env

# Test Redis connectivity, fallback to file cache if unavailable
if [ -n "$REDIS_URL" ]; then
    php -r "
        try {
            \$url = getenv('REDIS_URL');
            \$parsed = parse_url(\$url);
            \$host = \$parsed['host'] ?? '127.0.0.1';
            \$port = \$parsed['port'] ?? 6379;
            \$fp = @fsockopen(\$host, \$port, \$errno, \$errstr, 3);
            if (\$fp) { fclose(\$fp); echo 'ok'; } else { echo 'fail'; }
        } catch (Exception \$e) { echo 'fail'; }
    " > /tmp/redis_check 2>/dev/null || echo "fail" > /tmp/redis_check
    REDIS_STATUS=$(cat /tmp/redis_check)
    if [ "$REDIS_STATUS" != "ok" ]; then
        echo "Redis unavailable, falling back to file cache"
        echo "CACHE_DRIVER=file" >> .env
    else
        echo "Redis connected"
    fi
else
    echo "No REDIS_URL set, using file cache"
    echo "CACHE_DRIVER=file" >> .env
fi

echo ".env written with $(wc -l < .env) variables"

# Clear any cached config
php artisan config:clear 2>/dev/null || true

# Run migrations (crea los indices de Mongo de trento_*)
echo "Running migrations..."
php artisan migrate --force 2>&1 || echo "Migration warning (may be OK)"

# Start server
echo "Starting Laravel server on port ${PORT:-10000}..."
php artisan serve --host=0.0.0.0 --port=${PORT:-10000}
