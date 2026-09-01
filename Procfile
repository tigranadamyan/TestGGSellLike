web: php artisan migrate --force && php artisan l5-swagger:generate && php -S 0.0.0.0:$PORT -t public server.php
worker: php artisan migrate --force && php artisan horizon
scheduler: php artisan schedule:work
