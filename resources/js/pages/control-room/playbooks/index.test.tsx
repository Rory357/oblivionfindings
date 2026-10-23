import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PlaybooksIndex from './index';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    breadcrumbs: [] as Array<{ title: string; href?: string }>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        url: '/control-room/playbooks',
        props: { auth: { can: { controlRoom: { viewAny: true } } } },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: Array<{ title: string; href?: string }>;
    }) => {
        inertia.breadcrumbs = breadcrumbs;
        return <div>{children}</div>;
    },
}));

// The wizard has its own tests; here it only has to open.
vi.mock('@/components/control-room/playbook-wizard', () => ({
    PlaybookWizard: () => <div role="dialog" aria-label="Add response procedure" />,
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const base = {
    code: null,
    version: 1,
    requires_approval: false,
    sla_acknowledge_minutes: null,
    sla_response_minutes: null,
    sla_resolution_minutes: null,
    steps_count: 3,
    runs_count: 0,
    last_run_at: null,
    created_at: '2026-10-01T00:00:00Z',
};

const props = {
    playbooks: [
        {
            ...base,
            id: 1,
            name: 'Personal SOS alert',
            description: 'Urgent response to a request for help',
            category: 'emergency',
            is_active: true,
            auto_attach: true,
            runs_count: 2,
            last_run_at: '2026-10-20T20:24:00Z',
        },
        {
            ...base,
            id: 2,
            name: 'Fire alarm',
            description: 'Response to a reported site alarm',
            category: 'emergency',
            is_active: true,
            auto_attach: false,
        },
        {
            ...base,
            id: 3,
            name: 'Reported outside the safe zone',
            description: null,
            category: 'safety',
            is_active: false,
            auto_attach: false,
        },
    ],
    filters: {},
    categories: { emergency: 'Emergency', safety: 'Safety' },
    stepTypes: { task: 'Task' },
    can: { manage: true },
} satisfies ComponentProps<typeof PlaybooksIndex>;

const row = (name: string) =>
    screen.getByRole('link', { name }).closest('tr') as HTMLElement;

describe('Settings › Procedures', () => {
    it('opens on the Procedures tab of Settings with the add action for editors', () => {
        render(<PlaybooksIndex {...props} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Settings' })).toBeInTheDocument();
        expect(inertia.breadcrumbs.map((crumb) => crumb.title)).toEqual([
            'Home',
            'Control Room',
            'Settings',
        ]);
        const rail = screen.getByRole('tablist', { name: 'Settings views' });
        expect(within(rail).getByRole('tab', { name: /Procedures/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(document.body.textContent).not.toMatch(/playbook/i);

        fireEvent.click(screen.getByRole('button', { name: 'Add response procedure' }));
        expect(screen.getByRole('dialog', { name: 'Add response procedure' })).toBeInTheDocument();
    });

    it('shows the category as plain text, not an urgent badge', () => {
        render(<PlaybooksIndex {...props} />);

        const cell = within(row('Personal SOS alert')).getByText('Emergency');
        expect(cell.tagName).toBe('TD');
    });

    it('header numbers and the search narrow the list in place', () => {
        render(<PlaybooksIndex {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Show procedures that are off' }));
        expect(screen.queryByRole('link', { name: 'Fire alarm' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Reported outside the safe zone' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Show every procedure' }));
        fireEvent.change(screen.getByRole('searchbox', { name: 'Search procedures' }), {
            target: { value: 'fire' },
        });
        expect(screen.getByRole('link', { name: 'Fire alarm' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Personal SOS alert' })).not.toBeInTheDocument();

        expect(screen.getByRole('link', { name: /Open the run history of Personal SOS alert/ })).toHaveAttribute(
            'href',
            '/control-room/playbooks/1?tab=runs',
        );
    });

    it('turning a procedure off asks first in red', () => {
        render(<PlaybooksIndex {...props} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Procedure Personal SOS alert on' }));
        const confirm = screen.getByRole('alertdialog', { name: 'Turn off this procedure?' });
        expect(confirm).toHaveTextContent('no longer starts on its own');
        fireEvent.click(within(confirm).getByRole('button', { name: 'Turn off procedure' }));
        expect(inertia.post).toHaveBeenCalledWith(
            '/control-room/playbooks/1/toggle-active',
            {},
            expect.any(Object),
        );
    });

    it('rows keep the kebab and the right-click menu with the same actions', () => {
        render(<PlaybooksIndex {...props} />);

        fireEvent.contextMenu(row('Fire alarm'));
        expect(screen.getByRole('menuitem', { name: 'Open procedure' })).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: 'Turn off' })).toBeInTheDocument();
    });

    it('stays readable for people who can only view', () => {
        render(<PlaybooksIndex {...props} can={{ manage: false }} />);

        expect(screen.getByRole('link', { name: 'Fire alarm' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add response procedure' })).not.toBeInTheDocument();
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
        expect(within(row('Reported outside the safe zone')).getByText('Off')).toBeInTheDocument();

        fireEvent.contextMenu(row('Fire alarm'));
        expect(screen.getByRole('menuitem', { name: 'Open procedure' })).toBeInTheDocument();
        expect(screen.queryByRole('menuitem', { name: 'Turn off' })).not.toBeInTheDocument();
    });
});
