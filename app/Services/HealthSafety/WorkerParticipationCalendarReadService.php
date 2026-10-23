<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use App\Services\Sites\Calendar\CalendarSources;
use App\Services\Sites\Calendar\IcsFeedBuilder;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Participation's original read remains active through native payload construction, not wire delivery. */
final class WorkerParticipationCalendarReadService
{
    public function __construct(
        private readonly WorkerParticipationAccessService $access,
        private readonly SiteCalendarAggregator $calendar,
        private readonly IcsFeedBuilder $ics,
    ) {}

    public function json(?User $viewer, array $siteIds, Carbon $start, Carbon $end, array $filters = [], ?HealthSafetyReadContext $context = null): array
    {
        return $this->consume($viewer, $siteIds, $filters, $context, function (array $effective, array $sourceAccess, ?int $viewerId) use ($siteIds, $start, $end): array {
            $events = $effective['sources'] === [] ? [] : $this->calendar->arrayForRange($siteIds, $start, $end, $effective);
            $this->assertParticipationRead($sourceAccess, $siteIds);
            $payload = ['events' => $events, 'participation_source_access' => $sourceAccess,
                'participation_viewer_id' => $viewerId, 'availability' => ['participation' => $sourceAccess['status']]];
            // Match response()->json's native encoding and defaults inside the original callback.
            $body = (new JsonResponse($payload))->getContent();
            if (! is_string($body)) { throw new LogicException('Calendar JSON did not produce a detached body.'); }
            return ['body' => $body, 'content_type' => 'application/json', 'participation_source_access' => $sourceAccess, 'participation_viewer_id' => $viewerId];
        });
    }

    public function ics(User $viewer, array $siteIds, Carbon $start, Carbon $end, array $filters = [], string $calendarName = 'Oblivion Findings — Site Calendar', ?HealthSafetyReadContext $context = null): array
    {
        return $this->consume($viewer, $siteIds, $filters, $context, function (array $effective, array $sourceAccess, ?int $viewerId) use ($siteIds, $start, $end, $calendarName): array {
            $items = $effective['sources'] === [] ? [] : $this->calendar->itemsForRange($siteIds, $start, $end, $effective);
            $this->assertParticipationRead($sourceAccess, $siteIds);
            // Retain the existing per-user subscribe rule: never echo pulled busy blocks.
            $items = array_values(array_filter($items, fn ($item): bool => $item->source !== 'external'));
            return ['body' => $this->ics->build($items, $calendarName), 'content_type' => 'text/calendar; charset=utf-8',
                'participation_source_access' => $sourceAccess, 'participation_viewer_id' => $viewerId];
        });
    }

    private function consume(?User $viewer, array $siteIds, array $filters, ?HealthSafetyReadContext $context, callable $encode): array
    {
        if ($context !== null) {
            $context->assertActive();
            if ($viewer === null) { throw new LogicException('A supplied participation calendar context requires its viewer.'); }
            $context->assertViewer($viewer);
        }
        // Request input cannot provide the server's original source frame or prepared actor.
        unset($filters['participation_access'], $filters['health_safety_context'], $filters['viewer']);
        $requested = $filters['sources'] ?? null;
        $participationRequested = $requested === null || $requested === [] || in_array('participation', $requested, true);
        if (! $participationRequested) {
            return $encode([...$filters, 'viewer' => $viewer], ['status' => 'not_requested', 'reason' => null], null);
        }
        if ($context !== null) { $context->consumeSourceViewer(WorkerParticipationAccessService::PERMISSIONS, static fn () => null); }
        if ($viewer === null) { return $this->initiallyDenied($filters, $viewer, $encode, 403); }
        $entered = false;
        try {
            return $this->access->materializeSubset($viewer, $siteIds, function ($current, $reads, $active) use ($filters, $encode, &$entered): array {
                $entered = true;
                $sources = $filters['sources'] ?? null;
                return $encode([...$filters, 'sources' => $sources === [] ? null : $sources, 'viewer' => $current,
                    'health_safety_context' => $active, 'participation_access' => $this->access],
                    ['status' => 'available', 'reason' => null], (int) $current->id);
            }, $context);
        } catch (HttpExceptionInterface $error) {
            if ($entered || ! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
            if ($context !== null) { $context->assertActive(); $context->assertViewer($viewer); }
            // Admission failed before ANY outer provider or serializer was invoked.
            return $this->initiallyDenied($filters, $viewer, $encode, $error->getStatusCode());
        }
    }

    private function initiallyDenied(array $filters, ?User $viewer, callable $encode, int $status): array
    {
        $registered = array_values(array_diff(CalendarSources::keys(), ['participation']));
        $requested = $filters['sources'] ?? null;
        $sources = $requested === null || $requested === [] ? $registered : array_values(array_intersect($registered, $requested));
        // Aggregator's [] means ALL sources. encode explicitly bypasses it for this empty selection.
        return $encode([...$filters, 'sources' => $sources, 'viewer' => $viewer],
            ['status' => $status === 403 ? 'restricted' : 'unavailable', 'reason' => $status === 403
                ? 'Participation is restricted by source access.' : 'Participation source evidence is unavailable.'], null);
    }

    private function assertParticipationRead(array $expected, array $siteIds): void
    {
        if ($expected['status'] !== 'available') { return; }
        $actual = $this->calendar->participationSourceAccess()['status'];
        if ($siteIds === [] && $actual === 'not_requested') { return; }
        abort_unless($actual === 'available', $actual === 'restricted' ? 403 : 404, 'Participation calendar source did not remain admitted.');
    }
}
