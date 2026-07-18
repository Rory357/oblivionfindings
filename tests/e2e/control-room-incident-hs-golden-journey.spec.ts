import { expect, test } from '@playwright/test';

import {
    createGoldenAlert,
    expectJourneyBrowserHealthy,
    installJourneyBrowserGuards,
    postLaravelMultipart,
    readGoldenRelayState,
    readHandoverScope,
    scalar,
} from './control-room-incident-hs-helpers';
import {
    loginAsFixture,
    patchLaravel,
    postLaravel,
    seedIncidentHandoverFixtures,
} from './incident-handover-helpers';

test.describe('Control Room → Incident → H&S seven-persona closure relay', () => {
    test.describe.configure({ timeout: 300_000 });

    test('completes one evidence-preserving journey with ordered closure and no duplicate responsibility', async ({
        page,
    }, testInfo) => {
        test.skip(
            testInfo.project.name !== 'chromium-desktop',
            'The accepted multi-role remediation scope is desktop web.',
        );
        const manifest = seedIncidentHandoverFixtures();
        const guard = installJourneyBrowserGuards(page);

        // 1 — Experienced operator: create, own, acknowledge, triage, control,
        // evidence, escalate, and hand the exact journey to Incident/H&S.
        await loginAsFixture(page, manifest.users.operator);
        await page.goto('/control-room');
        await expect(
            page.getByRole('heading', {
                name: 'Desk',
                exact: true,
                level: 1,
            }),
        ).toBeVisible({ timeout: 60_000 });
        const alertId = await createGoldenAlert(page, manifest);
        await postLaravel(page, `/control-room/alerts/${alertId}/assign-to-me`);
        await postLaravel(page, `/control-room/alerts/${alertId}/acknowledge`, {
            notes: 'Operator confirmed the immediate welfare response.',
        });
        await postLaravel(page, `/control-room/alerts/${alertId}/triage`, {
            notes: 'Triage linked the bathroom rail failure to a fall risk.',
        });
        await postLaravel(page, `/control-room/alerts/${alertId}/note`, {
            purpose: 'immediate_controls',
            note: 'Bathroom isolated, first aid completed, and a second worker remained with Aroha.',
        });
        await postLaravel(page, `/control-room/alerts/${alertId}/tasks`, {
            title: 'Replace bathroom rail and sign the inspection check',
            description:
                'Keep this responsibility one-for-one through H&S transfer.',
            assigned_to_user_id: manifest.users.operator.id,
            priority: 'critical',
            due_at: new Date(Date.now() + 2 * 86_400_000).toISOString(),
        });
        const sourceTaskId = scalar<{ id: number }>(`
echo json_encode([
    'id' => \\App\\Models\\ControlRoom\\AlertTask::query()
        ->where('alert_id', ${alertId})
        ->where('title', 'Replace bathroom rail and sign the inspection check')
        ->value('id'),
], JSON_THROW_ON_ERROR);
`).id;
        await postLaravel(page, `/control-room/alerts/${alertId}/evidence`, {
            title: 'Golden relay operational evidence',
        });
        const packId = scalar<{ id: number }>(`
echo json_encode([
    'id' => \\App\\Models\\ControlRoom\\EvidencePack::query()
        ->where('alert_id', ${alertId})
        ->where('title', 'Golden relay operational evidence')
        ->value('id'),
], JSON_THROW_ON_ERROR);
`).id;
        await postLaravel(page, `/control-room/evidence/${packId}/items`, {
            item_type: 'note',
            content:
                'Photograph logged: loose rail, wet-floor marker, and isolation tape.',
        });
        await postLaravel(page, `/control-room/alerts/${alertId}/escalate`, {
            escalation_reason:
                'Potential serious harm requires manager and H&S attention.',
            escalation_level: 2,
        });
        const journeyResponse = await postLaravel(
            page,
            `/control-room/alerts/${alertId}/create-incident`,
            {
                type: 'injury',
                severity: 'high',
                title: 'Bathroom fall and failed rail control',
                description:
                    'Aroha fell beside a loose bathroom rail during morning support.',
                immediate_action_taken:
                    'Bathroom isolated, first aid completed, and a second worker remained with Aroha.',
                location: 'Bathroom',
            },
        );
        const journey = (await journeyResponse.json()) as {
            journey: {
                incident: { id: number; reference_number: string };
                health_safety: { id: number; reference_number: string };
            };
        };
        const incidentId = journey.journey.incident.id;
        const eventId = journey.journey.health_safety.id;
        let state = readGoldenRelayState(
            incidentId,
            manifest.users.action_owner,
        );
        expect(state.alert.assigned_to_user_id).toBe(
            manifest.users.operator.id,
        );
        expect(state.alert.escalation_level).toBe(2);
        expect(state.counts).toMatchObject({
            alerts: 1,
            incidents: 1,
            events: 1,
            investigations: 0,
            actions: 0,
        });

        // 2 — Reviewer: natural discovery by official reference and by the
        // operational task text, then independent incident review.
        await loginAsFixture(page, manifest.users.reviewer);
        await page.goto(
            `/incidents?q=${encodeURIComponent(state.incident.reference)}&incident=${incidentId}`,
        );
        await expect(
            page.getByText(state.incident.reference).first(),
        ).toBeVisible({ timeout: 60_000 });
        await page.getByRole('button', { name: /Linked records/ }).click();
        await expect(
            page.getByRole('heading', {
                name: 'Linked Control Room evidence',
            }),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Bathroom isolated, first aid completed, and a second worker remained with Aroha.',
            ),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Photograph logged: loose rail, wet-floor marker, and isolation tape.',
            ),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Replace bathroom rail and sign the inspection check',
            ),
        ).toBeVisible();
        for (const query of [
            manifest.client.name,
            'Replace bathroom rail and sign the inspection check',
            state.incident.reference,
        ]) {
            await page.goto(`/tasks?q=${encodeURIComponent(query)}`);
            await expect(
                page.getByLabel('Journey references').first(),
            ).toContainText(state.incident.reference);
        }
        await postLaravel(page, `/incidents/${incidentId}/review`, {
            review_notes:
                'Provider manager confirmed the incident facts and linked operational evidence.',
        });

        // 3 — H&S owner: start from the dashboard acceptance queue, record the
        // WorkSafe decision, complete the investigation, and transfer the
        // existing operational responsibility to a distinct site manager.
        await loginAsFixture(page, manifest.users.owner);
        await page.goto('/health-safety');
        await expect(
            page.getByText('Awaiting H&S acceptance').first(),
        ).toBeVisible();
        const acceptLink = page.getByRole('link', {
            name: `Accept H&S handover for ${state.event.reference}`,
        });
        await expect(acceptLink).toHaveAttribute(
            'href',
            `/health-safety/events/${eventId}?action=accept-handover`,
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/accept-handover`,
            {
                owner_user_id: manifest.users.owner.id,
                acceptance_notes:
                    'Named H&S owner reviewed the incident, task, and evidence.',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/worksafe/decision`,
            {
                notifiable: false,
                reason: 'No WorkSafe notification threshold was met after formal review.',
                source: 'manual',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations`,
            {
                methodology: '5_whys',
                lead_investigator_id: manifest.users.owner.id,
                team_member_ids: [manifest.users.reviewer.id],
                target_completion_date: new Date(Date.now() + 7 * 86_400_000)
                    .toISOString()
                    .slice(0, 10),
            },
        );
        const investigationId = scalar<{ id: number }>(`
echo json_encode([
    'id' => \\App\\Models\\HsInvestigation::query()
        ->where('hs_event_id', ${eventId})
        ->value('id'),
], JSON_THROW_ON_ERROR);
`).id;
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations/${investigationId}/findings`,
            {
                immediate_causes: [
                    { description: 'Loose rail beside a wet floor.' },
                ],
                root_causes: [
                    {
                        description:
                            'The inspection escalation rule did not name an accountable owner.',
                    },
                ],
                contributing_factors: [
                    {
                        description:
                            'The signed inspection check was not retained.',
                        factor_type: 'procedural',
                    },
                ],
                findings_summary:
                    'The physical control and escalation process both failed.',
                recommendations: [
                    {
                        description:
                            'Replace the rail and require a signed daily inspection check.',
                        priority: 'critical',
                        target_area: 'equipment',
                    },
                ],
                lessons_learned:
                    'Safety-critical maintenance needs named ownership and retained evidence.',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations/${investigationId}/submit`,
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations/${investigationId}/complete`,
            { approved_by_id: manifest.users.reviewer.id },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/investigations/${investigationId}/recommendations/0/disposition`,
            {
                disposition: 'corrective_action',
                assigned_to_user_id: manifest.users.action_owner.id,
                due_date: new Date(Date.now() + 3 * 86_400_000)
                    .toISOString()
                    .slice(0, 10),
                priority: 'critical',
                responsibility_choice: 'transfer_task',
                source_control_room_task_id: sourceTaskId,
            },
        );
        state = readGoldenRelayState(incidentId, manifest.users.action_owner);
        expect(state.event).toMatchObject({
            handover_status: 'accepted',
            owner_user_id: manifest.users.owner.id,
            accepted_by_user_id: manifest.users.owner.id,
            worksafe_notifiable: false,
        });
        expect(state.investigation?.status).toBe('completed');
        expect(state.action?.assigned_to_user_id).toBe(
            manifest.users.action_owner.id,
        );
        expect(state.sourceTask).toMatchObject({
            id: sourceTaskId,
            status: 'transferred',
            transferred_to_hs_corrective_action_id: state.action!.id,
        });
        expect(state.counts.active_universal_action_responsibilities).toBe(1);

        // 4 — Dedicated corrective-action owner: find assigned work naturally,
        // start it, upload retained evidence, and complete it.
        const actionId = state.action!.id;
        const actionReference = state.action!.reference;
        await loginAsFixture(page, manifest.users.action_owner);
        await page.goto(`/tasks?q=${encodeURIComponent(actionReference)}`);
        await expect(page.getByText(actionReference).first()).toBeVisible();
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/start`,
        );
        await postLaravelMultipart(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/evidence`,
            {
                description:
                    'Signed inspection sheet and completed rail work order.',
                file: {
                    name: 'signed-inspection-evidence.pdf',
                    mimeType: 'application/pdf',
                    buffer: Buffer.from(
                        '%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n',
                    ),
                },
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/complete`,
            {
                completion_notes:
                    'Rail replaced and the signed daily check is now in use.',
            },
        );

        // 5 — Independent verifier: inspect evidence, return it once, observe
        // the owner resubmission, then verify and close the action.
        await loginAsFixture(page, manifest.users.verifier);
        await page.goto(`/tasks?q=${encodeURIComponent(actionReference)}`);
        await expect(page.getByText(actionReference).first()).toBeVisible();
        await page.goto(`/health-safety/corrective-actions?event=${eventId}`);
        await expect(
            page.getByText('signed-inspection-evidence.pdf'),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Signed inspection sheet and completed rail work order.',
            ),
        ).toBeVisible();
        const firstVerifyButton = page.getByRole('button', { name: 'Verify' });
        await expect(firstVerifyButton).toBeEnabled();
        await firstVerifyButton.click();
        await expect(
            page.getByRole('heading', { name: 'Verify action' }),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Rail replaced and the signed daily check is now in use.',
            ),
        ).toBeVisible();
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/return`,
            {
                reason: 'Add the supervisor countersignature to the retained inspection evidence.',
            },
        );
        state = readGoldenRelayState(incidentId, manifest.users.action_owner);
        expect(state.action).toMatchObject({
            status: 'in_progress',
            attachments: 1,
            latest_return_reason:
                'Add the supervisor countersignature to the retained inspection evidence.',
        });

        await loginAsFixture(page, manifest.users.action_owner);
        await page.goto(`/health-safety/corrective-actions?event=${eventId}`);
        await expect(
            page
                .getByRole('dialog')
                .getByText(
                    'Returned for rework: Add the supervisor countersignature to the retained inspection evidence.',
                ),
        ).toBeVisible();
        await expect(
            page.getByText('signed-inspection-evidence.pdf'),
        ).toBeVisible();
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/complete`,
            {
                completion_notes:
                    'Supervisor countersigned the retained sheet; evidence is resubmitted.',
            },
        );
        await loginAsFixture(page, manifest.users.verifier);
        await page.goto(`/health-safety/corrective-actions?event=${eventId}`);
        await expect(
            page
                .getByRole('dialog')
                .getByText(
                    'Returned for rework: Add the supervisor countersignature to the retained inspection evidence.',
                ),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Verify' }).click();
        await expect(
            page.getByRole('heading', { name: 'Verify action' }),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Supervisor countersigned the retained sheet; evidence is resubmitted.',
            ),
        ).toBeVisible();
        await expect(
            page.getByText('signed-inspection-evidence.pdf'),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Add the supervisor countersignature to the retained inspection evidence.',
                { exact: true },
            ),
        ).toBeVisible();
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/verify`,
            {
                evidence_reviewed: true,
                effective: true,
                verification_notes:
                    'Independent check confirmed both the rail and daily control are effective.',
            },
        );
        await postLaravel(
            page,
            `/health-safety/events/${eventId}/corrective-actions/${actionId}/close`,
        );

        // Outgoing operator freezes the complete bounded scope; the incoming
        // operator accepts it, resolves operations, and sees final Close blocked
        // until H&S and Incident are independently closed.
        await loginAsFixture(page, manifest.users.operator);
        const scope = readHandoverScope(
            manifest.shift.id,
            manifest.users.operator,
        );
        expect(scope.required_alert_ids).toContain(alertId);
        expect(scope.required_alert_ids.length).toBeLessThanOrEqual(3);
        await patchLaravel(
            page,
            `/control-room/shifts/${manifest.shift.id}/handover/draft`,
            {
                handover_notes:
                    'Golden relay awaits final H&S, Incident, and alert closure.',
                incoming_shift_name: manifest.shift.name,
                incoming_lead_user_id: manifest.users.incoming.id,
                incoming_team_members: [manifest.users.incoming.id],
                reviewed_alert_ids: scope.required_alert_ids,
                priority_alert_ids: [alertId],
                carry_forward_acknowledged: scope.carry_forward_total > 0,
                carry_forward_signature:
                    scope.carry_forward_total > 0
                        ? scope.carry_forward_signature
                        : null,
                expected_version: scope.version,
            },
        );
        const draftVersion = scalar<{ version: number }>(`
echo json_encode([
    'version' => (int) \\App\\Models\\ControlRoom\\Shift::query()
        ->findOrFail(${manifest.shift.id})
        ->handover_version,
], JSON_THROW_ON_ERROR);
`).version;
        await postLaravel(
            page,
            `/control-room/shifts/${manifest.shift.id}/handover`,
            {
                incoming_lead_user_id: manifest.users.incoming.id,
                reviewed_alert_ids: scope.required_alert_ids,
                expected_version: draftVersion,
            },
        );
        const preparedVersion = scalar<{ version: number }>(`
echo json_encode([
    'version' => (int) \\App\\Models\\ControlRoom\\Shift::query()
        ->findOrFail(${manifest.shift.id})
        ->handover_version,
], JSON_THROW_ON_ERROR);
`).version;

        await loginAsFixture(page, manifest.users.incoming);
        await page.goto(`/control-room/shifts/${manifest.shift.id}/handover`);
        await expect(
            page.getByText(/Golden relay awaits final H&S/i),
        ).toBeVisible();
        await postLaravel(
            page,
            `/control-room/shifts/${manifest.shift.id}/accept-handover`,
            { expected_version: preparedVersion },
        );
        await postLaravel(page, `/control-room/alerts/${alertId}/resolve`, {
            resolution_notes:
                'Immediate response and transferred operational task are complete.',
            resolution_code: 'incident_logged',
        });
        await postLaravel(page, `/control-room/alerts/${alertId}/close`, {
            closure_notes:
                'This attempt must remain blocked until governance closes.',
        });
        state = readGoldenRelayState(incidentId, manifest.users.action_owner);
        expect(state.alert.status).toBe('resolved');
        expect(state.event.status).not.toBe('closed');
        expect(state.incident.status).toBe('reviewed');

        await loginAsFixture(page, manifest.users.owner);
        await postLaravel(page, `/health-safety/events/${eventId}/close`, {
            closure_summary:
                'Investigation, recommendation, evidence, and independent verification are complete.',
        });
        await loginAsFixture(page, manifest.users.reviewer);
        await postLaravel(page, `/incidents/${incidentId}/close`, {
            closed_outcome: 'Reviewed journey complete',
            closed_notes:
                'The official incident and linked governance records are complete.',
        });
        await loginAsFixture(page, manifest.users.incoming);
        await postLaravel(page, `/control-room/alerts/${alertId}/close`, {
            closure_notes:
                'Incoming operator closed the fully completed journey.',
        });

        // 7 — Novice worker: read-only truth, no governance mutation CTA, and
        // keyboard focus returns to the exact task row after Escape.
        state = readGoldenRelayState(incidentId, manifest.users.action_owner);
        expect(state.alert.status).toBe('closed');
        expect(state.incident.status).toBe('closed');
        expect(state.event.status).toBe('closed');
        expect(state.action).toMatchObject({
            status: 'closed',
            assigned_to_user_id: manifest.users.action_owner.id,
            completed_by_user_id: manifest.users.action_owner.id,
            verified_by_user_id: manifest.users.verifier.id,
            attachments: 1,
        });
        expect(state.counts).toMatchObject({
            alerts: 1,
            incidents: 1,
            events: 1,
            investigations: 1,
            actions: 1,
            active_universal_action_responsibilities: 0,
            done_universal_action_responsibilities: 1,
        });

        await loginAsFixture(page, manifest.users.worker);
        await page.goto(
            `/tasks?bucket=done&q=${encodeURIComponent(state.incident.reference)}`,
        );
        const actionRow = page.getByRole('button', {
            name: `Open ${state.action!.reference}`,
        });
        await expect(actionRow).toBeVisible();
        await actionRow.focus();
        await actionRow.press('Enter');
        const taskDialog = page.getByTestId('tasks-detail-dialog');
        await expect(taskDialog).toBeVisible();
        await expect(taskDialog.getByLabel('Journey references')).toContainText(
            state.incident.reference,
        );
        await expect(
            taskDialog.getByRole('button', { name: 'Accept H&S handover' }),
        ).toHaveCount(0);
        // The sole Close control is the dialog's dismiss button, not a
        // governance mutation exposed to the read-only worker.
        await expect(
            taskDialog.getByRole('button', { name: /^Close$/ }),
        ).toHaveCount(1);
        await page.keyboard.press('Escape');
        await expect(actionRow).toBeFocused();

        await expectJourneyBrowserHealthy(page, guard, {
            requireOwnedFocus: true,
        });
    });
});
