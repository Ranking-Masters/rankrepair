#!/usr/bin/env bash
# Draait de Interne Links-keten in een wegwerp-WordPress.
#
#   tests/wordpress/run.sh          start, seed en test
#   tests/wordpress/run.sh down     alles opruimen
#
# WordPress komt op http://localhost:8899 (admin / admin).
set -eu
cd "$(dirname "$0")"

if [ "${1:-}" = "down" ]; then
    docker compose down -v
    exit 0
fi

echo "› containers starten"
docker compose up -d >/dev/null

printf '› wachten op WordPress'
for _ in $(seq 1 60); do
    code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8899/ 2>/dev/null || echo 000)
    case "$code" in 200|302) echo " — klaar"; break ;; esac
    printf '.'; sleep 3
done

if ! docker compose exec -T cli wp core is-installed 2>/dev/null; then
    echo "› WordPress installeren"
    docker compose exec -T cli wp core install \
        --url=http://localhost:8899 --title="RankRepair test" \
        --admin_user=admin --admin_password=admin \
        --admin_email=test@example.com --skip-email >/dev/null
fi

# Fouten loggen in plaats van tonen. Met schermuitvoer aan breekt de inlogpagina:
# de add-ons roepen __() aan bij het laden van de plugin, wat een notice geeft
# vóór de headers, en dan mislukt de redirect na het inloggen.
docker compose exec -T cli wp config set WP_DEBUG_DISPLAY false --raw >/dev/null
docker compose exec -T cli wp config set WP_DEBUG_LOG true --raw >/dev/null

docker compose exec -T cli wp plugin activate rankrepair >/dev/null

if [ "$(docker compose exec -T cli wp post list --post_type=post --format=count)" -lt 7 ]; then
    echo "› testcontent aanmaken"
    docker compose exec -T cli wp eval-file \
        wp-content/plugins/rankrepair/tests/wordpress/seed.php 2>/dev/null | sed 's/^/   /'
fi

echo "› integratietest"
docker compose exec -T cli wp eval-file \
    wp-content/plugins/rankrepair/tests/wordpress/smoke.php 2>&1 \
    | grep -v "_load_textdomain_just_in_time"
