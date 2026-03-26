<?php
declare(strict_types=1);

namespace App\Model;

final class Report
{
    public function __construct(
        public readonly string $reportExternalId,
    ) {}
}
