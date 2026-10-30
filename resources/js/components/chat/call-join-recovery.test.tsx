import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { CallJoinRecovery } from './call-join-recovery';

afterEach(cleanup);
const props = {
    title: 'Care team',
    working: false,
    permissionPending: false,
    stopping: false,
    checked: false,
    closed: false,
    error: '',
    onCheck: vi.fn(),
    onJoin: vi.fn(),
    onStop: vi.fn(),
    onDismiss: vi.fn(),
};

it('moves focus from the lost answer to its explicit next action without connecting', () => {
    const view = render(<CallJoinRecovery {...props} />);
    expect(screen.getByRole('button', { name: 'Check call' })).toHaveFocus();
    fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
    view.rerender(<CallJoinRecovery {...props} working />);
    view.rerender(<CallJoinRecovery {...props} checked />);
    expect(
        screen.getByRole('button', { name: 'Try connecting' }),
    ).toHaveFocus();
    expect(props.onJoin).not.toHaveBeenCalled();
});

it('does not take focus away from work while a status check completes', () => {
    const content = (working: boolean, checked: boolean) => (
        <>
            <input aria-label="Work draft" />
            <CallJoinRecovery {...props} working={working} checked={checked} />
        </>
    );
    const view = render(content(false, false));
    fireEvent.click(screen.getByRole('button', { name: 'Check call' }));
    view.rerender(content(true, false));
    screen.getByLabelText('Work draft').focus();
    view.rerender(content(false, true));
    expect(screen.getByLabelText('Work draft')).toHaveFocus();
});
