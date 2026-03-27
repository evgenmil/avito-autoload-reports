<?php
declare(strict_types=1);

namespace App\Tests\Model;

use App\Model\ErrorAd;
use PHPUnit\Framework\TestCase;

final class ErrorAdTest extends TestCase
{
    public function testParsesRxGoodItemsFromAdExternalId(): void
    {
        $ad = new ErrorAd('456-some-data', 'BLOCKED');

        $this->assertSame(456, $ad->rxGoodItems);
    }

    public function testRxGoodItemsIsZeroWhenNoPrefix(): void
    {
        $ad = new ErrorAd('no-number-here', null);

        $this->assertSame(0, $ad->rxGoodItems);
    }

    public function testStoresAdExternalIdAndErrorType(): void
    {
        $ad = new ErrorAd('123-test', 'WRONG_PRICE');

        $this->assertSame('123-test', $ad->adExternalId);
        $this->assertSame('WRONG_PRICE', $ad->errorType);
    }
}
