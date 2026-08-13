import { expect, test, type Page } from '@playwright/test';

import {
    expectJourneyBrowserHealthy,
    installJourneyBrowserGuards,
    scalar,
} from './control-room-incident-hs-helpers';
import {
    loginAsFixture,
    postLaravel,
    seedIncidentHandoverFixtures,
    type IncidentHandoverManifest,
} from './incident-handover-helpers';

async function createTaggedAlert(
    page: Page,
    manifest: IncidentHandoverManifest,
    branch: string,
    overrides: Record<string, unknown> = {},
): Promise<number> {
    const response = await postLaravel(page, '/control-room/alerts', {
        source: 'manual',
        alert_type: `Alternate ${branch}`,
        severity: 'medium',
        client_id: manifest.client.id,
        site_id: manifest.site.id,
        notes: `Deterministic alternate branch ${branch}.`,
        context: {
            fixture_marker: manifest.marker,
            alternate_branch: branch,
        },
        ...overrides,
    });
    expect(response.status()).toBe(201);

    return ((await response.json()) as { alert: { id: number } }).alert.id;
}

async function acknowledgeAndTriage(page: Page, alertId: number) {
    await postLaravel(page, `/control-room/alerts/${alertId}/acknowledge`, {
        notes: 'Alternate branch acknowledged.',
    });
    await postLaravel(page, `/control-room/alerts/${alertId}/triage`, {
        notes: 'Alternate branch triaged.',
    });
}

async function createMediumJourney(
    page: Page,
    alertId: number,
    branch: string,
): Promise<{ incidentId: number; eventId: number }> {
    const response = await postLaravel(
        page,
        `/control-room/alerts/${alertId}/create-incident`,
        {
            type: 'near_miss',
            severity: 'medium',
            title: `Alternate ${branch} incident`,
            description: `Deterministic incident for alternate branch ${branch}.`,
            immediate_action_taken: 'Immediate controls were recorded.',
        },
    );
    const body = (await response.json()) as {
        journey: {
            incident: { id: number };
            health_safety: { id: number };
        };
    };

    return {
        incidentId: body.journey.incident.id,
        eventId: body.journey.health_safety.id,
    };
}

test.describe('Control Room / Incident / H&S alternate workflow branches', () => {
    test.describe.configure({ timeout: 300_000 });

    test('proves branches A–F without console, request, focus, or duplicate-work failures', async ({
        page,
    }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'The accepted multi-role remediation scope is desktop web.',
        );
        const manifest = seedIncidentHandoverFixtures();
        const guard = installJourneyBrowserGuards(page);
        await loginAsFixture(page, manifest.users.operator);

        // A — A routine alert ends without an incident or H&S record.
        const routineId = await createTaggedAlert(page, manifest, 'A-routine');
        await acknowledgeAndTriage(page, routineId);
        await postLaravel(page, `/control-room/alerts/${routineId}/resolve`, {
            resolution_notes:
                'Routine welfare call completed with no incident identified.',
            resolution_code: 'routine_complete',
        });
        await postLaravel(page, `/control-room/alerts/${routineId}/close`, {
            closure_notes: 'Routine response closed.',
        });
        const routine = scalar<{
            status: string;
            incidents: number;
            events: number;
        }>(`
$alert = \\App\\Models\\ControlRoomAlert::query()->findOrFail(${routineId});
echo json_encode([
    'status' => $alert->status,
    'incidents' => \\App\\Models\\ClientIncident::query()
        ->where('control_room_alert_id', $alert->id)
        ->count(),
    'events' => \\App\\Models\\HsEvent::withTrashed()
        ->where('control_room_alert_id', $alert->id)
        ->count(),
], JSON_THROW_ON_ERROR);
`);
        expect(routine).toEqual({
            status: 'closed',
            incidents: 0,
            events: 0,
        });

        // B — A false-positive sensor decision removes the alert from active
        // work/SLA truth while retaining the reason and audit.
        const sensorId = await createTaggedAlert(
            page,
            manifest,
            'B-false-positive',
            {
                source: 'personal_tracker',
                alert_type: 'sensor.fall_detected',
                context: {
                    fixture_marker: manifest.marker,
                    alternate_branch: 'B-false-positive',
                    sensor_type: 'fall_detection',
                    confidence: 0.41,
                },
            },
        );
        scalar<{ id: number }>(`
$type = \\App\\Models\\ControlRoom\\SignalType::query()->updateOrCreate(
    ['code' => 'fall_detected'],
    [
        'name' => 'Fall detected',
        'category' => \\App\\Models\\ControlRoom\\SignalType::CATEGORY_PEOPLE_SAFETY,
        'default_severity' => 'high',
        'is_active' => true,
    ],
);
$signal = \\App\\Models\\ControlRoom\\Signal::query()->create([
    'alert_id' => ${sensorId},
    'signal_type_id' => $type->id,
    'signal_type_code' => $type->code,
    'site_id' => ${manifest.site.id},
    'client_id' => ${manifest.client.id},
    'external_ref' => 'task19-false-positive-${sensorId}',
    'severity_hint' => 'medium',
    'occurred_at' => now(),
    'payload' => ['confidence' => 0.41, 'location' => 'Floor mat'],
    'status' => 'processed',
]);
echo json_encode(['id' => $signal->id], JSON_THROW_ON_ERROR);
`);
        await postLaravel(page, `/control-room/alerts/${sensorId}/dismiss`, {
            reason: 'Resident deliberately sat on the floor mat; no fall occurred.',
        });
        const sensor = scalar<{
            status: string;
            resolution_code: string;
            reason: string;
            incidents: number;
            applicable_sla: number;
            audits: number;
            suppressed_signals: number;
        }>(`
$alert = \\App\\Models\\ControlRoomAlert::query()->findOrFail(${sensorId});
echo json_encode([
    'status' => $alert->status,
    'resolution_code' => $alert->resolution_code,
    'reason' => data_get($alert->context, 'dismissed_reason'),
    'incidents' => \\App\\Models\\ClientIncident::query()
        ->where('control_room_alert_id', $alert->id)
        ->count(),
    'applicable_sla' => \\App\\Models\\ControlRoom\\AlertSla::query()
        ->where('alert_id', $alert->id)
        ->applicable()
        ->count(),
    'audits' => \\App\\Models\\AuditLog::query()
        ->where('auditable_type', $alert->getMorphClass())
        ->where('auditable_id', $alert->id)
        ->where('action', 'controlRoom.alert.dismiss')
        ->count(),
    'suppressed_signals' => $alert->signals()
        ->where('status', 'suppressed')
        ->count(),
], JSON_THROW_ON_ERROR);
`);
        expect(sensor).toMatchObject({
            status: 'dismissed',
            resolution_code: 'false_positive',
            reason: 'Resident deliberately sat on the floor mat; no fall occurred.',
            incidents: 0,
            applicable_sla: 0,
            audits: 1,
            suppressed_signals: 1,
        });

        // C — A resolved journey can be reopened after new incident evidence
        // without losing the original note/evidence or official references.
        const reopenId = await createTaggedAlert(
            page,
            manifest,
            'C-reopen-for-incident',
        );
        await acknowledgeAndTriage(page, reopenId);
        await postLaravel(page, `/control-room/alerts/${reopenId}/evidence`, {
            title: 'Original resolved evidence',
        });
        const reopenPackId = scalar<{ id: number }>(`
echo json_encode([
    'id' => \\App\\Models\\ControlRoom\\EvidencePack::query()
        ->where('alert_id', ${reopenId})
        ->value('id'),
], JSON_THROW_ON_ERROR);
`).id;
        await postLaravel(
            page,
            `/control-room/alerts/${reopenId}/evidence/${reopenPackId}/items`,
            {
                item_type: 'note',
                content: 'Original evidence remains attached after reopen.',
            },
        );
        const reopenJourney = await createMediumJourney(
            page,
            reopenId,
            'C-reopen-for-incident',
        );
        scalar<{ id: number }>(`
$definition = \\App\\Models\\ControlRoom\\SlaDefinition::query()->updateOrCreate(
    ['code' => 'task19-incident-reopen'],
    [
        'name' => 'Task 19 incident reopen',
        'alert_types' => ['Alternate C-reopen-for-incident'],
        'severities' => ['medium'],
        'sources' => ['manual'],
        'acknowledge_target_minutes' => 5,
        'response_target_minutes' => 15,
        'resolution_target_minutes' => 60,
        'is_active' => true,
    ],
);
echo json_encode(['id' => $definition->id], JSON_THROW_ON_ERROR);
`);
        await postLaravel(page, `/control-room/alerts/${reopenId}/resolve`, {
            resolution_notes:
                'Initial response resolved before new witness evidence.',
            resolution_code: 'initial_review_complete',
        });
        scalar<{ ok: boolean }>(`
$incident = \\App\\Models\\ClientIncident::query()->findOrFail(${reopenJourney.incidentId});
$event = \\App\\Models\\HsEvent::query()->findOrFail(${reopenJourney.eventId});
$event->forceFill([
    'status' => \\App\\Models\\HsEvent::STATUS_CLOSED,
    'closed_at' => now(),
    'closed_by' => ${manifest.users.owner.id},
    'closure_summary' => 'Initial governance closure before new evidence.',
])->saveQuietly();
$incident->forceFill([
    'status' => 'closed',
    'reviewed_at' => now()->subMinute(),
    'reviewed_by' => ${manifest.users.reviewer.id},
    'closed_at' => now(),
    'closed_by' => ${manifest.users.reviewer.id},
    'closed_outcome' => 'Initial review complete',
])->saveQuietly();
echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
`);
        await loginAsFixture(page, manifest.users.reviewer);
        await postLaravel(
            page,
            `/incidents/${reopenJourney.incidentId}/reopen`,
            {
                reopened_reason:
                    'New witness evidence changes the immediate risk picture.',
            },
        );
        await loginAsFixture(page, manifest.users.operator);
        await postLaravel(
            page,
            `/control-room/alerts/${reopenId}/reopen-for-incident`,
            {
                reason: 'Restart operational controls for the new witness evidence.',
            },
        );
        const reopened = scalar<{
            alert_status: string;
            incident_status: string;
            evidence_items: number;
            reference: string;
            reopen_audits: number;
        }>(`
$alert = \\App\\Models\\ControlRoomAlert::query()->findOrFail(${reopenId});
$incident = \\App\\Models\\ClientIncident::query()->findOrFail(${reopenJourney.incidentId});
echo json_encode([
    'alert_status' => $alert->status,
    'incident_status' => $incident->status,
    'evidence_items' => \\App\\Models\\ControlRoom\\EvidenceItem::query()
        ->whereHas('evidencePack', fn ($query) => $query->where('alert_id', $alert->id))
        ->count(),
    'reference' => $incident->reference_number,
    'reopen_audits' => \\App\\Models\\AuditLog::query()
        ->where('auditable_type', $alert->getMorphClass())
        ->where('auditable_id', $alert->id)
        ->where('action', 'controlRoom.alert.reopenForIncident')
        ->count(),
], JSON_THROW_ON_ERROR);
`);
        expect(reopened).toMatchObject({
            alert_status: 'triaging',
            incident_status: 'reviewed',
            evidence_items: 1,
            reopen_audits: 1,
        });
        expect(reopened.reference).not.toBe('');

        // D — Snooze hides only temporarily; unsnooze and escalation restore
        // active queue truth with plain language and a durable audit trail.
        await loginAsFixture(page, manifest.users.operator);
        scalar<{ id: number }>(`
$definition = \\App\\Models\\ControlRoom\\SlaDefinition::query()->updateOrCreate(
    ['code' => 'task19-snooze-escalate'],
    [
        'name' => 'Task 19 snooze and escalation',
        'alert_types' => ['Alternate D-snooze-escalate'],
        'severities' => ['medium'],
        'sources' => ['manual'],
        'acknowledge_target_minutes' => 5,
        'response_target_minutes' => 15,
        'resolution_target_minutes' => 60,
        'is_active' => true,
    ],
);
echo json_encode(['id' => $definition->id], JSON_THROW_ON_ERROR);
`);
        const snoozeId = await createTaggedAlert(
            page,
            manifest,
            'D-snooze-escalate',
        );
        const initialSla = scalar<{
            count: number;
            resolution_deadline: string;
        }>(`
$sla = \\App\\Models\\ControlRoom\\AlertSla::query()
    ->where('alert_id', ${snoozeId})
    ->applicable()
    ->sole();
echo json_encode([
    'count' => 1,
    'resolution_deadline' => $sla->resolution_deadline->toIso8601String(),
], JSON_THROW_ON_ERROR);
`);
        expect(initialSla.count).toBe(1);
        scalar<{ id: number }>(`
$queue = \\App\\Models\\ControlRoom\\TriageQueue::query()->updateOrCreate(
    ['code' => 'task19-escalation-queue'],
    [
        'name' => 'Task 19 escalation queue',
        'tier' => 1,
        'description' => 'Deterministic browser acceptance queue.',
        'is_active' => true,
    ],
);
$alert = \\App\\Models\\ControlRoomAlert::query()->findOrFail(${snoozeId});
$alert->forceFill(['queue_id' => $queue->id])->saveQuietly();
\\App\\Models\\ControlRoom\\AlertQueue::query()
    ->where('alert_id', $alert->id)
    ->whereNull('exited_at')
    ->delete();
\\App\\Models\\ControlRoom\\AlertQueue::query()->create([
    'alert_id' => $alert->id,
    'queue_id' => $queue->id,
    'entered_at' => now(),
]);
echo json_encode(['id' => $queue->id], JSON_THROW_ON_ERROR);
`);
        await postLaravel(page, `/control-room/alerts/${snoozeId}/snooze`, {
            window: '15m',
            note: 'Waiting for the house callback.',
        });
        const snoozed = scalar<{
            snoozed: boolean;
            applicable_sla: number;
            resolution_deadline: string;
        }>(`
$alert = \\App\\Models\\ControlRoomAlert::query()->findOrFail(${snoozeId});
$sla = \\App\\Models\\ControlRoom\\AlertSla::query()
    ->where('alert_id', $alert->id)
    ->applicable()
    ->sole();
echo json_encode([
    'snoozed' => $alert->isSnoozed(),
    'applicable_sla' => 1,
    'resolution_deadline' => $sla->resolution_deadline->toIso8601String(),
], JSON_THROW_ON_ERROR);
`);
        expect(snoozed).toEqual({
            snoozed: true,
            applicable_sla: 1,
            resolution_deadline: initialSla.resolution_deadline,
        });
        await postLaravel(page, `/control-room/alerts/${snoozeId}/unsnooze`);
        await postLaravel(page, `/control-room/alerts/${snoozeId}/escalate`, {
            escalation_reason:
                'The callback is late and management attention is now required.',
            escalation_level: 2,
        });
        const escalation = scalar<{
            snoozed_until: string | null;
            level: number;
            audits: number;
            applicable_sla: number;
            resolution_deadline: string;
            active_queue_entries: number;
        }>(`
$alert = \\App\\Models\\ControlRoomAlert::query()->findOrFail(${snoozeId});
$sla = \\App\\Models\\ControlRoom\\AlertSla::query()
    ->where('alert_id', $alert->id)
    ->applicable()
    ->sole();
echo json_encode([
    'snoozed_until' => $alert->snoozed_until?->toIso8601String(),
    'level' => (int) $alert->escalation_level,
    'audits' => \\App\\Models\\AuditLog::query()
        ->where('auditable_type', $alert->getMorphClass())
        ->where('auditable_id', $alert->id)
        ->whereIn('action', [
            'controlRoom.alert.snooze',
            'controlRoom.alert.unsnooze',
            'controlRoom.alert.escalate',
        ])
        ->count(),
    'applicable_sla' => 1,
    'resolution_deadline' => $sla->resolution_deadline->toIso8601String(),
    'active_queue_entries' => \\App\\Models\\ControlRoom\\AlertQueue::query()
        ->where('alert_id', $alert->id)
        ->whereNull('exited_at')
        ->count(),
], JSON_THROW_ON_ERROR);
`);
        expect(escalation).toEqual({
            snoozed_until: null,
            level: 2,
            audits: 3,
            applicable_sla: 1,
            resolution_deadline: initialSla.resolution_deadline,
            active_queue_entries: 1,
        });
        const escalatedReference = scalar<{ reference: string }>(`
echo json_encode([
    'reference' => \\App\\Models\\ControlRoomAlert::query()
        ->findOrFail(${snoozeId})
        ->reference_number,
], JSON_THROW_ON_ERROR);
`).reference;
        await page.goto(
            `/control-room/escalations?search=${encodeURIComponent(escalatedReference)}`,
        );
        await expect(page.getByText(escalatedReference)).toBeVisible();
        await expect(
            page.getByText(
                'Escalation queues — SLA-tracked tiers with guided moves and escalations.',
            ),
        ).toBeVisible();

        // E — One Control Room task becomes exactly one reciprocal H&S action,
        // including an exact retry.
        const transferId = await createTaggedAlert(
            page,
            manifest,
            'E-task-transfer',
        );
        await acknowledgeAndTriage(page, transferId);
        await postLaravel(page, `/control-room/alerts/${transferId}/tasks`, {
            title: 'Transfer exactly once to H&S',
            assigned_to_user_id: manifest.users.operator.id,
            priority: 'high',
            due_at: new Date(Date.now() + 2 * 86_400_000).toISOString(),
        });
        const transferTaskId = scalar<{ id: number }>(`
echo json_encode([
    'id' => \\App\\Models\\ControlRoom\\AlertTask::query()
        ->where('alert_id', ${transferId})
        ->where('title', 'Transfer exactly once to H&S')
        ->value('id'),
], JSON_THROW_ON_ERROR);
`).id;
        const transferJourney = await createMediumJourney(
            page,
            transferId,
            'E-task-transfer',
        );
        await loginAsFixture(page, manifest.users.owner);
        await postLaravel(
            page,
            `/health-safety/events/${transferJourney.eventId}/accept-handover`,
            {
                owner_user_id: manifest.users.owner.id,
                acceptance_notes:
                    'Accepted for one-for-one operational task transfer.',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${transferJourney.eventId}/investigations`,
            {
                methodology: '5_whys',
                lead_investigator_id: manifest.users.owner.id,
                target_completion_date: new Date(Date.now() + 5 * 86_400_000)
                    .toISOString()
                    .slice(0, 10),
            },
        );
        const transferInvestigationId = scalar<{ id: number }>(`
echo json_encode([
    'id' => \\App\\Models\\HsInvestigation::query()
        ->where('hs_event_id', ${transferJourney.eventId})
        ->value('id'),
], JSON_THROW_ON_ERROR);
`).id;
        await postLaravel(
            page,
            `/health-safety/events/${transferJourney.eventId}/investigations/${transferInvestigationId}/findings`,
            {
                immediate_causes: [{ description: 'Control was incomplete.' }],
                root_causes: [{ description: 'Ownership was unclear.' }],
                findings_summary: 'The task needs explicit H&S ownership.',
                recommendations: [
                    {
                        description: 'Complete the transferred control.',
                        priority: 'high',
                    },
                ],
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${transferJourney.eventId}/investigations/${transferInvestigationId}/submit`,
        );
        await postLaravel(
            page,
            `/health-safety/events/${transferJourney.eventId}/investigations/${transferInvestigationId}/complete`,
        );
        const transferPayload = {
            disposition: 'corrective_action',
            assigned_to_user_id: manifest.users.action_owner.id,
            due_date: new Date(Date.now() + 3 * 86_400_000)
                .toISOString()
                .slice(0, 10),
            priority: 'high',
            responsibility_choice: 'transfer_task',
            source_control_room_task_id: transferTaskId,
        };
        const transferPath = `/health-safety/events/${transferJourney.eventId}/investigations/${transferInvestigationId}/recommendations/0/disposition`;
        await postLaravel(page, transferPath, transferPayload);
        await postLaravel(page, transferPath, transferPayload);
        const transfer = scalar<{
            actions: number;
            action_id: number;
            source_task_status: string;
            reciprocal_action_id: number | null;
            canonical_source_task_id: number | null;
        }>(`
$task = \\App\\Models\\ControlRoom\\AlertTask::query()->findOrFail(${transferTaskId});
$action = \\App\\Models\\HsCorrectiveAction::query()
    ->where('hs_event_id', ${transferJourney.eventId})
    ->sole();
echo json_encode([
    'actions' => \\App\\Models\\HsCorrectiveAction::query()
        ->where('hs_event_id', ${transferJourney.eventId})
        ->count(),
    'action_id' => $action->id,
    'source_task_status' => $task->status,
    'reciprocal_action_id' => $task->transferred_to_hs_corrective_action_id,
    'canonical_source_task_id' => $action->source_control_room_task_id,
], JSON_THROW_ON_ERROR);
`);
        expect(transfer).toMatchObject({
            actions: 1,
            source_task_status: 'transferred',
            reciprocal_action_id: transfer.action_id,
            canonical_source_task_id: transferTaskId,
        });

        // F — Every parent close remains blocked on its unmet prerequisite,
        // typed closing text remains in place, and the same path succeeds after
        // WorkSafe, H&S, and Incident are completed in order.
        await loginAsFixture(page, manifest.users.operator);
        const gateId = await createTaggedAlert(
            page,
            manifest,
            'F-closure-gates',
        );
        await acknowledgeAndTriage(page, gateId);
        const gateJourney = await createMediumJourney(
            page,
            gateId,
            'F-closure-gates',
        );
        await loginAsFixture(page, manifest.users.owner);
        await postLaravel(
            page,
            `/health-safety/events/${gateJourney.eventId}/accept-handover`,
            {
                owner_user_id: manifest.users.owner.id,
                acceptance_notes:
                    'Accepted while the WorkSafe decision remains deliberately open.',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${gateJourney.eventId}/close`,
            {
                closure_summary:
                    'This must stay blocked until the WorkSafe decision is explicit.',
            },
        );
        await page.goto(
            `/health-safety/events/${gateJourney.eventId}?section=overview`,
        );
        await page.getByRole('button', { name: 'Close event' }).first().click();
        const eventClosureSummary = page.getByRole('textbox', {
            name: /Closure summary/,
        });
        await eventClosureSummary.fill(
            'Preserve this H&S summary while the WorkSafe decision is incomplete.',
        );
        await expect(eventClosureSummary).toHaveValue(
            'Preserve this H&S summary while the WorkSafe decision is incomplete.',
        );
        await expect(
            page.getByRole('button', { name: 'Close event' }).last(),
        ).toBeDisabled();
        await expect(
            page.locator(
                `a[href="/health-safety/events/${gateJourney.eventId}?action=worksafe-decision"]`,
            ),
        ).toHaveAttribute(
            'href',
            `/health-safety/events/${gateJourney.eventId}?action=worksafe-decision`,
        );

        await loginAsFixture(page, manifest.users.reviewer);
        await postLaravel(page, `/incidents/${gateJourney.incidentId}/review`, {
            review_notes: 'Reviewed while H&S remains deliberately incomplete.',
        });
        await postLaravel(page, `/incidents/${gateJourney.incidentId}/close`, {
            closed_outcome: 'Must remain blocked',
            closed_notes: 'H&S is not closed yet.',
        });
        await page.goto(`/incidents?incident=${gateJourney.incidentId}`);
        await page
            .getByRole('contentinfo')
            .getByRole('button', { name: 'Close' })
            .click();
        const incidentOutcome = page.getByRole('textbox', { name: 'Outcome' });
        const incidentClosingNotes = page.getByRole('textbox', {
            name: 'Closing notes',
        });
        await incidentOutcome.fill('Preserve this blocked incident outcome');
        await incidentClosingNotes.fill(
            'Preserve these incident notes while H&S remains open.',
        );
        await expect(incidentOutcome).toHaveValue(
            'Preserve this blocked incident outcome',
        );
        await expect(incidentClosingNotes).toHaveValue(
            'Preserve these incident notes while H&S remains open.',
        );
        await expect(
            page.getByRole('button', { name: 'Close incident' }).last(),
        ).toBeDisabled();
        await expect(
            page.locator(
                `a[href="/health-safety/events/${gateJourney.eventId}"]`,
            ),
        ).toHaveAttribute(
            'href',
            `/health-safety/events/${gateJourney.eventId}`,
        );

        await loginAsFixture(page, manifest.users.operator);
        await postLaravel(page, `/control-room/alerts/${gateId}/resolve`, {
            resolution_notes:
                'Operational response complete; governance remains open.',
        });
        await postLaravel(page, `/control-room/alerts/${gateId}/close`, {
            closure_notes: 'Must remain blocked by Incident and H&S.',
        });
        const blocked = scalar<{
            alert: string;
            incident: string;
            event: string;
        }>(`
echo json_encode([
    'alert' => \\App\\Models\\ControlRoomAlert::query()->findOrFail(${gateId})->status,
    'incident' => \\App\\Models\\ClientIncident::query()->findOrFail(${gateJourney.incidentId})->status,
    'event' => \\App\\Models\\HsEvent::query()->findOrFail(${gateJourney.eventId})->status,
], JSON_THROW_ON_ERROR);
`);
        expect(blocked).toEqual({
            alert: 'resolved',
            incident: 'reviewed',
            event: 'open',
        });

        await page.goto(`/control-room/alerts/${gateId}`);
        await page
            .getByRole('contentinfo')
            .getByRole('button', { name: 'Close' })
            .click();
        const closingNote = page.getByLabel('Closing note');
        await closingNote.fill(
            'Preserve this closing text while the prerequisites are incomplete.',
        );
        await expect(closingNote).toHaveValue(
            'Preserve this closing text while the prerequisites are incomplete.',
        );
        await expect(
            page.getByRole('button', { name: 'Close alert' }),
        ).toBeDisabled();
        await expect(
            page.locator(`a[href="/incidents/${gateJourney.incidentId}"]`),
        ).toHaveAttribute('href', `/incidents/${gateJourney.incidentId}`);

        await loginAsFixture(page, manifest.users.owner);
        await postLaravel(
            page,
            `/health-safety/events/${gateJourney.eventId}/worksafe/decision`,
            {
                notifiable: false,
                reason: 'Formal review confirmed no WorkSafe threshold was met.',
                source: 'manual',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${gateJourney.eventId}/close`,
            {
                closure_summary:
                    'Acceptance and WorkSafe decision are complete.',
            },
        );
        await loginAsFixture(page, manifest.users.reviewer);
        await postLaravel(page, `/incidents/${gateJourney.incidentId}/close`, {
            closed_outcome: 'All governance complete',
            closed_notes: 'H&S closed first.',
        });
        await loginAsFixture(page, manifest.users.operator);
        await postLaravel(page, `/control-room/alerts/${gateId}/close`, {
            closure_notes:
                'The same closure path now succeeds after prerequisites.',
        });
        const recovered = scalar<{
            alert: string;
            incident: string;
            event: string;
        }>(`
echo json_encode([
    'alert' => \\App\\Models\\ControlRoomAlert::query()->findOrFail(${gateId})->status,
    'incident' => \\App\\Models\\ClientIncident::query()->findOrFail(${gateJourney.incidentId})->status,
    'event' => \\App\\Models\\HsEvent::query()->findOrFail(${gateJourney.eventId})->status,
], JSON_THROW_ON_ERROR);
`);
        expect(recovered).toEqual({
            alert: 'closed',
            incident: 'closed',
            event: 'closed',
        });

        await page.goto('/control-room/alerts?lens=all_records');
        await expectJourneyBrowserHealthy(page, guard);
    });
});
