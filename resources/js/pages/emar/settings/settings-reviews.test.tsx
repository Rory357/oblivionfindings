import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type SettingsContext } from './_context';
import {
    changes,
    stillToDecide,
    type Draft,
    type SettingsPayload,
} from './_model';
import { ReviewCadenceSettings } from './_reviews';

vi.mock('@inertiajs/react', () => ({ router: {} }));
vi.mock('@/components/confirm-dialog', () => ({ ConfirmDialog: () => null }));
vi.mock('@/components/lists/entity-cells', () => ({ EntityChip: () => null }));
vi.mock('@/components/lists/entity-menu', () => ({
    compactMenu: () => [],
    useEntityContextMenu: () => ({}),
}));
vi.mock('@/components/lists/entity-table', () => ({ EntityTable: () => null }));
vi.mock('@/lib/datetime', () => ({
    formatDateLong: vi.fn(),
    formatDateTime: vi.fn(),
    formatTime: vi.fn(),
    formatDateOnly: vi.fn(),
}));
vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...props }: { children: ReactNode }) => (
        <button {...props}>{children}</button>
    ),
}));
vi.mock('@/components/ui/card', () => ({
    Card: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/components/ui/label', () => ({
    Label: ({
        children,
        htmlFor,
    }: {
        children: ReactNode;
        htmlFor: string;
    }) => <label htmlFor={htmlFor}>{children}</label>,
}));
vi.mock('@/components/ui/empty-state', () => ({
    EmptyState: ({ title, action }: { title: string; action: ReactNode }) => (
        <div>
            {title}
            {action}
        </div>
    ),
}));
vi.mock('@/components/ui/status-badge', () => ({
    StatusBadge: ({ children }: { children: ReactNode }) => (
        <span>{children}</span>
    ),
}));
vi.mock('@/components/ui/select', () => ({
    Select: ({
        children,
        value,
        onValueChange,
        disabled,
    }: {
        children: ReactNode;
        value: string;
        onValueChange: (value: string) => void;
        disabled: boolean;
    }) => (
        <select
            aria-label="Default interval in calendar months"
            value={value}
            onChange={(e) => onValueChange(e.target.value)}
            disabled={disabled}
        >
            {children}
        </select>
    ),
    SelectTrigger: () => null,
    SelectValue: () => null,
    SelectContent: ({ children }: { children: ReactNode }) => <>{children}</>,
    SelectItem: ({
        children,
        value,
    }: {
        children: ReactNode;
        value: string;
    }) => <option value={value}>{children}</option>,
}));
vi.mock('./_ui', () => ({
    Changed: () => <span>Changed — not saved</span>,
    Section: ({ children }: { children: ReactNode }) => (
        <section>{children}</section>
    ),
    Choice: () => null,
    GroupGrid: () => null,
    GroupRow: () => null,
    Note: () => null,
    NumberInput: () => null,
    OnOff: () => null,
    RowMenu: () => null,
    SettingGroup: () => null,
}));

const payload: SettingsPayload = {
    groups: {
        review_cadence: {
            key: 'review_cadence',
            view: 'rules',
            effect: 'For future regular reviews; already booked dates stay as they are',
            audit_event: 'medications.review_default.updated',
            keys: ['months'],
        },
    },
    definitions: {
        review_cadence: {
            months: {
                group: 'review_cadence',
                key: 'months',
                scope: 'organisation',
                section: 'reviews',
                label: 'Regular medication review interval',
                options: [],
                default: '3',
                range: [1, 12],
                unit: 'months',
                paired_with: null,
                rank: null,
                numeric: {
                    direction: 'higher_is_looser',
                    off: null,
                    off_is_loosest: false,
                },
            },
        },
    },
    values: { review_cadence: { months: '3' } },
    reviewed: {},
    site_values: {},
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
};
function Harness({
    editable = true,
    show = 'all',
    open = vi.fn(),
}: {
    editable?: boolean;
    show?: string;
    open?: (dialog: unknown) => void;
}) {
    const [draft, setDraft] = useState<Draft>({});
    const ctx = {
        s: payload,
        draft,
        setDraft,
        canEdit: () => editable,
        open,
        errors: {},
        clearError: vi.fn(),
        close: vi.fn(),
        go: vi.fn(),
        flash: vi.fn(),
        freshAfter: 0,
        leave: vi.fn(),
    } as SettingsContext;
    return (
        <SettingsCtx.Provider value={ctx}>
            <ReviewCadenceSettings q="" show={show} clear={vi.fn()} />
            <output data-testid="draft">{JSON.stringify(draft)}</output>
        </SettingsCtx.Provider>
    );
}
afterEach(cleanup);
describe('P05 cadence in the Rules settings lifecycle', () => {
    it('keeps the initial default unreviewed until the existing keep dialog completes', () => {
        const open = vi.fn();
        render(<Harness open={open} />);
        expect(screen.getByText('Not reviewed')).toBeTruthy();
        fireEvent.click(
            screen.getByRole('button', { name: "Keep today's value" }),
        );
        expect(open).toHaveBeenCalledWith({
            kind: 'keep',
            group: 'review_cadence',
            key: 'months',
        });
        expect(screen.getByTestId('draft').textContent).toBe('{}');
        expect(stillToDecide(payload)).toHaveLength(1);
    });
    it('edits an unsaved Rules draft without marking the saved default reviewed', () => {
        render(<Harness />);
        fireEvent.change(screen.getByRole('combobox'), {
            target: { value: '6' },
        });
        expect(screen.getByTestId('draft').textContent).toContain(
            '"months":"6"',
        );
        expect(screen.getByText('Changed — not saved')).toBeTruthy();
        expect(screen.getByText('Not reviewed')).toBeTruthy();
        expect(
            (
                screen.getByRole('button', {
                    name: "Keep today's value",
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        const change = changes(
            payload,
            { review_cadence: { months: '6' } },
            'rules',
        )[0];
        expect(change).toMatchObject({
            group: 'review_cadence',
            key: 'months',
            section: 'reviews',
            loosens: true,
        });
    });
    it('uses existing group authority for both editing and keeping defaults', () => {
        render(<Harness editable={false} />);
        expect(
            (screen.getByRole('combobox') as HTMLSelectElement).disabled,
        ).toBe(true);
        expect(
            (
                screen.getByRole('button', {
                    name: "Keep today's value",
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });
    it('uses the shared unsaved-change filter before a draft exists', () => {
        render(<Harness show="changed" />);
        expect(screen.getByText('No settings match this filter')).toBeTruthy();
        expect(screen.queryByRole('combobox')).toBeNull();
    });
});
