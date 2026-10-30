import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { TeamUpdates } from './digest-panel';
import type { MyDayNotification } from '../lib/types';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => <a href={href} {...props}>{children}</a>,
    router: { visit: vi.fn() },
}));
vi.mock('@/hooks/use-my-day-labels', () => ({ useMyDayLabels: () => (key: string) => key }));
const id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const typed: MyDayNotification = {
    id, title: 'Kia ora whānau — 你好 👋\nAn update for the team', at: 'Today',
    broadcast: { schema_version: 1, notification_id: id, recipient_viewer_id: 7,
        reader_read_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', href: `/notifications?notification=${id}` },
};
const ordinary = { id: 'ordinary', title: 'Ordinary update', at: 'Today' };
const refresh = vi.fn();
describe('Team updates discovery', () => {
    it('hides unverified typed text/count/link, preserves ordinary updates and provides a read-only recovery', () => {
        render(<TeamUpdates notifications={[ordinary, typed]} broadcastUpdates={{ items: null, loading: false, refresh }} />);
        expect(screen.getByText('Ordinary update')).toBeInTheDocument();
        expect(screen.queryByText(/Kia ora/)).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Read full message' })).not.toBeInTheDocument();
        expect(screen.getByText(/1 preview shown/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Check staff messages' }));
        expect(refresh).toHaveBeenCalledOnce();
        expect(screen.getByRole('link', { name: 'View all updates' })).toHaveAttribute('href', '/notifications');
    });
    it('links the exact admitted message, keeps Unicode plain text and removes it on invalidation', () => {
        const { rerender } = render(<TeamUpdates notifications={[typed]} broadcastUpdates={{ items: [typed], loading: false, refresh }} />);
        expect(screen.getByText(/Kia ora whānau/)).toHaveTextContent('你好 👋');
        expect(screen.getByRole('link', { name: 'Read full message' })).toHaveAttribute('href', typed.broadcast!.href);
        expect(screen.queryByRole('button', { name: /read|acknowledge/i })).not.toBeInTheDocument();
        rerender(<TeamUpdates notifications={[typed]} broadcastUpdates={{ items: null, loading: false, refresh }} />);
        expect(screen.queryByText(/Kia ora/)).not.toBeInTheDocument();
        expect(screen.queryByText(/1 preview shown/)).not.toBeInTheDocument();
    });
    it('does not reuse matching IDs from a different payload snapshot', () => {
        render(<TeamUpdates notifications={[{ ...typed }]} broadcastUpdates={{ items: [typed], loading: false, refresh }} />);
        expect(screen.queryByText(/Kia ora/)).not.toBeInTheDocument();
    });
    it('does not call an unavailable preview an empty inbox', () => {
        render(<TeamUpdates notifications={[]} broadcastUpdates={{ items: null, loading: true, refresh }} />);
        expect(screen.getByRole('button', { name: 'Checking messages…' })).toBeDisabled();
        expect(screen.queryByText(/No updates/)).not.toBeInTheDocument();
    });
});
