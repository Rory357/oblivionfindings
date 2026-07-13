import { configure, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// The app marks elements with `data-test` (not the RTL default `data-testid`).
configure({ testIdAttribute: 'data-test' });

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
}));

import { AbcEntryDialog } from '@/components/clients/profile/abc-dialog';
import { BehaviourAbcTab } from '@/components/clients/profile/tabs/behaviour-abc';

const patterns = {
    window_days: 30,
    entry_count: 5,
    concern_note_count: 0,
    escalated_count: 1,
    with_harm_count: 0,
    function_breakdown: [
        { key: 'escape_avoidance', label: 'Escape / avoidance', count: 3 },
        { key: 'attention_social', label: 'Attention / social', count: 2 },
    ],
    intensity_mix: { low: 2, medium: 2, high: 1 },
    top_settings: [{ label: 'Dining room', count: 3 }],
    top_strategies: [{ label: 'Quiet space', count: 2 }],
    top_behaviour_tags: [{ label: 'Pacing', count: 2 }],
    daily_series: [{ date: '2026-06-10', entries: 2, concerns: 0 }],
    summary: {
        entries_90d: 3,
        avg_duration_seconds: 240,
        trend_pct: -40,
        top_antecedent: 'Noise',
        entries_by_month: [
            { key: '2025-12', label: 'Dec', count: 2 },
            { key: '2026-01', label: 'Jan', count: 1 },
            { key: '2026-05', label: 'May', count: 0 },
        ],
    },
};

const entry = {
    id: 10,
    occurred_at: '2026-06-10T20:00:00.000Z',
    setting: 'Dining room',
    antecedent: 'Noisy dining room',
    behaviour: 'Paced the hallway',
    consequence: 'Offered a quiet space',
    behaviour_function: 'escape_avoidance',
    behaviour_function_label: 'Escape / avoidance',
    intensity: 'medium',
    escalated: true,
    harm_occurred: false,
    requires_followup: false,
    recorder: { id: 1, name: 'Aroha' },
};

function mockFetch(data: unknown[], total = data.length) {
    global.fetch = vi.fn().mockImplementation(async (input: RequestInfo | URL) => {
        const url =
            typeof input === 'string'
                ? input
                : input instanceof URL
                  ? input.toString()
                  : input.url;

        if (url.includes('/health-safety/restraints/clients/')) {
            return {
                ok: true,
                json: async () => ({
                    active_plan: null,
                    recent_events: [],
                    total_events: 0,
                }),
            };
        }

        return {
            ok: true,
            json: async () => ({
                data,
                current_page: 1,
                last_page: 1,
                total,
            }),
        };
    }) as unknown as typeof fetch;
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('BehaviourAbcTab', () => {
    it('renders the headline stat strip and the deeper PBS analytics', async () => {
        mockFetch([]);
        render(
            <BehaviourAbcTab
                clientId={1}
                patterns={patterns}
                canRecord
                onNewEntry={vi.fn()}
                onOpenEntry={vi.fn()}
                refreshToken={0}
            />,
        );

        // Design stat strip
        expect(screen.getByText('Entries (90 days)')).toBeTruthy();
        expect(screen.getByText('Avg duration')).toBeTruthy();
        expect(screen.getByText('4m')).toBeTruthy();
        expect(screen.getByText('-40%')).toBeTruthy();
        expect(screen.getByText('Noise')).toBeTruthy();
        expect(screen.getByText('Entries by month')).toBeTruthy();
        // Deeper patterns card
        expect(screen.getByText('Function of behaviour')).toBeTruthy();
        expect(screen.getByText('Escape / avoidance')).toBeTruthy();
    });

    it('lists fetched ABC entries and opens one on click', async () => {
        mockFetch([entry]);
        const onOpenEntry = vi.fn();
        render(
            <BehaviourAbcTab
                clientId={1}
                patterns={patterns}
                canRecord
                onNewEntry={vi.fn()}
                onOpenEntry={onOpenEntry}
                refreshToken={0}
            />,
        );

        // The row arrives after the lazy fetch resolves.
        const row = await screen.findByText('Paced the hallway');
        expect(row).toBeTruthy();
        expect(screen.getAllByText('Escape / avoidance').length).toBeGreaterThan(0);

        fireEvent.click(screen.getAllByTestId('abc-entry-row')[0]);
        expect(onOpenEntry).toHaveBeenCalledWith(expect.objectContaining({ id: 10 }));
    });

    it('fires onNewEntry from the header CTA', async () => {
        mockFetch([entry]);
        const onNewEntry = vi.fn();
        render(
            <BehaviourAbcTab
                clientId={1}
                patterns={patterns}
                canRecord
                onNewEntry={onNewEntry}
                onOpenEntry={vi.fn()}
                refreshToken={0}
            />,
        );

        fireEvent.click(screen.getByTestId('abc-new-entry'));
        expect(onNewEntry).toHaveBeenCalledTimes(1);
    });

    it('shows the empty state with a CTA when there are no entries', async () => {
        mockFetch([], 0);
        const onNewEntry = vi.fn();
        render(
            <BehaviourAbcTab
                clientId={1}
                patterns={undefined}
                canRecord
                onNewEntry={onNewEntry}
                onOpenEntry={vi.fn()}
                refreshToken={0}
            />,
        );

        expect(await screen.findByText('No ABC entries yet')).toBeTruthy();
        fireEvent.click(screen.getByTestId('abc-log-empty-cta'));
        expect(onNewEntry).toHaveBeenCalledTimes(1);
    });
});

describe('AbcEntryDialog', () => {
    it('mounts the Add-Client-style create wizard with the ABC steps', () => {
        render(
            <AbcEntryDialog
                open
                onClose={vi.fn()}
                clientId={1}
                clientLabel="Tane · Tūī House"
                preferredName="Tane"
            />,
        );

        // Context step is first; the A · B · C and Analysis steps are in the rail.
        expect(screen.getByText('Setting the scene')).toBeTruthy();
        expect(screen.getByText('A · B · C')).toBeTruthy();
        expect(screen.getByText('Analysis')).toBeTruthy();
        expect(screen.getByTestId('abc-occurred-at')).toBeTruthy();
        expect(screen.getByTestId('abc-continue')).toBeTruthy();
    });

    it('reaches the A·B·C fields and the function picker on the analysis step', () => {
        render(
            <AbcEntryDialog
                open
                onClose={vi.fn()}
                clientId={1}
                preferredName="Tane"
            />,
        );

        // Step 0 → 1 (A · B · C)
        fireEvent.click(screen.getByTestId('abc-continue'));
        const antecedent = screen.getByTestId('abc-antecedent');
        expect(antecedent).toBeTruthy();

        // Fill A·B·C so the step is valid, then advance to Analysis.
        fireEvent.change(antecedent, { target: { value: 'Noisy room' } });
        fireEvent.change(screen.getByTestId('abc-behaviour'), { target: { value: 'Paced' } });
        fireEvent.change(screen.getByTestId('abc-consequence'), { target: { value: 'Quiet space' } });
        fireEvent.click(screen.getByTestId('abc-continue'));

        // Analysis step exposes the PBS function tiles.
        expect(screen.getByText('Escape / avoidance')).toBeTruthy();
        expect(screen.getByText('Sensory / automatic')).toBeTruthy();
    });
});
