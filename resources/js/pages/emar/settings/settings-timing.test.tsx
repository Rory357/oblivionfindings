import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type SettingsContext } from './_context';
import {
    canRestore,
    changes,
    format,
    loosens,
    stillToDecide,
    validateView,
    withDraft,
    type Draft,
    type HistoryEntry,
    type SettingDefinition,
    type SettingsPayload,
} from './_model';
import { DoseTiming, RoundsOverview } from './_timing';

vi.mock('@inertiajs/react', () => ({
    router: { put: vi.fn(), post: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}));

const looser = {
    direction: 'higher_is_looser' as const,
    off: null,
    off_is_loosest: false,
};
const minutes = (
    key: string,
    label: string,
    unit: string,
    dflt: string,
    over: Partial<SettingDefinition> = {},
): SettingDefinition => ({
    group: 'timing',
    key,
    scope: 'organisation',
    section: 'timing',
    label,
    options: [],
    default: dflt,
    rank: null,
    numeric: looser,
    range: [1, 1440],
    unit,
    paired_with: null,
    ...over,
});

const timing = {
    early: minutes(
        'early',
        'Doses can be given from',
        'minutes before the dose time',
        '30',
    ),
    late: minutes(
        'late',
        'Doses count as late',
        'minutes after the dose time',
        '60',
    ),
    late_incident: minutes(
        'late_incident',
        'A late dose raises an incident',
        'minutes after the dose time',
        '120',
    ),
    refusal_count: minutes(
        'refusal_count',
        'Repeated refusals escalate',
        'refusals or withholds',
        '3',
        { range: [1, 50], paired_with: 'refusal_days' },
    ),
    refusal_days: minutes(
        'refusal_days',
        'Repeated refusals escalate — within',
        'days',
        '7',
        {
            range: [1, 90],
            paired_with: 'refusal_count',
            numeric: { ...looser, direction: 'higher_is_stricter' },
        },
    ),
};

const payload = (over: Partial<SettingsPayload> = {}): SettingsPayload => ({
    groups: {
        timing: {
            key: 'timing',
            view: 'rounds',
            effect: 'From the next dose shown on Meds today, at every house — recording is never blocked',
            audit_event: 'medications.mar_timing.updated',
            keys: Object.keys(timing),
        },
    },
    definitions: { timing },
    values: {
        timing: {
            early: '30',
            late: '60',
            late_incident: '120',
            refusal_count: '3',
            refusal_days: '7',
        },
    },
    reviewed: {
        timing: {
            early: { by: 'Hana Kereama', at: null },
            late: null,
            late_incident: null,
            refusal_count: null,
            refusal_days: null,
        },
    },
    site_values: {},
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
    ...over,
});

function renderWith(
    node: ReactNode,
    over: Partial<SettingsContext> = {},
    s: SettingsPayload = payload(),
) {
    const ctx: SettingsContext = {
        s,
        draft: {},
        setDraft: vi.fn(),
        canEdit: (group) => !!s.groups[group] && s.can_manage_organisation,
        go: vi.fn(),
        open: vi.fn(),
        close: vi.fn(),
        flash: vi.fn(),
        freshAfter: 0,
        leave: vi.fn(),
        errors: {},
        clearError: vi.fn(),
        ...over,
    };
    return {
        ctx,
        ...render(
            <SettingsCtx.Provider value={ctx}>{node}</SettingsCtx.Provider>,
        ),
    };
}

afterEach(cleanup);

describe('dose timing values', () => {
    it('reads as the number and its unit, in the history and the review', () => {
        expect(format(timing.early, '45')).toBe(
            '45 minutes before the dose time',
        );
        const draft = withDraft({}, 'timing', 'late', '90');
        expect(changes(payload(), draft, 'rounds')[0]).toMatchObject({
            fromText: '60 minutes after the dose time',
            toText: '90 minutes after the dose time',
            loosens: true,
        });
    });

    it('loosens the same way as the server', () => {
        expect(loosens(timing.early, '30', '45')).toBe(true);
        expect(loosens(timing.early, '30', '15')).toBe(false);
        expect(loosens(timing.refusal_count, '3', '5')).toBe(true);
        // More days catches more refusals: stricter.
        expect(loosens(timing.refusal_days, '7', '14')).toBe(false);
        expect(loosens(timing.refusal_days, '7', '3')).toBe(true);
    });

    it('stops "Review changes" on a value the server won’t accept, with its words', () => {
        let draft: Draft = withDraft({}, 'timing', 'early', '0');
        draft = withDraft(draft, 'timing', 'late', '');
        draft = withDraft(draft, 'timing', 'late_incident', '2.5');
        draft = withDraft(draft, 'timing', 'refusal_days', '91');
        draft = withDraft(draft, 'timing', 'refusal_count', '4');
        expect(validateView(payload(), draft, 'rounds')).toEqual({
            'timing.early': 'Enter a whole number from 1 to 1,440.',
            'timing.late': 'Enter a whole number from 1 to 1,440.',
            'timing.late_incident': 'Enter a whole number from 1 to 1,440.',
            'timing.refusal_days': 'Enter a whole number from 1 to 90.',
        });
        expect(validateView(payload(), draft, 'rules')).toEqual({});
    });

    it('lists the escalation pair once in Still to decide, with today’s rule', () => {
        const pending = stillToDecide(payload());
        expect(pending.map((p) => p.key)).toEqual([
            'late',
            'late_incident',
            'refusal_count',
        ]);
        expect(pending[2].until).toBe(
            'Today’s rule: 3 refusals or withholds within 7 days',
        );
        expect(pending[0].until).toBe(
            'Today’s rule: 60 minutes after the dose time',
        );
    });

    it('treats saving either half of the pair as deciding it', () => {
        const s = payload();
        s.reviewed.timing.refusal_days = { by: 'Hana Kereama', at: null };
        expect(stillToDecide(s).map((p) => p.key)).toEqual([
            'late',
            'late_incident',
        ]);
        renderWith(<DoseTiming q="" show="all" clear={vi.fn()} />, {}, s);
        expect(screen.getAllByText('Default — not yet reviewed')).toHaveLength(
            2,
        );
    });

    it('can put an earlier number back', () => {
        const entry: HistoryEntry = {
            id: 1,
            at: null,
            who: 'Hana Kereama',
            action: 'changed',
            group: 'timing',
            key: 'early',
            site_id: null,
            site_name: null,
            view: 'rounds',
            section: 'timing',
            label: timing.early.label,
            before_text: '45 minutes before the dose time',
            after_text: '30 minutes before the dose time',
            before_value: '45',
            loosens: false,
            note: null,
            event: 'medications.mar_timing.updated',
        };
        expect(canRestore(payload(), {}, entry, () => true)).toBe(true);
        expect(
            canRestore(
                payload(),
                {},
                { ...entry, before_value: '5000' },
                () => true,
            ),
        ).toBe(false);
    });
});

describe('Rounds & timing › Dose timing', () => {
    it('shows each time with its state, and the escalation as one row', () => {
        renderWith(<DoseTiming q="" show="all" clear={vi.fn()} />);
        expect(screen.getByLabelText('Can be given from')).toHaveValue(30);
        expect(screen.getByLabelText('Counts as late')).toHaveValue(60);
        expect(screen.getByLabelText('Raises an incident after')).toHaveValue(
            120,
        );
        expect(screen.getByLabelText('Number of refusals')).toHaveValue(3);
        expect(screen.getByLabelText('Number of days')).toHaveValue(7);
        // Early was reviewed; the other three rows are still defaults.
        expect(screen.getAllByText('Default — not yet reviewed')).toHaveLength(
            3,
        );
        expect(screen.queryByText(/due soon/i)).toBeNull();
        expect(screen.queryByText(/offer again/i)).toBeNull();
    });

    it('edits the draft and clears the field’s message', () => {
        const { ctx } = renderWith(
            <DoseTiming q="" show="all" clear={vi.fn()} />,
            {
                errors: {
                    'timing.late': 'Enter a whole number from 1 to 1,440.',
                },
            },
        );
        const late = screen.getByLabelText('Counts as late');
        expect(late).toHaveAttribute('aria-invalid', 'true');
        expect(
            screen.getByText('Enter a whole number from 1 to 1,440.'),
        ).toBeInTheDocument();
        fireEvent.change(late, { target: { value: '90' } });
        expect(ctx.setDraft).toHaveBeenCalled();
        expect(ctx.clearError).toHaveBeenCalledWith('timing.late');
    });

    it('is read-only for anyone without all-houses authority', () => {
        renderWith(
            <DoseTiming q="" show="all" clear={vi.fn()} />,
            {},
            payload({ can_manage_organisation: false }),
        );
        screen
            .getAllByRole('spinbutton')
            .forEach((input) => expect(input).toBeDisabled());
    });

    it('filters to the rows nobody has reviewed', () => {
        renderWith(<DoseTiming q="" show="open" clear={vi.fn()} />);
        expect(screen.queryByLabelText('Can be given from')).toBeNull();
        expect(screen.getByLabelText('Counts as late')).toBeInTheDocument();
    });
});

describe('Rounds & timing › Overview', () => {
    it('sums up the saved times and what is still to review', () => {
        renderWith(<RoundsOverview q="" templates={[]} />);
        expect(
            screen.getByText(
                'Can be given from 30 minutes before. Late after 60 minutes.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Escalates after 3 refusals within 7 days. A late dose becomes an incident after 120 minutes.',
            ),
        ).toBeInTheDocument();
        expect(screen.getAllByText(/not yet reviewed/)).toHaveLength(2);
    });
});

describe('Shows as due soon (P01 C6(b) reads it)', () => {
    const withDueSoon = () => {
        const base = payload();
        const dueSoon = minutes(
            'due_soon',
            'Doses show as due soon',
            'minutes before the dose time',
            '60',
            { numeric: null },
        );
        return payload({
            groups: {
                timing: {
                    ...base.groups.timing,
                    keys: [
                        'early',
                        'due_soon',
                        ...base.groups.timing.keys.slice(1),
                    ],
                },
            },
            definitions: { timing: { ...timing, due_soon: dueSoon } },
            values: { timing: { ...base.values.timing, due_soon: '60' } },
            reviewed: { timing: { ...base.reviewed.timing, due_soon: null } },
        });
    };

    it('shows v5’s row and early hint', () => {
        renderWith(
            <DoseTiming q="" show="all" clear={vi.fn()} />,
            {},
            withDueSoon(),
        );
        expect(screen.getByLabelText('Shows as due soon')).toHaveValue(60);
        expect(
            screen.getByText('Only changes what Meds today highlights.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Before this, the dose shows as not yet due.'),
        ).toBeInTheDocument();
    });

    it('never counts as loosening a check — it only changes what is highlighted', () => {
        const s = withDueSoon();
        const def = s.definitions.timing.due_soon;
        expect(loosens(def, '60', '120')).toBe(false);
        expect(loosens(def, '60', '15')).toBe(false);
    });
});
