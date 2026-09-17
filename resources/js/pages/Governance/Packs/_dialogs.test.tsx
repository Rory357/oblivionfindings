import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn(), reload: vi.fn(), post: vi.fn(), get: vi.fn() },
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

import { GenerateBoardPackDialog, splitMeetingsForPacks } from './_dialogs';
import { packState } from './Index';
import { packHeaderChip } from './Show';

vi.mock('@/routes/governance/packs', () => ({
    download: { url: ({ pack }: { pack: number }) => `/governance/packs/${pack}/download` },
}));

const meetings = [
    {
        id: 1,
        title: 'October board meeting',
        scheduled_at: '2026-10-20T04:00:00Z',
        status: 'agenda_final',
        agenda_items_count: 4,
    },
    {
        id: 2,
        title: 'November board meeting',
        scheduled_at: '2026-11-17T04:00:00Z',
        status: 'scheduled',
        agenda_items_count: 0,
    },
];

describe('GenerateBoardPackDialog', () => {
    afterEach(cleanup);

    it('keeps meetings without an agenda out of the picker, with a visible reason', () => {
        expect(splitMeetingsForPacks(meetings).ready.map((m) => m.id)).toEqual([1]);

        render(<GenerateBoardPackDialog isOpen onClose={vi.fn()} meetings={meetings} />);

        expect(screen.getByText('Add an agenda first')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Add an agenda' }).getAttribute('href')).toBe(
            '/governance/meetings/2',
        );
        // The picker only offers the meeting that can have a pack.
        expect(screen.getByRole('button', { name: /October board meeting/ })).toBeTruthy();
        expect(screen.queryByRole('button', { name: /November board meeting/ })).toBeNull();
        // Nothing reads like raw enum values or promises a send.
        expect(screen.getByText(/nothing is sent/i)).toBeTruthy();
        expect(screen.queryByText('AGENDA_FINAL')).toBeNull();
        expect(screen.getByText(/Agenda ready/)).toBeTruthy();
    });

    it('explains that the generate button needs a meeting', () => {
        render(
            <GenerateBoardPackDialog
                isOpen
                onClose={vi.fn()}
                meetings={[meetings[0], { ...meetings[0], id: 3, title: 'Special meeting' }]}
            />,
        );

        const generate = screen.getByRole('button', { name: 'Generate draft pack' });
        expect((generate as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText('Choose a meeting to continue.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /Special meeting/ }));
        expect((generate as HTMLButtonElement).disabled).toBe(false);
    });

    it('shows an empty state when every meeting already has a pack', () => {
        render(<GenerateBoardPackDialog isOpen onClose={vi.fn()} meetings={[]} />);
        expect(screen.getByText('No meetings need a pack')).toBeTruthy();
    });
});

describe('board pack status chips', () => {
    it('use the shared labels, not hand-made ones', () => {
        expect(packState({ build_status: 'published', is_current: true, distributed_at: null })).toEqual({
            label: 'Draft',
            variant: 'neutral',
        });
        expect(
            packState({ build_status: 'published', is_current: true, distributed_at: '2026-09-01' }).label,
        ).toBe('Sent to members');
        expect(packState({ build_status: 'failed', is_current: false, distributed_at: null }).label).toBe(
            "Couldn't be prepared",
        );
    });

    it('only calls a version replaced once the newer version has been sent', () => {
        expect(packHeaderChip('published', true, { id: 9, revision_number: 3, is_distributed: false }).label).toBe(
            'Sent to members',
        );
        expect(packHeaderChip('published', true, { id: 9, revision_number: 3, is_distributed: true }).label).toBe(
            'Replaced by a newer version',
        );
    });
});
