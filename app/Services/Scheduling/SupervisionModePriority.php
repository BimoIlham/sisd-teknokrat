<?php

namespace App\Services\Scheduling;

class SupervisionModePriority
{
    public static function rank(?string $deliveryMode): int
    {
        return $deliveryMode === 'OFFLINE' ? 0 : 1;
    }
}
