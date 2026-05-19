<?php

declare(strict_types=1);

/**
 * Prepare .env and DATABASE_URL for Docker / Railway before Symfony console runs.
 */

$projectDir = dirname(__DIR__);
$envPath = $projectDir.'/.env';
$envDockerPath = $projectDir.'/.env.docker';

if (!is_file($envPath)) {
    if (is_file($envDockerPath)) {
        copy($envDockerPath, $envPath);
    } else {
        file_put_contents($envPath, implode("\n", [
            'APP_ENV=prod',
            'APP_SECRET=change_me_in_production',
            'APP_SHARE_DIR=var/share',
            'DEFAULT_URI=http://localhost',
            'DATABASE_URL=',
            'MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0',
            'MAILER_DSN=null://null',
            '',
        ]));
    }
}

/**
 * @return array<string, string>
 */
function readEnvFile(string $path): array
{
    $vars = [];
    if (!is_file($path)) {
        return $vars;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $vars[$name] = trim($value, " \t\"'");
    }

    return $vars;
}

function formatEnvValue(string $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES);
}

/**
 * @param array<string, string> $vars
 */
function writeEnvFile(string $path, array $vars): void
{
    $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $output = [];
    $written = [];

    foreach ($lines as $line) {
        if (preg_match('/^([A-Z0-9_]+)=/', $line, $matches)) {
            $key = $matches[1];
            if (array_key_exists($key, $vars)) {
                $output[] = $key.'='.formatEnvValue($vars[$key]);
                $written[$key] = true;
                continue;
            }
        }
        $output[] = rtrim($line, "\r");
    }

    foreach ($vars as $key => $value) {
        if (!isset($written[$key])) {
            $output[] = $key.'='.formatEnvValue($value);
        }
    }

    file_put_contents($path, implode("\n", $output)."\n");
}

function env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }

    static $fileVars = null;
    if ($fileVars === null) {
        global $envPath;
        $fileVars = readEnvFile($envPath);
    }

    return $fileVars[$name] ?? $default;
}

function buildDatabaseUrl(): ?string
{
    $url = env('DATABASE_URL');
    if ($url) {
        return normalizeDatabaseUrl($url);
    }

    $url = env('MYSQL_URL') ?? env('MYSQL_PUBLIC_URL');
    if ($url) {
        return normalizeDatabaseUrl($url);
    }

    $host = env('MYSQLHOST');
    $user = env('MYSQLUSER');
    $password = env('MYSQLPASSWORD');
    $database = env('MYSQLDATABASE');
    $port = env('MYSQLPORT', '3306');

    if ($host && $user && $password !== null && $database) {
        return normalizeDatabaseUrl(sprintf(
            'mysql://%s:%s@%s:%s/%s',
            rawurlencode($user),
            rawurlencode($password),
            $host,
            $port,
            $database
        ));
    }

    return null;
}

function normalizeDatabaseUrl(string $url): string
{
    if (str_contains($url, '@db:') || str_contains($url, '@db/')) {
        fwrite(STDERR, "ERROR: DATABASE_URL uses host \"db\" (docker-compose only).\n");
        fwrite(STDERR, "On Railway, set DATABASE_URL = \${{MySQL.MYSQL_URL}} on your app service.\n");
        exit(1);
    }

    if (!str_contains($url, 'serverVersion=')) {
        $url .= str_contains($url, '?') ? '&' : '?';
        $url .= 'serverVersion=8.0&charset=utf8mb4';
    }

    return $url;
}

function maskDatabaseUrl(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false) {
        return '(invalid url)';
    }

    $user = $parts['user'] ?? 'unknown';
    $host = $parts['host'] ?? 'unknown';
    $port = $parts['port'] ?? 3306;
    $db = ltrim($parts['path'] ?? '', '/');

    return sprintf('mysql://%s@%s:%s/%s', $user, $host, $port, $db);
}

function exportToRuntime(string $name, string $value): void
{
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$databaseUrl = buildDatabaseUrl();
if ($databaseUrl === null) {
    fwrite(STDERR, "ERROR: No database configuration found.\n");
    fwrite(STDERR, "Set DATABASE_URL or link Railway MySQL (MYSQL_URL / MYSQLHOST variables).\n");
    exit(1);
}

exportToRuntime('DATABASE_URL', $databaseUrl);

$appEnv = env('APP_ENV', 'prod') ?? 'prod';
$appSecret = env('APP_SECRET');

if (!$appSecret || $appSecret === 'change_me_in_production') {
    fwrite(STDERR, "ERROR: Set APP_SECRET in Railway or docker-compose (random string, 32+ chars).\n");
    exit(1);
}

exportToRuntime('APP_ENV', $appEnv);
exportToRuntime('APP_SECRET', $appSecret);

if (env('APP_DEBUG') === null) {
    exportToRuntime('APP_DEBUG', '0');
}

writeEnvFile($envPath, [
    'APP_ENV' => $appEnv,
    'APP_SECRET' => $appSecret,
    'APP_SHARE_DIR' => env('APP_SHARE_DIR', 'var/share') ?? 'var/share',
    'DEFAULT_URI' => env('DEFAULT_URI', 'http://localhost') ?? 'http://localhost',
    'DATABASE_URL' => $databaseUrl,
    'MESSENGER_TRANSPORT_DSN' => env('MESSENGER_TRANSPORT_DSN', 'doctrine://default?auto_setup=0') ?? 'doctrine://default?auto_setup=0',
    'MAILER_DSN' => env('MAILER_DSN', 'null://null') ?? 'null://null',
]);

echo 'Database target: '.maskDatabaseUrl($databaseUrl)."\n";

if (($argc > 1 && $argv[1] === '--check') || (getenv('BOOTSTRAP_CHECK_DB') ?: '') === '1') {
    $params = parse_url($databaseUrl);
    if ($params === false) {
        fwrite(STDERR, "ERROR: Invalid DATABASE_URL.\n");
        exit(1);
    }

    $host = $params['host'] ?? '';
    $port = $params['port'] ?? 3306;
    $user = $params['user'] ?? '';
    $pass = $params['pass'] ?? '';
    $db = ltrim($params['path'] ?? '', '/');

    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);
        new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        echo "Database connection: OK\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'Database connection failed: '.$e->getMessage()."\n");
        exit(1);
    }
}

exit(0);
