import {
    expect,
    type APIResponse,
    type Page,
    type Request,
    type Response,
} from '@playwright/test';

import { runLaravelJson } from './helpers';
import {
    postLaravel,
    type FixtureUser,
    type IncidentHandoverManifest,
} from './incident-handover-helpers';

export type BrowserFailureGuard = {
    consoleErrors: string[];
    pageErrors: string[];
    failedRequests: string[];
    badResponses: string[];
};

export type GoldenRelayState = {
    alert: {
        id: number;
        reference: string;
        status: string;
        assigned_to_user_id: number | null;
        escalation_level: number;
        resolution_notes: string | null;
        closed_at: string | null;
    };
    incident: {
        id: number;
        reference: string;
        status: string;
        reviewed_by: number | null;
        closed_by: number | null;
    };
    event: {
        id: number;
        reference: string;
        status: string;
        handover_status: string;
        owner_user_id: number | null;
        accepted_by_user_id: number | null;
        worksafe_notifiable: boolean | null;
        worksafe_status: string | null;
    };
    investigation: null | {
        id: number;
        reference: string;
        status: string;
    };
    action: null | {
        id: number;
        reference: string;
        status: string;
        assigned_to_user_id: number | null;
        completed_by_user_id: number | null;
        verified_by_user_id: number | null;
        source_control_room_task_id: number | null;
        attachments: number;
        latest_return_reason: string | null;
    };
    sourceTask: null | {
        id: number;
        status: string;
        transferred_to_hs_corrective_action_id: number | null;
    };
    counts: {
        alerts: number;
        incidents: number;
        events: number;
        investigations: number;
        actions: number;
        active_universal_action_responsibilities: number;
        done_universal_action_responsibilities: number;
    };
};

export function installJourneyBrowserGuards(
    page: Page,
    allowedResponse?: (response: Response) => boolean,
): BrowserFailureGuard {
    const guard: BrowserFailureGuard = {
        consoleErrors: [],
        pageErrors: [],
        failedRequests: [],
        badResponses: [],
    };

    page.on('console', (message) => {
        if (message.type() === 'error') {
            guard.consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => guard.pageErrors.push(error.message));
    page.on('requestfailed', (request: Request) => {
        guard.failedRequests.push(
            `${request.method()} ${request.url()} — ${request.failure()?.errorText ?? 'failed'}`,
        );
    });
    page.on('response', (response) => {
        if (
            response.status() >= 400 &&
            !response.url().endsWith('/favicon.ico') &&
            !allowedResponse?.(response)
        ) {
            guard.badResponses.push(
                `${response.status()} ${response.request().method()} ${response.url()}`,
            );
        }
    });

    return guard;
}

export async function expectJourneyBrowserHealthy(
    page: Page,
    guard: BrowserFailureGuard,
    options: { requireOwnedFocus?: boolean } = {},
): Promise<void> {
    expect(guard.consoleErrors, 'browser console errors').toEqual([]);
    expect(guard.pageErrors, 'uncaught browser page errors').toEqual([]);
    expect(guard.failedRequests, 'failed browser requests').toEqual([]);
    expect(guard.badResponses, 'unexpected browser 4xx/5xx responses').toEqual(
        [],
    );

    if (options.requireOwnedFocus) {
        await expect
            .poll(() =>
                page.evaluate(
                    () =>
                        document.activeElement !== null &&
                        document.activeElement !== document.body,
                ),
            )
            .toBe(true);
    }
}

export async function postLaravelMultipart(
    page: Page,
    path: string,
    multipart: Record<
        string,
        | string
        | number
        | boolean
        | { name: string; mimeType: string; buffer: Buffer }
    >,
): Promise<APIResponse> {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    const response = await page.request.post(path, {
        multipart,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.value) : '',
        },
        maxRedirects: 0,
    });

    expect(
        response.status(),
        `${path} should succeed; received ${response.status()}: ${await response.text()}`,
    ).toBeLessThan(400);

    return response;
}

export async function createGoldenAlert(
    page: Page,
    manifest: IncidentHandoverManifest,
): Promise<number> {
    const response = await postLaravel(page, '/control-room/alerts', {
        source: 'manual',
        alert_type: 'Seven-persona bathroom safety relay',
        severity: 'high',
        priority: 'critical',
        client_id: manifest.client.id,
        site_id: manifest.site.id,
        notes: 'Golden relay: bathroom rail failure and fall risk.',
        context: {
            fixture_marker: manifest.marker,
            scenario: 'golden-seven-persona-relay',
        },
    });
    expect(response.status()).toBe(201);

    return ((await response.json()) as { alert: { id: number } }).alert.id;
}

export function scalar<T>(php: string): T {
    return runLaravelJson<T>(php);
}

export function readGoldenRelayState(
    incidentId: number,
    taskViewer: FixtureUser,
): GoldenRelayState {
    return runLaravelJson<GoldenRelayState>(`
$incident = \\App\\Models\\ClientIncident::query()->findOrFail(${incidentId});
$alert = \\App\\Models\\ControlRoomAlert::query()->findOrFail($incident->control_room_alert_id);
$event = \\App\\Models\\HsEvent::withTrashed()->findOrFail($incident->hs_event_id);
$investigation = \\App\\Models\\HsInvestigation::withTrashed()
    ->where('hs_event_id', $event->id)
    ->orderBy('id')
    ->first();
$action = \\App\\Models\\HsCorrectiveAction::withTrashed()
    ->where('hs_event_id', $event->id)
    ->orderBy('id')
    ->first();
$sourceTask = $action?->source_control_room_task_id
    ? \\App\\Models\\ControlRoom\\AlertTask::query()->find($action->source_control_room_task_id)
    : \\App\\Models\\ControlRoom\\AlertTask::query()->where('alert_id', $alert->id)->orderBy('id')->first();
$actionAudit = $action
    ? \\App\\Models\\AuditLog::query()
        ->where('auditable_type', \\App\\Models\\HsCorrectiveAction::class)
        ->where('auditable_id', $action->id)
        ->get()
    : collect();
$viewer = \\App\\Models\\User::query()->findOrFail(${taskViewer.id});
$taskAggregator = new \\App\\Services\\Tasks\\TaskAggregator;
$activeTaskItems = collect($taskAggregator->arrayFor($viewer, [
    'q' => $event->reference_number,
]));
$allTaskItems = collect($taskAggregator->arrayFor($viewer, [
    'q' => $event->reference_number,
    'include_done' => true,
]));
$actionTaskId = $action ? 'corrective_action-'.$action->id : null;
$payload = [
    'alert' => [
        'id' => $alert->id,
        'reference' => $alert->reference_number,
        'status' => $alert->status,
        'assigned_to_user_id' => $alert->assigned_to_user_id,
        'escalation_level' => (int) ($alert->escalation_level ?? 0),
        'resolution_notes' => $alert->resolution_notes,
        'closed_at' => $alert->closed_at?->toIso8601String(),
    ],
    'incident' => [
        'id' => $incident->id,
        'reference' => $incident->reference_number,
        'status' => $incident->status,
        'reviewed_by' => $incident->reviewed_by,
        'closed_by' => $incident->closed_by,
    ],
    'event' => [
        'id' => $event->id,
        'reference' => $event->reference_number,
        'status' => $event->status,
        'handover_status' => $event->handover_status,
        'owner_user_id' => $event->owner_user_id,
        'accepted_by_user_id' => $event->accepted_by_user_id,
        'worksafe_notifiable' => $event->worksafe_notifiable,
        'worksafe_status' => $event->worksafe_status,
    ],
    'investigation' => $investigation ? [
        'id' => $investigation->id,
        'reference' => $investigation->reference_number,
        'status' => $investigation->status,
    ] : null,
    'action' => $action ? [
        'id' => $action->id,
        'reference' => $action->reference_number,
        'status' => $action->status,
        'assigned_to_user_id' => $action->assigned_to_user_id,
        'completed_by_user_id' => $action->completed_by_user_id,
        'verified_by_user_id' => $action->verified_by_user_id,
        'source_control_room_task_id' => $action->source_control_room_task_id,
        'attachments' => $action->attachments()->count(),
        'latest_return_reason' => app(\\App\\Support\\HealthSafety\\HsCorrectiveActionActivityLabels::class)
            ->latestReturnReason($actionAudit),
    ] : null,
    'sourceTask' => $sourceTask ? [
        'id' => $sourceTask->id,
        'status' => $sourceTask->status,
        'transferred_to_hs_corrective_action_id' => $sourceTask->transferred_to_hs_corrective_action_id,
    ] : null,
    'counts' => [
        'alerts' => \\App\\Models\\ControlRoomAlert::query()->whereKey($alert->id)->count(),
        'incidents' => \\App\\Models\\ClientIncident::query()->whereKey($incident->id)->count(),
        'events' => \\App\\Models\\HsEvent::withTrashed()->whereKey($event->id)->count(),
        'investigations' => \\App\\Models\\HsInvestigation::withTrashed()->where('hs_event_id', $event->id)->count(),
        'actions' => \\App\\Models\\HsCorrectiveAction::withTrashed()->where('hs_event_id', $event->id)->count(),
        'active_universal_action_responsibilities' => $actionTaskId === null
            ? 0
            : $activeTaskItems->where('id', $actionTaskId)->count(),
        'done_universal_action_responsibilities' => $actionTaskId === null
            ? 0
            : $allTaskItems->where('id', $actionTaskId)->where('bucket', 'done')->count(),
    ],
];
echo json_encode($payload, JSON_THROW_ON_ERROR);
`);
}

export function readHandoverScope(
    shiftId: number,
    viewer: FixtureUser,
): {
    version: number;
    required_alert_ids: number[];
    carry_forward_signature: string;
    carry_forward_total: number;
} {
    return runLaravelJson(`
$shift = \\App\\Models\\ControlRoom\\Shift::query()->findOrFail(${shiftId});
$viewer = \\App\\Models\\User::query()->findOrFail(${viewer.id});
$scope = app(\\App\\Services\\ControlRoom\\ControlRoomHandoverScopeService::class)
    ->build($shift, $viewer);
echo json_encode([
    'version' => (int) $shift->handover_version,
    'required_alert_ids' => collect($scope['required_alerts'])->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
    'carry_forward_signature' => data_get($scope, 'carry_forward.signature'),
    'carry_forward_total' => (int) data_get($scope, 'carry_forward.total', 0),
], JSON_THROW_ON_ERROR);
`);
}
