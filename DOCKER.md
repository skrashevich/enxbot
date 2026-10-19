# Запуск enxbot в Docker

Образ запускает три процесса: Telegram long polling, `daemon.php` и
периодический `cron.php`. Входящий HTTP-порт и webhook не используются.

Образ собирается непосредственно из каталога `enxbot`. PHP-биндинги и
`libencx` загружаются из тега `v0.26.0` публичного репозитория
`encx-cli` и собираются внутри builder-стадии. Более новый тег можно задать
через `--build-arg ENCX_VERSION=v…`; минимальная версия — v0.26.0:

```sh
docker build -t enxbot .

docker run -d \
  --name enxbot \
  --restart unless-stopped \
  -v enxbot-data:/data \
  -e BOT_TOKEN='123456:telegram-token' \
  -e BOT_USERNAME='my_encounter_bot' \
  -e ENCRYPTION_KEY='replace-with-a-long-random-secret' \
  -e ADMIN_USERNAME='telegram_admin' \
  enxbot
```

Именованный том `enxbot-data` содержит `/data/enxbot.sqlite` и сохраняется
при пересоздании контейнера. Удаление контейнера не удаляет том.

Обязательные переменные:

- `BOT_TOKEN` — токен Telegram-бота;
- `BOT_USERNAME` — имя бота без `@`;
- `ENCRYPTION_KEY` — ключ шифрования паролей Encounter.

Дополнительные переменные:

- `ADMIN_USERNAME` — главный администратор;
- `ENXBOT_CRON_INTERVAL` — пауза между проходами `cron.php`, по умолчанию 5 секунд; на каждую игру выполняется один запрос состояния за проход;
- `ENXBOT_POLL_TIMEOUT` — таймаут long polling, по умолчанию 30 секунд;
- `ENXBOT_SQLITE_PATH` — путь к базе, по умолчанию `/data/enxbot.sqlite`;
- `PUBLIC_URL` — базовый URL дополнительных страниц вроде `map.php`, если они используются;
- `API_URL` — альтернативный Telegram API endpoint, полезен для тестов.
- `ENCX_ENGINE` — backend Encounter (`auto` по умолчанию, также `legacy`/`new`);
- `ENCX_USE_HTTP` — принудительно использовать HTTP (локальные адреса определяются автоматически);
- `ENCX_INSECURE_TLS` — отключить проверку TLS для нестандартного Encounter-домена.

При старте polling вызывает `deleteWebhook` с `drop_pending_updates=false`,
поэтому прежний webhook отключается без удаления накопившихся обновлений.
