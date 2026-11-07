<?php

namespace Oblivion\Collector\Access;

use RuntimeException;

/** The console answered but said no; the message is safe to show in Setup. */
final class ConsoleRefused extends RuntimeException {}
