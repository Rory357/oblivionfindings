import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { CallStartRecovery } from './call-start-recovery';

afterEach(cleanup);
const props = {
    title: 'Care team',
    state: 'unconfirmed' as const,
    cancelling: false,
    working: false,
    permissionPending: false,
    error: '',
    onCheck: vi.fn(),
    onRetry: vi.fn(),
    onJoin: vi.fn(),
    onStop: vi.fn(),
    onDismiss: vi.fn(),
};

it('does not announce an interrupted call while confirmation is still running', () => {
    const view = render(<CallStartRecovery {...props} working />);
    expect(screen.getByRole('heading')).toHaveTextContent(
        'Checking call confirmation',
    );
    expect(
        screen.queryByText('Call confirmation interrupted'),
    ).not.toBeInTheDocument();
    view.rerender(<CallStartRecovery {...props} />);
    expect(screen.getByRole('heading')).toHaveTextContent(
        'Call confirmation interrupted',
    );
});

it('returns keyboard focus to the next recovery action without joining automatically', () => {
    const view = render(<CallStartRecovery {...props} />);
    screen.getByRole('button', { name: 'Check call' }).focus();
    fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
    view.rerender(<CallStartRecovery {...props} working />);
    view.rerender(<CallStartRecovery {...props} state="started" />);
    expect(
        screen.getByRole('button', { name: 'Join existing call' }),
    ).toHaveFocus();
    expect(props.onJoin).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Stop my attempt' }));
    view.rerender(<CallStartRecovery {...props} cancelling working />);
    view.rerender(
        <CallStartRecovery {...props} cancelling state="cancelled" />,
    );
    expect(screen.getByRole('button', { name: 'Dismiss' })).toHaveFocus();
});

it('keeps focus in the work draft if someone returns to work during the check', () => {
    const content = (working: boolean, state: 'unconfirmed' | 'started') => (
        <>
            <input aria-label="Work draft" />
            <CallStartRecovery {...props} working={working} state={state} />
        </>
    );
    const view = render(content(false, 'unconfirmed'));
    screen.getByRole('button', { name: 'Check call' }).focus();
    fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
    view.rerender(content(true, 'unconfirmed'));
    screen.getByLabelText('Work draft').focus();
    view.rerender(content(false, 'started'));
    expect(screen.getByLabelText('Work draft')).toHaveFocus();
});
