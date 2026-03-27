<?php
declare(strict_types=1);

namespace App\Persistence;

use Doctrine\DBAL\Connection;

final class AvitoErrorAdsRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @param list<array{ad_external_id:string, error_type:?string}> $items
     */
    public function saveMany(int $reportId, array $items): int
    {
        $existing = $this->conn->fetchFirstColumn(
            'SELECT ad_external_id FROM avito_error_ads WHERE report_id = ?',
            [$reportId]
        );
        $existingSet = array_flip($existing);

        $toInsert = [];
        foreach ($items as $item) {
            if (isset($existingSet[$item['ad_external_id']])) {
                continue;
            }
            $toInsert[] = [
                'report_id'      => $reportId,
                'ad_external_id' => $item['ad_external_id'],
                'error_type'     => $item['error_type'],
                'rx_good_items'  => (int) explode('-', $item['ad_external_id'])[0],
            ];
        }

        if ($toInsert === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($toInsert), '(?, ?, ?, NOW(), \'NEW\', ?)'));
        $params = [];
        foreach ($toInsert as $row) {
            $params[] = $row['report_id'];
            $params[] = $row['ad_external_id'];
            $params[] = $row['error_type'];
            $params[] = $row['rx_good_items'];
        }

        $this->conn->executeStatement(
            "INSERT INTO avito_error_ads
               (report_id, ad_external_id, error_type, fetched_at, status, rx_good_items)
             VALUES {$placeholders}",
            $params
        );

        return count($toInsert);
    }
}
