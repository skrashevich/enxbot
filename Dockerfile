FROM golang:1.27-bookworm AS encx-builder

WORKDIR /src/encx-cli
ADD https://github.com/skrashevich/encx-cli/archive/refs/heads/main.tar.gz /tmp/encx-cli.tar.gz
RUN tar -xzf /tmp/encx-cli.tar.gz --strip-components=1 \
    && rm /tmp/encx-cli.tar.gz
RUN bash bindings/php/build.sh

FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libffi-dev libzip-dev chromium chromium-driver python3 tini util-linux fonts-dejavu-core \
    && docker-php-ext-install -j"$(nproc)" ffi zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=encx-builder /src/encx-cli/bindings/php /opt/encx-php
COPY --chown=www-data:www-data *.php db.sql enscreen.js /app/
COPY --chown=www-data:www-data config.docker.php /app/config.php
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY screenshot-runner.py /usr/local/bin/enxbot-screenshot

RUN chmod 0755 /usr/local/bin/docker-entrypoint.sh /usr/local/bin/enxbot-screenshot
# Проверяем, что скрипт синтаксически цел и Chromium на месте, но НЕ запускаем
# Chromium: под QEMU (кросс-сборка amd64 на arm64 хосте) он падает на отсутствии
# SSE3. На боевом amd64/arm64 хосте Chromium исполняется в рантайме нормально.
RUN python3 -c "import py_compile; py_compile.compile('/usr/local/bin/enxbot-screenshot', doraise=True)" \
    && test -x /usr/bin/chromium \
    && mkdir -p /data /app/screens \
    && chown -R www-data:www-data /data /app/screens

ENV ENCX_BINDINGS_PATH=/opt/encx-php \
    ENXBOT_SQLITE_PATH=/data/enxbot.sqlite \
    ENXBOT_PID_FILE=/tmp/enxbot-daemon.pid \
    ENXBOT_CRON_INTERVAL=60 \
    ENXBOT_POLL_TIMEOUT=30 \
    ENXBOT_POLL_RETRY_DELAY=5

VOLUME ["/data"]
USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD test -r /tmp/enxbot-polling.pid && kill -0 "$(cat /tmp/enxbot-polling.pid)" || exit 1

STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/docker-entrypoint.sh"]
