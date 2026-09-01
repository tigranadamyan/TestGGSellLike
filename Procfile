web: php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan migrate --force && php artisan horizon
scheduler: php artisan schedule:work
