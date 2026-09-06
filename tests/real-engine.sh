#!/usr/bin/env bash
#
# Смоук против БОЕВОГО движка Encounter (по умолчанию demo.en.cx).
#
# Проверяет то, что моки подтвердить не могут: что обёртки движка в
# functions.php (auth, encxGameModel, getHints/getSectors/getBonuses/
# getMessages, getLevelText, getScheme, isGameFinished, testGame) работают
# против настоящей разметки/JSON движка и корректно деградируют, когда
# команда не в бою. ТОЛЬКО ЧТЕНИЕ — коды не отправляются.
#
# Не запускается автоматически (нужны реальные реквизиты):
#   EN_LOGIN=...  EN_PASS=...    реквизиты игрока Encounter (обязательно)
#   EN_GAMEID=...                id игры на домене (иначе проверяется только логин)
#   EN_DOMAIN=demo.en.cx         (по умолчанию)
#   ENCX_REPO=/path              локальный чекаут encx-cli (иначе — апстрим с GitHub)
#
# Пример:
#   EN_LOGIN=user EN_PASS=pass EN_GAMEID=31748 tests/real-engine.sh
#
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$ROOT/tests/lib/encx-source.sh"

: "${EN_LOGIN:?нужен EN_LOGIN}"
: "${EN_PASS:?нужен EN_PASS}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/enxbot-real.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

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
exec php -d error_reporting=E_ALL -d display_errors=1 "$ROOT/tests/real-engine.php"
