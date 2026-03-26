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
        $saved = 0;

        foreach ($items as $item) {
            $rxGoodItems = (int) explode('-', $item['ad_external_id'])[0];

            $this->conn->executeStatement(
                'INSERT INTO avito_error_ads
                   (report_id, ad_external_id, error_type, fetched_at, status, rx_good_items)
                 VALUES (?, ?, ?, NOW(), \'NEW\', ?)
                 ON DUPLICATE KEY UPDATE
                   report_id     = VALUES(report_id),
                   error_type    = VALUES(error_type),
                   fetched_at    = VALUES(fetched_at),
                   rx_good_items = VALUES(rx_good_items)',
                [$reportId, $item['ad_external_id'], $item['error_type'], $rxGoodItems]
            );
            $saved++;
        }

        return $saved;
    }
}
