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
            manage_requirement: true,
            upload: true,
            report: true,
            link_work: true,
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
