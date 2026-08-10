import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAs,
    loginAsStaff,
    runLaravelPhp,
} from './helpers';

interface ItAcceptanceManifest {
    emailTicket: { id: number; reference: string };
    problem: { id: number; reference: string };
    change: { id: number; reference: string };
    majorIncident: { id: number; reference: string };
}

function seedItAcceptanceFixtures(): ItAcceptanceManifest {
    const marker = '__IT_ACCEPTANCE_MANIFEST__';
    const output = runLaravelPhp(String.raw`
ob_start();
$admin = \App\Models\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$worker = \App\Models\User::query()->where('email', 'sw1@demo.test')->firstOrFail();

$service = \App\Models\ItService::query()->updateOrCreate(
    ['key' => 'e2e-managed-connectivity'],
    [
        'owner_user_id' => $admin->id,
        'name' => 'Managed connectivity',
        'description' => 'End-to-end acceptance service for site connectivity.',
        'status' => 'operational',
        'criticality' => 'high',
        'is_active' => true,
    ],
);

\App\Models\ItCatalogItem::query()->updateOrCreate(
    ['slug' => 'e2e-network-access'],
    [
        'it_service_id' => $service->id,
        'name' => 'Request managed network access',
        'description' => 'Request governed access to a managed site network.',
        'outcome_type' => 'service_request',
        'category' => 'network',
        'default_priority' => 'normal',
        'requires_approval' => false,
        'is_published' => true,
        'internal_only' => false,
        'form_schema_version' => 1,
        'form_schema' => [
            'fields' => [[
                'key' => 'business_reason',
                'label' => 'Business reason',
                'type' => 'textarea',
                'required' => true,
                'visibility' => 'requester',
                'max' => 1000,
                'help' => 'Explain who needs access and why.',
            ]],
        ],
        'search_terms' => ['network', 'wifi', 'site access'],
        'sort_order' => 0,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ],
);

\App\Models\ItKbArticle::query()->updateOrCreate(
    ['slug' => 'e2e-restore-site-connectivity'],
    [
        'title' => 'Restore site connectivity',
        'category' => 'network',
        'body' => 'Check the site gateway status, confirm the affected service, and contact IT if connectivity has not recovered.',
        'status' => 'published',
        'audience' => 'all_staff',
        'site_scope' => [],
        'author_user_id' => $admin->id,
        'owner_user_id' => $admin->id,
        'reviewed_by_user_id' => $admin->id,
        'related_service_id' => $service->id,
        'review_due_at' => now()->addMonths(6)->toDateString(),
        'review_started_at' => now(),
        'published_at' => now(),
        'view_count' => 3,
        'helpful_yes' => 2,
        'helpful_no' => 0,
        'deflection_count' => 1,
    ],
);

$ticket = function (string $title, array $attributes) use ($admin, $worker, $service) {
    return \App\Models\ItTicket::query()->updateOrCreate(
        ['title' => $title],
        [
            'description' => $attributes['description'],
            'requester_user_id' => $worker->id,
            'assigned_to_user_id' => $admin->id,
            'owner_user_id' => $admin->id,
            'it_service_id' => $service->id,
            'category' => 'network',
            'source' => $attributes['source'] ?? 'agent',
            'work_type' => $attributes['work_type'],
            'workflow_state' => $attributes['workflow_state'],
            'is_organisation_wide' => true,
            'priority' => $attributes['priority'] ?? 'high',
            'impact' => $attributes['impact'] ?? 'site',
            'urgency' => $attributes['urgency'] ?? 'high',
            'status' => $attributes['status'] ?? 'in_progress',
            'sla_state' => 'ok',
            'next_action' => $attributes['next_action'] ?? 'Technician review',
        ],
    );
};

$emailTicket = $ticket('E2E inbound email printer incident', [
    'description' => 'A site printer stopped responding and the requester emailed the service desk.',
    'source' => 'email',
    'work_type' => 'incident',
    'workflow_state' => 'triage',
]);
$emailTicket->comments()->updateOrCreate(
    ['body' => 'Requester confirmed the printer is still offline.'],
    [
        'author_user_id' => $worker->id,
        'is_internal' => false,
    ],
);
$emailTicket->comments()->updateOrCreate(
    ['body' => 'Internal technician diagnostic: do not expose to requester.'],
    [
        'author_user_id' => $admin->id,
        'is_internal' => true,
    ],
);

\App\Models\ItEmailDelivery::query()->updateOrCreate(
    ['notification_uuid' => '00000000-0000-4000-8000-000000000011'],
    [
        'it_ticket_id' => $emailTicket->id,
        'recipient_user_id' => $worker->id,
        'recipient_email' => $worker->email,
        'notification_type' => 'ticket_replied',
        'audience' => 'requester',
        'subject' => "Delivery failed for {$emailTicket->reference}",
        'provider' => 'acceptance-provider',
        'provider_message_id' => 'e2e-provider-message-11',
        'status' => 'bounced',
        'attempt_count' => 1,
        'retry_count' => 0,
        'last_error' => 'Mailbox rejected the acceptance message.',
        'queued_at' => now()->subMinutes(5),
        'accepted_at' => now()->subMinutes(4),
        'provider_status_at' => now()->subMinutes(3),
        'bounced_at' => now()->subMinutes(3),
    ],
);

$problemTicket = $ticket('E2E recurring WAN instability', [
    'description' => 'Recurring packet loss affects several managed site links.',
    'work_type' => 'problem',
    'workflow_state' => 'investigating',
    'next_action' => 'Confirm the underlying carrier fault',
]);
$problem = \App\Models\ItProblem::query()->updateOrCreate(
    ['ticket_id' => $problemTicket->id],
    [
        'impact_summary' => 'Intermittent service degradation across managed sites.',
        'workaround' => 'Fail affected traffic over to the secondary SD-WAN path.',
        'created_by_user_id' => $admin->id,
        'updated_by_user_id' => $admin->id,
    ],
);

$changeTicket = $ticket('E2E SD-WAN firmware change', [
    'description' => 'Upgrade managed gateways during the approved maintenance window.',
    'work_type' => 'change',
    'workflow_state' => 'draft',
    'status' => 'open',
    'next_action' => 'Review risk and approve the implementation plan',
]);
$change = \App\Models\ItChange::query()->updateOrCreate(
    ['ticket_id' => $changeTicket->id],
    [
        'change_type' => 'normal',
        'risk_level' => 'medium',
        'is_restricted' => false,
        'impact_summary' => 'Short site failover while each gateway restarts.',
        'implementation_plan' => 'Upgrade one gateway at a time and confirm service health.',
        'validation_plan' => 'Confirm monitored links, latency, and application reachability.',
        'backout_plan' => 'Restore the previous signed firmware image.',
        'maintenance_starts_at' => now()->addDay(),
        'maintenance_ends_at' => now()->addDay()->addHour(),
        'created_by_user_id' => $admin->id,
        'updated_by_user_id' => $admin->id,
    ],
);

$majorTicket = $ticket('E2E regional connectivity outage', [
    'description' => 'A regional carrier fault has affected multiple sites.',
    'work_type' => 'major_incident',
    'workflow_state' => 'declared',
    'status' => 'open',
    'priority' => 'urgent',
    'impact' => 'organization',
    'urgency' => 'critical',
    'next_action' => 'Publish the next stakeholder update',
]);
$major = \App\Models\ItMajorIncident::query()->updateOrCreate(
    ['ticket_id' => $majorTicket->id],
    [
        'severity' => 'sev2',
        'impact_summary' => 'Multiple sites cannot reach centrally hosted services.',
        'commander_user_id' => $admin->id,
        'communications_lead_user_id' => $admin->id,
        'target_update_minutes' => 30,
        'declared_at' => now()->subMinutes(10),
        'next_update_due_at' => now()->addMinutes(20),
        'created_by_user_id' => $admin->id,
        'updated_by_user_id' => $admin->id,
    ],
);

$profile = \App\Domain\Hr\Models\HrEmployeeProfile::withTrashed()
    ->where('user_id', $worker->id)
    ->first();
if ($profile === null) {
    $profile = \App\Domain\Hr\Models\HrEmployeeProfile::query()->create([
        'user_id' => $worker->id,
        'employee_number' => 'E2E-SW1',
        'work_email' => $worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);
} elseif ($profile->trashed()) {
    $profile->restore();
}

$acceptanceSite = \App\Models\Site::query()
    ->where('is_active', true)
    ->where('archived', false)
    ->whereNull('archived_at')
    ->orderBy('id')
    ->firstOrFail();
$adminProfile = \App\Domain\Hr\Models\HrEmployeeProfile::query()
    ->where('user_id', $admin->id)
    ->firstOrFail();
foreach ([$adminProfile, $profile] as $acceptanceProfile) {
    $acceptanceProfile->forceFill([
        'primary_site_id' => $acceptanceSite->id,
        'is_active' => true,
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ])->save();
}

foreach (['joiner', 'mover', 'leaver'] as $offset => $lifecycle) {
    $template = \App\Models\ItProvisioningTemplate::query()
        ->where('lifecycle_type', $lifecycle)
        ->first();
    \App\Models\ItProvisioningWorkflow::query()->updateOrCreate(
        ['source_event_key' => "e2e-acceptance-{$lifecycle}"],
        [
            'employee_profile_id' => $profile->id,
            'provisioning_template_id' => $template?->id,
            'lifecycle_type' => $lifecycle,
            'source_type' => 'acceptance_fixture',
            'source_id' => $offset + 1,
            'status' => $lifecycle === 'joiner' ? 'in_progress' : 'pending',
            'effective_at' => now()->addDays($offset),
            'role_snapshot' => $profile->position_role,
            'site_id_snapshot' => $acceptanceSite->id,
            'employment_type_snapshot' => $profile->employment_type,
            'changes' => $lifecycle === 'mover' ? ['position_role' => ['from' => 'support_worker', 'to' => 'senior_support_worker']] : null,
            'created_by_user_id' => $admin->id,
        ],
    );
}

$manifest = [
    'emailTicket' => ['id' => $emailTicket->id, 'reference' => $emailTicket->reference],
    'problem' => ['id' => $problem->id, 'reference' => $problemTicket->reference],
    'change' => ['id' => $change->id, 'reference' => $changeTicket->reference],
    'majorIncident' => ['id' => $major->id, 'reference' => $majorTicket->reference],
];
ob_end_clean();
echo '__IT_ACCEPTANCE_MANIFEST__'.json_encode($manifest, JSON_THROW_ON_ERROR);
`);
    const markerIndex = output.lastIndexOf(marker);
    if (markerIndex === -1) {
        throw new Error(`IT acceptance fixture failed:\n${output}`);
    }

    return JSON.parse(
        output.slice(markerIndex + marker.length),
    ) as ItAcceptanceManifest;
}

async function expectNoBlockingAxeViolations(page: Page, route: string) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    const blocking = results.violations.filter((violation) =>
        ['serious', 'critical'].includes(violation.impact ?? ''),
    );

    expect(
        blocking,
        `${route} axe blockers:\n${blocking
            .map(
                (violation) =>
                    `  - [${violation.impact}] ${violation.id}: ${violation.help} (${violation.nodes.length} nodes)`,
            )
            .join('\n')}`,
    ).toEqual([]);
}

async function expectNoPageOverflow(page: Page) {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

test.describe('IT & Support end-to-end acceptance', () => {
    let manifest: ItAcceptanceManifest;

    test.beforeAll(() => {
        manifest = seedItAcceptanceFixtures();
    });

    test('requester completes self-service and sees only the public email thread', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        const errors = collectConsoleErrors(page);

        await loginAs(page, 'sw1@demo.test');
        await page.goto('/it?tab=catalog');
        await expect(
            page.getByRole('heading', { name: 'Service catalogue', level: 2 }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Request managed network access' })
            .click();
        const dialog = page.getByRole('dialog');
        await dialog
            .getByLabel('Business reason')
            .fill(
                'A support worker needs approved connectivity at the managed site.',
            );
        await dialog.getByRole('button', { name: 'Submit request' }).click();
        await expect(dialog).toBeHidden();

        await page.goto('/it?tab=my-tickets');
        await expect(
            page
                .getByText('Request managed network access', { exact: true })
                .first(),
        ).toBeVisible();
        await expect(
            page.getByText('E2E inbound email printer incident', {
                exact: true,
            }),
        ).toBeVisible();

        await page.goto('/it?tab=knowledge');
        await expect(
            page.getByRole('button', { name: /Restore site connectivity/ }),
        ).toBeVisible();

        await page.goto(`/it/tickets/${manifest.emailTicket.id}`);
        await expect(
            page.getByRole('heading', {
                name: 'E2E inbound email printer incident',
                level: 1,
            }),
        ).toBeVisible();
        await expect(page.getByText('via email')).toBeVisible();
        await expect(
            page.getByText('Requester confirmed the printer is still offline.'),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Internal technician diagnostic: do not expose to requester.',
            ),
        ).toHaveCount(0);
        await expectNoPageOverflow(page);
        expectNoConsoleErrors(errors);

        const setupResponse = await page.goto('/it/setup');
        expect(setupResponse?.status()).toBe(403);
    });

    test('technician can operate incident, problem, change, major incident, JML, API, and delivery workspaces', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto(`/it/tickets/${manifest.emailTicket.id}`);
        await expect(
            page.getByRole('heading', {
                name: 'E2E inbound email printer incident',
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Internal technician diagnostic: do not expose to requester.',
            ),
        ).toBeVisible();

        await page.goto(`/it/problems/${manifest.problem.id}`);
        await expect(
            page.getByRole('heading', {
                name: 'E2E recurring WAN instability',
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByText('Fail affected traffic over'),
        ).toBeVisible();

        await page.goto(`/it/changes/${manifest.change.id}`);
        await expect(
            page.getByRole('heading', {
                name: 'E2E SD-WAN firmware change',
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Risk, impact, and plans' }),
        ).toBeVisible();

        await page.goto(`/it/major-incidents/${manifest.majorIncident.id}`);
        await expect(
            page.getByRole('heading', {
                name: 'E2E regional connectivity outage',
                level: 1,
            }),
        ).toBeVisible();
        await expect(page.getByText('SEV2', { exact: true })).toBeVisible();

        await page.goto('/it?tab=provisioning');
        await expect(
            page.getByRole('heading', {
                name: 'Joiner, mover & leaver workflows',
                level: 2,
            }),
        ).toBeVisible();
        for (const lifecycle of ['Joiner', 'Mover', 'Leaver']) {
            await expect(
                page.getByText(lifecycle, { exact: true }),
            ).toBeVisible();
        }

        await page.goto('/it/setup');
        await page.getByRole('tab', { name: 'API identities' }).click();
        await expect(
            page.getByRole('heading', { name: 'API identities', level: 2 }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'New API identity' }).click();
        await expect(page.getByLabel('Execution account')).toBeVisible();
        await page.getByRole('button', { name: 'Cancel' }).click();
        await page.getByRole('tab', { name: 'Operations audit' }).click();
        await expect(
            page.getByRole('heading', { name: 'Email delivery', level: 2 }),
        ).toBeVisible();
        await expect(
            page.getByText(
                `Delivery failed for ${manifest.emailTicket.reference}`,
            ),
        ).toBeVisible();
        await expect(page.getByText('Mailbox rejected')).toBeVisible();

        await expectNoPageOverflow(page);
        expectNoConsoleErrors(errors);
    });

    test('core service-management routes have no serious or critical accessibility violations', async ({
        page,
    }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'The route accessibility matrix runs once on desktop.',
        );
        test.setTimeout(180_000);
        await loginAsStaff(page);

        const routes = [
            '/it',
            '/it?tab=tickets',
            `/it/tickets/${manifest.emailTicket.id}`,
            '/it?tab=catalog',
            '/it?tab=knowledge',
            `/it/problems/${manifest.problem.id}`,
            `/it/changes/${manifest.change.id}`,
            `/it/major-incidents/${manifest.majorIncident.id}`,
            '/it?tab=provisioning',
            '/it/setup',
        ];

        for (const route of routes) {
            await page.goto(route);
            await expect(page.locator('#main-content')).toBeVisible();
            await expectNoBlockingAxeViolations(page, route);
        }
    });
});
