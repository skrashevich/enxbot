#!/bin/bash
set -eu

cd /app

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

cron_interval="${ENXBOT_CRON_INTERVAL:-60}"
case "$cron_interval" in
    ''|*[!0-9]*) echo "ENXBOT_CRON_INTERVAL должен быть положительным числом" >&2; exit 2 ;;
esac
if ! [ "$cron_interval" -gt 0 ] 2>/dev/null; then
    echo "ENXBOT_CRON_INTERVAL должен быть положительным числом" >&2
    exit 2
fi
export ENXBOT_CRON_INTERVAL="$cron_interval"

# Только проверка конфигурации и инициализация БД. Владением cron-lock управляет cron.php.
php -r "require 'config.php'; require 'db.php';"

pids=()
cleanup() {
    trap '' TERM INT
    # setsid изолирует каждую службу вместе со всеми её потомками.
    for pid in "${pids[@]}"; do
        kill -TERM -- "-$pid" 2>/dev/null || true
    done
    # Ограниченная пауза для shutdown handlers, затем завершаем зависших потомков.
    for ((attempt=0; attempt<50; attempt++)); do
        alive=0
        for pid in "${pids[@]}"; do
            if kill -0 -- "-$pid" 2>/dev/null; then alive=1; fi
        done
        [ "$alive" -eq 0 ] && break
        sleep 0.1
    done
    for pid in "${pids[@]}"; do
        kill -KILL -- "-$pid" 2>/dev/null || true
    done
    wait 2>/dev/null || true
    rm -f /tmp/enxbot-polling.pid
}
trap 'exit 0' TERM INT
trap cleanup EXIT

setsid php daemon.php &
pids+=("$!")
setsid bash -c '
    while :; do
        php cron.php || echo "cron.php завершился с ошибкой, повтор через ${ENXBOT_CRON_INTERVAL} с" >&2
        sleep "$ENXBOT_CRON_INTERVAL"
    done
' &
pids+=("$!")
setsid php polling.php &
polling_pid=$!
pids+=("$polling_pid")
echo "$polling_pid" > /tmp/enxbot-polling.pid

# Выход любой службы завершает контейнер, чтобы restart policy восстановила весь набор.
set +e
wait -n "${pids[@]}"
status=$?
set -e
# Даже штатный неожиданный выход службы должен быть виден как отказ контейнера.
[ "$status" -ne 0 ] || status=1
exit "$status"
