#!/usr/bin/env bash
#
# Полный сквозной прогон против БОЕВОГО движка Encounter: создаёт временную
# 2-уровневую игру на живом домене, ждёт её старта, гоняет код-путь бота
# (parseCode -> auth -> getSectors -> sendCode -> автопереход -> финиш) и
# затем стирает игру.
#
# В отличие от tests/real-engine.sh (только чтение), здесь коды РЕАЛЬНО
# отправляются в движок — поэтому нужна своя игра, а не чужая.
#
# Не запускается автоматически. Требует аккаунт с правами автора игр:
#   EN_LOGIN=...  EN_PASS=...    реквизиты (обязательно)
#   EN_DOMAIN=demo.en.cx         домен с правами автора (по умолчанию)
#   ENCX_REPO=/path              локальный чекаут encx-cli (иначе — апстрим)
#   KEEP_GAME=1                  не стирать игру после прогона (для разбора)
#
# Пример:
#   EN_LOGIN=user EN_PASS=pass tests/real-game.sh
#
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$ROOT/tests/lib/encx-source.sh"

: "${EN_LOGIN:?нужен EN_LOGIN}"
: "${EN_PASS:?нужен EN_PASS}"
export EN_DOMAIN="${EN_DOMAIN:-demo.en.cx}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/enxbot-realgame.XXXXXX")"
GAMEID=""
cleanup() {
    if [ -n "$GAMEID" ] && [ "${KEEP_GAME:-0}" != "1" ]; then
        echo "== стираю игру #$GAMEID =="
        "$WORK/enxsetup" wipe "$GAMEID" 2>&1 | sed 's/^/  /' || true
    elif [ -n "$GAMEID" ]; then
        echo "  (KEEP_GAME=1) игра #$GAMEID оставлена"
    fi
    rm -rf "$WORK"
}
trap cleanup EXIT

echo "== источник encx-cli =="
resolve_encx_source "$ROOT" || exit 1

echo "== сборка PHP-биндингов =="
if (cd "$ENCX_BINDINGS_PATH" && bash build.sh >"$WORK/bindings.log" 2>&1); then
    echo "  ok   $ENCX_BINDINGS_PATH"
elif ls "$ENCX_BINDINGS_PATH"/lib/libencx.* >/dev/null 2>&1; then
    echo "  ok   (ранее собранная libencx)"
else
    echo "  ПРОВАЛ: биндинги не собрать"; tail -5 "$WORK/bindings.log"; exit 1
fi
export ENCX_BINDINGS_PATH

echo "== сборка enxsetup (админ-обвязка) =="
mkdir -p "$ENCX_REPO/cmd/enxsetup"
cp "$ROOT/tests/lib/enxsetup.go" "$ENCX_REPO/cmd/enxsetup/main.go"
( cd "$ENCX_REPO" && go build -o "$WORK/enxsetup" ./cmd/enxsetup )
rc=$?
rm -rf "$ENCX_REPO/cmd/enxsetup"
[ $rc -eq 0 ] || { echo "  ПРОВАЛ: go build enxsetup"; exit 1; }
echo "  ok   $WORK/enxsetup"

echo "== создаю временную игру на $EN_DOMAIN =="
CREATE_OUT="$("$WORK/enxsetup" create 2>"$WORK/create.err")"
sed 's/^/  /' "$WORK/create.err"
GAMEID="$(printf '%s\n' "$CREATE_OUT" | sed -n 's/^GAMEID=//p')"
STARTUNIX="$(printf '%s\n' "$CREATE_OUT" | sed -n 's/^STARTUNIX=//p')"
[ -n "$GAMEID" ] || { echo "  ПРОВАЛ: игра не создана"; exit 1; }
echo "  создана игра #$GAMEID"

WAIT=$(( STARTUNIX - $(date +%s) + 6 ))
if [ "$WAIT" -gt 0 ]; then
    echo "== жду старта игры (${WAIT} c) =="
    perl -e "select(undef,undef,undef,$WAIT)" 2>/dev/null || sleep "$WAIT"
fi

echo "== код-путь бота против #$GAMEID =="
EN_GAMEID="$GAMEID" php -d error_reporting=E_ALL -d display_errors=1 "$ROOT/tests/real-game.php"
