<?php

namespace App\Support;

use Illuminate\Http\Request;

/** A one-response acknowledgement of a completed local UniFi write, not a durable action receipt. */
final class UnifiActionOutcome
{
    /** The request changed the saved record. */
    public const SAVED = 'saved';

    /** The request was accepted, but the saved record already matched it. */
    public const UNCHANGED = 'unchanged';

    public static function flashSaved(Request $request): void
    {
        self::flash($request, self::SAVED);
    }

    public static function flashUnchanged(Request $request): void
    {
        self::flash($request, self::UNCHANGED);
    }

    public static function forActor(Request $request): ?array
    {
        $value = $request->session()->get('unifi_action_outcome');
        if (! is_array($value) || ($value['actor_id'] ?? null) !== $request->user()?->id
            || ! in_array($value['outcome'] ?? null, [self::SAVED, self::UNCHANGED], true)
            || ! is_string($value['request_id'] ?? null) || ! preg_match('/\A[a-f0-9]{32}\z/', $value['request_id'])
            || ! is_string($value['method'] ?? null) || ! is_string($value['path'] ?? null) || ! self::supported($value['method'], $value['path'])) {
            return null;
        }

        return array_intersect_key($value, array_flip(['actor_id', 'request_id', 'method', 'path', 'outcome']));
    }

    private static function flash(Request $request, string $outcome): void
    {
        $marker = $request->header('X-Obli-UniFi-Action');
        $actor = $request->user()?->id;
        $method = strtolower($request->method());
        $path = $request->getPathInfo();
        if (! is_int($actor) || ! is_string($marker) || ! preg_match('/\A[a-f0-9]{32}\z/', $marker) || ! self::supported($method, $path)) {
            return;
        }
        $request->session()->flash('unifi_action_outcome', [
            'actor_id' => $actor,
            'request_id' => $marker,
            'method' => $method,
            'path' => $path,
            'outcome' => $outcome,
        ]);
    }

    private static function supported(string $method, string $path): bool
    {
        $prefix = '/security-devices/integrations/unifi/';

        return match ($method) {
            'post' => in_array($path, array_map(fn ($name) => $prefix.$name, ['key', 'rotate', 'map-site', 'disable']), true),
            'put' => $path === $prefix.'defaults' || preg_match('#\A'.preg_quote($prefix, '#').'hardware/[1-9][0-9]*/room\z#', $path) === 1,
            'delete' => preg_match('#\A'.preg_quote($prefix, '#').'map-site/[1-9][0-9]*\z#', $path) === 1,
            default => false,
        };
    }
}
