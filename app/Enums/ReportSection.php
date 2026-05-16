<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportSection: string
{
    case Strategic = 'strategic';
    case Technical = 'technical';
    case Tooling   = 'tooling';
}
