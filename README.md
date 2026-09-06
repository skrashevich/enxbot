# enxbot

Telegram-бот для игры [Encounter](https://en.cx/). Помогает команде пробивать
коды, следит за таймерами уровня и разгружает штурманов от рутины при работе с
движком.

Что умеет:

- **Пробитие кодов и бонусов** прямо из игрового чата (`&en123`, `#en123`,
  `;en123 //комментарий`), автоопределение бонусных кодов, гео-коды.
- **Метки и сектора**: сводка по секторам уровня, списки открытых/закрытых
  меток и бонусов, схема дохода, подсказки, сообщения организатора.
- **Таймеры**: уведомления о подсказках и автопереходе за 15 и 5 минут и в
  момент события, скриншот движка на каждое событие.
- **Точки на местности**: `/setpoint`, `/listpoint`, `/closepoint`, приём
  Telegram-venue, карта точек.
- **Штабной чат** (`/game shtab`) и **инфоканал** для дублирования событий.
- **Скриншоты** движка через headless Chromium (`/screenshot`, `/getscreens`).

Полный список команд — `/help` в самом боте (см. также `bot.php`).

## Как это устроено

Бот работает **только на long polling**, входящий HTTP-порт и webhook не
нужны. Внутри контейнера крутятся три процесса:

| Процесс        | Назначение                                                            |
| -------------- | ------------------------------------------------------------------- |
| `polling.php`  | Telegram long polling → `handleUpdate()`; подтверждает update только после обработки |
| `daemon.php`   | обработка просроченных таймеров (подсказка, автопереход, события)     |
| `cron.php`     | периодический проход по всем играм, таймерам и секторам               |

Состояние (игры, коды, таймеры, настройки чатов, очки, точки, координаты)
хранится в одном файле **SQLite** — `/data/enxbot.sqlite`. Схема создаётся и
мигрируется автоматически при старте.

PHP-биндинги к движку и `libencx` берутся из публичного репозитория
[`skrashevich/encx-cli`](https://github.com/skrashevich/encx-cli) (ветка
`main`) и собираются в builder-стадии образа.

## Требования

- **Docker** 24+ с плагином Compose v2 — для всех вариантов, кроме запуска
  «на голом PHP».
- Токен бота от [@BotFather](https://t.me/BotFather).
- Данные аккаунта Encounter с правами на нужную игру (домен, логин, пароль).

## Запуск

### Вариант 1. Docker Compose + готовый образ из GHCR (рекомендуется)

Репозиторный `docker-compose.yml` уже настроен на образ
`ghcr.io/skrashevich/enxbot:latest`.

```sh
cp .env.example .env
# заполните BOT_TOKEN, BOT_USERNAME, ENCRYPTION_KEY (и, при желании, ADMIN_USERNAME)

docker compose up -d
docker compose logs -f
```

Обновление до свежего образа:

```sh
docker compose pull
docker compose up -d
```

> Если образ в GHCR приватный, сначала выполните
> `docker login ghcr.io` (username — логин GitHub, password — PAT с правом
> `read:packages`) либо сделайте пакет публичным в настройках GitHub Packages.

### Вариант 2. Docker Compose + локальная сборка

Тот же файл содержит `build: .`, поэтому образ можно собрать из исходников,
не заходя в реестр:

```sh
cp .env.example .env   # заполнить обязательные переменные

docker compose up -d --build
```

Сборка тянет `encx-cli` из GitHub, поэтому нужен доступ в сеть.

### Вариант 3. `docker run` без Compose

```sh
docker run -d \
  --name enxbot \
  --restart unless-stopped \
  -v enxbot-data:/data \
  -e BOT_TOKEN='123456:ваш-telegram-токен' \
  -e BOT_USERNAME='my_encounter_bot' \
  -e ENCRYPTION_KEY='длинная-случайная-строка' \
  -e ADMIN_USERNAME='telegram_admin' \
  ghcr.io/skrashevich/enxbot:latest
```

Собственная сборка образа: `docker build -t enxbot . && docker run ... enxbot`.

Именованный том `enxbot-data` хранит `/data/enxbot.sqlite` и переживает
пересоздание контейнера; удаление контейнера том не трогает.

### Вариант 4. Без Docker, напрямую на PHP

Подходит для отладки и нестандартных окружений. Docker-вариант проще, потому
что сам собирает биндинги движка.

1. **PHP 8.1+** (проверяется на 8.4–8.5) с расширениями `ffi`, `pdo_sqlite`,
   `curl`, `openssl`, `mbstring`, `zip`.
2. **Go 1.22+** и `git` — для сборки PHP-биндингов `encx-cli`:

   ```sh
   git clone https://github.com/skrashevich/encx-cli /opt/encx-cli
   bash /opt/encx-cli/bindings/php/build.sh
   export ENCX_BINDINGS_PATH=/opt/encx-cli/bindings/php
   ```

3. *(опционально)* **Chromium** + `chromium-driver` и Python 3 — для
   `/screenshot` и `/getscreens`. Путь к обёртке задаётся константой
   `PHANTOMJS`.
4. Создайте `config.php` в корне репозитория:

   ```php
   <?php
   define('BOT_TOKEN', '123456:ваш-telegram-токен');
   define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
   define('ENCRYPTION_KEY', 'длинная-случайная-строка');
   define('BOT_USERNAME', 'my_encounter_bot');   // без @
   define('ADMIN_USERNAME', 'telegram_admin');   // без @, можно ''
   define('PHANTOMJS', '/usr/local/bin/enxbot-screenshot'); // обёртка над Chromium
   // define('PUBLIC_URL', 'https://bot.example.com'); // только для ссылок map.php
   $sqlite_path = __DIR__.'/data/enxbot.sqlite';  // каталог создаётся автоматически
   ```

5. Запустите три процесса (каждый — своим сервисом systemd/supervisor или в
   отдельном терминале):

   ```sh
   php polling.php
   php daemon.php
   while :; do php cron.php; sleep 60; done
   ```

## Конфигурация

В Docker всё задаётся переменными окружения (см. `.env.example`), в
bare-metal — одноимёнными константами в `config.php`.

| Переменная               | Обяз. | По умолчанию            | Назначение                                                    |
| ------------------------ | :---: | ---------------------- | ----------------------------------------------------------- |
| `BOT_TOKEN`              |  да   | —                      | токен Telegram-бота                                          |
| `BOT_USERNAME`           |  да   | —                      | имя бота без `@`                                             |
| `ENCRYPTION_KEY`         |  да   | —                      | ключ шифрования паролей Encounter в БД                        |
| `ADMIN_USERNAME`         |  нет  | `''`                   | главный администратор бота (username без `@`)                 |
| `PUBLIC_URL`             |  нет  | —                      | базовый URL для ссылок на `map.php` и адреса точек            |
| `ENXBOT_SQLITE_PATH`     |  нет  | `/data/enxbot.sqlite`  | путь к файлу БД                                              |
| `ENXBOT_CRON_INTERVAL`   |  нет  | `60`                   | период запуска `cron.php`, секунды                            |
| `ENXBOT_POLL_TIMEOUT`    |  нет  | `30`                   | таймаут Telegram long polling, 1..50 секунд                   |
| `ENXBOT_IDLE_TIMEOUT`    |  нет  | `86400`                | тишина в чате до автоостановки игры, секунды                  |
| `ENXBOT_CRON_LOCK_TIMEOUT` | нет | `300`                  | протухание блокировки `cron.php`, секунды                     |
| `API_URL`                |  нет  | Telegram Bot API       | альтернативный endpoint (полезно для тестов)                  |
| `GEOCODER_URL`           |  нет  | —                      | сервис геокодинга для адресов точек                           |
| `ENCX_ENGINE`            |  нет  | `auto`                 | backend движка: `auto` / `legacy` / `new`                     |
| `ENCX_USE_HTTP`          |  нет  | auto                   | принудительный HTTP (локальные адреса определяются сами)      |
| `ENCX_INSECURE_TLS`      |  нет  | `false`                | отключить проверку TLS для нестандартного домена Encounter    |

Подробнее о Docker-специфике — в [`DOCKER.md`](DOCKER.md).

## Настройка игры в Telegram

Команды доступны администраторам чата и бота. Добавьте бота в игровой чат и
введите по порядку:

```
/game domain moscow.en.cx     # домен игры
/game login ЛОГИН              # логин движка
/game pass ЗАШИФР_ПАРОЛЬ       # см. /encrypt в личке боту
/game id 12345                 # ID игры из адресной строки
/game auth                     # авторизация на движке
/game start                    # старт бота в этом чате
```

Зашифрованный пароль получают командой `/encrypt пароль` в личном чате с
ботом (в открытом виде пароль в БД не хранится). Прочие команды управления —
`/game print`, `/game stop`, `/game restart`, `/game delete`,
`/game codes on|off`, `/game shtab -100…`, `/game infochannel @channel`.

## Данные и бэкапы

Вся персистентность — файл `enxbot.sqlite` в томе `/data`. Горячий бэкап без
остановки бота:

```sh
docker compose exec -T enxbot php -r '$d=new PDO("sqlite:/data/enxbot.sqlite"); $d->exec("VACUUM INTO \"/data/backup.sqlite\"");'
docker compose cp enxbot:/data/backup.sqlite ./enxbot-backup.sqlite
```

Либо просто скопировать `enxbot.sqlite` (+ `-wal`, `-shm`) при остановленном
контейнере.

## CI/CD

GitHub Actions (`.github/workflows/docker-image.yml`) собирает и публикует
multi-arch образ (`linux/amd64`, `linux/arm64`) в GitHub Container Registry:

| Событие                          | Тег образа                          |
| -------------------------------- | ---------------------------------- |
| push в ветку по умолчанию        | `latest`, `sha-<короткий-хеш>`     |
| push произвольной ветки          | `sha-<короткий-хеш>`               |
| тег `v*` (например `v1.2.0`)     | `v1.2.0`                           |
| pull request                     | только сборка, без публикации      |

Аутентификация — встроенным `GITHUB_TOKEN` (`packages: write`), отдельные
секреты не нужны. После первой публикации при необходимости сделайте пакет
`enxbot` публичным в настройках GitHub Packages.

## Тесты

Смоук-стенд, e2e и прогоны против боевого движка описаны в
[`tests/README.md`](tests/README.md):

```sh
tests/run.sh      # смоук: php -l, SQLite, encx-mock, 40+ сценариев апдейтов
tests/e2e.sh      # сквозной прогон через реальные моки Telegram и движка
```

## Автор

[@skrashevich](https://github.com/skrashevich)
