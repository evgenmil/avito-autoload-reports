<?php
declare(strict_types=1);

namespace App\Tests\Persistence;

use App\Persistence\AvitoErrorAdsRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

final class AvitoErrorAdsRepositoryTest extends TestCase
{
    private const REPORT_ID = 1;

    private Connection $conn;
    private AvitoErrorAdsRepository $repo;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
        $this->repo = new AvitoErrorAdsRepository($this->conn);
    }

    public function testReturnsZeroForEmptyItems(): void
    {
        $this->conn->expects($this->never())->method('fetchFirstColumn');
        $this->conn->expects($this->never())->method('executeStatement');

        $this->assertSame(0, $this->repo->saveMany(self::REPORT_ID, []));
    }

    public function testInsertsAllNewItems(): void
    {
        $this->expectFetchExistingGlobalByAdIds(['100-abc', '200-def'], []);

        $this->conn->expects($this->once())->method('executeStatement');

        $items = [
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED',     'rx_good_items' => 100],
            ['ad_external_id' => '200-def', 'error_type' => 'WRONG_PRICE', 'rx_good_items' => 200],
        ];

        $this->assertSame(2, $this->repo->saveMany(self::REPORT_ID, $items));
    }

    /**
     * Уже сохранённый ad_external_id (в т.ч. в строке с другим report_id) не должен ломать вставку остальных.
     */
    public function testSkipsAdExternalIdAlreadyStoredForAnotherReport(): void
    {
        $this->expectFetchExistingGlobalByAdIds(
            ['6018039-1774794743-196', '200-other', '300-other'],
            ['6018039-1774794743-196'],
        );

        $capturedParams = null;
        $this->conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return 2;
            });

        $items = [
            ['ad_external_id' => '6018039-1774794743-196', 'error_type' => 'BLOCKED',     'rx_good_items' => 1],
            ['ad_external_id' => '200-other',              'error_type' => 'WRONG_PRICE', 'rx_good_items' => 2],
            ['ad_external_id' => '300-other',              'error_type' => null,        'rx_good_items' => 3],
        ];

        $this->assertSame(2, $this->repo->saveMany(self::REPORT_ID, $items));
        $this->assertNotContains('6018039-1774794743-196', $capturedParams);
        $this->assertContains('200-other', $capturedParams);
        $this->assertContains('300-other', $capturedParams);
    }

    public function testSkipsExistingItems(): void
    {
        $this->expectFetchExistingGlobalByAdIds(['100-abc', '200-def'], ['100-abc']);

        $capturedParams = null;
        $this->conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return 1;
            });

        $items = [
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED',     'rx_good_items' => 100],
            ['ad_external_id' => '200-def', 'error_type' => 'WRONG_PRICE', 'rx_good_items' => 200],
        ];

        $this->assertSame(1, $this->repo->saveMany(self::REPORT_ID, $items));
        $this->assertNotContains('100-abc', $capturedParams);
        $this->assertContains('200-def', $capturedParams);
    }

    public function testReturnsZeroWhenAllExist(): void
    {
        $this->expectFetchExistingGlobalByAdIds(['100-abc', '200-def'], ['100-abc', '200-def']);

        $this->conn->expects($this->never())->method('executeStatement');

        $items = [
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED',     'rx_good_items' => 100],
            ['ad_external_id' => '200-def', 'error_type' => 'WRONG_PRICE', 'rx_good_items' => 200],
        ];

        $this->assertSame(0, $this->repo->saveMany(self::REPORT_ID, $items));
    }

    /**
     * Несколько id уже есть в таблице (любой report_id); оставшиеся уникальные вставляются одним INSERT.
     */
    public function testSkipsMultipleGloballyStoredAdsAndInsertsTheRest(): void
    {
        $this->expectFetchExistingGlobalByAdIds(
            ['dup-a', 'new-1', 'dup-b', 'new-2'],
            ['dup-a', 'dup-b'],
        );

        $capturedParams = null;
        $this->conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return 2;
            });

        $items = [
            ['ad_external_id' => 'dup-a',   'error_type' => 'BLOCKED', 'rx_good_items' => 1],
            ['ad_external_id' => 'new-1',   'error_type' => 'BLOCKED', 'rx_good_items' => 2],
            ['ad_external_id' => 'dup-b',   'error_type' => 'BLOCKED', 'rx_good_items' => 3],
            ['ad_external_id' => 'new-2',   'error_type' => 'BLOCKED', 'rx_good_items' => 4],
        ];

        $this->assertSame(2, $this->repo->saveMany(self::REPORT_ID, $items));
        $this->assertNotContains('dup-a', $capturedParams);
        $this->assertNotContains('dup-b', $capturedParams);
        $this->assertContains('new-1', $capturedParams);
        $this->assertContains('new-2', $capturedParams);
    }

    /**
     * Дубликат в батче + уже существующий в БД для другого отчёта: вставляется только один новый id.
     */
    public function testSkipsGlobalDuplicateAndInBatchDuplicate(): void
    {
        $this->expectFetchExistingGlobalByAdIds(['old-global', 'fresh'], ['old-global']);

        $capturedParams = null;
        $this->conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return 1;
            });

        $items = [
            ['ad_external_id' => 'old-global', 'error_type' => 'BLOCKED', 'rx_good_items' => 1],
            ['ad_external_id' => 'old-global', 'error_type' => 'BLOCKED', 'rx_good_items' => 2],
            ['ad_external_id' => 'fresh',      'error_type' => 'BLOCKED', 'rx_good_items' => 3],
        ];

        $this->assertSame(1, $this->repo->saveMany(self::REPORT_ID, $items));
        $this->assertNotContains('old-global', $capturedParams);
        $this->assertContains('fresh', $capturedParams);
    }

    public function testSkipsDuplicatesWithinItems(): void
    {
        $this->expectFetchExistingGlobalByAdIds(['100-abc'], []);

        $this->conn->expects($this->once())->method('executeStatement');

        $items = [
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED', 'rx_good_items' => 100],
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED', 'rx_good_items' => 100],
        ];

        $this->assertSame(1, $this->repo->saveMany(self::REPORT_ID, $items));
    }

    public function testPassesRxGoodItemsFromItem(): void
    {
        $this->expectFetchExistingGlobalByAdIds(['456-some-data'], []);

        $capturedParams = null;
        $this->conn->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return 1;
            });

        $items = [
            ['ad_external_id' => '456-some-data', 'error_type' => null, 'rx_good_items' => 456],
        ];

        $this->repo->saveMany(self::REPORT_ID, $items);

        // params order: report_id, ad_external_id, error_type, rx_good_items
        $this->assertSame(self::REPORT_ID, $capturedParams[0]);
        $this->assertSame('456-some-data', $capturedParams[1]);
        $this->assertNull($capturedParams[2]);
        $this->assertSame(456, $capturedParams[3]);
    }

    public function testThrowsOnDuplicateAdExternalId(): void
    {
        $this->expectFetchExistingGlobalByAdIds(['100-abc'], []);

        $driverException = new class ('Duplicate entry \'100-abc\' for key \'uniq_ad_external_id\'') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): ?string { return '23000'; }
        };

        $this->conn->expects($this->once())
            ->method('executeStatement')
            ->willThrowException(new UniqueConstraintViolationException($driverException, null));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repo->saveMany(self::REPORT_ID, [
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED', 'rx_good_items' => 100],
        ]);
    }

    /**
     * Контракт saveMany: один запрос «какие ad_external_id из батча уже есть в таблице» — по IN (...), не по report_id.
     *
     * @param list<string> $uniqueIdsInBatchOrder порядок первого появления в $items
     * @param list<string> $rowsReturned          подмножество $uniqueIdsInBatchOrder, как вернёт БД
     */
    private function expectFetchExistingGlobalByAdIds(array $uniqueIdsInBatchOrder, array $rowsReturned): void
    {
        $this->conn->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(
                $this->callback(static function (string $sql): bool {
                    return str_contains($sql, 'ad_external_id')
                        && preg_match('/\bIN\s*\(/i', $sql) === 1
                        && !str_contains($sql, 'report_id');
                }),
                $this->equalTo($uniqueIdsInBatchOrder),
            )
            ->willReturn($rowsReturned);
    }
}
