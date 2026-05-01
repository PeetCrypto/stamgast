<?php
declare(strict_types=1);

/**
 * Simpele .env loader — geen Composer/vendor dependencies nodig.
 * Laadt ALLEEN als .env bestaat in de project root.
 * Lokaal zonder .env = geen effect, productie met .env = overrides.
 */

$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Remove surrounding quotes
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $matches)) {
            $value = $matches[1];
        }
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}
