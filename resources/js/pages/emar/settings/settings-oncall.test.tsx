import { router } from '@inertiajs/react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type Dialog, type SettingsContext } from './_context';
import type { SettingsPayload } from './_model';
import {
    OnCallContacts,
    OnCallDialogHost,
    resolveNight,
    type OnCallData,
    type OnCallHouse,
} from './_oncall';

vi.mock('@inertiajs/react', () => ({
    router: { put: vi.fn(), post: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));

const nights = (
    onCall: [string, string] | null,
    lead: [string, string] | null,
): OnCallHouse['roster'] => [
    {
        label: 'Tonight',
        hours: '5:00 pm – 7:00 am',
        on_call: onCall ? { id: 21, name: onCall[0], phone: onCall[1] } : null,
        team_lead: null,
    },
    {
        label: 'Sun 4 Oct',
        hours: '5:00 pm – 7:00 am',
        on_call: null,
        team_lead: lead ? { id: 22, name: lead[0], phone: lead[1] } : null,
    },
    {
        label: 'Mon 5 Oct',
        hours: '5:00 pm – 7:00 am',
        on_call: null,
        team_lead: null,
    },
];

const KOWHAI: OnCallHouse = {
    site_id: 3,
    name: 'Kōwhai House',
    house_leads: ['Jordan Tipene'],
    rule: {
        mode: 'roster',
        team_lead: true,
        backup: { id: 7, name: 'Hana Kereama', phone: '021 555 0142' },
        describe:
            'Follows the roster, then the team lead on shift · backup Hana Kereama',
        changed_by: 'Jordan Tipene',
        changed_at: '2026-10-02T21:00:00Z',
    },
    roster: nights(
        ['Jordan Tipene', '021 555 0163'],
        ['Priya Shah', '022 555 0134'],
    ),
    can_manage: true,
};
const RIMU: OnCallHouse = {
    site_id: 4,
    name: 'Rimu House',
    house_leads: ['Sione Taufa'],
    rule: null,
    roster: nights(['Sione Taufa', '021 555 0177'], null),
    can_manage: false,
};
const data: OnCallData = {
    houses: [KOWHAI, RIMU],
    staff: {
        '3': [
            {
                id: 7,
                name: 'Hana Kereama',
                role: 'Clinical lead',
                phone: '021 555 0142',
                ok: true,
                why: null,
                // On leave the third night.
                away: [2],
                leave: 'On leave Mon 5 – Fri 9 Oct (Leave hub)',
            },
            {
                id: 8,
                name: 'Leilani Faleolo',
                role: 'Support worker',
                phone: null,
                ok: false,
                why: 'no work phone on their staff record',
                away: [],
                leave: null,
            },
        ],
    },
};

function Harness({ initial = null }: { initial?: Dialog | null }) {
    const [dialog, setDialog] = useState<Dialog | null>(initial);
    const ctx = {
        s: {} as SettingsPayload,
        draft: {},
        setDraft: vi.fn(),
        canEdit: () => true,
        go: vi.fn(),
        open: setDialog,
        close: () => setDialog(null),
        flash: vi.fn(),
        freshAfter: 0,
        leave: vi.fn(),
        errors: {},
        clearError: vi.fn(),
    } as unknown as SettingsContext;
    return (
        <SettingsCtx.Provider value={ctx}>
            <OnCallContacts
                q=""
                clear={vi.fn()}
                data={data}
                readOnlyAudit={false}
            />
            <OnCallDialogHost dialog={dialog} data={data} />
        </SettingsCtx.Provider>
    );
}

afterEach(() => {
    cleanup();
    vi.mocked(router.put).mockClear();
    vi.mocked(router.delete).mockClear();
});

describe('Alerts & access › On-call contacts (B2 C4)', () => {
    it('works out who staff see each night: on-call shift, team lead, then the backup — nobody when the backup is away', () => {
        const backup = { id: 7, name: 'Hana Kereama', phone: '021', away: [2] };
        const rule = { mode: 'roster' as const, team_lead: true };
        expect(resolveNight(rule, backup, KOWHAI.roster[0], 0)).toMatchObject({
            how: 'On an on-call shift',
        });
        expect(resolveNight(rule, backup, KOWHAI.roster[1], 1)).toMatchObject({
            how: 'Team lead on shift',
        });
        expect(resolveNight(rule, backup, KOWHAI.roster[2], 2)).toMatchObject({
            who: null,
            warning: 'Nobody — Hana Kereama is on leave',
        });
        expect(
            resolveNight(
                { ...rule, team_lead: false },
                backup,
                KOWHAI.roster[1],
                1,
            ),
        ).toMatchObject({ how: 'Backup — nobody rostered' });
        expect(
            resolveNight(
                { mode: 'fixed', team_lead: false },
                backup,
                KOWHAI.roster[0],
                0,
            ),
        ).toMatchObject({ how: 'Always this person' });
    });

    it('shows a card per house: tonight’s contact, how it’s decided, gaps, and a not-configured house', () => {
        render(<Harness />);
        const kowhai = screen
            .getByText('Kōwhai House')
            .closest('[data-setting]') as HTMLElement;
        expect(
            within(kowhai).getByText('Follows the roster'),
        ).toBeInTheDocument();
        expect(
            within(kowhai).getByText('Jordan Tipene · 021 555 0163'),
        ).toBeInTheDocument();
        expect(
            within(kowhai).getByText(
                /Mon 5 Oct: Nobody — Hana Kereama is on leave/,
            ),
        ).toBeInTheDocument();
        const rimu = screen
            .getByText('Rimu House')
            .closest('[data-setting]') as HTMLElement;
        expect(within(rimu).getByText('Not configured')).toBeInTheDocument();
        expect(
            within(rimu).getByText(/Rostering has on-call shifts here/),
        ).toBeInTheDocument();
        // Someone who can't change it opens the read-only view from the card.
        fireEvent.click(
            within(rimu).getByRole('button', { name: /View details/ }),
        );
        expect(
            screen.getByText(
                'Only someone who manages settings for this house can change it.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Not configured — screens at this house give no number.',
            ),
        ).toBeInTheDocument();
    });

    it('saves straight away, asks for a backup, and only offers staff with a work phone', () => {
        render(<Harness initial={{ kind: 'oncall', siteId: 3 }} />);
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText('On-call contact — Kōwhai House'),
        ).toBeInTheDocument();
        // The preview follows the draft: switching the team lead off.
        fireEvent.click(
            within(dialog).getByRole('switch', {
                name: 'Then the team lead on shift',
            }),
        );
        expect(
            within(dialog).getAllByText('Backup — nobody rostered').length,
        ).toBeGreaterThan(0);
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Save contact' }),
        );
        expect(router.put).toHaveBeenCalledWith(
            '/emar/settings/oncall/3',
            { mode: 'roster', team_lead: false, backup_user_id: 7 },
            expect.anything(),
        );
    });

    it('asks before removing a contact, in red', () => {
        render(<Harness initial={{ kind: 'oncallremove', siteId: 3 }} />);
        const confirm = screen.getByRole('alertdialog');
        expect(
            within(confirm).getByText(
                'Remove the on-call contact for Kōwhai House?',
            ),
        ).toBeInTheDocument();
        expect(
            within(confirm).getByText(/Now: Follows the roster/),
        ).toBeInTheDocument();
        fireEvent.click(
            within(confirm).getByRole('button', { name: 'Remove contact' }),
        );
        expect(router.delete).toHaveBeenCalledWith(
            '/emar/settings/oncall/3',
            expect.anything(),
        );
    });
});
