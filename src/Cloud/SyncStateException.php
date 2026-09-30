<?php

declare(strict_types=1);

namespace Mooeen\MonitorLaravel\Cloud;

use RuntimeException;

/** @internal Expected persistence failures; code errors must remain visible to the caller. */
class SyncStateException extends RuntimeException {}
