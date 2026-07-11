import { cleanup, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import * as clientProfilePage from '@/pages/operations/clients/show';
import { ClientCalendarTab } from '@/pages/operations/clients/tabs/legacy-profile-sections';

vi.mock('@fullcalendar/react', async () => {
    const React = await import('react');

    return {
        default: React.forwardRef(function FullCalendarMock(
            props: { selectable?: boolean },
            ref: React.ForwardedRef<unknown>,
        ) {
            React.useImperativeHandle(ref, () => ({
                getApi: () => ({
                    changeView: vi.fn(),
                    next: vi.fn(),
                    prev: vi.fn(),
                    refetchEvents: vi.fn(),
                    today: vi.fn(),
                    unselect: vi.fn(),
                }),
            }));

            return (
                <div
                    data-testid="full-calendar"
                    data-selectable={String(Boolean(props.selectable))}
                />
            );
        }),
    };
});

vi.mock('@fullcalendar/daygrid', () => ({ default: {} }));
vi.mock('@fullcalendar/interaction', () => ({ default: {} }));
vi.mock('@fullcalendar/list', () => ({ default: {} }));
vi.mock('@fullcalendar/timegrid', () => ({ default: {} }));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        Link: ({ children }: { children: React.ReactNode }) => children,
        router: {
            delete: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            reload: vi.fn(),
            visit: vi.fn(),
        },
        useForm: <T,>(initial: T) => ({
            data: initial,
            delete: vi.fn(),
            errors: {},
            patch: vi.fn(),
            post: vi.fn(),
            processing: false,
            put: vi.fn(),
            reset: vi.fn(),
            setData: vi.fn(),
        }),
        usePage: () => ({
            props: {
                auth: { can: {}, user: { id: 1, name: 'Worker' } },
                labels: {},
            },
        }),
    };
});

type CalendarTabWithAccess = React.ComponentType<{
    clientId: number;
    clientFirstName: string;
    initialEvents?: unknown[];
    canCreate: boolean;
}>;

describe('client profile appointment authorization', () => {
    afterEach(() => cleanup());

    it('only renders calendar create affordances when calendar.create is granted', () => {
        const CalendarTab = ClientCalendarTab as CalendarTabWithAccess;
        const { rerender } = render(
            <CalendarTab
                clientId={9040}
                clientFirstName="Tane"
                canCreate={false}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Schedule' }),
        ).not.toBeInTheDocument();
        expect(screen.getByTestId('full-calendar')).toHaveAttribute(
            'data-selectable',
            'false',
        );

        rerender(
            <CalendarTab clientId={9040} clientFirstName="Tane" canCreate />,
        );

        expect(screen.getByRole('button', { name: 'Schedule' })).toBeVisible();
        expect(screen.getByTestId('full-calendar')).toHaveAttribute(
            'data-selectable',
            'true',
        );
    });

    it('uses calendar.create for creation and calendar.manage for lifecycle actions', () => {
        const profilePage = clientProfilePage as typeof clientProfilePage & {
            clientAppointmentActionAllowed?: (
                action: 'create' | 'edit' | 'cancel' | 'delete',
                permissions?: { create?: boolean; manage?: boolean },
            ) => boolean;
        };

        expect(profilePage.clientAppointmentActionAllowed).toBeTypeOf(
            'function',
        );
        if (!profilePage.clientAppointmentActionAllowed) return;

        expect(
            profilePage.clientAppointmentActionAllowed('create', {
                create: true,
                manage: false,
            }),
        ).toBe(true);
        expect(
            profilePage.clientAppointmentActionAllowed('create', {
                create: false,
                manage: true,
            }),
        ).toBe(false);

        for (const action of ['edit', 'cancel', 'delete'] as const) {
            expect(
                profilePage.clientAppointmentActionAllowed(action, {
                    create: true,
                    manage: false,
                }),
                `${action} must not inherit calendar.create`,
            ).toBe(false);
            expect(
                profilePage.clientAppointmentActionAllowed(action, {
                    create: false,
                    manage: true,
                }),
                `${action} must require calendar.manage`,
            ).toBe(true);
        }
    });
});
