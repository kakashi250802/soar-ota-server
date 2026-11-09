<?php

namespace App\Ota;

use App\Models\Update;

/** The update a device should get and the rollout percentage the manifest will carry. */
final class Selection
{
    public function __construct(public readonly Update $update, public readonly int $rolloutPercent)
    {
    }
}
