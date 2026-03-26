<?php
declare(strict_types=1);

namespace App\Logging;

final class FileLogger
{
    private const LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
    ];

    private int $minLevelValue;

    public function __construct(
        private string $logFile,
        string $minLevel
    ) {
        $minLevel = strtoupper($minLevel);
        $this->minLevelValue = self::LEVELS[$minLevel] ?? self::LEVELS['INFO'];

        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $levelValue = self::LEVELS[$level] ?? self::LEVELS['INFO'];
        if ($levelValue < $this->minLevelValue) {
            return;
        }

        // Extra guard: don't leak known secret fields.
        foreach (['client_secret', 'refresh_token', 'access_token'] as $secretKey) {
            unset($context[$secretKey]);
        }

        $timestamp = date('Y-m-d H:i:s');
        $ctx = $context !== [] ? (' context=' . json_encode($context, JSON_UNESCAPED_SLASHES)) : '';
        $line = sprintf("[%s] %s %s%s\n", $timestamp, $level, $message, $ctx);
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

