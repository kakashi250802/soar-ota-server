<?php

namespace App\Ota;

use RuntimeException;

/** A manifest that is malformed, out of range or not signed by the expected key. */
class InvalidManifest extends RuntimeException
{
}
