# Тесты enxbot

Смоук-стенд, который проверяет, что бот работает на PHP 8 без фатальных
ошибок, warning'ов и deprecation'ов.

## Запуск

```sh
tests/run.sh
```

Требования: PHP 8.x с расширениями `ffi`, `pdo_sqlite`, `curl`, `openssl`,
`mbstring`, `zip`, Go 1.22+ и git (encx-cli тянется из апстрима на GitHub и кэшируется в `tests/.cache/`). Внешний сервер БД и отдельная подготовка схемы не нужны:
тест создаёт временный файл SQLite и удаляет его после завершения.

## Что проверяется

| Этап | Содержание |
| --- | --- |
| `php -l` | Все файлы проекта разбираются текущим PHP |
| `check_removed_api.php` | Нет вызовов API, удалённых в PHP 7/8, и функций новее PHP 8.0 |
| `smoke.php` | Не зависящие от движка функции `functions.php` и SQLite |
| `encx_mock.php` | PHP → FFI → encx-cli → настоящий stateful mock движка |
| Обработчик updates | Более 40 сценариев Telegram-апдейтов через `bot.php` |
| `polling.php` | Получение update через Telegram `getUpdates` без webhook |
| `cron.php` | Полный проход по играм, таймерам и секторам |
| `daemon.php` | Обработка просроченных таймеров (подсказка, автопереход, прочее) |
| Анализ журналов | Ни одного `Fatal error`, `Warning`, `Deprecated`, `Notice` |

Стенд разворачивает копию проекта во временном каталоге и создаёт там свой
`config.php`, поэтому рабочий каталог и боевой `config.php` не затрагиваются.

`stub_server.php` теперь нужен только для Telegram API и геокодера; игровая
часть проверяется через `encx-mock` и PHP-биндинги.

## Проверка против `encx-mock`

[`encx-mock`](https://github.com/skrashevich/encx-cli/tree/main/cmd/encx-mock) —
мок движка Encounter с состоянием.

```sh
tests/run.sh
# или
ENCX_REPO=/path/to/encx-cli tests/run.sh
ENCX_MOCK_BIN=/path/to/encx-mock tests/run.sh
```

Раннер сам собирает `libencx`, а при отсутствии установленного `encx-mock` —
и бинарник мока из соседнего репозитория. Проверяются логин, сохранение и
восстановление сессии, модель игры, сектора, отправка верного/неверного кода,
а также ответы 401 и 404.

## Сквозной тест `tests/e2e.sh`

`run.sh` дёргает `handleUpdate()` напрямую и использует самодельные заглушки.
`e2e.sh` идёт дальше: поднимает **настоящие** моки и гоняет бота через его же
точки входа.

```sh
tests/e2e.sh
# или
TGMOCK_REPO=/path/to/telegram-mock-ai ENCX_REPO=/path/to/encx-cli tests/e2e.sh
KEEP=1 tests/e2e.sh     # оставить рабочий каталог для разбора
```

| Компонент | Роль |
| --- | --- |
| [`telegram-mock-ai`](https://github.com/skrashevich/telegram-mock-ai) | эмулятор `api.telegram.org` (Bot API + Admin API для инъекции сообщений) |
| `encx-mock` из апстрима `skrashevich/encx-cli` | stateful-мок движка Encounter (та же кодовая база, что и у биндингов; тот же источник, что и в Dockerfile) |
| `polling.php`, `cron.php`, `daemon.php` | запускаются как есть, без правок |

Сценарий проводит целую игру: регистрация → `/game auth` (сессия движка
сохраняется в SQLite и восстанавливается коротким PHP-процессом) → модель
игры, подсказки, сектора → приём верных и неверных кодов → автопереходы →
сигнал движка о завершении → автоостановка игры в `cron.php` → обработка
просроченного таймера в `daemon.php`. Отдельный блок проверяет модель прав,
подписанные HMAC кнопки настроек (и отклонение подделки), гео-режим приёма
кодов и `/game restart`. Любой `Fatal`/`Warning`/`Deprecated`/`Notice` —
провал.

`telegram-mock-ai` берётся из `TGMOCK_REPO` или соседнего каталога, иначе
клонируется во временный каталог. Две правки совместимости с боевым
`api.telegram.org` (это были дефекты мока, не бота) влиты в апстрим —
[PR #2](https://github.com/skrashevich/telegram-mock-ai/pull/2):

1. `POST /bot<token>/` с именем метода в теле запроса (так шлёт
   `apiRequestJSON()` бота и официальный PHP-пример `hellobot`) мок не
   принимал вовсе;
2. лимит длины сообщения мок считал в байтах, а не в символах, и обрезал
   `/help` (~2.7 тыс. символов).

## Проверка против боевого движка `tests/real-engine.sh`

Моки подтверждают согласованность парсеров с самими собой, но не с
разметкой боевого Encounter. `real-engine.sh` закрывает этот разрыв:
логинится на настоящий домен и прогоняет обёртки движка из `functions.php`
(`auth`, `encxGameModel`, `getHints`/`getSectors`/`getBonuses`/`getMessages`,
`getLevelText`, `getScheme`, `isGameFinished`, `testGame`) против живых
ответов. **Только чтение — коды не отправляются.**

Не запускается автоматически (нужны реальные реквизиты):

```sh
EN_LOGIN=user EN_PASS=pass EN_GAMEID=31748 tests/real-engine.sh
# EN_DOMAIN по умолчанию demo.en.cx
```

Без `EN_GAMEID` проверяется только логин и отклонение неверного пароля.
С `EN_GAMEID` — что каждая обёртка не бросает фатал и корректно деградирует,
когда команда не в бою (реальный движок отвечает `Event=5/7/8`, `Level`
отсутствует).

## Полный прогон боевого движка `tests/real-game.sh`

`real-engine.sh` только читает. `real-game.sh` идёт до конца: через
`encx`-админку **создаёт** временную 2-уровневую игру на живом домене (уровень
1 — 2 сектора, уровень 2 — 1 сектор, у каждого задание и подсказка), ждёт её
старта, гоняет код-путь бота (`parseCode` → `auth` → `getSectors` →
`sendCode` → автопереход по закрытию всех секторов → финиш), затем **стирает**
игру. Коды реально уходят в движок, поэтому нужен свой аккаунт с правами
автора игр.

```sh
EN_LOGIN=user EN_PASS=pass tests/real-game.sh
# EN_DOMAIN по умолчанию demo.en.cx; KEEP_GAME=1 — не стирать игру
```

Именно этот прогон вскрыл, что боевой REST-движок сообщает о завершённой игре
кодом `Event=17` (`EventGameEnded`), а не `6`: `isGameFinished()` теперь
принимает оба (регресс закрыт `isGameFinishedEvent()` в `smoke.php`).

Обвязка создания/зачистки игры — `tests/lib/enxsetup.go`, собирается скриптом
внутри кэша `encx-cli` (импортирует `github.com/skrashevich/encx-cli/encx`).
Удалить сам «скелет» игры движок по API не даёт — после прогона остаётся
пустая запись `enxbot e2e …`, которую при желании убирают через веб-панель.

## Источник encx-cli

`run.sh`, `e2e.sh`, `real-engine.sh` и `real-game.sh` тянут `encx-cli`
(PHP-биндинги + `cmd/encx-mock`) из апстрима `github.com/skrashevich/encx-cli`
— тот же источник, что и `Dockerfile`. Клон кэшируется в `tests/.cache/encx-cli`
(в `.gitignore`). Переменные:

| Переменная | Назначение |
| --- | --- |
| `ENCX_REPO=/path` | взять локальный чекаут вместо GitHub |
| `ENCX_REF=main` | ветка/тег/коммит для клонирования |
| `ENCX_OFFLINE=1` | не ходить в сеть (нужен готовый кэш или `ENCX_REPO`) |
