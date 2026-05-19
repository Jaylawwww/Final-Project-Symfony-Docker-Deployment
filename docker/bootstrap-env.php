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

function isRailway(): bool
{
    foreach (['RAILWAY_ENVIRONMENT', 'RAILWAY_PROJECT_ID', 'RAILWAY_SERVICE_ID', 'RAILWAY_REPLICA_ID'] as $name) {
        if (getRuntimeEnv($name) !== null) {
            return true;
        }
    }

    return false;
}

function getRuntimeEnv(string $name): ?string
{
    $sources = [
        $_ENV[$name] ?? null,
        $_SERVER[$name] ?? null,
    ];

    $fromGetenv = getenv($name);
    if ($fromGetenv !== false) {
        $sources[] = $fromGetenv;
    }

    foreach ($sources as $value) {
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return null;
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
        $value = trim($value);
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }
        $vars[$name] = $value;
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
    $runtime = getRuntimeEnv($name);
    if ($runtime !== null && $runtime !== '') {
        return $runtime;
    }

    // On Railway, only real container env vars count (not the baked .env file).
    if (isRailway()) {
        return $default;
    }

    static $fileVars = null;
    if ($fileVars === null) {
        global $envPath;
        $fileVars = readEnvFile($envPath);
    }

    $fileValue = $fileVars[$name] ?? null;
    if ($fileValue !== null && $fileValue !== '') {
        return $fileValue;
    }

    return $default;
}

/**
 * @return list<string>
 */
function listDatabaseRelatedEnvKeys(): array
{
    $keys = [];

    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (preg_match('/^(MYSQL|DATABASE|RAILWAY)/', $key)) {
            $keys[] = $key;
        }
    }

    sort($keys);

    return array_values(array_unique($keys));
}

function findUrlFromScannedEnvironment(): ?string
{
    $urlKeys = [
        'DATABASE_URL',
        'MYSQL_URL',
        'MYSQL_PUBLIC_URL',
        'MYSQL_PRIVATE_URL',
        'DATABASE_PRIVATE_URL',
    ];

    foreach ($urlKeys as $key) {
        $value = getRuntimeEnv($key);
        if ($value !== null) {
            return $value;
        }
    }

    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (!is_string($key) || !is_string($value) || $value === '') {
            continue;
        }
        if (preg_match('/(?:^|_)MYSQL_URL$/', $key) || preg_match('/^DATABASE_URL$/', $key)) {
            return $value;
        }
    }

    return null;
}

function buildDatabaseUrl(): ?string
{
    $url = findUrlFromScannedEnvironment();
    if ($url !== null) {
        return normalizeDatabaseUrl($url);
    }

    $url = env('DATABASE_URL');
    if ($url) {
        return normalizeDatabaseUrl($url);
    }

    $host = env('MYSQLHOST') ?? env('MYSQL_HOST');
    $user = env('MYSQLUSER') ?? env('MYSQL_USER');
    $password = env('MYSQLPASSWORD') ?? env('MYSQL_PASSWORD') ?? env('MYSQL_ROOT_PASSWORD');
    $database = env('MYSQLDATABASE') ?? env('MYSQL_DATABASE');
    $port = env('MYSQLPORT') ?? env('MYSQL_PORT') ?? '3306';

    if ($host && $user && $password !== null && $database) {
        return normalizeDatabaseUrl(sprintf(
            'mysql://%s:%s@%s:%s/%s',
            rawurlencode($user),
            rawurlencode((string) $password),
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
        fwrite(STDERR, "Your local .env is NOT deployed. On Railway use: DATABASE_URL = \${{MySQL.MYSQL_URL}}\n");
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

function printEnvDiagnostics(): void
{
    $keys = listDatabaseRelatedEnvKeys();

    fwrite(STDERR, 'Railway detected: '.(isRailway() ? 'yes' : 'no')."\n");
    fwrite(STDERR, 'Database-related env vars on this container: '.($keys !== [] ? implode(', ', $keys) : '(none)')."\n");

    if (isRailway() && $keys === []) {
        fwrite(STDERR, "\n");
        fwrite(STDERR, "Your APP service has no MySQL variables. The local .env file is NOT copied into Docker.\n");
        fwrite(STDERR, "Fix in Railway dashboard:\n");
        fwrite(STDERR, "  1. Open your APP service (Symfony) → Variables tab (not the MySQL service).\n");
        fwrite(STDERR, "  2. Click + New Variable → Variable Reference → pick MySQL → MYSQL_URL.\n");
        fwrite(STDERR, "  3. Name the variable: DATABASE_URL\n");
        fwrite(STDERR, "  4. Add APP_SECRET (random 32+ char string).\n");
        fwrite(STDERR, "  5. Redeploy the APP service.\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "Or: APP service → Settings → Connect → select your MySQL service.\n");
    }
}

$databaseUrl = buildDatabaseUrl();
if ($databaseUrl === null) {
    fwrite(STDERR, "ERROR: No database configuration found.\n");
    printEnvDiagnostics();
    exit(1);
}

exportToRuntime('DATABASE_URL', $databaseUrl);

$appEnv = env('APP_ENV', 'prod') ?? 'prod';
$appSecret = env('APP_SECRET');

$weakSecrets = ['change_me_in_production', 'change_me_for_local_dev_only'];
if (!$appSecret || in_array($appSecret, $weakSecrets, true) || strlen($appSecret) < 16) {
    fwrite(STDERR, "ERROR: Set APP_SECRET on the Railway APP service (random string, at least 16 chars).\n");
    printEnvDiagnostics();
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
