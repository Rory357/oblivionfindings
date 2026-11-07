<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use App\Domain\SecurityDevices\Screens\Alerts\AlertsPresenter;
use App\Domain\SecurityDevices\Screens\Sites\AlertWords;
use App\Domain\SecurityDevices\Screens\Sites\SiteStream;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Safety fix round (7 Nov 2026, Stephan: "Open just that alert"). Support workers and team leads
 * are pushed care and safety alerts but can't open the Alerts page. With this one key they open
 * ONLY an alert that was sent to them, at a house they work at, and say they're attending to it.
 * Nothing else: no snooze, no assign, no other alerts, no Alerts page.
 *
 * Someone who can open the Alerts page is sent there instead.
 */
final class CareAlertController extends Controller
{
    public const string KEY = 'securityDevices.careAlerts.attend';

    public function __construct(
        private readonly DeviceAlertEngine $engine,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function show(Request $request, int $alert): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if (AlertsPresenter::canOpen($user)) {
            return redirect('/security-devices/alerts?alert='.$alert);
        }
        $record = $this->allowed($user, $alert);

        return Inertia::render('security-devices/care-alert', ['alert' => $this->present($user, $record)]);
    }

    public function attend(Request $request, int $alert): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $record = $this->allowed($user, $alert);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        abort_unless($record->isActive(), 409, 'This alert is already done.');
        if ($record->acknowledged_at === null) {
            $this->engine->acknowledge($record, $user, $validated['note'] ?? null, false);
        }

        return response()->json(['message' => 'Everyone told knows you’re on it.']);
    }

    /** The alert, if this person may open it narrowly; otherwise 404 (nothing about it is given away). */
    private function allowed(User $user, int $id): DeviceAlert
    {
        abort_unless($user->canDo(self::KEY), 403);
        $record = DeviceAlert::query()->with(['device', 'site', 'acknowledgedBy'])->find($id);
        $sent = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('type', SecurityDeviceAlertNotification::class)
            ->where('data->context->alert_id', $id)
            ->exists();
        abort_unless(
            $record instanceof DeviceAlert
                && in_array($record->kind, DeviceAlertEngine::safetyCareKinds(), true)
                && $record->site_id !== null
                && in_array((int) $record->site_id, $this->access->accessibleSiteIds($user), true)
                && $sent,
            404,
        );

        return $record;
    }

    /**
     * What happens if nobody says they're attending, so the page never promises more than the engine does.
     *
     * @return array{state: 'told'|'will'|'none', minutes: int|null}
     */
    private function escalation(DeviceAlert $alert): array
    {
        if (DeviceAlertEvent::query()->where('alert_id', $alert->id)->where('kind', 'escalated')->exists()) {
            return ['state' => 'told', 'minutes' => null];
        }
        if (in_array($alert->kind, DeviceAlertEngine::EMERGENCY_KINDS, true)) {
            return ['state' => 'none', 'minutes' => null];
        }
        $settings = app(SecurityDevicesSettings::class);
        $minutes = $settings->minutes(in_array($alert->kind, DeviceAlertEngine::URGENT_KINDS, true) ? SecurityDevicesSettings::ESCALATE_URGENT : SecurityDevicesSettings::ESCALATE_CARE);

        return ['state' => 'will', 'minutes' => $minutes];
    }

    /** @return array<string, mixed> */
    private function present(User $user, DeviceAlert $alert): array
    {
        $carePeople = SiteStream::carePeople($user, collect([$alert]));
        $words = new AlertWords($alert, $carePeople[(int) $alert->id] ?? false);
        $events = DeviceAlertEvent::query()->where('alert_id', $alert->id)
            ->whereIn('kind', ['opened', 'told', 'acknowledged', 'escalated', 'recovered'])
            ->orderBy('at')->orderBy('id')->limit(20)->get();
        $opened = $events->firstWhere('kind', 'opened');

        return [
            'id' => (int) $alert->id,
            'title' => $words->title(),
            'tone' => $alert->severity === 'critical' ? 'critical' : 'warning',
            'what' => (string) ($words->text($opened?->text) ?? ''),
            'detail' => (string) ($words->text($opened?->detail) ?? ''),
            'site' => (string) ($alert->site?->name ?? ''),
            'device' => (string) ($alert->device?->name ?? ''),
            'opened_at' => $alert->opened_at?->toIso8601String(),
            'active' => $alert->isActive(),
            'attending' => $alert->acknowledgedBy !== null ? ['name' => (string) $alert->acknowledgedBy->name, 'at' => $alert->acknowledged_at?->toIso8601String(), 'you' => (int) $alert->acknowledged_by_user_id === (int) $user->id] : null,
            'escalation' => $this->escalation($alert),
            'timeline' => $events->map(fn (DeviceAlertEvent $event): array => [
                'id' => (int) $event->id,
                'at' => $event->at->toIso8601String(),
                'title' => (string) ($words->text($event->text) ?? ''),
                'tone' => match ($event->kind) {
                    'opened' => $alert->severity === 'critical' ? 'critical' : 'warning',
                    'escalated' => 'warning',
                    'acknowledged', 'recovered' => 'success',
                    default => 'info',
                },
            ])->values()->all(),
        ];
    }
}
