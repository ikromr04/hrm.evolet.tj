#!/usr/bin/env bash
#
# Everything a fresh container needs before the application can be opened: the
# dependencies, an .env pointed at SQLite, a database with demo data in it, and
# a build of the front end so the first page works without a dev server.
#
# SQLite rather than MySQL because a Codespace has no database server of its own
# and the schema is the same either way — the whole test suite runs on SQLite.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> composer"
composer install --no-interaction --prefer-dist

echo "==> npm"
npm ci

if [ ! -f .env ]; then
    echo "==> .env"
    cp .env.example .env
    php artisan key:generate --ansi
fi

# The sqlite driver reads DB_DATABASE as the file to open, so it is written out
# in full: a bare name would be looked for relative to whatever the working
# directory happens to be. The MySQL lines are commented out rather than deleted,
# so switching back is a matter of uncommenting them.
touch database/database.sqlite

php -r '
$path = __DIR__."/.env";
$env = file_get_contents($path);

$env = preg_replace("/^DB_CONNECTION=.*$/m", "DB_CONNECTION=sqlite", $env);
$env = preg_replace("/^DB_DATABASE=.*$/m", "DB_DATABASE=".__DIR__."/database/database.sqlite", $env);

foreach (["DB_HOST", "DB_PORT", "DB_USERNAME", "DB_PASSWORD"] as $key) {
    $env = preg_replace("/^".$key."=(.*)$/m", "# ".$key."=$1", $env);
}

// Inside a Codespace the browser reaches the application through a forwarded
// HTTPS address, and links have to be written the way the page is read.
if (getenv("CODESPACE_NAME")) {
    $url = "https://".getenv("CODESPACE_NAME")."-8000.".getenv("GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN");
    $env = preg_replace("/^APP_URL=.*$/m", "APP_URL=".$url, $env);

    // artisan serve listens on 127.0.0.1 by default, which is reachable from
    // nothing but the container itself; the forwarded port needs it to answer
    // on every interface.
    if (! str_contains($env, "SERVER_HOST=")) {
        $env .= "\nSERVER_HOST=0.0.0.0\n";
    }
}

file_put_contents($path, $env);
'

echo "==> database"
php artisan migrate --force --seed

echo "==> build"
npm run build

cat <<'DONE'

Готово. Запустить приложение:

    composer run dev

Открыть порт 8000 (вкладка Ports) и войти: admin@evolet.test / password

DONE
