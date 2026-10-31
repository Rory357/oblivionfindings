import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ can: {} as Record<string, unknown> }));
vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    usePage: () => ({ props: { auth: { can: page.can } } }),
}));

import { RoleStart, startTitle } from './role-start';

describe('The Overview start follows the role', () => {
    it('names each start', () => {
        expect(startTitle('governance')).toBe('Your queue');
        expect(startTitle('manager')).toBe('Your site');
        expect(startTitle('frontline')).toBe('Your start');
    });

    it('lists what a frontline worker may report, straight to the form', () => {
        page.can = {
            incidents: { create: true },
            hazards: { view: true, create: true },
            safeguarding: { create: true },
        };
        const onWorkflow = vi.fn();
        render(<RoleStart lens="frontline" onWorkflow={onWorkflow} />);
        expect(
            screen.getByRole('heading', { name: 'Something happened?' }),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Something could cause harm/ }),
        );
        expect(onWorkflow).toHaveBeenCalledWith('hazard');
        expect(
            screen.getByRole('button', {
                name: /worried a person is being harmed/,
            }),
        ).toBeInTheDocument();
    });

    it('keeps the safeguarding choice on the lead’s start too', () => {
        page.can = {
            incidents: { create: true },
            safeguarding: { create: true },
        };
        const onWorkflow = vi.fn();
        render(<RoleStart lens="governance" onWorkflow={onWorkflow} />);
        expect(
            screen.queryByRole('heading', { name: 'Something happened?' }),
        ).toBeNull();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Raise a safeguarding concern',
            }),
        );
        expect(onWorkflow).toHaveBeenCalledWith('safeguarding');
    });

    it('offers no safeguarding button to someone who can’t raise one', () => {
        page.can = { hazards: { view: true } };
        render(<RoleStart lens="manager" onWorkflow={() => {}} />);
        expect(
            screen.queryByRole('button', {
                name: 'Raise a safeguarding concern',
            }),
        ).toBeNull();
    });
});
