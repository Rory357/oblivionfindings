import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChecksStudio } from './checks-studio';
import type { VehicleChecks } from './checks-types';
import { ReportProblemDialog } from './report-problem';
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
    work: { can_view: true, open_count: 0, open: [], active_restrictions: 0 },
    can: { manage: true, manage_documents: true },
} as unknown as VehicleWorkspace;

const data: VehicleChecks = {
    requirement: {
        template_id: 7,
        source: 'vehicle',
        due_on: '2099-09-24',
        owner: { id: 5, name: 'Alex Morgan' },
        lock_version: 2,
    },
    templates: [
        {
            id: 7,
            version_id: 21,
            version: 3,
            name: 'Vehicle condition record',
            use: 'Before vehicle use',
            assignment: 'all_vehicles',
            assignment_label: 'All vehicles',
            evidence_required: false,
            items_sha256: 'b'.repeat(64),
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
            ],
            source: 'library',
            published_at: null,
            published_by: null,
            rule_version_id: null,
        },
    ],
    runs: {
        data: [
            {
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
                notes: null,
                answers: [],
                files: [],
                evidence_count: 0,
                amendments: [],
                linked: false,
                linked_work: null,
            },
        ],
        total: 1,
    },
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
        manage_requirement: true,
        upload: true,
        report: true,
        link_work: true,
        view_maintenance: true,
        view_files: true,
        view_answer_files: true,
    },
};

type Call = { url: string; method: string; body: unknown };

/** GETs answer with the checks payload; commands answer with `result`. */
function stubServer(result: Record<string, unknown>) {
    const calls: Call[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            const method = init?.method ?? 'GET';
            calls.push({ url, method, body: init?.body });
            return {
                ok: true,
                status: 200,
                json: async () => (method === 'GET' ? data : result),
            };
        }),
    );
    return calls;
}

const commands = (calls: Call[]) =>
    calls.filter((call) => call.method !== 'GET');

async function openStudio(view: 'recent' | 'templates') {
    render(
        <ChecksStudio workspace={workspace} view={view} onChanged={vi.fn()} />,
    );
    await screen.findByText('NEXT REQUIREMENT');
}

describe('check dialogs', () => {
    it('reports a problem from a check with its source and routing', async () => {
        const calls = stubServer({
            work_order: { id: 12, reference: 'WO-0012', title: 'Concern' },
            linked: false,
        });
        render(
            <ReportProblemDialog
                workspace={workspace}
                checks={data}
                source={{
                    id: 182,
                    reference: 'CHK-182',
                    template: 'Vehicle condition record',
                    version: 3,
                }}
                onClose={vi.fn()}
                onChanged={vi.fn()}
            />,
        );

        expect(screen.getByText('CHK-182')).toBeVisible();
        expect(
            screen.getByText(
                'Not known yet · no estimated dates will be recorded.',
            ),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            await screen.findByText('No open work for this vehicle'),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            await screen.findByText('Jamie Taylor · Kōwhai House Coordinator'),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Confirm report' }));

        expect(await screen.findByText('Report linked')).toBeVisible();
        expect(
            screen.getByRole('button', { name: /Review WO-0012/ }),
        ).toBeVisible();
        const [command] = commands(calls);
        expect(command.url).toBe(
            '/fleet-assets/vehicles/14/maintenance-reports',
        );
        expect(JSON.parse(String(command.body))).toMatchObject({
            title: 'Condition concern from vehicle check',
            source_run_id: 182,
            existing_work_order_id: null,
            estimated_start_date: null,
        });
    });

    it('publishes a checklist change as the next version', async () => {
        const calls = stubServer({
            template: { id: 7, version_id: 22, version: 4, name: 'x' },
        });
        await openStudio('templates');

        fireEvent.click(screen.getByRole('button', { name: /Customise/ }));
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByLabelText('Checklist name')).toHaveValue(
            'Vehicle condition record',
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
        fireEvent.click(
            await within(dialog).findByRole('button', { name: /Add question/ }),
        );
        fireEvent.change(within(dialog).getByLabelText('Question 2'), {
            target: { value: 'Cabin notes' },
        });
        fireEvent.change(within(dialog).getByLabelText('Answer type 2'), {
            target: { value: 'text' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
        fireEvent.click(
            await within(dialog).findByRole('button', {
                name: 'Publish version',
            }),
        );
        expect(
            await within(dialog).findByText(
                'Confirm that this version should be published for new checks.',
            ),
        ).toBeVisible();
        expect(commands(calls)).toHaveLength(0);
        fireEvent.click(
            within(dialog).getByLabelText(
                /Publish this version for new checks/,
            ),
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Publish version' }),
        );

        expect(
            await screen.findByText('Checklist version published'),
        ).toBeVisible();
        const [command] = commands(calls);
        expect(command.url).toBe(
            '/fleet-assets/vehicles/14/check-templates/7/versions',
        );
        const sent = JSON.parse(String(command.body));
        expect(sent).toMatchObject({
            expected_version_id: 21,
            expected_items_sha256: 'b'.repeat(64),
            confirmed: true,
        });
        expect(sent.questions).toHaveLength(2);
        expect(sent.questions[1]).toMatchObject({
            label: 'Cabin notes',
            kind: 'text',
        });
    });

    it('saves the check requirement with its expected version', async () => {
        const calls = stubServer({
            requirement: { template_id: 7, owner_user_id: 5, lock_version: 3 },
        });
        await openStudio('recent');

        fireEvent.click(
            screen.getByRole('button', { name: 'Manage requirement' }),
        );
        const dialog = await screen.findByRole('dialog');
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
        fireEvent.click(
            await within(dialog).findByRole('button', {
                name: 'Save check plan',
            }),
        );

        expect(
            await screen.findByText('Check requirement saved'),
        ).toBeVisible();
        const [command] = commands(calls);
        expect(command.method).toBe('PUT');
        expect(command.url).toBe('/fleet-assets/vehicles/14/check-requirement');
        expect(JSON.parse(String(command.body))).toEqual({
            template_id: 7,
            due_on: '2099-09-24',
            owner_user_id: 5,
            expected_version: 2,
        });
    });

    it('records an amendment beside the original check', async () => {
        const calls = stubServer({ amendment: { id: 3, note: 'x' } });
        await openStudio('recent');

        fireEvent.click(screen.getByRole('button', { name: 'Add amendment' }));
        const dialog = await screen.findByRole('dialog');
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
        expect(
            await within(dialog).findByText(
                'Record the amendment and its reason.',
            ),
        ).toBeVisible();
        fireEvent.change(
            within(dialog).getByLabelText('Amendment and reason'),
            {
                target: { value: 'Scratch was reported last week.' },
            },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
        fireEvent.click(
            await within(dialog).findByRole('button', {
                name: 'Record amendment',
            }),
        );

        expect(await screen.findByText('Amendment recorded')).toBeVisible();
        const [command] = commands(calls);
        expect(command.url).toBe(
            '/fleet-assets/vehicles/14/checks/182/amendments',
        );
        expect(JSON.parse(String(command.body))).toEqual({
            note: 'Scratch was reported last week.',
        });
    });

    it('keeps added evidence with the check as a vehicle document', async () => {
        const calls = stubServer({
            set: { id: 4 },
            files: [{ id: 9, name: 'door.pdf', state: 'available' }],
        });
        await openStudio('recent');

        fireEvent.click(
            screen.getByRole('button', { name: /Upload evidence/ }),
        );
        const dialog = await screen.findByRole('dialog');
        const input =
            dialog.querySelector<HTMLInputElement>('input[type="file"]');
        expect(input).not.toBeNull();
        fireEvent.change(input as HTMLInputElement, {
            target: {
                files: [
                    new File(['%PDF-1.4'], 'door.pdf', {
                        type: 'application/pdf',
                    }),
                ],
            },
        });
        expect(within(dialog).getByText('Selected · not saved')).toBeVisible();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Save evidence' }),
        );

        expect(await within(dialog).findByText('Saved')).toBeVisible();
        const [command] = commands(calls);
        expect(command.url).toBe('/fleet-assets/vehicles/14/documents');
        const body = command.body as FormData;
        expect(body.get('source_type')).toBe('checklist_run');
        expect(body.get('source_id')).toBe('182');
        expect(body.get('category')).toBe('Check evidence');
        expect((body.get('files[]') as File).name).toBe('door.pdf');
    });
});
