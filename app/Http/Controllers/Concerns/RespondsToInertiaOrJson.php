<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets one controller action serve two callers correctly:
 *
 *  - Inertia visits (router.post/put/delete, useForm) expect a redirect so
 *    Inertia can resolve the next page. Inertia's middleware rewrites our 302
 *    to a 303 for PUT/PATCH/DELETE, so the follow-up is a GET.
 *  - Plain XHR / axios calls expect a normal JSON 2xx. Returning a redirect to
 *    them is a trap: the browser transparently follows the 302 to the Referer
 *    (e.g. /catering) and, per the Fetch redirect rules, PRESERVES the
 *    PUT/DELETE method — landing on a GET-only page route → 405. (POST is
 *    silently downgraded to GET, which is why only PUT/DELETE looked broken.)
 *
 * Keyed on the X-Inertia header, which only Inertia requests send.
 */
trait RespondsToInertiaOrJson
{
    /**
     * @param  array<string, mixed>  $data  Extra payload (also flashed for Inertia).
     */
    protected function inertiaOrJson(Request $request, string $message, array $data = []): RedirectResponse|JsonResponse
    {
        if ($request->header('X-Inertia')) {
            $redirect = back()->with('status', $message);

            foreach ($data as $key => $value) {
                $redirect->with($key, $value);
            }

            return $redirect;
        }

        return response()->json(array_merge(['status' => $message], $data));
    }
}
