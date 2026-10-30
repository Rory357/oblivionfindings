<?php

namespace App\Support\Inertia;

use Illuminate\Http\Request;
use Inertia\Support\Header;

/**
 * Spots the follow-up request Inertia sends to fill background-loaded
 * ("deferred") props, so a page can build only what that request asked for.
 *
 * The first response leaves deferred props out; the browser then asks for
 * each group with a partial reload of the same page. That reload runs the
 * whole controller again, so without this check every section the page
 * builds up front is computed a second time (and once more per group).
 *
 * Nothing is skipped for access: the route middleware and the controller
 * still run, and every deferred prop does its own reads with the same checks
 * as before. No permission or row list is carried between requests.
 */
final class DeferredLoad
{
    /** Shared props every page loads in the background (see HandleInertiaRequests). */
    public const SHARED_KEYS = ['inbox', 'taskNav'];

    /**
     * The page's deferred keys this request asks for, when it asks for nothing
     * else from the page. Null for a first load or any other partial reload,
     * which keep building the page as they always did.
     *
     * @param  list<string>  $deferredKeys
     * @return list<string>|null
     */
    public static function only(Request $request, string $component, array $deferredKeys): ?array
    {
        if (! self::isPartialReload($request, $component) || $request->hasHeader(Header::PARTIAL_EXCEPT)) {
            return null;
        }

        $only = array_values(array_filter(array_map('trim', explode(',', (string) $request->header(Header::PARTIAL_ONLY, '')))));
        if ($only === []) {
            return null;
        }
        foreach ($only as $key) {
            if (! in_array($key, $deferredKeys, true) && ! in_array($key, self::SHARED_KEYS, true)) {
                return null;
            }
        }

        return array_values(array_intersect($deferredKeys, $only));
    }

    /**
     * True for any partial reload of this page (the browser already shows it).
     * The same rule Inertia uses (Response::isPartial()), so the two never disagree.
     */
    public static function isPartialReload(Request $request, string $component): bool
    {
        return $request->header(Header::PARTIAL_COMPONENT) === $component;
    }
}
