<?php
declare(strict_types=1);

namespace App\Persistence;

use App\Config\WorkerConfig;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;

final class Db
{
    private ?Connection $connection = null;

    public function __construct(private WorkerConfig $config) {}

    public function connect(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $this->connection = DriverManager::getConnection([
            // Doctrine DBAL v3 requires an explicit driver if no PDO instance is provided.
            'driver' => 'pdo_mysql',
            'host' => $this->config->dbHost,
            'port' => $this->config->dbPort,
            'dbname' => $this->config->dbName,
            'user' => $this->config->dbUser,
            'password' => $this->config->dbPassword,
        ]);

        return $this->connection;
    }
}

