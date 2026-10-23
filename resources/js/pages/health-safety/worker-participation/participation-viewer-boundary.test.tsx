import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState, type AnchorHTMLAttributes, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ParticipationViewerBoundary } from './participation-viewer-boundary';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    Head: () => null,
    router: { get: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
afterEach(cleanup);
function Draft() {
    const [value, setValue] = useState('');
    return (
        <>
            <p>Private retained participation record</p>
            <input
                aria-label="Open participation form"
                value={value}
                onChange={(event) => setValue(event.target.value)}
            />
        </>
    );
}
describe('Participation viewer boundary', () => {
    it('synchronously masks old record content and unmounts open forms when the account differs', () => {
        const view = render(
            <ParticipationViewerBoundary
                currentActorId={1}
                sourceActorId={1}
                manage
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Private unsent entry' },
        });
        view.rerender(
            <ParticipationViewerBoundary
                currentActorId={2}
                sourceActorId={1}
                manage
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        expect(
            screen.queryByText('Private retained participation record'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(
            screen.getByText('Participation is unavailable'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Worker participation' }),
        ).toBeInTheDocument();
        view.rerender(
            <ParticipationViewerBoundary
                currentActorId={2}
                sourceActorId={2}
                manage
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        expect(screen.getByRole('textbox')).toHaveValue('');
    });
    it('does not mount private content without a valid server source identity', () => {
        const view = render(
            <ParticipationViewerBoundary
                currentActorId={1}
                sourceActorId={undefined}
                manage={false}
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        expect(
            screen.queryByText('Private retained participation record'),
        ).not.toBeInTheDocument();
        view.rerender(
            <ParticipationViewerBoundary
                currentActorId={1}
                sourceActorId={0}
                manage={false}
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });
    it('discards cached form state on manage permission loss while retaining the current read view', () => {
        const view = render(
            <ParticipationViewerBoundary
                currentActorId={1}
                sourceActorId={1}
                manage
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Manager entry' },
        });
        view.rerender(
            <ParticipationViewerBoundary
                currentActorId={1}
                sourceActorId={1}
                manage={false}
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        expect(
            screen.getByText('Private retained participation record'),
        ).toBeInTheDocument();
        expect(screen.getByRole('textbox')).toHaveValue('');
    });
    it('preserves same-account draft state on an ordinary refresh', () => {
        const view = render(
            <ParticipationViewerBoundary
                currentActorId={1}
                sourceActorId={1}
                manage
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Kept entry' },
        });
        view.rerender(
            <ParticipationViewerBoundary
                currentActorId={1}
                sourceActorId={1}
                manage
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        expect(screen.getByRole('textbox')).toHaveValue('Kept entry');
    });
    it('reloads without carrying old actor filters or preserved workspace state', async () => {
        const { router } = await import('@inertiajs/react');
        render(
            <ParticipationViewerBoundary
                currentActorId={2}
                sourceActorId={1}
                manage={false}
            >
                <Draft />
            </ParticipationViewerBoundary>,
        );
        const button = screen.getByRole('button', {
            name: 'Reload participation',
        });
        expect(button).toHaveClass('frontline-tap', 'frontline-focus');
        fireEvent.click(button);
        expect(router.get).toHaveBeenCalledExactlyOnceWith(
            '/health-safety/worker-participation',
            {},
            { preserveState: false, preserveScroll: false },
        );
    });
});
