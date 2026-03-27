<?php
declare(strict_types=1);

namespace App\Tests\Persistence;

use App\Persistence\AvitoErrorAdsRepository;
use Doctrine\DBAL\Connection;
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
        $this->conn->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn([]);

        $this->conn->expects($this->once())->method('executeStatement');

        $items = [
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED',     'rx_good_items' => 100],
            ['ad_external_id' => '200-def', 'error_type' => 'WRONG_PRICE', 'rx_good_items' => 200],
        ];

        $this->assertSame(2, $this->repo->saveMany(self::REPORT_ID, $items));
    }

    public function testSkipsExistingItems(): void
    {
        $this->conn->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn(['100-abc']);

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
        $this->conn->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn(['100-abc', '200-def']);

        $this->conn->expects($this->never())->method('executeStatement');

        $items = [
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED',     'rx_good_items' => 100],
            ['ad_external_id' => '200-def', 'error_type' => 'WRONG_PRICE', 'rx_good_items' => 200],
        ];

        $this->assertSame(0, $this->repo->saveMany(self::REPORT_ID, $items));
    }

    public function testSkipsDuplicatesWithinItems(): void
    {
        $this->conn->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn([]);

        $this->conn->expects($this->once())->method('executeStatement');

        $items = [
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED', 'rx_good_items' => 100],
            ['ad_external_id' => '100-abc', 'error_type' => 'BLOCKED', 'rx_good_items' => 100],
        ];

        $this->assertSame(1, $this->repo->saveMany(self::REPORT_ID, $items));
    }

    public function testPassesRxGoodItemsFromItem(): void
    {
        $this->conn->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn([]);

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
}
