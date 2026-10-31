import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    can: { viewAny: true, viewAssigned: false, export: true },
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        href,
        ...props
    }: {
        children: ReactNode;
        href: string;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { auth: { can: { incidents: state.can } } } }),
    router: { get: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));

import IncidentReports from './incidents';

afterEach(cleanup);
beforeEach(() => {
    state.can = { viewAny: true, viewAssigned: false, export: true };
});

describe('Incident report export', () => {
    it('retains the exact selected dates, person, severity and review state in the export', () => {
        render(
            <IncidentReports
                clients={[{ id: 4, first_name: 'Test', last_name: 'Person' }]}
                filters={{
                    from: '2026-09-27',
                    to: '2026-09-27',
                    client_id: '4',
                    severity: 'critical',
                    reviewed: 'no',
                }}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'Download CSV' }),
        ).toHaveAttribute(
            'href',
            '/reports/incidents/export?from=2026-09-27&to=2026-09-27&client_id=4&severity=critical&reviewed=no',
        );
        expect(
            screen.getByRole('link', { name: 'Open register' }),
        ).toHaveAttribute('href', '/incidents');
        expect(screen.getByText(/New Zealand time/)).toBeInTheDocument();
    });

    it('keeps an invalid range editable and prevents downloading it', () => {
        render(
            <IncidentReports
                clients={[]}
                filters={{ from: '2026-10-07', to: '2026-10-06' }}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Download CSV' }),
        ).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'on or after the start date',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Occurrence date range' }),
        );
        fireEvent.change(screen.getByLabelText('To (NZ date)'), {
            target: { value: '2026-10-08' },
        });
        expect(
            screen.getByRole('link', { name: 'Download CSV' }),
        ).toHaveAttribute(
            'href',
            '/reports/incidents/export?from=2026-10-07&to=2026-10-08',
        );
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('does not offer unauthorized export or register actions to a report-only viewer', () => {
        state.can = { viewAny: false, viewAssigned: false, export: false };
        render(<IncidentReports clients={[]} filters={{}} />);
        expect(
            screen.queryByRole('link', { name: 'Download CSV' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Open register' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'You do not have permission to export incident records.',
            ),
        ).toBeInTheDocument();
    });
});
