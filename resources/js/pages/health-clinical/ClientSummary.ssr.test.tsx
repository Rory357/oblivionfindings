import type { ReactNode } from 'react';
import { renderToString } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

const sourceUrl =
    '/health-clinical/clients/16/summary?date=2026-10-07#allergies';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: sourceUrl, props: {} }),
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: ({ title, actions }: { title: string; actions: ReactNode }) => (
        <header>
            {title}
            {actions}
        </header>
    ),
    PageLayout: ({
        hero,
        children,
    }: {
        hero: ReactNode;
        children: ReactNode;
    }) => (
        <main>
            {hero}
            {children}
        </main>
    ),
}));

import ClientSummary from './ClientSummary';

afterEach(() => vi.unstubAllGlobals());

describe('clinical allergy entry point during server rendering', () => {
    it.each([true, false])(
        'renders safely with allergy management permission %s and keeps the exact return context',
        (canManage) => {
            vi.stubGlobal('window', undefined);
            const html = renderToString(
                <ClientSummary
                    client={{
                        id: 16,
                        first_name: 'Synthetic',
                        last_name: 'Person',
                    }}
                    summary={{
                        allergy_record: {
                            status: 'recorded',
                            entries: [
                                {
                                    key: 'test-allergy',
                                    allergen: 'Shellfish',
                                    severity: 'life_threatening',
                                    reaction: 'Anaphylaxis',
                                },
                            ],
                            reviewed: null,
                            digest: 'synthetic',
                        },
                        allergy_management_url: canManage
                            ? '/operations/clients/16?tab=medical&medical_section=allergies'
                            : null,
                        medical_profile: null,
                        recent_observations: [],
                        active_protocols: [],
                        recent_events: [],
                    }}
                    observation_types={{}}
                    event_types={{}}
                />,
            );
            const container = document.createElement('div');
            container.innerHTML = html;
            const managementLink = [...container.querySelectorAll('a')].find(
                (link) =>
                    link.textContent === 'Manage allergies in health profile',
            );
            if (!canManage) {
                expect(managementLink).toBeUndefined();
                return;
            }
            const target = new URL(
                managementLink!.getAttribute('href')!,
                'https://medication.invalid',
            );
            expect(target.pathname).toBe('/operations/clients/16');
            expect(target.searchParams.get('tab')).toBe('medical');
            expect(target.searchParams.get('medical_section')).toBe(
                'allergies',
            );
            expect(target.searchParams.get('return_to')).toBe(sourceUrl);
            expect(container.textContent).toContain('Anaphylaxis');
        },
    );
});
