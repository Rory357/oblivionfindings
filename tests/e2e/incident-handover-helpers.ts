import AxeBuilder from '@axe-core/playwright';
import { expect, type APIResponse, type Page } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { loginAs, runArtisan, runLaravelJson } from './helpers';

type FixtureUser = { id: number; email: string; name: string };

export interface IncidentHandoverManifest {
    site: { id: number; name: string };
    client: { id: number; name: string };
    users: {
        operator: FixtureUser;
        worker: FixtureUser;
        reviewer: FixtureUser;
        owner: FixtureUser;
        verifier: FixtureUser;
    };
}

export interface IncidentJourneyInvariant {
    incident: {
        id: number;
        reference: string;
        source: string;
        status: string;
        site_id: number | null;
        alert_id: number | null;
        hs_event_id: number | null;
        is_notifiable: boolean;
        worksafe_status: string | null;
        worksafe_reference: string | null;
    };
    alert: null | {
        id: number;
        reference: string;
        status: string;
        notes: string | null;
        incident_claim: number | null;
        tasks: number;
        evidence_packs: number;
        evidence_items: number;
    };
    health_safety: null | {
        id: number;
        reference: string;
        status: string;
        handover_status: string;
        owner_user_id: number | null;
        accepted_by_user_id: number | null;
        accepted_at: string | null;
        worksafe_notifiable: boolean;
        worksafe_status: string | null;
        worksafe_reference: string | null;
        investigations: number;
        corrective_actions: number;
        dispositions: number;
    };
    counts: {
        source_events: number;
        candidate_alerts: number;
        medication_errors: number;
    };
}

const artifactRoot = resolve(
    process.cwd(),
    'output',
    'playwright',
    'incident-handover',
);

export function seedIncidentHandoverFixtures(): IncidentHandoverManifest {
    const output = runArtisan([
        'db:seed',
        '--class=IncidentHandoverE2ESeeder',
        '--force',
    ]);
    const line = output
        .split(/\r?\n/)
        .find((entry) => entry.startsWith('INCIDENT_HANDOVER_MANIFEST='));

    if (!line) {
        throw new Error(
            `Incident handover seeder did not emit a manifest:\n${output}`,
        );
    }

    return JSON.parse(
        line.slice(line.indexOf('=') + 1),
    ) as IncidentHandoverManifest;
}

export async function loginAsFixture(page: Page, user: FixtureUser) {
    await page.context().clearCookies();
    await loginAs(page, user.email, 'password');
}

export async function postLaravel(
    page: Page,
    path: string,
    data: Record<string, unknown> = {},
): Promise<APIResponse> {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    const response = await page.request.post(path, {
        data,
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

export function incidentIdForRequest(reportRequestUuid: string): number {
    return runLaravelJson<{ id: number }>(`
$id = \\App\\Models\\ClientIncident::query()
    ->where('report_request_uuid', ${JSON.stringify(reportRequestUuid)})
    ->value('id');
echo json_encode(['id' => $id], JSON_THROW_ON_ERROR);
`).id;
}

export function readIncidentJourney(
    incidentId: number,
): IncidentJourneyInvariant {
    return runLaravelJson<IncidentJourneyInvariant>(`
$incident = \\App\\Models\\ClientIncident::query()->findOrFail(${incidentId});
$event = $incident->hs_event_id
    ? \\App\\Models\\HsEvent::withTrashed()->find($incident->hs_event_id)
    : \\App\\Models\\HsEvent::withTrashed()
        ->where('source_type', \\App\\Models\\ClientIncident::class)
        ->where('source_id', $incident->id)
        ->orderBy('id')
        ->first();
$candidateAlertIds = collect([$incident->control_room_alert_id, $event?->control_room_alert_id])
    ->filter()
    ->merge(
        \\App\\Models\\ControlRoomAlert::query()
            ->where('context->incident_id', $incident->id)
            ->orWhere('context->normalized_data->incident_id', $incident->id)
            ->pluck('id')
    )
    ->map(fn ($id) => (int) $id)
    ->unique()
    ->values();
$alert = $candidateAlertIds->count() === 1
    ? \\App\\Models\\ControlRoomAlert::query()->find($candidateAlertIds->first())
    : null;
$investigationIds = $event
    ? \\App\\Models\\HsInvestigation::withTrashed()->where('hs_event_id', $event->id)->pluck('id')
    : collect();
$payload = [
    'incident' => [
        'id' => $incident->id,
        'reference' => $incident->reference_number,
        'source' => $incident->source,
        'status' => $incident->status,
        'site_id' => $incident->site_id,
        'alert_id' => $incident->control_room_alert_id,
        'hs_event_id' => $incident->hs_event_id,
        'is_notifiable' => (bool) $incident->is_notifiable,
        'worksafe_status' => $incident->worksafe_notification_status,
        'worksafe_reference' => $incident->worksafe_reference,
    ],
    'alert' => $alert ? [
        'id' => $alert->id,
        'reference' => $alert->reference_number,
        'status' => $alert->status,
        'notes' => $alert->notes,
        'incident_claim' => data_get($alert->context, 'incident_id'),
        'tasks' => \\App\\Models\\ControlRoom\\AlertTask::query()->where('alert_id', $alert->id)->count(),
        'evidence_packs' => \\App\\Models\\ControlRoom\\EvidencePack::query()->where('alert_id', $alert->id)->count(),
        'evidence_items' => \\App\\Models\\ControlRoom\\EvidenceItem::query()
            ->whereHas('evidencePack', fn ($q) => $q->where('alert_id', $alert->id))
            ->count(),
    ] : null,
    'health_safety' => $event ? [
        'id' => $event->id,
        'reference' => $event->reference_number,
        'status' => $event->status,
        'handover_status' => $event->handover_status,
        'owner_user_id' => $event->owner_user_id,
        'accepted_by_user_id' => $event->accepted_by_user_id,
        'accepted_at' => $event->accepted_at?->toIso8601String(),
        'worksafe_notifiable' => (bool) $event->worksafe_notifiable,
        'worksafe_status' => $event->worksafe_status,
        'worksafe_reference' => $event->worksafe_reference,
        'investigations' => $investigationIds->count(),
        'corrective_actions' => \\App\\Models\\HsCorrectiveAction::withTrashed()->where('hs_event_id', $event->id)->count(),
        'dispositions' => \\App\\Models\\HsRecommendationDisposition::query()->whereIn('hs_investigation_id', $investigationIds)->count(),
    ] : null,
    'counts' => [
        'source_events' => \\App\\Models\\HsEvent::withTrashed()
            ->where('source_type', \\App\\Models\\ClientIncident::class)
            ->where('source_id', $incident->id)
            ->count(),
        'candidate_alerts' => $candidateAlertIds->count(),
        'medication_errors' => \\App\\Models\\MedicationError::withTrashed()
            ->where('client_incident_id', $incident->id)
            ->count(),
    ],
];
echo json_encode($payload, JSON_THROW_ON_ERROR);
`);
}

export async function captureIncidentEvidence(
    page: Page,
    scenario: string,
    invariant: IncidentJourneyInvariant,
) {
    mkdirSync(artifactRoot, { recursive: true });
    writeFileSync(
        resolve(artifactRoot, `${scenario}.json`),
        `${JSON.stringify(invariant, null, 2)}\n`,
        'utf8',
    );
    await page.screenshot({
        path: resolve(artifactRoot, `${scenario}.png`),
        fullPage: true,
    });
}

export async function expectNoBlockingAxeViolations(page: Page) {
    const result = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    const blocking = result.violations.filter((violation) =>
        ['serious', 'critical'].includes(violation.impact ?? ''),
    );

    expect(
        blocking,
        blocking
            .map(
                (violation) =>
                    `[${violation.impact}] ${violation.id}: ${violation.help}`,
            )
            .join('\n'),
    ).toEqual([]);
}
