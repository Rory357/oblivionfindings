<?php

namespace App\Services\Tracking;

use RuntimeException;

/** Must escape the guarded outer transaction; never means consent was revoked. */
final class ClientLocationEvidenceBusy extends RuntimeException {}
