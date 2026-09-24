import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CheckFlowDialog } from './check-flow';
import { ChecksStudio } from './checks-studio';
import type { CheckRun, CheckTemplate, VehicleChecks } from './checks-types';
import type { VehicleWorkspace } from './types';

afterEach(() => {
    cleanup();
    window.localStorage.clear();
    vi.unstubAllGlobals();
});

const workspace = {
    vehicle: {
        id: 14,
        name: 'Kōwhai van',
        asset_tag: 'VH-014',
        registration_number: 'KWH014',
        site: { id: 3, name: 'Kōwhai House' },
    },
    as_of: '2026-09-22T09:30:00+12:00',
    people: [{ id: 5, name: 'Alex Morgan' }],
    work: {
        can_view: true,
        open_count: 0,
        open: [],
        active_restrictions: 0,
    },
    readiness: {
        status: 'blocked',
        can_proceed: false,
        reasons: [],
        restriction_ids: [],
        check_run_ids: [182, 190],
    },
    can: { manage: true, manage_documents: true },
} as unknown as VehicleWorkspace;

const template: CheckTemplate = {
    id: 7,
    version_id: 21,
    version: 3,
    name: 'Vehicle condition record',
    use: 'Before vehicle use',
    assignment: 'all_vehicles',
    assignment_label: 'All vehicles',
    evidence_required: false,
    items_sha256: 'a'.repeat(64),
    questions: [
        {
            id: 'exterior',
            label: 'Exterior condition',
            kind: 'condition',
            required: true,
            options: [
                { value: 'pass', label: 'No issue recorded' },
                { value: 'fail', label: 'Issue recorded' },
                { value: 'unable', label: 'Unable to assess' },
            ],
        },
        {
            id: 'odometer',
            label: 'Odometer reading',
            kind: 'number',
            required: false,
            options: [],
        },
    ],
    source: 'library',
    published_at: '2026-09-20T01:00:00+00:00',
    published_by: 'Alex Morgan',
    rule_version_id: null,
};

const run: CheckRun = {
    id: 182,
    reference: 'CHK-182',
    template: 'Vehicle condition record',
    template_id: 7,
    version: 3,
    outcome: 'failed',
    check_kind: 'check',
    rule_applied: true,
    observed_at: '2026-09-20T20:10:00+00:00',
    submitted_at: '2026-09-20T20:14:00+00:00',
    recorded_by: 'Alex Morgan',
    notes: 'A condition concern was recorded for assessment.',
    answers: [
        {
            id: 'exterior',
            label: 'Exterior condition',
            value: 'Issue recorded',
            note: null,
            evidence: null,
        },
    ],
    files: [
        {
            id: 91,
            name: 'rear-door.jpg',
            state: 'available',
            url: '/fleet-assets/vehicles/14/documents/91/file',
        },
    ],
    evidence_count: 1,
    amendments: [],
    linked: false,
    linked_work: null,
    blocking: true,
    assessment: null,
    assess: {
        available: false,
        reason: 'An approved check rule recorded a failure. Report it to Maintenance for repair, a retest and an independent release.',
        issues: [
            {
                id: 'exterior',
                label: 'Exterior condition',
                value: 'Issue recorded',
            },
        ],
        needs_independent: true,
    },
};

/** A check no approved rule covers: it holds the vehicle until assessed. */
const unruled: CheckRun = {
    ...run,
    id: 190,
    reference: 'CHK-190',
    outcome: 'needs_assessment',
    rule_applied: false,
    recorded_by: 'Sam Rewi',
    answers: [
        {
            id: 'exterior',
            label: 'Exterior condition',
            value: 'Unable to assess',
            note: null,
            evidence: null,
        },
    ],
    files: [],
    evidence_count: 0,
    assess: {
        available: true,
        reason: null,
        issues: [
            {
                id: 'exterior',
                label: 'Exterior condition',
                value: 'Unable to assess',
            },
        ],
        needs_independent: true,
    },
};

function checks(overrides: Partial<VehicleChecks> = {}): VehicleChecks {
    return {
        requirement: {
            template_id: 7,
            source: 'vehicle',
            due_on: '2099-09-24',
            owner: { id: 5, name: 'Alex Morgan' },
            lock_version: 2,
        },
        templates: [template],
        runs: { data: [run], total: 1 },
        route: {
            approved: true,
            coordinator: 'Jamie Taylor',
            backup: 'Sam Rewi',
            site: 'Kōwhai House',
        },
        can: {
            start: true,
            amend: true,
            manage_templates: true,
            manage_shared_templates: true,
            manage_requirement: true,
            upload: true,
            report: true,
            link_work: true,
            assess: true,
            view_maintenance: true,
            view_files: true,
            view_answer_files: true,
        },
        ...overrides,
    };
}

function stubChecks(payload: VehicleChecks) {
    const fetchMock = vi.fn(async () => ({
        ok: true,
        status: 200,
        json: async () => payload,
    }));
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

describe('ChecksStudio', () => {
    it('shows the next requirement and the original observations', async () => {
        stubChecks(checks());
        render(
            <ChecksStudio
                workspace={workspace}
                view="recent"
                onChanged={vi.fn()}
            />,
        );

        expect(await screen.findByText('ORIGINAL OBSERVATIONS')).toBeVisible();
        expect(screen.getByText('NEXT REQUIREMENT')).toBeVisible();
        expect(screen.getByText('24 Sep 2099')).toBeVisible();
        expect(screen.getByText('Scheduled')).toBeVisible();
        expect(
            screen.getByText('Version 3 · latest published version'),
        ).toBeVisible();
        expect(screen.getByText('CHK-182')).toBeVisible();
        expect(screen.getByText('Failed')).toBeVisible();
        expect(screen.getByText('1 evidence file')).toBeVisible();
        expect(screen.getByText('Template version 3')).toBeVisible();
        expect(
            screen.getByRole('button', { name: /Upload evidence/ }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Manage requirement' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Start a retest' }),
        ).toBeEnabled();
    });

    it('disables actions the person cannot take', async () => {
        const readOnly = checks();
        readOnly.can = {
            ...readOnly.can,
            start: false,
            amend: false,
            upload: false,
            manage_requirement: false,
        };
        stubChecks(readOnly);
        render(
            <ChecksStudio
                workspace={workspace}
                view="recent"
                onChanged={vi.fn()}
            />,
        );

        expect(
            await screen.findByRole('button', { name: /Start check/ }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: /Upload evidence/ }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Add amendment' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Manage requirement' }),
        ).toBeDisabled();
    });

    it('opens the submitted check with its original answers', async () => {
        stubChecks(checks());
        render(
            <ChecksStudio
                workspace={workspace}
                view="recent"
                onChanged={vi.fn()}
            />,
        );

        fireEvent.click(await screen.findByText('CHK-182'));
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText('Answers as submitted')).toBeVisible();
        expect(within(dialog).getByText('Issue recorded')).toBeVisible();
        expect(
            within(dialog).getByRole('button', {
                name: 'Create or link maintenance',
            }),
        ).toBeVisible();
    });

    it('lists the controlled template library with search', async () => {
        stubChecks(checks());
        render(
            <ChecksStudio
                workspace={workspace}
                view="templates"
                onChanged={vi.fn()}
            />,
        );

        expect(await screen.findByText('CONTROLLED TEMPLATES')).toBeVisible();
        expect(screen.getByText('Before vehicle use')).toBeVisible();
        expect(screen.getByText('2 questions')).toBeVisible();
        expect(
            screen.getByRole('button', { name: /Create checklist/ }),
        ).toBeEnabled();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search checklist library' }),
            { target: { value: 'nothing like it' } },
        );
        expect(screen.getByText('No matching checklists')).toBeVisible();
    });
});

describe('CheckFlowDialog', () => {
    it('records answers against the checklist version and offers a report for an issue', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                run: {
                    id: 183,
                    reference: 'CHK-183',
                    outcome: 'needs_assessment',
                },
                files: [],
            }),
        }));
        vi.stubGlobal('fetch', fetchMock);
        const onChanged = vi.fn();
        render(
            <CheckFlowDialog
                workspace={workspace}
                checks={checks()}
                onClose={vi.fn()}
                onChanged={onChanged}
            />,
        );

        expect(screen.getByText('Approved rules unavailable')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            await screen.findByText(
                'Complete required answers and required evidence before review.',
            ),
        ).toBeVisible();
        fireEvent.change(screen.getByLabelText(/Exterior condition/), {
            target: { value: 'fail' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            await screen.findByText('Submission records the check'),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Submit check' }));

        expect(await screen.findByText('Check recorded')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Create or link maintenance' }),
        ).toBeVisible();
        expect(onChanged).toHaveBeenCalled();
        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];
        expect(url).toBe('/fleet-assets/vehicles/14/checks');
        const body = init.body as FormData;
        expect(body.get('template_id')).toBe('7');
        expect(body.get('template_version_id')).toBe('21');
        expect(body.get('items_sha256')).toBe('a'.repeat(64));
        expect(body.get('answers[exterior]')).toBe('fail');
        expect(body.get('answers[odometer]')).toBeNull();
        expect(body.get('rule_version_id')).toBeNull();
        await waitFor(() =>
            expect(
                (init.headers as Record<string, string>)['Idempotency-Key'],
            ).toBeTruthy(),
        );
    });
});

type Call = { url: string; method: string; body: unknown; headers: unknown };

/** GETs answer with the checks payload; commands answer with `result`. */
function stubServer(payload: VehicleChecks, result: Record<string, unknown>) {
    const calls: Call[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            const method = init?.method ?? 'GET';
            calls.push({
                url,
                method,
                body: init?.body,
                headers: init?.headers,
            });
            return {
                ok: true,
                status: 200,
                json: async () => (method === 'GET' ? payload : result),
            };
        }),
    );
    return calls;
}

const both = () => checks({ runs: { data: [run, unruled], total: 2 } });

function rowOf(reference: string): HTMLElement {
    const row = screen.getByText(reference).closest('[role="row"]');
    expect(row).not.toBeNull();
    return row as HTMLElement;
}

describe('No issue found — release for use', () => {
    it('is offered only for a check Maintenance can release, in the row menu and on the check', async () => {
        stubServer(both(), {});
        render(
            <ChecksStudio
                workspace={workspace}
                view="recent"
                onChanged={vi.fn()}
            />,
        );

        await screen.findByText('CHK-190');
        fireEvent.contextMenu(rowOf('CHK-190'));
        expect(
            within(screen.getByRole('menu')).getByRole('menuitem', {
                name: 'No issue found — release for use',
            }),
        ).toBeVisible();
        fireEvent.keyDown(window, { key: 'Escape' });
        fireEvent.contextMenu(rowOf('CHK-182'));
        expect(
            within(screen.getByRole('menu')).queryByRole('menuitem', {
                name: 'No issue found — release for use',
            }),
        ).toBeNull();
        fireEvent.keyDown(window, { key: 'Escape' });

        // A check an approved rule failed explains the route instead.
        fireEvent.click(screen.getByText('CHK-182'));
        let dialog = await screen.findByRole('dialog');
        expect(
            within(dialog).getByText('This check stops the vehicle being used'),
        ).toBeVisible();
        expect(
            within(dialog).getByText(
                /An approved check rule recorded a failure/,
            ),
        ).toBeVisible();
        expect(
            within(dialog).queryByRole('button', { name: /No issue found/ }),
        ).toBeNull();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Back to vehicle' }),
        );

        fireEvent.click(screen.getByText('CHK-190'));
        dialog = await screen.findByRole('dialog');
        fireEvent.click(
            within(dialog).getByRole('button', { name: /No issue found/ }),
        );
        expect(
            await screen.findByRole('dialog', {
                name: 'No issue found — release for use',
            }),
        ).toBeVisible();
    });

    it('needs a reason and confirmation, shows the recorded issues and saves the decision', async () => {
        const calls = stubServer(both(), {
            assessment: { id: 3, run_id: 190, decision: 'no_issue_release' },
            vehicle_ready: true,
        });
        const onChanged = vi.fn();
        render(
            <ChecksStudio
                workspace={workspace}
                view="recent"
                onChanged={onChanged}
            />,
        );

        await screen.findByText('CHK-190');
        fireEvent.contextMenu(rowOf('CHK-190'));
        fireEvent.click(
            screen.getByRole('menuitem', {
                name: 'No issue found — release for use',
            }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'No issue found — release for use',
        });
        const issues = within(dialog).getByRole('list', {
            name: 'Recorded issues',
        });
        expect(within(issues).getByText('Exterior condition')).toBeVisible();
        expect(within(issues).getByText('Unable to assess')).toBeVisible();
        // Another check still holds the vehicle.
        expect(
            within(dialog).getByText('Other holds stay in force'),
        ).toBeVisible();

        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Release for use' }),
        );
        expect(
            await within(dialog).findByText(
                'Record why the vehicle is safe to use.',
            ),
        ).toBeVisible();
        expect(
            within(dialog).getByText(
                'Confirm that you assessed this check and found nothing that stops safe use.',
            ),
        ).toBeVisible();
        expect(calls.filter((call) => call.method !== 'GET')).toHaveLength(0);

        fireEvent.change(within(dialog).getByLabelText('Reason'), {
            target: {
                value: '  Checked the panel myself; the sensor was dirty.  ',
            },
        });
        fireEvent.click(
            within(dialog).getByRole('checkbox', {
                name: 'I assessed this check and found nothing that stops safe use.',
            }),
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Release for use' }),
        );

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        const [command] = calls.filter((call) => call.method !== 'GET');
        expect(command.url).toBe(
            '/fleet-assets/vehicles/14/checks/190/assessments',
        );
        expect(JSON.parse(String(command.body))).toEqual({
            decision: 'no_issue_release',
            reason: 'Checked the panel myself; the sensor was dirty.',
            confirmed: true,
        });
        expect(
            (command.headers as Record<string, string>)['Idempotency-Key'],
        ).toBeTruthy();
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', {
                    name: 'No issue found — release for use',
                }),
            ).toBeNull(),
        );
    });

    it('asks for the vehicle’s own evidence first and blocks the release until then', async () => {
        stubServer(both(), {});
        const blocked = {
            ...workspace,
            readiness: {
                ...workspace.readiness,
                reasons: [
                    {
                        code: 'compliance.wof.expired',
                        message: 'WoF: recorded evidence has expired.',
                        scope: 'vehicle',
                        kind: 'wof',
                        source_id: 4,
                        version_id: 9,
                        blocks_decision: true,
                    },
                    {
                        code: 'maintenance.unresolved_check',
                        message: 'A vehicle check needs assessment or repair.',
                        scope: 'vehicle',
                        kind: null,
                        source_id: 190,
                        version_id: null,
                        blocks_decision: true,
                    },
                ],
                check_run_ids: [190],
            },
        } as VehicleWorkspace;
        render(
            <ChecksStudio
                workspace={blocked}
                view="recent"
                onChanged={vi.fn()}
            />,
        );

        await screen.findByText('CHK-190');
        fireEvent.contextMenu(rowOf('CHK-190'));
        fireEvent.click(
            screen.getByRole('menuitem', {
                name: 'No issue found — release for use',
            }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'No issue found — release for use',
        });
        expect(within(dialog).getByText('Resolve these first')).toBeVisible();
        expect(
            within(dialog).getByText('WoF: recorded evidence has expired.'),
        ).toBeVisible();
        // The check itself isn't listed as something to resolve first.
        expect(
            within(dialog).queryByText(
                'A vehicle check needs assessment or repair.',
            ),
        ).toBeNull();
        expect(
            within(dialog).queryByText('Other holds stay in force'),
        ).toBeNull();
        expect(
            within(dialog).getByRole('button', { name: 'Release for use' }),
        ).toBeDisabled();
    });

    it('shows the recorded decision beside the original outcome', async () => {
        const released: CheckRun = {
            ...unruled,
            blocking: false,
            assess: null,
            assessment: {
                id: 3,
                decision: 'no_issue_release',
                label: 'No issue found — released for use',
                reason: 'Checked the panel myself; the sensor was dirty.',
                assessed_by: 'Jamie Taylor',
                assessed_at: '2026-09-22T01:00:00+00:00',
                self_assessed: false,
                issues: ['Exterior condition'],
            },
        };
        stubServer(checks({ runs: { data: [released], total: 1 } }), {});
        render(
            <ChecksStudio
                workspace={workspace}
                view="recent"
                onChanged={vi.fn()}
            />,
        );

        await screen.findByText('CHK-190');
        expect(screen.getByText('Needs assessment')).toBeVisible();
        expect(screen.getByText('No issue found')).toBeVisible();
        fireEvent.contextMenu(rowOf('CHK-190'));
        expect(
            within(screen.getByRole('menu')).queryByRole('menuitem', {
                name: 'No issue found — release for use',
            }),
        ).toBeNull();
        fireEvent.keyDown(window, { key: 'Escape' });

        fireEvent.click(screen.getByText('CHK-190'));
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText('Maintenance decision')).toBeVisible();
        expect(
            within(dialog).getByText(
                'Checked the panel myself; the sensor was dirty.',
            ),
        ).toBeVisible();
        expect(
            within(dialog).getAllByText('No issue found').length,
        ).toBeGreaterThan(0);
    });

    it('opens the check a readiness link names, once', async () => {
        stubServer(both(), {});
        const onFocusHandled = vi.fn();
        render(
            <ChecksStudio
                workspace={workspace}
                view="recent"
                focusRunId={190}
                onFocusHandled={onFocusHandled}
                onChanged={vi.fn()}
            />,
        );

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText('Check CHK-190')).toBeVisible();
        expect(onFocusHandled).toHaveBeenCalledTimes(1);
    });
});
