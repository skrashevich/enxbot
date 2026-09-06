#!/usr/bin/env bash
#
# Смоук-тест бота на PHP 8.
#
# Поднимает временную БД, заглушки внешних сервисов и веб-сервер с копией
# проекта, после чего прогоняет функции и вебхук-сценарии bot.php,
# а также cron.php и daemon.php. Любой Fatal/Warning/Deprecated - провал.
#
# Требования: php 8.x с PDO SQLite, доступ к localhost.
#
# Использование: tests/run.sh

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TESTS="$ROOT/tests"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/enxbot-test.XXXXXX")"
# Источник encx-cli (биндинги + encx-mock) — апстрим на GitHub, как в Dockerfile.
# Локальный чекаут можно навязать через ENCX_REPO=/path. resolve_encx_source
# вызывается ниже, перед сборкой биндингов.
. "$TESTS/lib/encx-source.sh"

DB_PATH="$WORK/enxbot.sqlite"

APP_PORT=18080
STUB_PORT=18081

APP_PID=""
STUB_PID=""
MOCK_PID=""
DAEMON_STATUS=0
MOCK_STATUS=0
POLLING_STATUS=0
MAP_STATUS=0

cleanup() {
    [ -n "$APP_PID" ] && kill "$APP_PID" 2>/dev/null
    [ -n "$STUB_PID" ] && kill "$STUB_PID" 2>/dev/null
    [ -n "$MOCK_PID" ] && kill "$MOCK_PID" 2>/dev/null
    wait 2>/dev/null
    rm -f "$TESTS/../.stub_fail_mode"
    rm -rf "$WORK"
}
trap cleanup EXIT

sqlite_cmd() {
    php "$TESTS/sqlite.php" "$DB_PATH" "$@"
}

echo "== Проверка синтаксиса (php -l) =="
LINT_FAILED=0
for f in "$ROOT"/*.php "$TESTS"/*.php; do
    if ! php -l "$f" > /dev/null; then
        echo "  СИНТАКСИЧЕСКАЯ ОШИБКА: $f"
        php -l "$f"
        LINT_FAILED=1
    fi
done
[ "$LINT_FAILED" -eq 0 ] && echo "  ok   все файлы разбираются на $(php -r 'echo PHP_VERSION;')"
[ "$LINT_FAILED" -eq 1 ] && exit 1

echo "== Источник encx-cli =="
resolve_encx_source "$ROOT" || exit 1

echo "== Сборка PHP-биндингов encx-cli =="
BINDINGS_STALE=0
if [ ! -f "$ENCX_BINDINGS_PATH/build.sh" ]; then
    echo "  ПРОВАЛ: биндинги не найдены в $ENCX_BINDINGS_PATH"
    exit 1
fi
if (cd "$ENCX_BINDINGS_PATH" && bash build.sh > "$WORK/bindings.log" 2>&1); then
    echo "  ok   $ENCX_BINDINGS_PATH"
elif ls "$ENCX_BINDINGS_PATH"/lib/libencx.* > /dev/null 2>&1; then
    # Соседний репозиторий может быть временно сломан чужой правкой.
    # Тесты бота продолжаем на ранее собранной библиотеке, но честно
    # предупреждаем: результат получен не на свежих биндингах.
    BINDINGS_STALE=1
    echo "  ВНИМАНИЕ: сборка биндингов провалилась, используется ранее собранная библиотека"
    sed 's/^/    /' "$WORK/bindings.log" | tail -10
else
    echo "  ПРОВАЛ: биндинги не собираются и готовой библиотеки нет"
    sed 's/^/    /' "$WORK/bindings.log" | tail -20
    exit 1
fi

echo "== Поиск удалённых в PHP 8 API =="
php "$TESTS/check_removed_api.php" "$ROOT"/*.php || exit 1

PHP_STRICT=(-d error_reporting=E_ALL -d display_errors=1 -d log_errors=1 -d html_errors=0)

echo "== Миграция схемы со старой базы =="
php "${PHP_STRICT[@]}" "$TESTS/migration.php"
MIGRATION_STATUS=$?

echo "== Подготовка SQLite $DB_PATH =="
sqlite_cmd <<SQL
INSERT INTO games (chat_id, game_id, game_domain, game_login, game_pass, cookies, last_level_id, status, infochannel)
VALUES (-1001, 12345, '127.0.0.1:18081', 'login', 'pass', '', 777, 1, NULL);
INSERT INTO admins (admin_username) VALUES ('rootadmin');
SQL
echo "  ok   схема загружена, тестовая игра создана"

echo "== Разворачивание копии проекта =="
cp "$ROOT"/*.php "$ROOT"/db.sql "$ROOT"/enscreen.js "$WORK/"
cp "$TESTS/config.template.php" "$WORK/config.php"
cp "$TESTS/smoke.php" "$TESTS/encx_mock.php" "$WORK/"
echo "  ok   $WORK"

echo "== Запуск заглушек внешних сервисов =="
# Журнал исходящих вызовов Telegram: тесты проверяют не только "не упало",
# но и что именно бот отправил.
TG_LOG="$WORK/telegram.jsonl"
: > "$TG_LOG"
export ENXBOT_TG_LOG="$TG_LOG"
php "${PHP_STRICT[@]}" -S "127.0.0.1:$STUB_PORT" -t "$TESTS" "$TESTS/stub_server.php" > "$WORK/stub.log" 2>&1 &
STUB_PID=$!
php "${PHP_STRICT[@]}" -S "127.0.0.1:$APP_PORT" -t "$WORK" > "$WORK/app.log" 2>&1 &
APP_PID=$!

for _ in $(seq 1 50); do
    if curl -s -o /dev/null "http://127.0.0.1:$STUB_PORT/tg/getMe" && curl -s -o /dev/null "http://127.0.0.1:$APP_PORT/"; then
        break
    fi
    sleep 0.1
done
echo "  ok   заглушка :$STUB_PORT, приложение :$APP_PORT"

echo "== Смоук-тест функций =="
(cd "$WORK" && php "${PHP_STRICT[@]}" smoke.php)
SMOKE_STATUS=$?

echo "== Проверка против encx-mock (настоящий мок движка Encounter) =="
MOCK_STATUS=0
MOCK_PORT=18099
MOCK_BIN="${ENCX_MOCK_BIN:-$(command -v encx-mock || true)}"
if [ -z "$MOCK_BIN" ] && [ -d "$ENCX_REPO/cmd/encx-mock" ]; then
    MOCK_BIN="$WORK/encx-mock"
    (cd "$ENCX_REPO" && go build -o "$MOCK_BIN" ./cmd/encx-mock) || exit 1
fi
if [ -z "$MOCK_BIN" ]; then
    echo "  ПРОПУСК: бинарник encx-mock не найден."
    echo "  Установка: go install github.com/skrashevich/encx-cli/cmd/encx-mock@latest"
    echo "  Либо укажите путь: ENCX_MOCK_BIN=/path/to/encx-mock tests/run.sh"
else
    if lsof -ti ":$MOCK_PORT" -sTCP:LISTEN > /dev/null 2>&1; then
        echo "  ПРОВАЛ: порт $MOCK_PORT уже занят, encx-mock не запустить"
        exit 1
    fi
    ENCX_MOCK_ADDR="127.0.0.1:$MOCK_PORT" "$MOCK_BIN" > "$WORK/encxmock.log" 2>&1 &
    MOCK_PID=$!
    # Ждём именно encx-mock: проверяем JSON endpoint игрового движка.
    MOCK_READY=0
    for _ in $(seq 1 100); do
        if curl -s "http://127.0.0.1:$MOCK_PORT/" >/dev/null 2>&1; then
            MOCK_READY=1
            break
        fi
        sleep 0.1
    done
    if [ "$MOCK_READY" -eq 0 ]; then
        echo "  ПРОВАЛ: encx-mock не поднялся на :$MOCK_PORT"
        cat "$WORK/encxmock.log"
        exit 1
    fi
    (cd "$WORK" && ENCX_MOCK_DOMAIN="127.0.0.1:$MOCK_PORT" php "${PHP_STRICT[@]}" encx_mock.php)
    MOCK_STATUS=$?
    kill "$MOCK_PID" 2>/dev/null
    wait "$MOCK_PID" 2>/dev/null
fi

echo "== Сценарии обработчика bot.php =="
send_update() {
    : > "$TG_LOG"
    curl -s -o /dev/null -X POST -H 'Content-Type: application/json' \
        --data-binary "$2" "http://127.0.0.1:$APP_PORT/bot.php"
    echo "  отправлено: $1"
}

# Проверяет, что среди отправленного боту в Telegram есть текст с подстрокой
tg_sent_contains() {
    grep -qF "$1" "$TG_LOG"
}

# Проверяет, что бот вызвал указанный метод Telegram API
tg_called() {
    grep -qF "\"method\":\"$1\"" "$TG_LOG"
}

expect_sent() {
    if tg_sent_contains "$2"; then
        echo "  ok   $1"
    else
        echo "  ПРОВАЛ: $1 (не найдено в ответе бота: $2)"
        sed 's/^/    /' "$TG_LOG"
        exit 1
    fi
}

send_update "/help" '{"message":{"message_id":1,"chat":{"id":-1001,"title":"Тест"},"from":{"id":7,"username":"rootadmin","first_name":"Рут"},"text":"/help"}}'
send_update "/help без username" '{"message":{"message_id":2,"chat":{"id":-1001},"from":{"id":8,"first_name":"Аноним"},"text":"/help"}}'
send_update "/game без аргументов" '{"message":{"message_id":3,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game"}}'
send_update "/game print" '{"message":{"message_id":4,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game print"}}'
echo "-- права доступа --"
send_update "/game stop обычным игроком (должно быть отказано)" '{"message":{"message_id":40,"chat":{"id":-1001},"from":{"id":8,"username":"player"},"text":"/game stop"}}'
[ "$(sqlite_cmd "SELECT status FROM games WHERE chat_id = -1001")" = "1" ] || { echo "  ПРОВАЛ: обычный игрок остановил бота"; exit 1; }
expect_sent "обычному игроку отказано в /game stop" "только администраторам чата"

send_update "/game delete обычным игроком (должно быть отказано)" '{"message":{"message_id":42,"chat":{"id":-1001},"from":{"id":8,"username":"player"},"text":"/game delete"}}'
[ "$(sqlite_cmd "SELECT COUNT(*) FROM games WHERE chat_id = -1001")" = "1" ] || { echo "  ПРОВАЛ: обычный игрок удалил игру"; exit 1; }
expect_sent "обычному игроку отказано в /game delete" "только администраторам чата"

send_update "/нко обычным игроком (читающая команда доступна)" '{"message":{"message_id":46,"chat":{"id":-1001},"from":{"id":8,"username":"player"},"text":"/нко"}}'
if tg_sent_contains "только администраторам чата"; then
    echo "  ПРОВАЛ: читающая команда /нко закрыта от обычного игрока"
    exit 1
fi
echo "  ok   читающая команда /нко доступна обычному игроку"

send_update "/game stop админом бота" '{"message":{"message_id":43,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game stop"}}'
[ "$(sqlite_cmd "SELECT status FROM games WHERE chat_id = -1001")" = "0" ] || { echo "  ПРОВАЛ: админ бота не смог остановить бота"; exit 1; }
echo "  ok   админ бота выполняет /game stop"

send_update "/game start админом чата (не админом бота)" '{"message":{"message_id":44,"chat":{"id":-1001},"from":{"id":555,"username":"chatadmin"},"text":"/game start"}}'
[ "$(sqlite_cmd "SELECT status FROM games WHERE chat_id = -1001")" = "1" ] || { echo "  ПРОВАЛ: администратор чата не смог запустить бота"; exit 1; }
echo "  ok   администратор чата выполняет /game start"

send_update "/encrypt в группе (должно быть отказано)" '{"message":{"message_id":45,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/encrypt секрет"}}'
expect_sent "/encrypt в группе отклонён" "только в личной переписке"
if tg_sent_contains "Зашифрованный пароль"; then
    echo "  ПРОВАЛ: /encrypt опубликовал шифртекст в группе"
    exit 1
fi
send_update "/game domain" '{"message":{"message_id":5,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game domain 127.0.0.1:18081"}}'

# /game domain сохраняет домен как есть, без легаси-префикса m.
sqlite_cmd "UPDATE games SET status = 0 WHERE chat_id = -1001;"
send_update "/game domain на остановленной игре" '{"message":{"message_id":501,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game domain svk.en.cx"}}'
STORED_DOMAIN="$(sqlite_cmd "SELECT game_domain FROM games WHERE chat_id = -1001")"
[ "$STORED_DOMAIN" = "svk.en.cx" ] || { echo "  ПРОВАЛ: /game domain сохранил '$STORED_DOMAIN' вместо 'svk.en.cx' (префикс m. вернулся?)"; exit 1; }
echo "  ok   /game domain сохраняет домен без префикса m."
sqlite_cmd "UPDATE games SET game_domain = '127.0.0.1:18081', status = 1 WHERE chat_id = -1001;"

send_update "/game auth" '{"message":{"message_id":6,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game auth"}}'
send_update "/game test" '{"message":{"message_id":7,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game test"}}'
send_update "/game codes stat" '{"message":{"message_id":8,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game codes stat"}}'
send_update "/game codes print" '{"message":{"message_id":9,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game codes print"}}'
send_update "/game codes map (без константы)" '{"message":{"message_id":10,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game codes map"}}'
send_update "/game infochannel" '{"message":{"message_id":11,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game infochannel @infochan"}}'

echo "-- подкоманды /game: url, chatid, shtab --"
send_update "/game url" '{"message":{"message_id":50,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game url"}}'
expect_sent "/game url выдаёт ссылку на игру в движке" "/gameengines/encounter/play/12345"

send_update "/game chatid" '{"message":{"message_id":51,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game chatid"}}'
expect_sent "/game chatid выдаёт идентификатор чата" "ID этого чата: -1001"

send_update "/game shtab с мусором" '{"message":{"message_id":52,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game shtab мусор"}}'
expect_sent "/game shtab отвергает неверный идентификатор" "Неверный идентификатор штабного чата"

send_update "/game shtab -1009" '{"message":{"message_id":53,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game shtab -1009"}}'
[ "$(sqlite_cmd "SELECT shtab_id FROM games WHERE chat_id = -1001")" = "-1009" ] || { echo "  ПРОВАЛ: штабной чат не сохранён"; exit 1; }
echo "  ok   /game shtab сохраняет штабной чат"

send_update "/game print из штабного чата" '{"message":{"message_id":54,"chat":{"id":-1009},"from":{"id":7,"username":"rootadmin"},"text":"/game print"}}'
expect_sent "команда из штабного чата видит боевую игру" "Игра 12345"
send_update "/level" '{"message":{"message_id":12,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/level"}}'
send_update "/hints" '{"message":{"message_id":13,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/hints"}}'
send_update "/sectors" '{"message":{"message_id":14,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/sectors"}}'
send_update "/schema" '{"message":{"message_id":15,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/schema"}}'
send_update "/messages" '{"message":{"message_id":16,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/messages"}}'
send_update "!нко" '{"message":{"message_id":17,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"!нко"}}'
send_update "!всеко" '{"message":{"message_id":18,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"!всеко"}}'
send_update "!зко" '{"message":{"message_id":19,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"!зко"}}'

echo "-- бонусные списки --"
send_update "!нбко" '{"message":{"message_id":70,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"!нбко"}}'
expect_sent "!нбко отвечает про бонусы" "бонус"
send_update "!всебко" '{"message":{"message_id":71,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"!всебко"}}'
expect_sent "!всебко отвечает про бонусы" "бонус"
send_update "!збко" '{"message":{"message_id":72,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"!збко"}}'
expect_sent "!збко отвечает про бонусы" "бонус"
send_update "/allbhl (латинский алиас)" '{"message":{"message_id":73,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/allbhl"}}'
expect_sent "латинский алиас бонусов работает" "бонус"
send_update "/settings" '{"message":{"message_id":20,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/settings"}}'
send_update "/keyboard" '{"message":{"message_id":21,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/keyboard"}}'
send_update "/keyboard список" '{"message":{"message_id":22,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/keyboard /a,/b,/c"}}'
send_update "/keyboard off" '{"message":{"message_id":23,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/keyboard off"}}'
send_update "/coords" '{"message":{"message_id":24,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/coords 55.755814, 37.617635"}}'
send_update "/screenshot" '{"message":{"message_id":25,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/screenshot"}}'
send_update "/getscreens без каталога screens" '{"message":{"message_id":26,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/getscreens all"}}'
# Каталог со скриншотом появляется - проверяем ветку с архивацией
mkdir -p "$WORK/screens"
printf 'PNG' > "$WORK/screens/-1001.12345.777.1700000000.0001.png"
send_update "/getscreens с архивом" '{"message":{"message_id":27,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/getscreens all"}}'
send_update "/getscreens по номеру уровня" '{"message":{"message_id":28,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/getscreens 777"}}'
send_update "/admin print" '{"message":{"message_id":27,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/admin print"}}'
send_update "/admin daemon status" '{"message":{"message_id":28,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/admin daemon status"}}'
send_update "/encrypt в личке" '{"message":{"message_id":29,"chat":{"id":7},"from":{"id":7,"username":"rootadmin"},"text":"/encrypt секрет"}}'
send_update "/game pass с мусором" '{"message":{"message_id":30,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/game pass мусор"}}'
send_update "код с префиксом" '{"message":{"message_id":31,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin","first_name":"Рут"},"text":"&right//коммент"}}'
send_update "код без комментария" '{"message":{"message_id":32,"chat":{"id":-1001},"from":{"id":9,"first_name":"Безымянный"},"text":"&wrong"}}'
send_update "venue" '{"message":{"message_id":33,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin","first_name":"Рут"},"venue":{"location":{"latitude":55.75,"longitude":37.61},"title":"&right//метка","address":"Москва"}}}'
send_update "venue без адреса" '{"message":{"message_id":34,"chat":{"id":-1001},"from":{"id":9},"venue":{"location":{"latitude":55.75,"longitude":37.61},"title":"Просто точка"}}}'
send_update "pinned_message" '{"message":{"message_id":35,"chat":{"id":-1001},"from":{"id":7},"pinned_message":{"message_id":34,"text":"Важное"}}}'
send_update "pinned_message без text" '{"message":{"message_id":36,"chat":{"id":-1001},"from":{"id":7},"pinned_message":{"message_id":34}}}'
send_update "migrate_from_chat_id" '{"message":{"message_id":37,"chat":{"id":-1001},"from":{"id":7},"migrate_from_chat_id":-500}}'
send_update "left_chat_member" '{"message":{"message_id":38,"chat":{"id":-1002},"from":{"id":7},"left_chat_member":{"username":"someone"}}}'
send_update "inline_query" '{"inline_query":{"id":"1","from":{"id":7},"location":{"latitude":55.75,"longitude":37.61},"query":"точка"}}'
send_update "inline_query без локации" '{"inline_query":{"id":"2","from":{"id":7},"query":"точка"}}'
echo "-- меню настроек на кнопках --"
# Подписанная боту callback_data строится тем же кодом, что и в самом боте
sign_callback() {
    (cd "$WORK" && php -r '
        require "config.php";
        require "functions.php";
        echo settingsCallbackData($argv[1], $argv[2]);
    ' "$1" "$2")
}

CB_VALID="$(sign_callback optimize_chat -1001)"
[ "${#CB_VALID}" -le 64 ] || { echo "  ПРОВАЛ: callback_data длиннее лимита Telegram (${#CB_VALID} байт)"; exit 1; }
echo "  ok   callback_data укладывается в лимит Telegram (${#CB_VALID} из 64 байт)"

sqlite_cmd "DELETE FROM settings WHERE chat_id = -1001 AND name = 'optimize_chat';"
send_update "callback_query: админ включает optimize_chat" "{\"callback_query\":{\"id\":\"1\",\"from\":{\"id\":7,\"username\":\"rootadmin\"},\"data\":\"$CB_VALID\"}}"
[ "$(sqlite_cmd "SELECT value FROM settings WHERE chat_id = -1001 AND name = 'optimize_chat'")" = "true" ] || { echo "  ПРОВАЛ: настройка не переключилась"; exit 1; }
tg_called editMessageText || { echo "  ПРОВАЛ: меню настроек не обновлено на месте"; sed 's/^/    /' "$TG_LOG"; exit 1; }
echo "  ok   нажатие переключает настройку и обновляет меню через editMessageText"

send_update "callback_query: обычный игрок жмёт ту же кнопку" "{\"callback_query\":{\"id\":\"2\",\"from\":{\"id\":8,\"username\":\"player\"},\"data\":\"$CB_VALID\"}}"
[ "$(sqlite_cmd "SELECT value FROM settings WHERE chat_id = -1001 AND name = 'optimize_chat'")" = "true" ] || { echo "  ПРОВАЛ: обычный игрок переключил настройку"; exit 1; }
expect_sent "обычному игроку отказано в переключении настройки" "только администраторы чата"

CB_FORGED="optimize_chat -1001 deadbeef00"
send_update "callback_query: подделанная подпись" "{\"callback_query\":{\"id\":\"3\",\"from\":{\"id\":7,\"username\":\"rootadmin\"},\"data\":\"$CB_FORGED\"}}"
[ "$(sqlite_cmd "SELECT value FROM settings WHERE chat_id = -1001 AND name = 'optimize_chat'")" = "true" ] || { echo "  ПРОВАЛ: подделанная callback_data изменила настройку"; exit 1; }
expect_sent "подделанная callback_data отвергнута" "Кнопка устарела"

send_update "callback_query: старый неподписанный формат" '{"callback_query":{"id":"4","from":{"id":7,"username":"rootadmin"},"data":"/noprefix -1001"}}'
[ "$(sqlite_cmd "SELECT COUNT(*) FROM settings WHERE chat_id = -1001 AND name = 'noprefix' AND value = 'true'")" = "0" ] || { echo "  ПРОВАЛ: неподписанная callback_data изменила настройку"; exit 1; }
echo "  ok   неподписанная callback_data не меняет настроек"

echo "-- самоочистка чата (optimize_chat) --"
# optimize_chat включён предыдущим блоком. Каждый тип списка ведёт свой ключ.
sqlite_cmd "DELETE FROM settings WHERE chat_id = -1001 AND name LIKE 'last_%_message_id';"
for pair in "нко:ohl" "всеко:allhl" "зко:chl" "нбко:obhl" "всебко:allbhl" "збко:cbhl"; do
    cmd="${pair%%:*}"
    key="${pair##*:}"
    send_update "!$cmd (первый вызов)" "{\"message\":{\"message_id\":80,\"chat\":{\"id\":-1001},\"from\":{\"id\":7,\"username\":\"rootadmin\"},\"text\":\"!$cmd\"}}"
    STORED=$(sqlite_cmd "SELECT value FROM settings WHERE chat_id = -1001 AND name = 'last_${key}_message_id'")
    [ "$STORED" = "4242" ] || { echo "  ПРОВАЛ: !$cmd не сохранил id в last_${key}_message_id (получено '$STORED')"; exit 1; }
done
echo "  ok   у каждого из шести списков свой ключ last_*_message_id"

send_update "!нко (повторный вызов)" '{"message":{"message_id":81,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"!нко"}}'
tg_called deleteMessage || { echo "  ПРОВАЛ: повторный список не удалил предыдущее сообщение"; sed 's/^/    /' "$TG_LOG"; exit 1; }
grep -qF '"message_id":4242' "$TG_LOG" || { echo "  ПРОВАЛ: удалено не то сообщение"; sed 's/^/    /' "$TG_LOG"; exit 1; }
echo "  ok   при включённой optimize_chat предыдущий список удаляется"

sqlite_cmd "UPDATE settings SET value = 'false' WHERE chat_id = -1001 AND name = 'optimize_chat';"
send_update "!нко при выключенной optimize_chat" '{"message":{"message_id":82,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"!нко"}}'
if tg_called deleteMessage; then
    echo "  ПРОВАЛ: при выключенной optimize_chat сообщение всё равно удаляется"
    exit 1
fi
echo "  ok   при выключенной optimize_chat удаление не выполняется"
sqlite_cmd "UPDATE settings SET value = 'true' WHERE chat_id = -1001 AND name = 'optimize_chat';"

echo "-- точки на местности --"
sqlite_cmd "DELETE FROM locations WHERE chat_id = -1001; UPDATE games SET last_level_id = 777, status = 1 WHERE chat_id = -1001;"

send_update "/setpoint обычным игроком (должно быть отказано)" '{"message":{"message_id":90,"chat":{"id":-1001},"from":{"id":8,"username":"player"},"text":"/setpoint 55.755814 37.617635"}}'
[ "$(sqlite_cmd "SELECT COUNT(*) FROM locations WHERE chat_id = -1001")" = "0" ] || { echo "  ПРОВАЛ: обычный игрок поставил точку"; exit 1; }
expect_sent "обычному игроку отказано в /setpoint" "только штаб и администраторы"

send_update "/setpoint админом" '{"message":{"message_id":91,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/setpoint 55.755814 37.617635"}}'
[ "$(sqlite_cmd "SELECT COUNT(*) FROM locations WHERE chat_id = -1001 AND type = 2")" = "1" ] || { echo "  ПРОВАЛ: /setpoint не создал точку"; exit 1; }
expect_sent "/setpoint подтверждает точку" "Точка принята"

send_update "/listpoint" '{"message":{"message_id":92,"chat":{"id":-1001},"from":{"id":8,"username":"player"},"text":"/listpoint"}}'
expect_sent "/listpoint показывает точку как свободную" "свободна"

send_update "/closepoint" '{"message":{"message_id":93,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/closepoint"}}'
[ "$(sqlite_cmd "SELECT type FROM locations WHERE chat_id = -1001 ORDER BY id LIMIT 1")" = "4" ] || { echo "  ПРОВАЛ: /closepoint не закрыл точку"; exit 1; }
expect_sent "/closepoint подтверждает закрытие" "закрыта"

send_update "/listpoint после закрытия" '{"message":{"message_id":94,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"/listpoint"}}'
expect_sent "/listpoint показывает точку закрытой" "закрыта"

send_update "venue от игрока попадает в точки" '{"message":{"message_id":95,"chat":{"id":-1001},"from":{"id":8,"username":"player","first_name":"Игрок"},"venue":{"location":{"latitude":55.70,"longitude":37.50},"title":"Найдено","address":"Москва"}}}'
[ "$(sqlite_cmd "SELECT COUNT(*) FROM locations WHERE chat_id = -1001 AND type = 1")" = "1" ] || { echo "  ПРОВАЛ: venue не попал в таблицу точек"; exit 1; }
echo "  ok   присланная игроком геометка попадает в список точек"

echo "-- отметка найденной метки (?N) --"
sqlite_cmd "DELETE FROM codes WHERE chat_id = -1001; INSERT INTO codes (chat_id, level, time, code_number, code_type, code_status, find) VALUES (-1001, 777, 1, 3, 1, 0, 0), (-1001, 777, 1, 5, 1, 1, 0);"
send_update "?3 - метка найдена" '{"message":{"message_id":83,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"?3"}}'
[ "$(sqlite_cmd "SELECT find FROM codes WHERE chat_id = -1001 AND code_number = 3")" = "1" ] || { echo "  ПРОВАЛ: ?3 не отметил метку найденной"; exit 1; }
expect_sent "?3 подтверждает отметку" "Метка 3 отмечена"

send_update "?5 - метка уже закрыта" '{"message":{"message_id":84,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"?5"}}'
expect_sent "?N по закрытой метке сообщает об этом" "уже закрыта"

send_update "?99 - несуществующая метка" '{"message":{"message_id":85,"chat":{"id":-1001},"from":{"id":7,"username":"rootadmin"},"text":"?99"}}'
expect_sent "?N по несуществующей метке сообщает об этом" "нет метки 99"
[ "$(sqlite_cmd "SELECT COUNT(*) FROM codes WHERE chat_id = -1001")" = "2" ] || { echo "  ПРОВАЛ: ?99 создал мусорную строку в codes"; exit 1; }
echo "  ok   ?N по несуществующей метке не создаёт строк"

send_update "callback_query без данных" '{"callback_query":{"id":"5","from":{"id":9}}}'
send_update "callback_query game" '{"callback_query":{"id":"3","from":{"id":7,"username":"rootadmin"},"game_short_name":"map"}}'
send_update "пустой апдейт" '{}'

echo "-- каскадное удаление и сброс игры (на отдельном чате) --"
sqlite_cmd <<SQL
INSERT INTO games (chat_id, game_id, game_domain, last_level_id, status) VALUES (-1077, 555, 'example.org', 42, 1);
INSERT INTO timers (chat_id, game_id, level_id, hint, time, type) VALUES (-1077, 555, 42, 1, 1, 1);
INSERT INTO codes (chat_id, level, code_number, code_status, code) VALUES (-1077, 42, 1, 0, 'x');
INSERT INTO codeslog (chat_id, level, code, comment, time, sender) VALUES (-1077, 42, 'x', '', 1, 'кто-то');
INSERT INTO locations (chat_id, time, sender_username, sender_name, lat, lon, title, level, type) VALUES (-1077, 1, 'u', 'n', 55.0, 37.0, 't', 42, 1);
INSERT INTO messages (chat_id, time, whom, message) VALUES (-1077, 1, 'орг', 'привет');
INSERT INTO coords (chat_id, level, lat, lon, time) VALUES (-1077, 42, 55.0, 37.0, 1);
INSERT INTO settings (chat_id, name, value) VALUES (-1077, 'noprefix', 'true');
SQL

send_update "/game restart" '{"message":{"message_id":60,"chat":{"id":-1077},"from":{"id":7,"username":"rootadmin"},"text":"/game restart"}}'
RESTART_LEFT=$(sqlite_cmd "SELECT (SELECT COUNT(*) FROM timers WHERE chat_id=-1077)+(SELECT COUNT(*) FROM codes WHERE chat_id=-1077)+(SELECT COUNT(*) FROM codeslog WHERE chat_id=-1077)+(SELECT COUNT(*) FROM locations WHERE chat_id=-1077)+(SELECT COUNT(*) FROM messages WHERE chat_id=-1077)+(SELECT COUNT(*) FROM coords WHERE chat_id=-1077)")
[ "$RESTART_LEFT" = "0" ] || { echo "  ПРОВАЛ: /game restart не очистил игровые данные (осталось $RESTART_LEFT)"; exit 1; }
[ "$(sqlite_cmd "SELECT COUNT(*) FROM games WHERE chat_id = -1077")" = "1" ] || { echo "  ПРОВАЛ: /game restart удалил строку игры"; exit 1; }
[ "$(sqlite_cmd "SELECT last_level_id || '/' || status FROM games WHERE chat_id = -1077")" = "0/0" ] || { echo "  ПРОВАЛ: /game restart не сбросил уровень и статус"; exit 1; }
[ "$(sqlite_cmd "SELECT COUNT(*) FROM settings WHERE chat_id = -1077")" = "1" ] || { echo "  ПРОВАЛ: /game restart стёр настройки чата"; exit 1; }
echo "  ok   /game restart чистит игровые данные, сохраняя чат и настройки"

sqlite_cmd "INSERT INTO timers (chat_id, game_id, level_id, hint, time, type) VALUES (-1077, 555, 42, 1, 1, 1); INSERT INTO codes (chat_id, level, code_number, code_status, code) VALUES (-1077, 42, 1, 0, 'x');"
send_update "/game delete" '{"message":{"message_id":61,"chat":{"id":-1077},"from":{"id":7,"username":"rootadmin"},"text":"/game delete"}}'
DELETE_LEFT=$(sqlite_cmd "SELECT (SELECT COUNT(*) FROM games WHERE chat_id=-1077)+(SELECT COUNT(*) FROM timers WHERE chat_id=-1077)+(SELECT COUNT(*) FROM codes WHERE chat_id=-1077)+(SELECT COUNT(*) FROM codeslog WHERE chat_id=-1077)+(SELECT COUNT(*) FROM locations WHERE chat_id=-1077)+(SELECT COUNT(*) FROM settings WHERE chat_id=-1077)+(SELECT COUNT(*) FROM messages WHERE chat_id=-1077)+(SELECT COUNT(*) FROM coords WHERE chat_id=-1077)")
[ "$DELETE_LEFT" = "0" ] || { echo "  ПРОВАЛ: /game delete оставил данные чата (осталось $DELETE_LEFT)"; exit 1; }
echo "  ok   /game delete каскадно очищает все таблицы чата"

echo "== Telegram polling =="
(cd "$WORK" && ENXBOT_POLL_TIMEOUT=1 ENXBOT_POLL_MAX_CYCLES=2 php "${PHP_STRICT[@]}" polling.php > "$WORK/polling.log" 2>&1)
POLLING_STATUS=$?
POLLING_LOGGED=$(sqlite_cmd "SELECT COUNT(*) FROM log WHERE message_id = 9001")
if [ "$POLLING_STATUS" -eq 0 ] && [ "$POLLING_LOGGED" = "1" ]; then
    echo "  ok   update получен через getUpdates и обработан ровно один раз"
else
    echo "  ПРОВАЛ: polling status=$POLLING_STATUS, записей update=$POLLING_LOGGED"
    sed 's/^/  /' "$WORK/polling.log"
    POLLING_STATUS=1
fi

echo "== Устойчивость polling к сбоям =="
STUB_FLAG="$WORK/.stub_fail_mode"
# Заглушка ищет флаг рядом с каталогом tests, поэтому кладём его туда же
STUB_FLAG_PATH="$TESTS/../.stub_fail_mode"

# 1. Ошибки getUpdates: цикл не должен крутиться вплотную
echo error > "$STUB_FLAG_PATH"
POLL_START=$(date +%s)
(cd "$WORK" && ENXBOT_POLL_TIMEOUT=1 ENXBOT_POLL_RETRY_DELAY=1 ENXBOT_POLL_MAX_CYCLES=3 \
    php "${PHP_STRICT[@]}" polling.php > "$WORK/polling_err.log" 2>&1)
POLL_ERR_STATUS=$?
POLL_ELAPSED=$(( $(date +%s) - POLL_START ))
rm -f "$STUB_FLAG_PATH"

# Прогрессивная задержка 1+2+3 = 6 с; без неё три цикла заняли бы доли секунды
if [ "$POLL_ERR_STATUS" -eq 0 ] && [ "$POLL_ELAPSED" -ge 5 ]; then
    echo "  ok   при ошибках getUpdates polling выдерживает прогрессивную паузу (${POLL_ELAPSED} с на 3 цикла)"
else
    echo "  ПРОВАЛ: polling не выдержал паузу (код $POLL_ERR_STATUS, ${POLL_ELAPSED} с)"
    sed 's/^/    /' "$WORK/polling_err.log"
    POLLING_STATUS=1
fi

# 2. Невалидный токен: внятный выход, а не необработанное исключение
echo 401 > "$STUB_FLAG_PATH"
(cd "$WORK" && ENXBOT_POLL_TIMEOUT=1 ENXBOT_POLL_MAX_CYCLES=3 \
    php "${PHP_STRICT[@]}" polling.php > "$WORK/polling_401.log" 2>&1)
POLL_401_STATUS=$?
rm -f "$STUB_FLAG_PATH"

if [ "$POLL_401_STATUS" -eq 2 ] && grep -q "Невалидный токен" "$WORK/polling_401.log" \
   && ! grep -q "Uncaught" "$WORK/polling_401.log"; then
    echo "  ok   при 401 polling завершается с сообщением о токене, без Uncaught"
else
    echo "  ПРОВАЛ: polling некорректно обработал 401 (код $POLL_401_STATUS)"
    sed 's/^/    /' "$WORK/polling_401.log"
    POLLING_STATUS=1
fi

echo "== cron.php =="
(cd "$WORK" && php "${PHP_STRICT[@]}" cron.php > "$WORK/cron.log" 2>&1)
CRON_STATUS=$?
sed 's/^/  /' "$WORK/cron.log"

# cron.php при недоступном движке не должен затирать last_level_id
sqlite_cmd "UPDATE games SET last_level_id = 777, game_domain = '127.0.0.1:1', status = 1 WHERE chat_id = -1001; DELETE FROM settings WHERE name = 'cron_running';"
(cd "$WORK" && php "${PHP_STRICT[@]}" cron.php > "$WORK/cron_offline.log" 2>&1)
KEPT=$(sqlite_cmd "SELECT last_level_id FROM games WHERE chat_id = -1001")
if [ "$KEPT" != "777" ]; then
    echo "  ПРОВАЛ: cron.php затёр last_level_id при недоступном движке (стало $KEPT)"
    CRON_STATUS=1
else
    echo "  ok   недоступный движок не затирает last_level_id в cron.php"
fi
sqlite_cmd "UPDATE games SET game_domain = '127.0.0.1:18081' WHERE chat_id = -1001; DELETE FROM settings WHERE name = 'cron_running';"

echo "== Блокировка cron =="
# Свежая блокировка отсекает параллельный прогон
sqlite_cmd "DELETE FROM settings WHERE chat_id = 0 AND name IN ('cron_running','cron_starttime','cron_pid');
INSERT INTO settings (chat_id, name, value) VALUES (0,'cron_running','true'),(0,'cron_starttime',CAST(strftime('%s','now') AS INTEGER)),(0,'cron_pid','999999');"
(cd "$WORK" && php "${PHP_STRICT[@]}" cron.php > "$WORK/cron_locked.log" 2>&1)
if grep -q "параллельный процесс" "$WORK/cron_locked.log"; then
    echo "  ok   свежая блокировка отсекает параллельный прогон"
else
    echo "  ПРОВАЛ: свежая блокировка не сработала"
    sed 's/^/    /' "$WORK/cron_locked.log"
    CRON_STATUS=1
fi

# Протухшая блокировка снимается сама, cron не встаёт навсегда
sqlite_cmd "UPDATE settings SET value = CAST(strftime('%s','now') AS INTEGER) - 100000 WHERE chat_id = 0 AND name = 'cron_starttime';
UPDATE settings SET value = 'true' WHERE chat_id = 0 AND name = 'cron_running';"
(cd "$WORK" && php "${PHP_STRICT[@]}" cron.php > "$WORK/cron_stale.log" 2>&1)
if grep -q "параллельный процесс" "$WORK/cron_stale.log"; then
    echo "  ПРОВАЛ: протухшая блокировка навсегда остановила cron"
    CRON_STATUS=1
else
    echo "  ok   протухшая блокировка снимается по таймауту"
fi
# После нормального завершения блокировка снята
if [ "$(sqlite_cmd "SELECT value FROM settings WHERE chat_id = 0 AND name = 'cron_running'")" = "false" ]; then
    echo "  ok   после прогона блокировка снята"
else
    echo "  ПРОВАЛ: блокировка осталась висеть после прогона"
    CRON_STATUS=1
fi

echo "== Сообщения организаторов и автоостановка =="
# Дедупликация: три прогона на неизменном наборе дают одну запись
sqlite_cmd "DELETE FROM messages WHERE chat_id = -1001; DELETE FROM settings WHERE chat_id = 0 AND name = 'cron_running';
UPDATE games SET status = 1, game_domain = '127.0.0.1:18081' WHERE chat_id = -1001;
INSERT INTO log (time, message_id, chat_id, text, type, sender_id) VALUES (CAST(strftime('%s','now') AS INTEGER), 1, -1001, 'свежая активность', 0, 7);"
for _ in 1 2 3; do
    (cd "$WORK" && php "${PHP_STRICT[@]}" cron.php > /dev/null 2>&1)
    sqlite_cmd "DELETE FROM settings WHERE chat_id = 0 AND name = 'cron_running';"
done
MSG_ROWS=$(sqlite_cmd "SELECT COUNT(*) FROM messages WHERE chat_id = -1001")
MSG_DISTINCT=$(sqlite_cmd "SELECT COUNT(DISTINCT message) FROM messages WHERE chat_id = -1001")
if [ "$MSG_ROWS" = "$MSG_DISTINCT" ]; then
    echo "  ok   три прогона cron не наплодили дублей сообщений ($MSG_ROWS строк)"
else
    echo "  ПРОВАЛ: дедупликация сообщений не работает ($MSG_ROWS строк, уникальных $MSG_DISTINCT)"
    CRON_STATUS=1
fi

# Свежая активность игру не останавливает
if [ "$(sqlite_cmd "SELECT status FROM games WHERE chat_id = -1001")" = "1" ]; then
    echo "  ok   игра со свежей активностью не останавливается"
else
    echo "  ПРОВАЛ: игра со свежей активностью была остановлена"
    CRON_STATUS=1
fi

# Недоступный движок не должен останавливать игру по признаку завершённости.
# Активность держим свежей, чтобы проверялась именно эта ветка.
sqlite_cmd "UPDATE games SET status = 1, game_domain = '127.0.0.1:1' WHERE chat_id = -1001;
UPDATE log SET time = CAST(strftime('%s','now') AS INTEGER) WHERE chat_id = -1001;
DELETE FROM settings WHERE chat_id = 0 AND name = 'cron_running';"
(cd "$WORK" && php "${PHP_STRICT[@]}" cron.php > "$WORK/cron_nostop.log" 2>&1)
if [ "$(sqlite_cmd "SELECT status FROM games WHERE chat_id = -1001")" = "1" ]; then
    echo "  ok   недоступный движок не приводит к ложной остановке игры"
else
    echo "  ПРОВАЛ: игра остановлена из-за недоступности движка"
    CRON_STATUS=1
fi

# Давняя тишина в чате останавливает игру даже при недоступном движке:
# активность считается по своей таблице log и от движка не зависит
sqlite_cmd "UPDATE games SET status = 1, game_domain = '127.0.0.1:1' WHERE chat_id = -1001;
UPDATE log SET time = CAST(strftime('%s','now') AS INTEGER) - 200000 WHERE chat_id = -1001;
DELETE FROM settings WHERE chat_id = 0 AND name = 'cron_running';"
(cd "$WORK" && ENXBOT_IDLE_TIMEOUT=60 php "${PHP_STRICT[@]}" cron.php > "$WORK/cron_idle.log" 2>&1)
if [ "$(sqlite_cmd "SELECT status FROM games WHERE chat_id = -1001")" = "0" ]; then
    echo "  ok   игра без активности дольше порога останавливается"
else
    echo "  ПРОВАЛ: игра без активности не остановлена"
    sed 's/^/    /' "$WORK/cron_idle.log"
    CRON_STATUS=1
fi

# Возвращаем игру в рабочее состояние для последующих проверок
sqlite_cmd "UPDATE games SET status = 1, last_level_id = 777 WHERE chat_id = -1001;
UPDATE log SET time = CAST(strftime('%s','now') AS INTEGER) WHERE chat_id = -1001;
DELETE FROM settings WHERE chat_id = 0 AND name = 'cron_running';"

echo "== Веб-карта точек =="
MAP_TOKEN=$(cd "$WORK" && php -r 'require "config.php"; require "functions.php"; echo mapToken(-1001, 777);')
sqlite_cmd "DELETE FROM locations WHERE chat_id = -1001;
INSERT INTO locations (chat_id, time, sender_username, sender_name, lat, lon, title, level, type)
VALUES (-1001, 1, 'u', 'Игрок', 55.75, 37.61, '<script>alert(1)</script>'' \"', 777, 2);"
MAP_OK=$(curl -s "http://127.0.0.1:$APP_PORT/map.php?c=-1001&l=777&t=$MAP_TOKEN")
MAP_DENIED_CODE=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$APP_PORT/map.php?c=-1001&l=777&t=deadbeef")
MAP_NOTOKEN_CODE=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$APP_PORT/map.php?c=-1001&l=777")

if [ "$MAP_DENIED_CODE" = "403" ] && [ "$MAP_NOTOKEN_CODE" = "403" ]; then
    echo "  ok   без корректного токена карта отдаёт 403"
else
    echo "  ПРОВАЛ: карта открывается без токена (коды $MAP_DENIED_CODE / $MAP_NOTOKEN_CODE)"
    MAP_STATUS=1
fi

if printf '%s' "$MAP_OK" | grep -q '55.75'; then
    echo "  ok   с корректным токеном карта отдаёт точку"
else
    echo "  ПРОВАЛ: карта не отдала точку по валидному токену"
    printf '%s' "$MAP_OK" | head -5 | sed 's/^/    /'
    MAP_STATUS=1
fi

if printf '%s' "$MAP_OK" | grep -q '<script>alert(1)</script>'; then
    echo "  ПРОВАЛ: заголовок точки попал на страницу без экранирования (XSS)"
    MAP_STATUS=1
else
    echo "  ok   опасный заголовок точки экранирован"
fi

echo "== daemon.php (5 секунд) =="
# Просроченные таймеры на подсказку и на автопереход, чтобы демон прошёл обе ветки
NOW=$(date +%s)
sqlite_cmd <<SQL
UPDATE games SET status = 1, last_level_id = 777, infochannel = '@infochan' WHERE chat_id = -1001;
DELETE FROM timers WHERE chat_id = -1001;
INSERT INTO timers (chat_id, game_id, level_id, hint, time, type) VALUES
  (-1001, 12345, 777, 1, $NOW - 10, 1),
  (-1001, 12345, 777, 0, $NOW - 10, 2),
  (-1001, 12345, 777, 0, $NOW - 10, 9);
SQL
(cd "$WORK" && php "${PHP_STRICT[@]}" daemon.php > "$WORK/daemon.log" 2>&1) &
DAEMON_PID=$!
sleep 5
kill "$DAEMON_PID" 2>/dev/null
wait "$DAEMON_PID" 2>/dev/null
sed 's/^/  /' "$WORK/daemon.log"

# Демон удаляет отработанные таймеры - по этому и проверяем, что он их обработал
REMAINING=$(sqlite_cmd "SELECT COUNT(*) FROM timers WHERE chat_id = -1001 AND time <= CAST(strftime('%s', 'now') AS INTEGER)")
DAEMON_STATUS=0
if [ "$REMAINING" != "0" ]; then
    echo "  ПРОВАЛ: демон не обработал просроченные таймеры (осталось $REMAINING)"
    DAEMON_STATUS=1
else
    echo "  ok   все просроченные таймеры обработаны (подсказка, автопереход, неизвестный тип)"
fi

echo "== Анализ журналов на диагностику PHP =="
ISSUE_PATTERN='PHP (Fatal error|Parse error|Warning|Deprecated|Notice|Recoverable)|Uncaught'
ISSUES=0
for log in app stub polling cron cron_offline daemon encxmock; do
    if [ -f "$WORK/$log.log" ] && grep -Eq "$ISSUE_PATTERN" "$WORK/$log.log"; then
        echo "  ДИАГНОСТИКА в $log.log:"
        grep -E "$ISSUE_PATTERN" "$WORK/$log.log" | sed 's/^/    /' | sort -u
        ISSUES=1
    fi
done
[ "$ISSUES" -eq 0 ] && echo "  ok   ни Fatal, ни Warning, ни Deprecated не зафиксировано"

echo
echo "== Итог =="
STATUS=0
[ "$MIGRATION_STATUS" -ne 0 ] && { echo "  ПРОВАЛ: миграция схемы"; STATUS=1; }
[ "$SMOKE_STATUS" -ne 0 ] && { echo "  ПРОВАЛ: смоук-тест функций"; STATUS=1; }
[ "$CRON_STATUS" -ne 0 ] && { echo "  ПРОВАЛ: cron.php завершился с кодом $CRON_STATUS"; STATUS=1; }
[ "$DAEMON_STATUS" -ne 0 ] && { echo "  ПРОВАЛ: daemon.php не обработал таймеры"; STATUS=1; }
[ "$MOCK_STATUS" -ne 0 ] && { echo "  ПРОВАЛ: проверка против encx-mock"; STATUS=1; }
[ "$POLLING_STATUS" -ne 0 ] && { echo "  ПРОВАЛ: Telegram polling"; STATUS=1; }
[ "$MAP_STATUS" -ne 0 ] && { echo "  ПРОВАЛ: веб-карта точек"; STATUS=1; }
[ "$ISSUES" -ne 0 ] && { echo "  ПРОВАЛ: в журналах есть диагностика PHP"; STATUS=1; }
[ "$BINDINGS_STALE" -eq 1 ] && echo "  ВНИМАНИЕ: биндинги encx не пересобирались (сборка соседнего репозитория сломана)"
[ "$STATUS" -eq 0 ] && echo "  ВСЁ ЗЕЛЁНОЕ на PHP $(php -r 'echo PHP_VERSION;')"

exit "$STATUS"
