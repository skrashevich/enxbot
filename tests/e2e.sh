#!/usr/bin/env bash
#
# Сквозной (end-to-end) тест enxbot.
#
# В отличие от tests/run.sh, который дёргает handleUpdate() напрямую и
# подсовывает самодельные заглушки, этот стенд поднимает НАСТОЯЩИЕ моки и
# гоняет бота через его же точки входа:
#
#   * telegram-mock-ai  -- эмулятор api.telegram.org (Bot API + Admin API).
#                          https://github.com/skrashevich/telegram-mock-ai
#   * encx-mock         -- stateful-мок движка Encounter из апстрима encx-cli,
#                          та же кодовая база, что и у PHP-биндингов бота.
#   * polling.php       -- реальный long-poll цикл бота;
#     cron.php, daemon.php -- реальные фоновые процессы.
#
# Прогоняется целая игра: регистрация -> авторизация на движке (сессия через
# SQLite) -> модель игры -> подсказки/сектора -> приём кодов -> автопереходы
# -> сигнал движка о завершении -> автоостановка. Проверяются и исходящие
# сообщения бота (через Admin API мока), и состояние в SQLite.
#
# Требования: bash, php 8.x (pdo_sqlite, curl, mbstring, ffi, openssl),
#             go 1.22+, git, curl. encx-cli (биндинги + cmd/encx-mock) и
#             telegram-mock-ai тянутся из апстрима на GitHub и кэшируются
#             (encx-cli — в tests/.cache/, telegram-mock-ai — во временном
#             каталоге). Локальные чекауты навязываются через ENCX_REPO=/path
#             и TGMOCK_REPO=/path.
#
# Использование:
#   tests/e2e.sh
#   ENCX_REPO=/path/to/encx-cli TGMOCK_REPO=/path/to/telegram-mock-ai tests/e2e.sh
#   ENCX_REF=some-branch tests/e2e.sh
#   KEEP=1 tests/e2e.sh          # не удалять рабочий каталог (для разбора)
#
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# encx-cli (биндинги + encx-mock) и telegram-mock-ai тянутся из апстрима на
# GitHub, как и в Dockerfile. Локальные чекауты навязываются через
# ENCX_REPO=/path и TGMOCK_REPO=/path.
. "$ROOT/tests/lib/encx-source.sh"
TGMOCK_REPO="${TGMOCK_REPO:-$(cd "$ROOT/../telegram-mock-ai" 2>/dev/null && pwd || true)}"
TGMOCK_URL="https://github.com/skrashevich/telegram-mock-ai.git"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/enxbot-e2e.XXXXXX")"
DB="$WORK/enxbot.sqlite"

TG_PORT="${TG_PORT:-18091}"
TG_ADMIN="${TG_ADMIN:-18092}"
ENCX_PORT="${ENCX_PORT:-18093}"

TOKEN="111111:E2E-TEST-TOKEN"
GROUP=-100777; INFOCH=-100888
ADMIN_UID=900001; PLAYER_UID=900002

PASS=0; FAIL=0
TG_PID=""; ENCX_PID=""; POLL_PID=""

cleanup() {
    [ -n "$POLL_PID" ] && kill "$POLL_PID" 2>/dev/null
    [ -n "$TG_PID" ]   && kill "$TG_PID" 2>/dev/null
    [ -n "$ENCX_PID" ] && kill "$ENCX_PID" 2>/dev/null
    wait 2>/dev/null
    if [ "${KEEP:-0}" = "1" ]; then
        echo "  (KEEP=1) рабочий каталог оставлен: $WORK"
    else
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

say() { printf '%s\n' "$*"; }
ok()  { PASS=$((PASS+1)); printf '  ok   %s\n' "$*"; }
bad() { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$*"; }
die() { printf '  ПРОВАЛ: %s\n' "$*"; exit 1; }
sq()  { php "$ROOT/tests/sqlite.php" "$DB" "$1"; }
chat_msgs() { curl -s "http://127.0.0.1:$TG_ADMIN/api/chats/$1/messages?limit=300"; }

inject() { # chat user text  -- сообщение "от пользователя", как в реальном чате
    curl -s -o /dev/null -X POST "http://127.0.0.1:$TG_ADMIN/api/chats/$1/messages" \
        -H 'Content-Type: application/json' \
        -d "$(php -r 'echo json_encode(["user_id"=>(int)$argv[1],"text"=>$argv[2]]);' -- "$2" "$3")"
}

inject_update() { # raw JSON update
    curl -s -o /dev/null -X POST "http://127.0.0.1:$TG_ADMIN/api/bots/$TOKEN/updates" \
        -H 'Content-Type: application/json' -d "$1"
}

# expect_reply CHAT NEEDLE LABEL -- ждём до ~10 c сообщение бота с подстрокой NEEDLE
expect_reply() {
    local chat="$1" needle="$2" label="$3" i out
    for i in $(seq 1 50); do
        out="$(chat_msgs "$chat")"
        if printf '%s' "$out" | php -r '
            $j=json_decode(stream_get_contents(STDIN),true); $n=$argv[1];
            if(!is_array($j)) exit(1);
            foreach($j as $m){ if(!empty($m["from"]["is_bot"]) && isset($m["text"]) && mb_strpos($m["text"],$n)!==false) exit(0); }
            exit(1);' -- "$needle"; then
            ok "$label"; return 0
        fi
        sleep 0.2
    done
    bad "$label — нет ответа бота с подстрокой: $needle"
    printf '%s' "$out" | php -r '$j=json_decode(stream_get_contents(STDIN),true); if(is_array($j)) foreach($j as $m){ if(!empty($m["from"]["is_bot"])) fwrite(STDERR,"      bot> ".str_replace("\n"," / ",mb_substr($m["text"]??"",0,160))."\n"); }'
    return 1
}

wait_db() { # sql expected label
    local i v
    for i in $(seq 1 40); do v="$(sq "$1")"; [ "$v" = "$2" ] && { ok "$3"; return 0; }; sleep 0.2; done
    bad "$3 — ожидалось [$1] = $2, получено [$v]"; return 1
}

run_php() { # SCRIPT [ENV=val ...]
    local script="$1"; shift
    ( cd "$WORK" && env "$@" php -d error_reporting=E_ALL -d log_errors=1 -d error_log="$WORK/php_errors.log" "$script" )
}

# ---------------------------------------------------------------------------
say "== окружение =="
command -v go   >/dev/null || die "нужен go (1.22+)"
command -v git  >/dev/null || die "нужен git"
command -v curl >/dev/null || die "нужен curl"
php -m | grep -qi pdo_sqlite || die "php без pdo_sqlite"
php -m | grep -qi FFI        || die "php без ffi"
say "  ok   go $(go version | awk '{print $3}'), php $(php -r 'echo PHP_VERSION;')"

say "== источник encx-cli =="
resolve_encx_source "$ROOT" || die "не удалось получить encx-cli"

say "== PHP-биндинги encx =="
if (cd "$ENCX_BINDINGS_PATH" && bash build.sh >"$WORK/bindings.log" 2>&1); then
    ok "собраны из $ENCX_BINDINGS_PATH"
elif ls "$ENCX_BINDINGS_PATH"/lib/libencx.* >/dev/null 2>&1; then
    ok "используется ранее собранная libencx"
else
    die "биндинги не собираются и готовой библиотеки нет ($(tail -1 "$WORK/bindings.log"))"
fi
export ENCX_BINDINGS_PATH ENCX_USE_HTTP=1

say "== сборка encx-mock =="
( cd "$ENCX_REPO" && go build -o "$WORK/encx-mock" ./cmd/encx-mock ) || die "go build encx-mock"
ok "$WORK/encx-mock"

say "== сборка telegram-mock-ai (апстрим) =="
# Обе правки совместимости с боевым api.telegram.org (метод в теле запроса и
# лимит длины в символах) влиты в апстрим — https://github.com/skrashevich/telegram-mock-ai/pull/2
if [ -z "$TGMOCK_REPO" ] || [ ! -d "$TGMOCK_REPO" ]; then
    say "  telegram-mock-ai не найден рядом — клонирую"
    git clone --depth 1 "$TGMOCK_URL" "$WORK/telegram-mock-ai" >"$WORK/clone.log" 2>&1 || die "git clone telegram-mock-ai"
    TGMOCK_REPO="$WORK/telegram-mock-ai"
fi
( cd "$TGMOCK_REPO" && go build -o "$WORK/tgmock-bin" ./cmd/telegram-mock-ai ) || die "go build telegram-mock-ai"
ok "$WORK/tgmock-bin"

# ---------------------------------------------------------------------------
say "== копия проекта =="
cp "$ROOT"/*.php "$ROOT"/db.sql "$ROOT"/enscreen.js "$WORK/" 2>/dev/null
cat > "$WORK/config.php" <<PHP
<?php
define('BOT_TOKEN', '$TOKEN');
define('API_URL', 'http://127.0.0.1:$TG_PORT/bot$TOKEN/');
define('ENCRYPTION_KEY', 'e2e-encryption-key');
define('BOT_USERNAME', 'enxe2ebot');
define('ADMIN_USERNAME', 'e2eadmin');
define('PHANTOMJS', '/nonexistent/phantomjs');
define('GEOCODER_URL', 'http://127.0.0.1:9/geocode');   # заведомо мёртвый адрес - геокодер деградирует в пустую строку
define('ROUTER_URL', 'http://127.0.0.1:9/route');
\$sqlite_path = '$DB';
PHP
ok "$WORK"

say "== запуск encx-mock :$ENCX_PORT =="
ENCX_MOCK_ADDR="127.0.0.1:$ENCX_PORT" "$WORK/encx-mock" >"$WORK/encx.log" 2>&1 & ENCX_PID=$!

say "== запуск telegram-mock-ai :$TG_PORT (admin :$TG_ADMIN) =="
cat > "$WORK/tg.yaml" <<YAML
server: { host: "127.0.0.1", port: $TG_PORT }
llm: { enabled: false }
proactive: { enabled: false }
admin: { enabled: true, host: "127.0.0.1", port: $TG_ADMIN }
log: { level: "warn", format: "text" }
seed:
  users:
    - { id: $ADMIN_UID,  first_name: "AdminE2E",  username: "e2eadmin" }
    - { id: $PLAYER_UID, first_name: "PlayerE2E", username: "e2eplayer" }
  chats:
    - { id: $GROUP,  type: "supergroup", title: "E2E Game Chat", members: [$ADMIN_UID, $PLAYER_UID] }
    - { id: $INFOCH, type: "channel",    title: "E2E Info",      members: [] }
  bots:
    - { token: "$TOKEN", username: "enxe2ebot", first_name: "ENX E2E Bot" }
YAML
"$WORK/tgmock-bin" -config "$WORK/tg.yaml" >"$WORK/tg.log" 2>&1 & TG_PID=$!

for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$ENCX_PORT/" && curl -s -o /dev/null "http://127.0.0.1:$TG_ADMIN/api/health" && break
    sleep 0.1
done
curl -s -o /dev/null "http://127.0.0.1:$TG_ADMIN/api/health" || die "telegram-mock-ai не поднялся ($(tail -3 "$WORK/tg.log"))"
curl -s -o /dev/null "http://127.0.0.1:$ENCX_PORT/" || die "encx-mock не поднялся ($(tail -3 "$WORK/encx.log"))"
ok "моки отвечают"

say "== БД: схема + активная игра =="
sq "SELECT 1" >/dev/null   # db.php создаёт схему из db.sql при первом подключении
sq "INSERT INTO games (chat_id, game_id, game_domain, game_login, game_pass, cookies, last_level_id, status, infochannel)
    VALUES ($GROUP, 424242, '127.0.0.1:$ENCX_PORT', 'e2e', 'e2e', '', 0, 1, '$INFOCH')"
sq "INSERT INTO admins (admin_username) VALUES ('e2eadmin')"
ok "game chat=$GROUP id=424242 status=1"

say "== polling.php (реальный входной цикл бота) =="
( cd "$WORK" && ENXBOT_POLL_TIMEOUT=2 ENXBOT_POLL_RETRY_DELAY=1 \
  php -d error_reporting=E_ALL -d log_errors=1 -d error_log="$WORK/php_errors.log" \
  polling.php >"$WORK/polling.log" 2>&1 ) & POLL_PID=$!
sleep 2

# ===========================================================================
say ""; say "== СЦЕНАРИЙ: полная игра =="

inject $GROUP $ADMIN_UID "/start"
expect_reply $GROUP "бот для игры Encounter" "/start отдаёт справку (длинный Markdown, ~2.7к символов)"

inject $GROUP $ADMIN_UID "/game print"
expect_reply $GROUP "127.0.0.1:$ENCX_PORT" "/game print: домен"
expect_reply $GROUP "Игра 424242"          "/game print: id игры"

inject $GROUP $ADMIN_UID "/game auth"
expect_reply $GROUP "Авторизация успешно пройдена" "/game auth: логин на движке через биндинги"
CK="$(sq "SELECT length(cookies) FROM games WHERE chat_id=$GROUP")"
[ "${CK:-0}" -gt 10 ] && ok "сессия движка сохранена в SQLite ($CK байт)" || bad "куки сессии не сохранены (len=$CK)"

inject $GROUP $ADMIN_UID "/game test"
expect_reply $GROUP "Mock Game 2026" "/game test: название игры из GameModel"

inject $GROUP $ADMIN_UID "/level"
expect_reply $GROUP "Уровень 1 из 3" "/level: номер текущего уровня"

inject $GROUP $ADMIN_UID "/hints"
expect_reply $GROUP "одсказок нет" "/hints: getHints() форматирует ответ без ошибок"

inject $GROUP $ADMIN_UID "/sectors"
expect_reply $GROUP "4 сектора" "/sectors: разбор секторов из модели"

say "-- приём кодов --"
inject $GROUP $PLAYER_UID "&NEVERMATCH"
expect_reply $GROUP "Код не принят" "неверный код отклонён движком"

inject $GROUP $PLAYER_UID "&CODE-1"
expect_reply $GROUP "Код принят" "код 1-го уровня принят"
wait_db "SELECT last_level_id FROM games WHERE chat_id=$GROUP" "1400002" "автопереход: last_level_id продвинут (уровень 2)"

say "-- cron.php против живого движка --"
run_php cron.php >"$WORK/cron1.log" 2>&1
[ $? -eq 0 ] && ok "cron.php завершился кодом 0" || bad "cron.php упал"
[ "$(sq "SELECT value FROM settings WHERE chat_id=0 AND name='cron_running'")" = "false" ] \
    && ok "cron снял блокировку (register_shutdown_function)" || bad "блокировка cron не снята"

inject $GROUP $ADMIN_UID "/game codes stat"
expect_reply $GROUP "Всего пробито через бота" "/game codes stat: агрегат по codeslog"

inject $GROUP $PLAYER_UID "&CODE-2"
expect_reply $GROUP "Код принят" "код 2-го уровня принят"
wait_db "SELECT last_level_id FROM games WHERE chat_id=$GROUP" "1400003" "автопереход: уровень 3"

inject $GROUP $PLAYER_UID "&CODE-3"
expect_reply $GROUP "Код принят" "код 3-го уровня принят — игра завершается движком"

say "-- координаты --"
inject $GROUP $ADMIN_UID "/coords 55.755814, 37.617635"
expect_reply $GROUP "55.755814" "/coords: публикация координаты со ссылками на карты"

say "-- автоостановка по сигналу движка (Event = game finished) --"
run_php cron.php ENXBOT_IDLE_TIMEOUT=999999 >"$WORK/cron2.log" 2>&1
wait_db "SELECT status FROM games WHERE chat_id=$GROUP" "0" "cron остановил завершённую движком игру"
expect_reply $GROUP "Игра завершена" "чат уведомлён о завершении игры"

say "-- daemon.php: просроченный таймер подсказки --"
sq "UPDATE games SET status=1 WHERE chat_id=$GROUP"
LL="$(sq "SELECT last_level_id FROM games WHERE chat_id=$GROUP")"
sq "INSERT INTO timers (game_id, chat_id, level_id, hint, time, type) VALUES (424242, $GROUP, $LL, 1, $(( $(date +%s) - 5 )), 1)"
( cd "$WORK" && ENXBOT_PID_FILE="$WORK/daemon.pid" timeout 6 \
  php -d error_reporting=E_ALL -d log_errors=1 -d error_log="$WORK/php_errors.log" daemon.php >"$WORK/daemon.log" 2>&1 )
wait_db "SELECT COUNT(*) FROM timers WHERE chat_id=$GROUP AND type=1 AND time<=$(date +%s)" "0" "daemon.php обработал просроченный таймер"

say "-- инфоканал --"
INFO_CNT="$(chat_msgs $INFOCH | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo is_array($j)?count($j):0;')"
[ "${INFO_CNT:-0}" -ge 1 ] && ok "бот дублировал сообщения в инфоканал ($INFO_CNT шт.)" || bad "в инфоканал ничего не ушло"

# ===========================================================================
say ""; say "== СЦЕНАРИЙ: права, кнопки настроек, гео-режим, restart =="
# отдельный чат: две активные игры путали бы gameSettingsbyUser()
sq "UPDATE games SET status=0 WHERE chat_id=$GROUP"

CHAT2=-100999; U_PLR2=900003
curl -s -o /dev/null -X POST "http://127.0.0.1:$TG_ADMIN/api/users" -H 'Content-Type: application/json' \
    -d "{\"id\":$U_PLR2,\"first_name\":\"Player2\",\"username\":\"e2eplayer2\"}"
curl -s -o /dev/null -X POST "http://127.0.0.1:$TG_ADMIN/api/chats" -H 'Content-Type: application/json' \
    -d "{\"id\":$CHAT2,\"type\":\"supergroup\",\"title\":\"E2E Adv\",\"members\":[$ADMIN_UID,$U_PLR2]}"
sq "INSERT INTO games (chat_id, game_id, game_domain, game_login, game_pass, cookies, last_level_id, status)
    VALUES ($CHAT2, 424242, '127.0.0.1:$ENCX_PORT', 'e2e', 'e2e', '', 1400001, 1)"

inject $CHAT2 $U_PLR2 "/game stop"
expect_reply $CHAT2 "только администратор" "обычному игроку отказано в /game stop"
[ "$(sq "SELECT status FROM games WHERE chat_id=$CHAT2")" = "1" ] && ok "игра не остановлена не-админом" || bad "не-админ остановил игру"

sq "DELETE FROM settings WHERE chat_id=$CHAT2 AND name='optimize_chat'"
sq "INSERT INTO settings (chat_id,name,value) VALUES ($CHAT2,'last_settings_message_id','1')"
CB_OK="$( cd "$WORK" && php -r 'require "config.php"; require "db.php"; require "functions.php"; echo settingsCallbackData("optimize_chat", $argv[1]);' -- "$CHAT2" )"
[ "${#CB_OK}" -le 64 ] && ok "подписанная callback_data в пределах лимита Telegram (${#CB_OK} байт)" || bad "callback_data > 64 байт (${#CB_OK})"
inject_update "$(php -r 'echo json_encode(["callback_query"=>["id"=>"cb1","from"=>["id"=>(int)$argv[1],"username"=>"e2eadmin"],"message"=>["message_id"=>1,"chat"=>["id"=>(int)$argv[2]]],"data"=>$argv[3]]]);' -- "$ADMIN_UID" "$CHAT2" "$CB_OK")"
wait_db "SELECT value FROM settings WHERE chat_id=$CHAT2 AND name='optimize_chat'" "true" "подписанная кнопка переключила настройку"

NP_BEFORE="$(sq "SELECT COUNT(*) FROM settings WHERE chat_id=$CHAT2 AND name='noprefix' AND value='true'")"
inject_update "$(php -r 'echo json_encode(["callback_query"=>["id"=>"cb2","from"=>["id"=>(int)$argv[1],"username"=>"e2eadmin"],"message"=>["message_id"=>1,"chat"=>["id"=>(int)$argv[2]]],"data"=>"noprefix ".$argv[2]." deadbeef00"]]);' -- "$ADMIN_UID" "$CHAT2")"
sleep 1
[ "$(sq "SELECT COUNT(*) FROM settings WHERE chat_id=$CHAT2 AND name='noprefix' AND value='true'")" = "$NP_BEFORE" ] \
    && ok "подделанная (неверная HMAC) callback_data отвергнута" || bad "подделанная callback_data изменила настройку"

inject $CHAT2 $ADMIN_UID "/game codes locon"
expect_reply $CHAT2 "только с локацией" "/game codes locon переводит приём кодов в гео-режим"
[ "$(sq "SELECT status FROM games WHERE chat_id=$CHAT2")" = "3" ] && ok "status=3 (гео-режим)" || bad "status не переключился в 3"
inject $CHAT2 $U_PLR2 "&CODE-1"
expect_reply $CHAT2 "только с геолокацией" "код без геометки в гео-режиме отклонён"

sq "UPDATE games SET status=1 WHERE chat_id=$CHAT2"
inject $CHAT2 $ADMIN_UID "/game restart"
expect_reply $CHAT2 "сброшена" "/game restart подтверждает сброс"
wait_db "SELECT last_level_id||'/'||status FROM games WHERE chat_id=$CHAT2" "0/0" "restart обнулил уровень и статус"
[ "$(sq "SELECT COUNT(*) FROM settings WHERE chat_id=$CHAT2 AND name='optimize_chat' AND value='true'")" = "1" ] \
    && ok "restart сохранил настройки чата" || bad "restart потерял настройки чата"

# ===========================================================================
say ""; say "== диагностика PHP =="
if [ -s "$WORK/php_errors.log" ] && grep -Eq 'PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught' "$WORK/php_errors.log"; then
    bad "php_errors.log содержит диагностику:"; sed 's/^/      /' "$WORK/php_errors.log"
else
    ok "ни Fatal, ни Warning, ни Deprecated, ни Notice, ни Uncaught"
fi
if grep -Eq 'PHP (Fatal|Parse|Warning|Deprecated|Notice)|Uncaught|Curl returned error [1-9]' "$WORK/polling.log" 2>/dev/null; then
    bad "polling.log содержит ошибки:"; sed 's/^/      /' "$WORK/polling.log" | head -40
else
    ok "polling.log чист"
fi

say ""; say "== ИТОГ =="
say "  успешно: $PASS,  провалов: $FAIL"
if [ "$FAIL" -ne 0 ]; then
    say "  --- encx.log ---";    tail -20 "$WORK/encx.log"    2>/dev/null
    say "  --- tg.log ---";      tail -20 "$WORK/tg.log"      2>/dev/null
    say "  --- polling.log ---"; tail -25 "$WORK/polling.log" 2>/dev/null
    exit 1
fi
say "  E2E ЗЕЛЁНЫЙ"
