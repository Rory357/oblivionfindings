<?php

namespace Tests\Support;

use App\Http\Middleware\HandleInertiaRequests;

/**
 * Main's shared sidebar props (the portal's own client list, the task and meds badges) run in HandleInertiaRequests
 * on every page, before the page's own read. Tests that count what a page's H&S read loads, or that fire a change at
 * its first query, set these frames aside; everything the page's own code loads still counts.
 */
final class SharedSidebarFrames
{
    /** True while the current call comes from the shared props or badges (any middleware frame but handle()). */
    public static function active(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === HandleInertiaRequests::class && ($frame['function'] ?? null) !== 'handle') {
                return true;
            }
        }

        return false;
    }
}
