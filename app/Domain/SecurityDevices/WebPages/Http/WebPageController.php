<?php

namespace App\Domain\SecurityDevices\WebPages\Http;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\WebPages\WebPages;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * S&D Phase G (pop-up 61): open a device's web page, serve its pages to the sandboxed frame, and the
 * hub's poll. All checks are in WebPages.
 */
final class WebPageController extends Controller
{
    public function __construct(private readonly WebPages $pages) {}

    public function open(Request $request, Device $device): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json($this->pages->open($user, $device), 201)->header('Cache-Control', 'no-store');
    }

    /** The frame's own requests. The token in the link is the key: the frame can't carry Oblivion's sign-in. */
    public function serve(Request $request, string $token, ?string $path = null): Response
    {
        $query = $request->getQueryString();
        $headers = [];
        foreach (WebPages::PASS_REQUEST as $name) {
            $value = $request->headers->get($name);
            if (is_string($value)) {
                $headers[$name] = $value;
            }
        }
        $answer = $this->pages->serve(
            $token,
            strtoupper($request->getMethod()),
            '/'.ltrim((string) $path, '/').($query !== null && $query !== '' ? '?'.$query : ''),
            $headers,
            (string) $request->getContent(),
        );

        return response($answer['body'], $answer['status'], $answer['headers']);
    }

    public function hubWork(Request $request): JsonResponse
    {
        $collector = $request->attributes->get('monitoring_collector');
        abort_unless($collector instanceof MonitoringCollector, 401, 'unavailable');
        if (strlen((string) $request->getContent()) > 6_291_456) {
            return response()->json(['message' => 'too_large'], 413)->header('Cache-Control', 'no-store');
        }
        $report = $request->json('report');
        try {
            $work = $this->pages->hubWork($collector, is_array($report) ? $report : []);
        } catch (Throwable) {
            return response()->json(['message' => 'unavailable'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json([...$work, 'server_now' => now()->utc()->format(DATE_ATOM)])->header('Cache-Control', 'no-store');
    }
}
