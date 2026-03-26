<?php
declare(strict_types=1);

namespace App\Persistence;

use Doctrine\DBAL\Connection;

final class AvitoReportsRepository
{
    public function __construct(private Connection $conn) {}

    public function findReportId(int $accountId, string $reportExternalId): ?int
    {
        $v = $this->conn->fetchOne(
            'SELECT id FROM avito_reports WHERE account_id = ? AND report_external_id = ? LIMIT 1',
            [$accountId, $reportExternalId]
        );

        if ($v === false || $v === null) {
            return null;
        }

        return (int) $v;
    }

    public function reportExists(int $accountId, string $reportExternalId): bool
    {
        return $this->findReportId($accountId, $reportExternalId) !== null;
    }

    public function saveReport(int $accountId, string $reportExternalId): void
    {
        $this->conn->executeStatement(
            'INSERT INTO avito_reports (account_id, report_external_id, fetched_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at)',
            [$accountId, $reportExternalId]
        );
    }
}

