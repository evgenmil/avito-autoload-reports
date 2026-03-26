<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
$autoload = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "Composer dependencies are missing. Run `make install` first.\n");
    exit(1);
}

require $autoload;

use App\Avito\AvitoApiClient;
use App\Avito\OAuthClient;
use App\Config\EnvLoader;
use App\Config\WorkerConfig;
use App\Logging\FileLogger;
use App\Persistence\Db;
use App\Worker\Worker;
use GuzzleHttp\Client;

$envFile = $rootDir . DIRECTORY_SEPARATOR . '.env';
if (getenv('ENV_FILE') !== false && trim((string)getenv('ENV_FILE')) !== '') {
    $envFile = (string)getenv('ENV_FILE');
    // If ENV_FILE is relative, treat it as path relative to project root.
    $isAbsWindows = preg_match('/^[A-Za-z]:[\\\\\\/]/', $envFile) === 1;
    $isAbsUnix = str_starts_with($envFile, '/');
    if (!$isAbsWindows && !$isAbsUnix) {
        $envFile = $rootDir . DIRECTORY_SEPARATOR . $envFile;
    }
}

try {
    (new EnvLoader($envFile))->load();
    $config = WorkerConfig::fromEnvironment();

    $logger = new FileLogger($config->logFile, $config->logLevel);
    $db = new Db($config);
    $http        = new Client();
    $oauthClient = new OAuthClient($http, $config->oauthTokenUrl);
    $apiClient   = new AvitoApiClient($http, $config->avitoBaseUrl);

    $worker = new Worker($config, $logger, $db, $oauthClient, $apiClient);
    $exitCode = $worker->run();
    exit($exitCode);
} catch (Throwable $e) {
    fwrite(STDERR, "Fatal error: " . $e->getMessage() . "\n");
    exit(1);
}

