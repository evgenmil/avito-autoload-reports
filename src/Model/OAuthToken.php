<?php
declare(strict_types=1);

namespace App\Model;

use DateTimeImmutable;

final class OAuthToken
{
    public function __construct(
        public readonly string $accessToken,
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
