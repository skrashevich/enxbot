<?php

// Telegram long polling. Процесс подтверждает update только после того, как
// handleUpdate() завершил его обработку, поэтому сбой не теряет сообщение.
error_reporting(E_ALL & ~E_NOTICE);

require 'bot.php';

if (php_sapi_name() !== 'cli') {
    die('Скрипт должен быть запущен из консоли');
}

$timeout = intval(getenv('ENXBOT_POLL_TIMEOUT') ?: 30);
$retryDelay = intval(getenv('ENXBOT_POLL_RETRY_DELAY') ?: 5);
$maxCycles = intval(getenv('ENXBOT_POLL_MAX_CYCLES') ?: 0);

if ($timeout < 1 || $timeout > 50) {
    throw new InvalidArgumentException('ENXBOT_POLL_TIMEOUT должен быть от 1 до 50 секунд');
}
if ($retryDelay < 1) {
    throw new InvalidArgumentException('ENXBOT_POLL_RETRY_DELAY должен быть положительным числом');
}

// getUpdates несовместим с активным webhook. Удаляем его, не выбрасывая уже
// накопленные Telegram обновления.
try {
    apiRequest('deleteWebhook', array('drop_pending_updates' => false));
} catch (TelegramUnauthorizedException $e) {
    fwrite(STDERR, 'polling: '.$e->getMessage()."\n");
    error_log('polling: '.$e->getMessage());
    exit(2);
}

$offset = 0;
$cycles = 0;
$errorCount = 0;

// Каждые столько подряд неуспешных циклов переустанавливаем соединение с БД:
// SQLite-хендл мог остаться в непригодном состоянии.
$reconnectAfter = 5;

while (true) {
    try {
        $updates = apiRequest('getUpdates', array(
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => array('message', 'inline_query', 'callback_query'),
        ));
    } catch (TelegramUnauthorizedException $e) {
        // Токен невалиден: повторять бессмысленно, выходим внятно
        fwrite(STDERR, 'polling: '.$e->getMessage()."\n");
        error_log('polling: '.$e->getMessage());
        exit(2);
    }

    if ($updates === false || !is_array($updates)) {
        $errorCount++;

        if ($errorCount % $reconnectAfter === 0) {
            error_log("polling: $errorCount ошибок подряд, переподключаюсь к БД");
            $db = null;
            require 'db.php';
        }

        // Прогрессивная задержка: при затяжном сбое не долбим API вплотную
        sleep(min($retryDelay * $errorCount, 30));
    } else {
        $errorCount = 0;

        foreach ($updates as $update) {
            if (!is_array($update) || !isset($update['update_id'])) {
                continue;
            }

            try {
                handleUpdate($update);
            } catch (TelegramUnauthorizedException $e) {
                fwrite(STDERR, 'polling: '.$e->getMessage()."\n");
                error_log('polling: '.$e->getMessage());
                exit(2);
            }

            $offset = max($offset, intval($update['update_id']) + 1);
        }
    }

    $cycles++;
    if ($maxCycles > 0 && $cycles >= $maxCycles) {
        break;
    }
}
