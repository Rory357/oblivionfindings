import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    runLaravelJson,
} from './helpers';
import {
    captureIncidentEvidence,
    expectNoBlockingAxeViolations,
    incidentIdForRequest,
    loginAsFixture,
    postLaravel,
    readIncidentJourney,
    seedIncidentHandoverFixtures,
    type IncidentHandoverManifest,
} from './incident-handover-helpers';

async function createAlert(
    page: Page,
    manifest: IncidentHandoverManifest,
    overrides: Record<string, unknown> = {},
) {
    const response = await postLaravel(page, '/control-room/alerts', {
        source: 'manual',
        alert_type: 'Playwright incident handover',
        severity: 'high',
        client_id: manifest.client.id,
        site_id: manifest.site.id,
        notes: 'Original Control Room note must survive handover.',
        ...overrides,
    });
    expect(response.status()).toBe(201);

    return (await response.json()) as {
        alert: { id: number; status: string };
    };
}

function scalar<T>(php: string): T {
    return runLaravelJson<T>(php);
}

test.describe('desktop incident handover journeys', () => {
    test.describe.configure({ timeout: 120_000 });

    test.beforeEach(async ({ browserName: _browserName }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'The Control Room/incident/H&S acceptance audit is desktop only.',
        );
    });

    test('1 — existing alert becomes one accepted H&S journey without losing operational context', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        const errors = collectConsoleErrors(page);
        await loginAsFixture(page, manifest.users.operator);
        await page.goto('/control-room');
        await expect(
            page.getByRole('heading', {
                name: 'Desk',
                exact: true,
                level: 1,
            }),
        ).toBeVisible();

        const { alert } = await createAlert(page, manifest);
        await postLaravel(page, `/control-room/alerts/${alert.id}/tasks`, {
            title: 'Preserve the immediate scene',
            description: 'Operational work remains visible after handover.',
            priority: 'high',
        });
        await postLaravel(page, `/control-room/alerts/${alert.id}/evidence`, {
            title: 'Initial incident evidence',
        });
        const packsResponse = await page.request.get(
            `/control-room/alerts/${alert.id}/evidence`,
            { headers: { Accept: 'application/json' } },
        );
        const packs = (await packsResponse.json()) as {
            packs: Array<{ id: number }>;
        };
        await postLaravel(
            page,
            `/control-room/alerts/${alert.id}/evidence/${packs.packs[0].id}/items`,
            {
                item_type: 'note',
                content: 'Bathroom rail photographed before repair.',
            },
        );

        const first = await postLaravel(
            page,
            `/control-room/alerts/${alert.id}/create-incident`,
            {
                type: 'injury',
                severity: 'high',
                title: 'Bathroom fall requiring clinical review',
                description: 'Aroha fell beside the bathroom rail.',
                immediate_action_taken:
                    'First aid completed and area isolated.',
            },
        );
        const firstJourney = (await first.json()) as {
            journey: {
                incident: { id: number };
                health_safety: { id: number };
            };
        };
        const retry = await postLaravel(
            page,
            `/control-room/alerts/${alert.id}/create-incident`,
            {
                type: 'injury',
                severity: 'high',
                title: 'Bathroom fall requiring clinical review',
                description: 'Aroha fell beside the bathroom rail.',
                immediate_action_taken:
                    'First aid completed and area isolated.',
            },
        );
        const retryJourney = (await retry.json()) as typeof firstJourney;
        expect(retryJourney.journey.incident.id).toBe(
            firstJourney.journey.incident.id,
        );

        let invariant = readIncidentJourney(firstJourney.journey.incident.id);
        expect(invariant.counts.source_events).toBe(1);
        expect(invariant.counts.candidate_alerts).toBe(1);
        expect(invariant.alert?.tasks).toBe(1);
        expect(invariant.alert?.evidence_items).toBe(1);
        expect(invariant.health_safety?.handover_status).toBe(
            'awaiting_acceptance',
        );

        await loginAsFixture(page, manifest.users.owner);
        await page.goto(
            `/health-safety/events/${firstJourney.journey.health_safety.id}`,
        );
        await expect(
            page.getByText('Awaiting H&S acceptance').first(),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Open full page' }),
        ).toHaveAttribute(
            'href',
            `/health-safety/events/${firstJourney.journey.health_safety.id}`,
        );
        await postLaravel(
            page,
            `/health-safety/events/${firstJourney.journey.health_safety.id}/accept-handover`,
            {
                owner_user_id: manifest.users.owner.id,
                acceptance_notes:
                    'Reviewed the Control Room evidence and accepted ownership.',
            },
        );
        await page.reload();
        await expect(page.getByText(/Accepted/i).first()).toBeVisible();

        invariant = readIncidentJourney(firstJourney.journey.incident.id);
        expect(invariant.health_safety?.owner_user_id).toBe(
            manifest.users.owner.id,
        );
        expect(invariant.health_safety?.accepted_at).not.toBeNull();
        expect(invariant.alert?.notes).toBe(
            'Original Control Room note must survive handover.',
        );
        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(errors);
        await captureIncidentEvidence(
            page,
            '01-alert-to-accepted-hs',
            invariant,
        );
    });

    test('2 — support-worker draft stays operationally quiet until submit and then shows read-only acceptance', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        const errors = collectConsoleErrors(page);
        const requestUuid = randomUUID();
        await loginAsFixture(page, manifest.users.worker);
        await postLaravel(page, '/incidents', {
            intent: 'draft',
            report_request_uuid: requestUuid,
            client_id: manifest.client.id,
            site_id: manifest.site.id,
            type: 'injury',
            severity: 'medium',
            occurred_at: new Date().toISOString(),
            description:
                'Saved by a support worker for completion after immediate care.',
        });
        const incidentId = incidentIdForRequest(requestUuid);

        let invariant = readIncidentJourney(incidentId);
        expect(invariant.incident.status).toBe('draft');
        expect(invariant.alert).toBeNull();
        expect(invariant.health_safety).toBeNull();

        await postLaravel(page, `/incidents/${incidentId}/submit`);
        invariant = readIncidentJourney(incidentId);
        expect(invariant.incident.status).toBe('submitted');
        expect(invariant.alert).toBeNull();
        expect(invariant.health_safety?.handover_status).toBe(
            'awaiting_acceptance',
        );

        await loginAsFixture(page, manifest.users.owner);
        await postLaravel(
            page,
            `/health-safety/events/${invariant.health_safety?.id}/accept-handover`,
            {
                owner_user_id: manifest.users.owner.id,
                acceptance_notes:
                    'Support-worker report accepted for H&S review.',
            },
        );

        await loginAsFixture(page, manifest.users.reviewer);
        await postLaravel(page, `/incidents/${incidentId}/review`, {
            review_notes:
                'Incident facts reviewed independently from H&S governance.',
        });
        await postLaravel(page, `/incidents/${incidentId}/close`, {
            closed_outcome: 'Incident review complete',
            closed_notes:
                'The factual incident record is complete; H&S governance remains independent.',
        });
        invariant = readIncidentJourney(incidentId);
        // Acceptance transfers governance ownership but does not complete it.
        // The incident must remain reviewed until the linked H&S journey closes.
        expect(invariant.incident.status).toBe('reviewed');
        expect(invariant.health_safety?.status).toBe('open');
        expect(invariant.health_safety?.handover_status).toBe('accepted');

        await loginAsFixture(page, manifest.users.worker);
        await page.goto(`/incidents?incident=${incidentId}`);
        await expect(page.getByText('Accepted into H&S')).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Accept H&S handover' }),
        ).toHaveCount(0);

        invariant = readIncidentJourney(incidentId);
        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(errors);
        await captureIncidentEvidence(
            page,
            '02-support-worker-draft-submit',
            invariant,
        );
    });

    test('3 — high notifiable incident completes WorkSafe, investigation, independent verification and closure', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        const errors = collectConsoleErrors(page);
        const requestUuid = randomUUID();
        await loginAsFixture(page, manifest.users.operator);
        await postLaravel(page, '/incidents', {
            intent: 'submit',
            report_request_uuid: requestUuid,
            client_id: manifest.client.id,
            site_id: manifest.site.id,
            type: 'injury',
            severity: 'high',
            reported_severity: 'critical',
            occurred_at: new Date().toISOString(),
            description: 'Hospital treatment required after a serious fall.',
            immediate_action_taken:
                'First aid provided, the area isolated, and emergency clinical support called.',
            medical_treatment_type: 'hospital',
            injury_classification: 'notifiable',
            is_notifiable: true,
            site_preserved: true,
        });
        const incidentId = incidentIdForRequest(requestUuid);
        let invariant = readIncidentJourney(incidentId);
        const eventId = invariant.health_safety!.id;
        expect(invariant.counts.candidate_alerts).toBe(1);
        expect(invariant.health_safety?.worksafe_notifiable).toBe(true);

        await loginAsFixture(page, manifest.users.owner);
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/accept-handover`,
            {
                owner_user_id: manifest.users.owner.id,
                acceptance_notes: 'Accepted as the formal H&S owner.',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/worksafe/notify`,
            {
                notified_at: new Date().toISOString(),
                method: 'online',
                reference: 'WS-E2E-9401',
                site_preserved: true,
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/worksafe/acknowledge`,
            { acknowledged_at: new Date().toISOString() },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations`,
            {
                methodology: '5_whys',
                lead_investigator_id: manifest.users.owner.id,
                team_member_ids: [manifest.users.verifier.id],
                target_completion_date: new Date(Date.now() + 7 * 86_400_000)
                    .toISOString()
                    .slice(0, 10),
            },
        );
        const investigationId = scalar<{ id: number }>(`
echo json_encode(['id' => \\App\\Models\\HsInvestigation::query()->where('hs_event_id', ${eventId})->value('id')], JSON_THROW_ON_ERROR);
`).id;
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations/${investigationId}/findings`,
            {
                immediate_causes: [
                    { description: 'Wet flooring beside an unsecured rail.' },
                ],
                root_causes: [
                    { description: 'Inspection escalation was not explicit.' },
                ],
                contributing_factors: [
                    {
                        description: 'Bathroom inspection checklist gap.',
                        factor_type: 'procedural',
                    },
                ],
                findings_summary:
                    'The rail and wet-floor controls both failed.',
                recommendations: [
                    {
                        description:
                            'Replace the rail and add a signed inspection step.',
                        priority: 'high',
                        target_area: 'equipment',
                    },
                ],
                lessons_learned:
                    'Escalate safety-critical maintenance immediately.',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations/${investigationId}/submit`,
        );
        await loginAsFixture(page, manifest.users.reviewer);
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations/${investigationId}/complete`,
        );
        await loginAsFixture(page, manifest.users.action_owner);
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations/${investigationId}/complete`,
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations/${investigationId}/recommendations/0/disposition`,
            {
                disposition: 'corrective_action',
                assigned_to_user_id: manifest.users.owner.id,
                due_date: new Date(Date.now() + 3 * 86_400_000)
                    .toISOString()
                    .slice(0, 10),
                priority: 'high',
                responsibility_choice: 'new_responsibility',
                new_responsibility_reason:
                    'The investigation created a new named safety responsibility.',
            },
        );
        const actionId = scalar<{ id: number }>(`
echo json_encode(['id' => \\App\\Models\\HsCorrectiveAction::query()->where('hs_event_id', ${eventId})->value('id')], JSON_THROW_ON_ERROR);
`).id;
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/start`,
            { assigned_to_user_id: manifest.users.owner.id },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/complete`,
            {
                completion_notes:
                    'Rail replaced; signed inspection evidence retained.',
            },
        );

        await loginAsFixture(page, manifest.users.verifier);
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/verify`,
            {
                evidence_reviewed: true,
                effective: true,
                verification_notes:
                    'Independent check confirmed the rail and process.',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/close`,
        );
        await postLaravel(page, `/health-safety/events/${eventId}/close`, {
            closure_summary:
                'WorkSafe acknowledged, investigation complete and corrective action independently verified.',
        });

        await page.goto(`/health-safety/events/${eventId}`);
        await expect(page.getByText(/^Closed$/).first()).toBeVisible();
        invariant = readIncidentJourney(incidentId);
        expect(invariant.health_safety?.status).toBe('closed');
        expect(invariant.health_safety?.worksafe_status).toBe('acknowledged');
        expect(invariant.incident.worksafe_status).toBe('acknowledged');
        expect(invariant.incident.worksafe_reference).toBe('WS-E2E-9401');
        expect(invariant.health_safety?.investigations).toBe(1);
        expect(invariant.health_safety?.corrective_actions).toBe(1);
        expect(invariant.health_safety?.dispositions).toBe(1);
        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(errors);
        await captureIncidentEvidence(
            page,
            '03-notifiable-full-governance',
            invariant,
        );
    });

    test('4 — sensor fall confirmation reuses evidence and resolves operationally after H&S acceptance', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        const errors = collectConsoleErrors(page);
        await loginAsFixture(page, manifest.users.operator);
        const { alert } = await createAlert(page, manifest, {
            source: 'personal_tracker',
            alert_type: 'sensor.fall_detected',
            context: {
                sensor_type: 'fall_detection',
                confidence: 0.98,
                location: 'Bathroom',
            },
            notes: 'Automatic fall signal retained as operational evidence.',
        });
        scalar<{ id: number }>(`
$signalType = \\App\\Models\\ControlRoom\\SignalType::query()->firstOrCreate(
    ['code' => 'wearable_fall_detected'],
    [
        'name' => 'Wearable fall detected',
        'category' => \\App\\Models\\ControlRoom\\SignalType::CATEGORY_PEOPLE_SAFETY,
        'default_severity' => 'critical',
        'is_active' => true,
    ]
);
$signal = \\App\\Models\\ControlRoom\\Signal::query()->create([
    'alert_id' => ${alert.id},
    'signal_type_id' => $signalType->id,
    'signal_type_code' => $signalType->code,
    'client_id' => ${manifest.client.id},
    'site_id' => ${manifest.site.id},
    'severity_hint' => 'critical',
    'occurred_at' => now()->subMinute(),
    'payload' => ['confidence' => 0.98, 'location' => 'Bathroom'],
    'status' => 'processed',
]);
echo json_encode(['id' => $signal->id], JSON_THROW_ON_ERROR);
`);
        await postLaravel(page, `/control-room/alerts/${alert.id}/evidence`, {
            title: 'Sensor evidence',
        });
        const packs = (await (
            await page.request.get(`/control-room/alerts/${alert.id}/evidence`)
        ).json()) as { packs: Array<{ id: number }> };
        await postLaravel(
            page,
            `/control-room/alerts/${alert.id}/evidence/${packs.packs[0].id}/items`,
            {
                item_type: 'note',
                content: 'Fall sensor confidence 98%; bathroom zone.',
            },
        );
        await postLaravel(page, `/control-room/alerts/${alert.id}/confirm`, {
            type: 'fall',
            severity: 'high',
            note: 'Operator called the house and confirmed the fall.',
            immediate_action_taken:
                'The bathroom was isolated and first aid was provided while clinical help was called.',
        });
        const incidentId = scalar<{ id: number }>(`
echo json_encode(['id' => \\App\\Models\\ClientIncident::query()->where('control_room_alert_id', ${alert.id})->value('id')], JSON_THROW_ON_ERROR);
`).id;
        await postLaravel(page, `/control-room/alerts/${alert.id}/confirm`, {
            type: 'fall',
            severity: 'high',
            note: 'Operator called the house and confirmed the fall.',
            immediate_action_taken:
                'The bathroom was isolated and first aid was provided while clinical help was called.',
        });
        let invariant = readIncidentJourney(incidentId);
        expect(invariant.incident.source).toBe('sensor');
        expect(invariant.counts.source_events).toBe(1);
        expect(invariant.counts.candidate_alerts).toBe(1);
        expect(invariant.alert?.evidence_items).toBe(1);

        await loginAsFixture(page, manifest.users.owner);
        await postLaravel(
            page,
            `/health-safety/events/${invariant.health_safety?.id}/accept-handover`,
            {
                owner_user_id: manifest.users.owner.id,
                acceptance_notes: 'Sensor evidence reviewed and accepted.',
            },
        );
        await page.goto(`/health-safety/events/${invariant.health_safety?.id}`);
        await page
            .getByRole('button', { name: /Handover Accepted into H&S/ })
            .click();
        await expect(
            page.getByText('Fall sensor confidence 98%; bathroom zone.'),
        ).toBeVisible();

        await loginAsFixture(page, manifest.users.operator);
        await postLaravel(page, `/control-room/alerts/${alert.id}/resolve`, {
            resolution_notes:
                'Immediate response complete; accepted H&S governance remains active.',
            resolution_code: 'incident_logged',
        });
        await page.goto(`/control-room/alerts/${alert.id}`);
        await expect(page.getByText(/^Resolved$/i).first()).toBeVisible();
        invariant = readIncidentJourney(incidentId);
        expect(invariant.alert?.status).toBe('resolved');
        expect(invariant.health_safety?.handover_status).toBe('accepted');
        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(errors);
        await captureIncidentEvidence(page, '04-sensor-fall', invariant);
    });

    test('5 — similar manual and medication incidents keep distinct alerts while medication evidence enriches one journey', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        const errors = collectConsoleErrors(page);
        await loginAsFixture(page, manifest.users.operator);

        const manualUuid = randomUUID();
        await postLaravel(page, '/incidents', {
            intent: 'submit',
            report_request_uuid: manualUuid,
            client_id: manifest.client.id,
            site_id: manifest.site.id,
            type: 'medication_error',
            severity: 'high',
            occurred_at: new Date().toISOString(),
            description: 'First similar medication concern reported manually.',
            immediate_action_taken:
                'The dose was withheld and the prescriber was contacted immediately.',
        });
        const manualIncidentId = incidentIdForRequest(manualUuid);

        await postLaravel(page, '/emar/errors', {
            client_id: manifest.client.id,
            error_type: 'wrong_dose',
            severity: 'major',
            reached_client: 'yes',
            open_disclosure: 'pending',
            description:
                'Second similar event: wrong dose, independently reported through eMAR.',
            immediate_action: 'Dose withheld and prescriber contacted.',
            contributing_factors:
                'Similar timing but a distinct administration event.',
            create_incident: true,
        });
        const medication = scalar<{
            error_id: number;
            incident_id: number;
        }>(`
$error = \\App\\Models\\MedicationError::query()->where('client_id', ${manifest.client.id})->latest('id')->firstOrFail();
echo json_encode(['error_id' => $error->id, 'incident_id' => $error->client_incident_id], JSON_THROW_ON_ERROR);
`);
        await postLaravel(
            page,
            `/emar/errors/${medication.error_id}/link-incident`,
        );

        const manual = readIncidentJourney(manualIncidentId);
        const medicationJourney = readIncidentJourney(medication.incident_id);
        expect(manual.incident.id).not.toBe(medicationJourney.incident.id);
        expect(manual.alert?.id).not.toBe(medicationJourney.alert?.id);
        expect(manual.counts.candidate_alerts).toBe(1);
        expect(medicationJourney.counts.candidate_alerts).toBe(1);
        expect(medicationJourney.counts.medication_errors).toBe(1);
        expect(medicationJourney.alert?.reference).not.toBeNull();

        await page.goto(
            `/tasks?q=${encodeURIComponent(medicationJourney.incident.reference)}`,
        );
        await expect(
            page.getByLabel('Journey references').first(),
        ).toContainText(medicationJourney.incident.reference);
        await expect(
            page.getByLabel('Journey references').first(),
        ).toContainText(medicationJourney.alert!.reference);
        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(errors);
        await captureIncidentEvidence(
            page,
            '05-similar-medication-correlation',
            medicationJourney,
        );
    });
});
