import { cleanup, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const navigation = vi.hoisted(() => ({
    url: '/medication-followups?administration=71',
    prepare: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ url: navigation.url, props: {} }),
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/hooks/use-emar-breadcrumbs', () => ({
    useEmarBreadcrumbs: () => [],
}));
vi.mock('@/hooks/use-offline-queue', () => ({
    useOfflineQueueState: () => ({ pendingCount: 0 }),
}));
vi.mock('@/components/emar/emar-hub-rail', () => ({ EmarHubRail: () => null }));
vi.mock('@/components/emar/followups/followup-list', () => ({
    MedicationFollowupList: () => null,
}));
vi.mock('@/components/emar/record-dose/record-dose-dialog', () => ({
    RecordDoseDialog: () => null,
}));
vi.mock('@/components/emar/followups/administration-followup-dialog', () => ({
    AdministrationFollowupDialog: ({
        administrationId,
    }: {
        administrationId: number;
    }) => {
        navigation.prepare(administrationId);
        return <div role="dialog">Prepare dose {administrationId}</div>;
    },
}));
vi.mock('@/components/emar/followups/followup-dialog', () => ({
    MedicationFollowupDialog: ({ id }: { id: number | null }) =>
        id ? <div role="dialog">Canonical check {id}</div> : null,
}));

import Followups from './Followups';

const base: ComponentProps<typeof Followups> = {
    followups: {
        data: [],
        current_page: 1,
        last_page: 1,
        total: 0,
        from: null,
        to: null,
    },
    meters: { open: 0, overdue: 0, lead: 0, unscheduled: 0 },
    filters: {},
    types: { effect: 'Effect check' },
};
const legacyRow = {
    source_key: 'effect:71',
    administration_id: 71,
    client: { id: 1, name: 'Synthetic person' },
    site: { id: 1, name: 'Test house' },
    medication: { id: 1, name: 'Synthetic medicine' },
    owner: null,
    due_at: null,
    given_at: null,
    can_prepare: true,
    record_url: '/emar/mar?client_id=1',
};
const checks = (row: typeof legacyRow | null) => ({
    total: row ? 1 : 0,
    overdue: 0,
    unscheduled: row ? 1 : 0,
    filtered_total: row ? 1 : 0,
    data: row ? [row] : [],
    has_more: false,
});

beforeEach(() => {
    navigation.url = '/medication-followups?administration=71';
    vi.clearAllMocks();
});
afterEach(cleanup);

it.each(['view-only', 'different dose', 'concealed'] as const)(
    'does not prepare a shared legacy deep link for %s',
    (kind) => {
        const row =
            kind === 'concealed'
                ? null
                : {
                      ...legacyRow,
                      can_prepare: kind !== 'view-only',
                      administration_id: kind === 'different dose' ? 72 : 71,
                  };
        render(<Followups {...base} legacy_effect_checks={checks(row)} />);
        expect(navigation.prepare).not.toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    },
);

it('opens an authorised legacy dose selected by the server', () => {
    render(<Followups {...base} legacy_effect_checks={checks(legacyRow)} />);
    expect(screen.getByRole('dialog')).toHaveTextContent('Prepare dose 71');
    expect(navigation.prepare).toHaveBeenCalledWith(71);
});

it('opens a canonical deep link outside the current list without preparing again', () => {
    render(
        <Followups
            {...base}
            selected_followup_id={99}
            legacy_effect_checks={checks(null)}
        />,
    );
    expect(screen.getByRole('dialog')).toHaveTextContent('Canonical check 99');
    expect(navigation.prepare).not.toHaveBeenCalled();
});
