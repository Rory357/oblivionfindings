import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { expect, it, vi } from 'vitest';
import { RespiteCheckIn } from './respite-check-in';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));

it('offers preparation when authorised and uses the existing start review when one is available', () => {
    const preparation = { person_id: 1, site_id: 2, service_context_id: 3 };
    const { rerender } = render(
        <RespiteCheckIn stayId={7} preparation={preparation} />,
    );
    expect(
        screen.getByRole('button', { name: 'Prepare support' }),
    ).toBeInTheDocument();
    rerender(
        <RespiteCheckIn
            stayId={7}
            preparation={preparation}
            reviewUrl="/operations/referrals/visible-case?tab=service-start"
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Prepare support' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Review service start' }),
    ).toBeInTheDocument();
    rerender(<RespiteCheckIn stayId={7} />);
    expect(
        screen.queryByRole('button', { name: 'Prepare support' }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
});

it('shows a blocked admission and prevents a duplicate click while saving', () => {
    render(
        <RespiteCheckIn
            stayId={7}
            reviewUrl="/operations/referrals/example?tab=service-start"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Check in' }));
    fireEvent.click(screen.getByRole('button', { name: 'Checking in…' }));
    expect(router.post).toHaveBeenCalledTimes(1);
    const options = vi.mocked(router.post).mock.calls[0][2]!;
    expect(options.errorBag).toBe('respiteCheckIn');
    act(() => {
        options.onError?.({
            service_start: 'The agreement and stay dates need review.',
        });
        options.onFinish?.(
            {} as Parameters<NonNullable<typeof options.onFinish>>[0],
        );
    });
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Check-in not completed',
    );
    expect(screen.getByRole('alert')).toHaveTextContent(
        'The agreement and stay dates need review.',
    );
    expect(screen.getByRole('button', { name: 'Check in' })).toBeEnabled();
    expect(
        screen.getByRole('link', { name: 'Review service start' }),
    ).toHaveAttribute(
        'href',
        '/operations/referrals/example?tab=service-start',
    );
});

it('does not manufacture a private review link when the server omits access', () => {
    render(<RespiteCheckIn stayId={8} reviewUrl={null} />);
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check in' })).toBeEnabled();
});
