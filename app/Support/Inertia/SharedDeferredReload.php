<?php

namespace App\Support\Inertia;

use Illuminate\Http\Request;
use Inertia\DeferProp;
use Inertia\Inertia;
use Inertia\Support\Header;

/**
 * Spots the follow-up request a page makes for shared deferred props (the header inbox).
 *
 * After a page loads, Inertia asks the same URL again for just the deferred shared props. Inertia then
 * drops every page prop the request did not ask for (except Inertia::always ones), so a controller that
 * builds its whole page first does all that work for nothing. A controller can check this and build only
 * its Inertia::always props instead; what the browser receives stays exactly the same.
 *
 * Only for pages that do not define a prop with the same name as a shared deferred prop.
 */
final class SharedDeferredReload
{
    public static function only(Request $request, string $component): bool
    {
        if (! $request->header(Header::INERTIA)
            || $request->header(Header::PARTIAL_COMPONENT) !== $component
            || $request->header(Header::PARTIAL_EXCEPT) !== null) {
            return false;
        }

        $keys = array_filter(explode(',', (string) $request->header(Header::PARTIAL_ONLY, '')));
        if ($keys === []) {
            return false;
        }

        $shared = Inertia::getShared();
        foreach ($keys as $key) {
            if (! (($shared[$key] ?? null) instanceof DeferProp)) {
                return false;
            }
        }

        return true;
    }
}
