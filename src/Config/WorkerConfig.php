<?php
declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class WorkerConfig
{
    /**
     * @param list<int> $errorCodes
     */
    public function __construct(
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public string $logLevel,
        public string $logFile,
        public string $oauthTokenUrl,
        public string $avitoBaseUrl,
        public string $avitoLastReportPath,
        public string $avitoErrorAdsPath,
        public array $errorCodes,
    ) {}

    public static function fromEnvironment(): self
    {
        $projectRoot = dirname(__DIR__, 2);

        $required = [
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'LOG_LEVEL',
            'OAUTH_TOKEN_URL',
            'AVITO_BASE_URL',
            'AVITO_LAST_REPORT_PATH',
            'AVITO_ERROR_ADS_PATH',
        ];

        $missing = [];
        foreach ($required as $key) {
            $val = getenv($key);
            if ($val === false || trim((string)$val) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('Missing required env vars: ' . implode(', ', $missing) . ". See `.env.example`.");
        }

        $logLevel = strtoupper((string)getenv('LOG_LEVEL'));
        $allowed = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];
        if (!in_array($logLevel, $allowed, true)) {
            throw new RuntimeException('Invalid LOG_LEVEL. Allowed: debug|info|warning|error');
        }

        $logFile = getenv('LOG_FILE');
        if ($logFile === false || trim((string)$logFile) === '') {
            $logFile = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'worker.log';
        } else {
            $logFile = (string)$logFile;
            $isAbsWindows = preg_match('/^[A-Za-z]:[\\\\\\/]/', $logFile) === 1;
            $isAbsUnix = str_starts_with($logFile, '/');
            if (!$isAbsWindows && !$isAbsUnix) {
                $logFile = $projectRoot . DIRECTORY_SEPARATOR . ltrim($logFile, '/\\');
            }
        }

        $dbPortRaw = getenv('DB_PORT');
        if ($dbPortRaw === false) {
            throw new RuntimeException('DB_PORT is missing');
        }
        $dbPort = (int)$dbPortRaw;
        if ($dbPort <= 0) {
            throw new RuntimeException('DB_PORT must be a positive integer');
        }

        $oauthTokenUrl = (string) getenv('OAUTH_TOKEN_URL');
        $avitoBaseUrl = (string) getenv('AVITO_BASE_URL');
        $avitoLastReportPath = (string) getenv('AVITO_LAST_REPORT_PATH');
        $avitoErrorAdsPath = (string) getenv('AVITO_ERROR_ADS_PATH');

        $rawCodes = trim((string)(getenv('AVITO_ERROR_CODES') ?: ''));
        $errorCodes = [];
        if ($rawCodes !== '') {
            foreach (explode(',', $rawCodes) as $part) {
                $code = (int) trim($part);
                if ($code > 0) {
                    $errorCodes[] = $code;
                }
            }
        }

        return new self(
            (string)getenv('DB_HOST'),
            $dbPort,
            (string)getenv('DB_NAME'),
            (string)getenv('DB_USER'),
            (string)getenv('DB_PASSWORD'),
            $logLevel,
            $logFile,
            $oauthTokenUrl,
            $avitoBaseUrl,
            $avitoLastReportPath,
            $avitoErrorAdsPath,
            $errorCodes,
        );
    }
}

