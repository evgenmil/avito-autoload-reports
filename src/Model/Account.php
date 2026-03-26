<?php
declare(strict_types=1);

namespace App\Model;

use DateTimeImmutable;

final class Account
{
    public function __construct(
        public readonly int $id,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly ?string $accessToken,
        public readonly ?DateTimeImmutable $tokenExpiresAt,
    ) {}
}
