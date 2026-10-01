import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type SettingsContext } from './_context';
import { AllChanges, StillToDecide } from './_history';
import type { HistoryEntry, SettingsPayload } from './_model';
import { SafetyChecks, WitnessPins } from './_sections';
import { SaveBar } from './_ui';

vi.mock('@inertiajs/react', () => ({
    router: { put: vi.fn(), post: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));

const opt = (value: string, label: string) => ({ value, label });
const def = (
    group: string,
    key: string,
    section: string,
    label: string,
    options: { value: string; label: string }[],
    dflt: string,
) => ({
    group,
    key,
    scope: 'organisation' as const,
    section,
    label,
    options,
    default: dflt,
    rank: options.map((o) => o.value),
    numeric: null,
    range: null,
    unit: null,
    paired_with: null,
});

/** What an auditor receives: every value, the history, and no authority to change anything. */
const auditorSettings: SettingsPayload = {
    groups: {
        safety: {
            key: 'safety',
            view: 'rules',
            effect: 'From the next dose signed, at every house',
            audit_event: 'medications.safety_policy.updated',
            keys: [
                'profile_allergy_match',
                'restricted_competency',
                'competency_areas',
            ],
        },
        pin: {
            key: 'pin',
            view: 'staff',
            effect: 'From the next dose signed or witnessed, at every house',
            audit_event: 'medications.witness_pin_rules.updated',
            keys: ['max_attempts', 'lockout_minutes', 'renewal_months'],
        },
    },
    definitions: {
        safety: {
            profile_allergy_match: def(
                'safety',
                'profile_allergy_match',
                'safety',
                'When a medicine matches a recorded allergy',
                [opt('warn', 'Warn'), opt('block', 'Block')],
                'warn',
            ),
            restricted_competency: def(
                'safety',
                'restricted_competency',
                'safety',
                'A worker’s medication competency is marked restricted',
                [
                    opt('off', 'Off'),
                    opt('block', 'Block'),
                    opt('cosigner', 'Co-signer'),
                ],
                'off',
            ),
            competency_areas: def(
                'safety',
                'competency_areas',
                'safety',
                'The controlled-drug or covert area wasn’t passed',
                [
                    opt('off', 'Off'),
                    opt('failed', 'Failed'),
                    opt('failed_or_not_seen', 'Failed or not seen'),
                ],
                'off',
            ),
        },
        pin: {
            max_attempts: def(
                'pin',
                'max_attempts',
                'pins',
                'Wrong attempts before a PIN locks',
                [
                    opt('3', '3 attempts'),
                    opt('5', '5 attempts'),
                    opt('10', '10 attempts'),
                ],
                '5',
            ),
            lockout_minutes: def(
                'pin',
                'lockout_minutes',
                'pins',
                'How long a locked PIN stays locked',
                [opt('5', '5 minutes'), opt('15', '15 minutes')],
                '15',
            ),
            renewal_months: def(
                'pin',
                'renewal_months',
                'pins',
                'PIN renewal (optional)',
                [opt('none', 'No renewal'), opt('6', 'Every 6 months')],
                'none',
            ),
        },
    },
    values: {
        safety: {
            profile_allergy_match: 'warn',
            restricted_competency: 'block',
            competency_areas: 'off',
        },
        pin: {
            max_attempts: '5',
            lockout_minutes: '15',
            renewal_months: 'none',
        },
    },
    reviewed: {
        safety: {
            profile_allergy_match: null,
            restricted_competency: { by: 'Hana Kereama', at: null },
            competency_areas: null,
        },
        pin: {
            max_attempts: null,
            lockout_minutes: null,
            renewal_months: null,
        },
    },
    site_values: {},
    site_reviewed: {},
    history: [
        {
            id: 7,
            at: '2026-10-01T03:46:00Z',
            who: 'Hana Kereama',
            action: 'changed',
            group: 'safety',
            key: 'restricted_competency',
            site_id: null,
            site_name: null,
            view: 'rules',
            section: 'safety',
            label: 'A worker’s medication competency is marked restricted',
            before_text: 'Off',
            after_text: 'Block',
            before_value: 'off',
            loosens: false,
            note: null,
            event: 'medications.safety_policy.updated',
        } satisfies HistoryEntry,
    ],
    can_manage_organisation: false,
};

function renderAsAuditor(node: ReactNode) {
    const ctx: SettingsContext = {
        s: auditorSettings,
        draft: {},
        setDraft: vi.fn(),
        canEdit: (group) =>
            !!auditorSettings.groups[group] &&
            auditorSettings.can_manage_organisation,
        go: vi.fn(),
        open: vi.fn(),
        close: vi.fn(),
        flash: vi.fn(),
        freshAfter: 99,
        leave: vi.fn(),
        errors: {},
        clearError: vi.fn(),
    };
    return {
        ctx,
        ...render(
            <SettingsCtx.Provider value={ctx}>{node}</SettingsCtx.Provider>,
        ),
    };
}

afterEach(cleanup);

describe('Medication Settings for an auditor (read-only)', () => {
    it('shows safety checks and PIN rules with every control disabled', () => {
        renderAsAuditor(
            <>
                <SafetyChecks q="" show="all" clear={vi.fn()} />
                <WitnessPins q="" show="all" clear={vi.fn()} />
            </>,
        );
        const switches = screen.getAllByRole('switch');
        expect(switches.length).toBeGreaterThan(0);
        switches.forEach((s) => expect(s).toBeDisabled());
        for (const name of [
            'Warn',
            'Block',
            'Co-signer with witness PIN',
            '3 attempts',
            '10 attempts',
            '15 minutes',
        ]) {
            const buttons = screen.getAllByRole('button', { name });
            buttons.forEach((b) => expect(b).toBeDisabled());
        }
    });

    it('gives no Review changes or Discard — only why it is read-only', () => {
        render(
            <SaveBar
                count={0}
                onDiscard={vi.fn()}
                onReview={vi.fn()}
                readOnly="Read-only — auditors can view settings and their history, not change them."
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Review changes' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Discard changes' }),
        ).toBeNull();
        expect(
            screen.getByText(/auditors can view settings and their history/),
        ).toBeInTheDocument();
    });

    it('offers no Keep today’s value and no walkthrough in Still to decide', () => {
        renderAsAuditor(<StillToDecide q="" />);
        expect(
            screen.getByText('When a medicine matches a recorded allergy'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Review the defaults/ }),
        ).toBeNull();
        const row = screen
            .getByText('When a medicine matches a recorded allergy')
            .closest('[role="row"]')!;
        fireEvent.contextMenu(row);
        expect(screen.getByText('Go to the setting')).toBeInTheDocument();
        expect(screen.queryByText('Keep today’s value')).toBeNull();
    });

    it('reads the change history but can’t put an earlier value back', () => {
        renderAsAuditor(
            <AllChanges
                q=""
                clearQ={vi.fn()}
                filters={{ area: 'all', who: 'all', where: 'all' }}
                setFilters={vi.fn()}
                page={1}
                setPage={vi.fn()}
            />,
        );
        const row = screen
            .getByText('A worker’s medication competency is marked restricted')
            .closest('[role="row"]')!;
        fireEvent.contextMenu(row);
        expect(screen.getByText('View before and after')).toBeInTheDocument();
        expect(screen.queryByText('Put the earlier value back')).toBeNull();
    });
});
