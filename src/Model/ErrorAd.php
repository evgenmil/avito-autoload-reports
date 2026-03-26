<?php
declare(strict_types=1);

namespace App\Model;

final class ErrorAd
{
    public function __construct(
        public readonly string $adExternalId,
        public readonly ?string $errorType,
    ) {}
}
