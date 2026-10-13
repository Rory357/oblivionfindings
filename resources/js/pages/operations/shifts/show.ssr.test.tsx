import type { ComponentProps, ReactNode } from 'react';
import { renderToString } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/operations/shifts/3?tab=medications',
        props: { auth: { user: { id: 1 }, can: {} } },
    }),
    useForm: (data: unknown) => ({ data, errors: {}, processing: false }),
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: {},
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/page-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({ PageHero: () => null }));
vi.mock('@/components/operations/shift-medication-card', () => ({
    default: () => <div>Shift medication content</div>,
}));
vi.mock('./components/use-create-shift-launcher', () => ({
    useCreateShiftLauncher: () => ({ dialog: null }),
}));

import ShiftShow from './show';

afterEach(() => vi.unstubAllGlobals());

describe('medication shift entry point during server rendering', () => {
    it.each([true, false])(
        'selects only an authorised medication tab with permission %s',
        (canViewMedication) => {
            const props: ComponentProps<typeof ShiftShow> = {
                shift: {
                    id: 3,
                    client_id: 16,
                    user_id: 1,
                    starts_at: '2026-10-07T07:00:00+13:00',
                    ends_at: '2026-10-07T15:00:00+13:00',
                    status: 'completed',
                    client: {
                        id: 16,
                        first_name: 'Synthetic',
                        last_name: 'Person',
                    },
                    staff: null,
                    tasks: [],
                },
                handover: [],
                notes: [],
                incidents: [],
                incidentTemplates: [],
                forms: { available: [], submissions: [] },
                medications: null,
                medicationWitnesses: [],
                transports: [],
                client_safety: null,
                links: { client_care: null },
                can: {
                    add_note: false,
                    create_incident: false,
                    view_forms: false,
                    submit_form: false,
                    view_medication: canViewMedication,
                    record_medication: false,
                    record_controlled_medication: false,
                    request_replacement: false,
                    cancel_replacement: false,
                },
            };
            vi.stubGlobal('window', undefined);
            const container = document.createElement('div');
            container.innerHTML = renderToString(<ShiftShow {...props} />);
            const selected = container.querySelector(
                '[role="tab"][aria-selected="true"]',
            );
            expect(selected?.textContent).toContain(
                canViewMedication ? 'Medications' : 'Tasks',
            );
            if (!canViewMedication) {
                expect(
                    [...container.querySelectorAll('[role="tab"]')].some(
                        (tab) => tab.textContent?.includes('Medications'),
                    ),
                ).toBe(false);
            }
        },
    );
});
