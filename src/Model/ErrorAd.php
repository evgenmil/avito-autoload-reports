<?php
declare(strict_types=1);

namespace App\Model;

final class ErrorAd
{
    public readonly int $rxGoodItems;

    public function __construct(
        public readonly string $adExternalId,
        public readonly ?string $errorType,
    ) {
        $this->rxGoodItems = (int) explode('-', $adExternalId)[0];
    }
}
