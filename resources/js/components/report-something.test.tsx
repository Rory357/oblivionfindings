import { fireEvent, render, screen, within } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    can: {} as Record<string, unknown>,
    url: '/rostering',
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Link: React.forwardRef<
            HTMLAnchorElement,
            React.AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }
        >(({ href, children, ...props }, ref) => (
            <a ref={ref} href={href} {...props}>
                {children}
            </a>
        )),
        router: { visit: state.visit, post: vi.fn() },
        usePage: () => ({
            url: state.url,
            props: {
                auth: {
                    user: { id: 7, name: 'Mere Tane' },
                    can: state.can,
                    unreadMessageCount: 0,
                },
                branding: { name: 'Oblivion Living', logoUrl: null },
            },
        }),
    };
});
vi.mock('@/components/user-menu-content', () => ({
    UserMenuContent: () => null,
}));
vi.mock('@/components/inbox-menus', () => ({ default: () => null }));
vi.mock('@/components/global-nav-search', () => ({ default: () => null }));
vi.mock('@/components/global-query-bar', () => ({ default: () => null }));
vi.mock('@/components/chat/chat-calling', () => ({
    useChatCalling: () => null,
}));

import { AppHeader } from './app-header';
import { reportDestination, reportReturnTo } from './report-something';

beforeEach(() => {
    state.visit.mockClear();
    state.url = '/rostering';
});

// Board G4-2 (decision 9): the top bar's "Report something" opens the chooser on every page.
describe('Report something from the top bar', () => {
    it('opens the chooser with an incident already chosen, and one more click carries on as before', () => {
        state.can = {
            incidents: { create: true },
            safeguarding: { create: true },
        };
        render(<AppHeader />);
        expect(
            screen.queryByRole('link', { name: /Report incident/ }),
        ).toBeNull();
        fireEvent.click(
            screen.getByRole('button', { name: 'Report something' }),
        );
        const chooser = screen.getByRole('dialog', {
            name: 'Report something',
        });
        fireEvent.click(
            within(chooser).getByRole('button', { name: 'Report incident' }),
        );
        // A support worker (no H&S Overview) comes back to My Day.
        expect(state.visit).toHaveBeenCalledWith(
            '/incidents/create?return_to=%2Fmy-day',
        );
    });

    it('is not offered to someone with nothing to report', () => {
        state.can = { hazards: { view: true } };
        render(<AppHeader />);
        expect(
            screen.queryByRole('button', { name: 'Report something' }),
        ).toBeNull();
    });

    it('sends each choice to its form and back to where the person can return', () => {
        const manager = { hazards: { view: true } };
        expect(reportReturnTo('/my-day', manager)).toBe('/my-day');
        expect(reportReturnTo('/rostering', manager)).toBeUndefined();
        expect(reportReturnTo('/rostering', {})).toBe('/my-day');
        expect(reportDestination('incident', undefined)).toBe(
            '/incidents/create',
        );
        expect(reportDestination('rtw', '/my-day')).toBe(
            '/health-safety/injuries/create?return_to=%2Fmy-day',
        );
        expect(reportDestination('rtw', undefined)).toBe(
            '/health-safety/injuries/create?return_to=%2Fhealth-safety',
        );
        expect(reportDestination('safeguarding', '/my-day')).toBe(
            '/safeguarding/create',
        );
        expect(reportDestination('hazard', undefined)).toBe(
            '/compliance/hazards',
        );
    });
});
