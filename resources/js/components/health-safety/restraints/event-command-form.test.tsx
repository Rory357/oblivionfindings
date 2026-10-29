import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { RestraintEventCommandForm } from './event-command-form';
import type { RestraintEventDetail } from './types';

const command = vi.hoisted(() => ({
    save: vi.fn(),
    settings: {} as Record<string, unknown>,
}));
vi.mock('./use-source-command', async () => {
    const React = await import('react');
    return {
        useSourceCommand: ({
            initial,
            ...settings
        }: {
            initial: Record<string, unknown>;
        }) => {
            command.settings = settings;
            const [data, setData] = React.useState(initial);
            return {
                form: { data, errors: {}, processing: false },
                change: (field: string, value: unknown) =>
                    setData((before) => ({ ...before, [field]: value })),
                save: command.save,
                guard: {},
                current: null,
                stale: false,
                checking: false,
                checkFailed: false,
                reviewAvailable: false,
                checkedResponse: null,
                allowed: true,
                receipt: null,
                confirmedHref: null,
                failure: null,
                checkCurrent: vi.fn(),
                useCurrent: vi.fn(),
            };
        },
    };
});
vi.mock('./use-capture-choices', async () => {
    const { EMPTY_RESTRAINT_PICKERS } = await import('./types');
    return {
        useCaptureChoices: () => ({
            pickers: EMPTY_RESTRAINT_PICKERS,
            ready: true,
            checking: false,
            failure: null,
            select: vi.fn(),
        }),
    };
});
vi.mock('@/components/health-safety/hazard-staff-picker', () => ({
    HazardStaffPicker: ({
        value,
        onChange,
        options,
        placeholder,
    }: {
        value: string;
        onChange: (value: string) => void;
        options: { value: string; label: string }[];
        placeholder: string;
    }) => (
        <select
            aria-label={placeholder}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">Choose</option>
            {options.map((item) => (
                <option key={item.value} value={item.value}>
                    {item.label}
                </option>
            ))}
        </select>
    ),
}));
beforeEach(() => {
    command.save.mockReset();
    command.settings = {};
});
afterEach(cleanup);
function detail(): RestraintEventDetail {
    return {
        can: { review: true, capture: true, manage: true },
        connections: {},
        event: {
            id: 9,
            lock_version: 4,
            source_revision: 4,
            client_id: 12,
            site_id: 3,
            client: { id: 12, name: 'Aroha Rangi' },
            site: { id: 3, name: 'Harbour House' },
            restraint_type: 'physical',
            severity: 'high',
            started_at: '2026-10-11T23:45:07.123Z',
            ended_at: null,
            duration_minutes: null,
            restraint_description: 'Recorded intervention.',
            within_support_plan: false,
            staff_involved: [
                { kind: 'historical_text', text: 'Historical recorded team' },
            ],
            authorised_by: {
                id: 31,
                name: null,
                recorded_name: 'Historical authoriser',
                visibility: 'identity_retained',
            },
            observations: [
                {
                    id: 7,
                    occurred_at: '2026-10-11T23:48:09.123Z',
                    evidence_ids: [11],
                    actual_actor: {
                        id: 31,
                        name: null,
                        recorded_name: 'Historical observer',
                        visibility: 'identity_retained',
                    },
                    facts: {
                        condition: 'Person alert.',
                        response: 'Quiet support provided.',
                        next_check_at: '2026-10-12T00:03:10.123Z',
                    },
                },
            ],
            debriefs: [],
            reviews: [],
            followups: [],
            attachments: [
                {
                    id: 11,
                    original_name: 'Observed evidence.pdf',
                    removed_at: null,
                },
            ],
            deliveries: [],
        },
    } as unknown as RestraintEventDetail;
}
function open(action: 'correct' | 'correct-observation') {
    return render(
        <RestraintEventCommandForm
            detail={detail()}
            command={action}
            childId={action === 'correct-observation' ? 7 : undefined}
            returnTo="/respite/stays/21"
            pickers={{ staff: [{ id: 32, name: 'Current participant' }] }}
            onDone={vi.fn()}
            onCancel={vi.fn()}
        />,
    );
}
it('leaves historical staff and authoriser facts untouched during a factual narrative correction and preserves unchanged timestamp precision', () => {
    const { container } = open('correct');
    fireEvent.submit(container.querySelector('form')!);
    const values = command.save.mock.calls[0][2];
    expect(values).not.toHaveProperty('staff_involved');
    expect(values).not.toHaveProperty('authorised_by');
    expect(values).not.toHaveProperty('started_at');
    expect(values).not.toHaveProperty('ended_at');
    expect(values.return_to).toBe('/respite/stays/21');
    expect(command.save.mock.calls[0].slice(0, 2)).toEqual([
        'post',
        '/health-safety/restraints/events/9/corrections',
    ]);
});
it('binds a corrected observation receipt to its predecessor while retaining actual observer, evidence and unchanged instants', () => {
    const { container } = open('correct-observation');
    expect(command.settings.childKey).toBe('supersedes_id');
    expect(command.settings.childId).toBe(7);
    fireEvent.submit(container.querySelector('form')!);
    const values = command.save.mock.calls[0][2];
    expect(values).not.toHaveProperty('observed_by');
    expect(values.evidence_ids).toEqual([11]);
    expect(values.observed_at).toBe('2026-10-11T23:48:09.123Z');
    expect(values.next_check_at).toBe('2026-10-12T00:03:10.123Z');
    expect(values.condition).toBe('Person alert.');
    expect(screen.getByText(/The person who made the check stays on/)).toBeVisible();
});
it('requires a selected current participant before explicitly replacing the actual observer', () => {
    const { container } = open('correct-observation');
    fireEvent.click(
        screen.getByRole('checkbox', { name: 'Change who made the check' }),
    );
    expect(
        screen.getByRole('button', { name: 'Save correction' }),
    ).toBeDisabled();
    fireEvent.submit(container.querySelector('form')!);
    expect(command.save).not.toHaveBeenCalled();
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Who made the check' }),
        { target: { value: '32' } },
    );
    fireEvent.submit(container.querySelector('form')!);
    expect(command.save.mock.calls[0][2].observed_by).toBe(32);
});
it('restores preservation when the operator cancels an explicit observer replacement', () => {
    const { container } = open('correct-observation');
    const replace = screen.getByRole('checkbox', {
        name: 'Change who made the check',
    });
    fireEvent.click(replace);
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Who made the check' }),
        { target: { value: '32' } },
    );
    fireEvent.click(replace);
    fireEvent.submit(container.querySelector('form')!);
    expect(command.save.mock.calls[0][2]).not.toHaveProperty('observed_by');
});
