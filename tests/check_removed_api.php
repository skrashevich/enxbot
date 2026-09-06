<?php
/*
    Ищет использование API, удалённых или объявленных устаревшими в PHP 7/8.

    Разбор идёт по токенам, а не по grep: комментарии и строки не дают
    ложных срабатываний, а `foreach` не путается с удалённой `each()`.

    Использование: php tests/check_removed_api.php <файл> [...]
*/

$removedFunctions = array(
    'mcrypt_encrypt' => 'удалена в PHP 7.2, замена - openssl_encrypt()',
    'mcrypt_decrypt' => 'удалена в PHP 7.2, замена - openssl_decrypt()',
    'mcrypt_create_iv' => 'удалена в PHP 7.2, замена - openssl_random_pseudo_bytes()',
    'mcrypt_get_iv_size' => 'удалена в PHP 7.2, замена - openssl_cipher_iv_length()',
    'mcrypt_module_open' => 'удалена в PHP 7.2',
    'each' => 'удалена в PHP 8.0, замена - foreach',
    'create_function' => 'удалена в PHP 8.0, замена - анонимная функция',
    'money_format' => 'удалена в PHP 8.0, замена - NumberFormatter',
    'ereg' => 'удалена в PHP 7.0, замена - preg_match()',
    'eregi' => 'удалена в PHP 7.0',
    'ereg_replace' => 'удалена в PHP 7.0',
    'split' => 'удалена в PHP 7.0, замена - preg_split()',
    'utf8_encode' => 'объявлена устаревшей в PHP 8.2',
    'utf8_decode' => 'объявлена устаревшей в PHP 8.2',
    'get_magic_quotes_gpc' => 'удалена в PHP 8.0',
    'restore_include_path' => 'удалена в PHP 8.0',
);

// Функции, появившиеся позже PHP 8.0: их использование сломало бы код
// на минимальной поддерживаемой версии, хотя на 8.5 всё пройдёт
$tooNewFunctions = array(
    'array_is_list' => 'появилась в PHP 8.1',
    'enum_exists' => 'появилась в PHP 8.1',
    'fsync' => 'появилась в PHP 8.1',
    'fdatasync' => 'появилась в PHP 8.1',
    'array_find' => 'появилась в PHP 8.4',
    'array_any' => 'появилась в PHP 8.4',
    'array_all' => 'появилась в PHP 8.4',
    'mb_str_pad' => 'появилась в PHP 8.3',
    'json_validate' => 'появилась в PHP 8.3',
    'str_increment' => 'появилась в PHP 8.3',
    'ldap_connect_wallet' => 'появилась в PHP 8.3',
    'request_parse_body' => 'появилась в PHP 8.4',
    'grapheme_str_split' => 'появилась в PHP 8.4',
);

$removedConstants = array(
    'E_STRICT' => 'уровень ошибок удалён, константа устарела в PHP 8.4',
    'FILTER_SANITIZE_STRING' => 'объявлена устаревшей в PHP 8.1',
    'CURLOPT_SAFE_UPLOAD' => 'отключение небезопасной загрузки в PHP 8 бросает ValueError',
    'MCRYPT_BLOWFISH' => 'расширение mcrypt удалено в PHP 7.2',
    'MCRYPT_MODE_ECB' => 'расширение mcrypt удалено в PHP 7.2',
    'MCRYPT_RAND' => 'расширение mcrypt удалено в PHP 7.2',
);

$files = array_slice($argv, 1);
if (!$files) {
    fwrite(STDERR, "Использование: php tests/check_removed_api.php <файл> [...]\n");
    exit(2);
}

$findings = array();

foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $name = $token[1];
        $lower = strtolower($name);

        // Пропускаем обращения к методам и свойствам: ->each(), Foo::each()
        $prev = previousMeaningful($tokens, $i);
        if ($prev !== null && is_array($prev)
            && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) {
            continue;
        }

        $next = nextMeaningful($tokens, $i);
        $isCall = $next === '(';

        if ($isCall && isset($removedFunctions[$lower])) {
            $findings[] = sprintf('%s:%d  %s() - %s', $file, $token[2], $name, $removedFunctions[$lower]);
        }

        if ($isCall && isset($tooNewFunctions[$lower])) {
            $findings[] = sprintf('%s:%d  %s() - %s, а целевая версия PHP 8.0', $file, $token[2], $name, $tooNewFunctions[$lower]);
        }

        if (!$isCall && isset($removedConstants[$name])) {
            $findings[] = sprintf('%s:%d  %s - %s', $file, $token[2], $name, $removedConstants[$name]);
        }
    }
}

if ($findings) {
    echo "  НАЙДЕНЫ удалённые/устаревшие API:\n";
    foreach ($findings as $finding) {
        echo "    $finding\n";
    }
    exit(1);
}

echo '  ok   удалённых API не найдено ('.count($files)." файлов проверено)\n";
exit(0);

function previousMeaningful($tokens, $i)
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return $tokens[$j];
    }
    return null;
}

function nextMeaningful($tokens, $i)
{
    $count = count($tokens);
    for ($j = $i + 1; $j < $count; $j++) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return $tokens[$j];
    }
    return null;
}
