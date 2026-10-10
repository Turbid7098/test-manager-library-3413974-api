<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$application = require dirname(__DIR__, 3).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$username = strtolower(trim(getenv('ADMIN_USERNAME') ?: ''));
$password = getenv('ADMIN_PASSWORD') ?: '';
$database = getenv('DB_DATABASE') ?: '';

if ($username === '' || $password === '' || $database === '') {
    throw new RuntimeException('Set DB_DATABASE, ADMIN_USERNAME, and ADMIN_PASSWORD for the isolated bootstrap check.');
}
if (DB::connection()->getDriverName() !== 'pgsql') {
    throw new RuntimeException('The empty-database bootstrap check requires PostgreSQL.');
}
$existingSchema = DB::selectOne(
    "SELECT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'library'
    ) AS has_library_tables"
);
$migrationTable = DB::selectOne("SELECT to_regclass('public.migrations') AS migration_table");
if (
    filter_var($existingSchema->has_library_tables, FILTER_VALIDATE_BOOLEAN)
    || $migrationTable->migration_table !== null
) {
    throw new RuntimeException('The bootstrap check requires a pristine database with no application tables or migration history.');
}

Artisan::call('migrate', ['--force' => true]);
DB::purge();

if ((int) DB::table('library.category')->count() !== 5) {
    throw new RuntimeException('The initial migration did not create all five categories.');
}
if (DB::table('library.user')->where('username', $username)->exists()) {
    throw new RuntimeException('The configured administrator unexpectedly existed before provisioning.');
}

Artisan::call('app:create-admin');
Artisan::call('app:create-admin');
DB::purge();
$admin = DB::table('library.user')->where('username', $username)->first();

if ($admin === null || $admin->role !== 'admin' || ! Hash::check($password, $admin->password_hash)) {
    throw new RuntimeException('The administrator command did not provision the configured admin safely.');
}
if (DB::table('library.user')->where('username', $username)->count() !== 1) {
    throw new RuntimeException('Repeated administrator provisioning created duplicate users.');
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($socket === false) {
    throw new RuntimeException('Could not reserve a local port for the isolated HTTP startup check: '.$errorMessage);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
if ($address === false) {
    throw new RuntimeException('Could not determine a local port for the isolated HTTP startup check.');
}
$port = (int) substr(strrchr($address, ':'), 1);
$environment = [];
foreach ([
    'APP_ENV',
    'APP_KEY',
    'APP_DEBUG',
    'JWT_SECRET',
    'DB_CONNECTION',
    'DB_HOST',
    'DB_PORT',
    'DB_USERNAME',
    'DB_PASSWORD',
    'FRONTEND_ORIGIN',
] as $key) {
    $value = getenv($key);
    if ($value !== false) {
        $environment[$key] = $value;
    }
}
$environment['DB_DATABASE'] = $database;
$server = new Process(
    [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$port, '--no-reload'],
    dirname(__DIR__, 3),
    $environment
);
$server->setTimeout(30);
$server->start();

try {
    $health = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (! $server->isRunning()) {
            throw new RuntimeException('The isolated Laravel HTTP server exited during startup: '.$server->getErrorOutput());
        }
        $health = httpRequest('GET', 'http://127.0.0.1:'.$port.'/api/v1/health');
        if ($health !== null) {
            break;
        }
        usleep(100_000);
    }
    if ($health === null || $health['status'] !== 200 || $health['body'] !== '{"status":"ok"}') {
        throw new RuntimeException('The isolated Laravel HTTP health endpoint did not start successfully.');
    }

    $login = httpRequest(
        'POST',
        'http://127.0.0.1:'.$port.'/api/v1/auth/login',
        ['username' => $username, 'password' => $password]
    );
    if ($login === null || $login['status'] !== 200) {
        throw new RuntimeException('The configured administrator could not authenticate through the HTTP endpoint.');
    }
    $token = json_decode($login['body'], true, flags: JSON_THROW_ON_ERROR);
    if (
        ($token['token_type'] ?? null) !== 'Bearer'
        || ($token['expires_in'] ?? null) !== 28800
        || ($token['role'] ?? null) !== 'admin'
        || ! is_string($token['access_token'] ?? null)
    ) {
        throw new RuntimeException('The administrator login response did not match the JWT contract.');
    }

    $categories = httpRequest(
        'GET',
        'http://127.0.0.1:'.$port.'/api/v1/categories',
        null,
        $token['access_token']
    );
    if ($categories === null || $categories['status'] !== 200) {
        throw new RuntimeException('The freshly provisioned administrator JWT was rejected by a protected route.');
    }
    $categoryPayload = json_decode($categories['body'], true, flags: JSON_THROW_ON_ERROR);
    if (count($categoryPayload['items'] ?? []) !== 5) {
        throw new RuntimeException('The protected categories endpoint did not return the initial five categories.');
    }

    echo 'PASS: empty PostgreSQL database migrated; five categories seeded; admin provisioned idempotently; live HTTP login, JWT, and protected route verified.'.PHP_EOL;
} finally {
    $server->stop(1);
}

/**
 * @return array{status: int, body: string}|null
 */
function httpRequest(string $method, string $url, ?array $payload = null, ?string $token = null): ?array
{
    $headers = ['Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 2,
        ],
    ]);
    $stream = @fopen($url, 'rb', false, $context);
    if ($stream === false) {
        return null;
    }

    $metadata = stream_get_meta_data($stream);
    $body = stream_get_contents($stream);
    fclose($stream);
    $statusLine = $metadata['wrapper_data'][0] ?? '';
    if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) !== 1 || $body === false) {
        throw new RuntimeException('The isolated HTTP server returned an invalid response.');
    }

    return ['status' => (int) $matches[1], 'body' => $body];
}
