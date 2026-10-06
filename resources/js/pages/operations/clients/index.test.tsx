import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ClientsIndex from './index';

const { visit, page } = vi.hoisted(() => ({
    visit: vi.fn(),
    page: {
        props: {
            clients: [] as Record<string, unknown>[],
            auth: { user: { name: 'Synthetic Worker' }, can: { clients: {} } },
        },
    },
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { visit, on: () => () => {} },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/assign-worker-dialog', () => ({
    AssignWorkerDialog: () => null,
}));
vi.mock('@/components/client-edit-dialog', () => ({
    ClientEditDialog: () => null,
}));
vi.mock('./_create-dialog', () => ({ AddClientDialog: () => null }));
vi.mock('@/pages/operations/clients/dialogs/daily-note-wizard', () => ({
    DailyNoteWizard: () => null,
}));

function person(id: number, fields: Record<string, unknown> = {}) {
    return {
        id,
        first_name: 'Synthetic',
        last_name: String(id),
        nhi_number: null,
        status: 'active',
        age: null,
        address: null,
        site: { id: 5, name: 'Synthetic House' },
        key_worker: null,
        archived: false,
        mine: true,
        safety: null,
        ...fields,
    };
}

describe('client directory with section-limited worker payloads', () => {
    beforeEach(() => {
        visit.mockReset();
        page.props.clients = [person(16)];
    });
    it('renders and opens an authorised person without withheld onboarding, notes or respite fields', () => {
        render(<ClientsIndex />);
        fireEvent.click(screen.getByText('Synthetic 16'));
        expect(visit).toHaveBeenCalledWith('/operations/clients/16');
        expect(
            screen.queryByRole('tab', { name: /Onboarding/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'View onboarding in progress',
            }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/undefined/)).not.toBeInTheDocument();
        expect(screen.queryByText(/0 on respite/)).not.toBeInTheDocument();
        expect(
            screen.getByText('no high-risk alerts shown'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('no safeguarding flags shown'),
        ).toBeInTheDocument();
        expect(screen.queryByText('All clear')).not.toBeInTheDocument();
        expect(
            screen.getByText('Safety details unavailable'),
        ).toBeInTheDocument();
    });
    it('includes only explicitly incomplete visible records in the onboarding view', () => {
        page.props.clients = [
            person(16),
            person(17, {
                onboarding: {
                    status: 'incomplete',
                    completed: 1,
                    total: 2,
                    percent: 50,
                },
                notes_week: 2,
                has_respite: false,
            }),
            person(18, {
                onboarding: {
                    status: 'complete',
                    completed: 2,
                    total: 2,
                    percent: 100,
                },
                notes_week: 0,
                has_respite: true,
            }),
        ];
        render(<ClientsIndex />);
        fireEvent.click(screen.getByRole('tab', { name: /Onboarding/ }));
        expect(screen.getByText('Synthetic 17')).toBeInTheDocument();
        expect(screen.queryByText('Synthetic 16')).not.toBeInTheDocument();
        expect(screen.queryByText('Synthetic 18')).not.toBeInTheDocument();
        expect(screen.getByText('1 of 3 shown')).toBeInTheDocument();
    });
    it('does not present an empty permission-filtered safety summary as all clear', () => {
        page.props.clients = [
            person(16, {
                safety: {
                    has_any: false,
                    safeguarding: false,
                    allergies_count: 0,
                    critical_risks_count: 0,
                },
            }),
        ];
        render(<ClientsIndex />);
        expect(screen.getByText('No alerts shown')).toBeInTheDocument();
        expect(screen.queryByText('All clear')).not.toBeInTheDocument();
    });
});
