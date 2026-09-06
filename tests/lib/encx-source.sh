#!/usr/bin/env bash
#
# Общий резолвер источника encx-cli (PHP-биндинги + cmd/encx-mock) для
# tests/run.sh и tests/e2e.sh. Источник — апстрим на GitHub, тот же, что
# тянет Dockerfile.
#
# Экспортирует:
#   ENCX_REPO            корень дерева encx-cli
#   ENCX_BINDINGS_PATH   $ENCX_REPO/bindings/php
#
# Переменные окружения:
#   ENCX_REPO=/path       использовать локальный чекаут вместо GitHub (escape hatch)
#   ENCX_REF=main         ветка/тег/коммит для клонирования (по умолчанию main)
#   ENCX_CACHE=/path       каталог кэша (по умолчанию <repo>/tests/.cache/encx-cli)
#   ENCX_OFFLINE=1        не ходить в сеть: требовать готовый кэш или ENCX_REPO

ENCX_UPSTREAM_URL="https://github.com/skrashevich/encx-cli.git"

resolve_encx_source() {
    local root="$1"
    local ref="${ENCX_REF:-main}"
    local cache="${ENCX_CACHE:-$root/tests/.cache/encx-cli}"

    if [ -n "${ENCX_REPO:-}" ] && [ -d "$ENCX_REPO/bindings/php" ]; then
        echo "  encx-cli: локальный чекаут $ENCX_REPO"
    else
        if [ -d "$cache/.git" ]; then
            if [ "${ENCX_OFFLINE:-0}" != "1" ]; then
                echo "  encx-cli: обновляю кэш ($ref)"
                git -C "$cache" fetch --depth 1 origin "$ref" >/dev/null 2>&1 \
                    && git -C "$cache" checkout -q FETCH_HEAD 2>/dev/null || true
            else
                echo "  encx-cli: офлайн, использую кэш $cache"
            fi
        else
            if [ "${ENCX_OFFLINE:-0}" = "1" ]; then
                echo "  ПРОВАЛ: ENCX_OFFLINE=1, но кэша нет ($cache) и ENCX_REPO не задан" >&2
                return 1
            fi
            echo "  encx-cli: клонирую $ENCX_UPSTREAM_URL@$ref -> $cache"
            mkdir -p "$(dirname "$cache")"
            git clone --depth 1 --branch "$ref" "$ENCX_UPSTREAM_URL" "$cache" >/dev/null 2>&1 \
                || git clone --depth 1 "$ENCX_UPSTREAM_URL" "$cache" >/dev/null 2>&1 \
                || { echo "  ПРОВАЛ: не удалось клонировать encx-cli" >&2; return 1; }
        fi
        ENCX_REPO="$cache"
        echo "  encx-cli: $(git -C "$ENCX_REPO" rev-parse --short HEAD 2>/dev/null) $(git -C "$ENCX_REPO" log -1 --format=%s 2>/dev/null | cut -c1-60)"
    fi

    ENCX_BINDINGS_PATH="${ENCX_BINDINGS_PATH:-$ENCX_REPO/bindings/php}"
    export ENCX_REPO ENCX_BINDINGS_PATH

    [ -f "$ENCX_BINDINGS_PATH/build.sh" ] || { echo "  ПРОВАЛ: нет $ENCX_BINDINGS_PATH/build.sh" >&2; return 1; }
    [ -d "$ENCX_REPO/cmd/encx-mock" ]     || { echo "  ПРОВАЛ: нет $ENCX_REPO/cmd/encx-mock" >&2; return 1; }
}
