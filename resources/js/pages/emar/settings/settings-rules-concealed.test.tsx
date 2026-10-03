import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type SettingsContext } from './_context';
import type { SettingsPayload } from './_model';
import {
    MedicineRules,
    RuleView,
    settingsRuleData,
    type MedicineRule,
    type RuleData,
} from './_rules';

vi.mock('@inertiajs/react', () => ({
    router: { put: vi.fn(), post: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));

const rule = (over: Partial<MedicineRule>): MedicineRule => ({
    id: 1,
    site_id: null,
    site_name: null,
    match_type: 'medicine_name',
    match_value: 'Insulin glargine',
    requires_countersign: false,
    required_observations: ['blood_glucose'],
    active: true,
    what: 'Insulin glargine',
    needs: 'record blood sugar (BSL)',
    sentence:
        'Before saving a dose of Insulin glargine at All houses: record blood sugar (BSL).',
    last_changed_by: 'Hana Kereama',
    last_changed_at: '2026-08-03T09:05:00+12:00',
    paused_note: null,
    can_change: false,
    concealed: false,
    overlaps: [],
    ...over,
});

/** What a settings manager without controlled-medicine access receives. */
const data: RuleData = {
    rules: [
        rule({ id: 1, overlaps: [2] }),
        rule({
            id: 2,
            match_value: '',
            requires_countersign: false,
            required_observations: [],
            what: 'Controlled-medicine rule',
            needs: 'Details need controlled-medicine access',
            sentence:
                'Only people with controlled-medicine access can see this rule’s details.',
            concealed: true,
        }),
    ],
    options: { names: [], routes: [], nzulm: [] },
    sites: [],
    can: { manage: true, manage_global: true },
    readOnlyAudit: false,
};

function renderWith(node: ReactNode) {
    const ctx: SettingsContext = {
        s: {
            groups: {},
            definitions: {},
            values: {},
            reviewed: {},
            site_values: {},
            site_reviewed: {},
            history: [],
            can_manage_organisation: true,
        } as SettingsPayload,
        draft: {},
        setDraft: vi.fn(),
        canEdit: () => false,
        go: vi.fn(),
        open: vi.fn(),
        close: vi.fn(),
        flash: vi.fn(),
        freshAfter: 0,
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

describe('a rule naming a controlled medicine, for someone without controlled access', () => {
    it('is listed without its details and offers only viewing', () => {
        const { ctx } = renderWith(
            <MedicineRules
                data={data}
                q=""
                where="all"
                state="all"
                clear={vi.fn()}
            />,
        );
        expect(
            screen.getByText('Controlled-medicine rule'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Details need controlled-medicine access'),
        ).toBeInTheDocument();
        // The other rule says it overlaps one other rule — never which.
        expect(
            screen.getByText(/overlaps 1 other rule — both apply/),
        ).toBeInTheDocument();
        const row = screen
            .getByText('Controlled-medicine rule')
            .closest('[role="row"]')!;
        fireEvent.contextMenu(row);
        expect(screen.getByText('View rule')).toBeInTheDocument();
        expect(screen.queryByText('Edit rule')).toBeNull();
        expect(screen.queryByText('Pause rule')).toBeNull();
        fireEvent.click(screen.getByText('View rule'));
        expect(ctx.open).toHaveBeenCalledWith({ kind: 'ruleview', id: 2 });
    });

    it('opens to a notice, not the rule', () => {
        renderWith(<RuleView id={2} data={data} />);
        expect(
            screen.getByText(
                /Only people with controlled-medicine access can see this rule’s details/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Would apply now to', { exact: false }),
        ).toBeNull();
    });
});

describe('Settings rule authority with shared navigation in the renderer fixture', () => {
    it.each([
        { manage: false, navigation: true, audit: false, canAdd: false },
        { manage: true, navigation: false, audit: false, canAdd: true },
        { manage: true, navigation: true, audit: true, canAdd: false },
    ])(
        'keeps add-rule authority scoped when manage=$manage, navigation=$navigation, audit=$audit',
        ({ manage, navigation, audit, canAdd }) => {
            const page = {
                rules: data.rules,
                ruleOptions: data.options,
                sites: data.sites,
                settingsCan: { manage, manage_global: manage },
                can: { medications: { settingsManage: navigation } },
                readOnlyAudit: audit,
            };
            const { ctx } = renderWith(
                <MedicineRules
                    data={settingsRuleData(page)}
                    q=""
                    where="all"
                    state="all"
                    clear={vi.fn()}
                />,
            );
            const add = screen.queryByRole('button', { name: 'Add a rule' });
            if (canAdd) {
                expect(add).toBeInTheDocument();
                fireEvent.click(add!);
                expect(ctx.open).toHaveBeenCalledWith({
                    kind: 'rule',
                    id: 'new',
                });
            } else {
                expect(add).toBeNull();
            }
        },
    );
});
