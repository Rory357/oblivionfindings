<?php

namespace Oblivion\Collector\Access;

use RuntimeException;

/** The console gave no answer (or refused the trusted key) — the hub then checks its certificate. */
final class ConsoleUnreachable extends RuntimeException {}
