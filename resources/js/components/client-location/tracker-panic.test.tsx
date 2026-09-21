import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import TrackerPanic from './tracker-panic';
afterEach(cleanup);
it('keeps missing and default-false panic states unconfirmed', () => {
    const view = render(<TrackerPanic panic_active={null} />);
    expect(screen.getByText('Status unconfirmed')).toBeVisible();
    expect(screen.queryByText('No active alert')).toBeNull();
    view.rerender(<TrackerPanic panic_active={false} />);
    expect(screen.getByText('Status unconfirmed')).toBeVisible();
    expect(screen.queryByText('No panic events recorded')).toBeNull();
});
it('shows evidenced acknowledgement and ignores an acknowledgement older than the latest safety event', () => {
    const view = render(
        <TrackerPanic
            panic_active={false}
            panic_acknowledged_at="2026-09-21T01:00:00Z"
            last_safety_event_at="2026-09-21T00:00:00Z"
        />,
    );
    expect(screen.getByText('Alert acknowledged')).toBeVisible();
    expect(screen.getByText(/^Acknowledged Mon/)).toBeVisible();
    view.rerender(
        <TrackerPanic
            panic_active={false}
            panic_acknowledged_at="2026-09-20T01:00:00Z"
            last_safety_event_at="2026-09-21T00:00:00Z"
        />,
    );
    expect(screen.getByText('Status unconfirmed')).toBeVisible();
    view.rerender(
        <TrackerPanic panic_active={false} panic_acknowledged_at="malformed" />,
    );
    expect(screen.getByText('Status unconfirmed')).toBeVisible();
});
it('highlights an active panic and preserves the last safety-event time', () => {
    render(
        <TrackerPanic
            panic_active
            last_safety_event_at="2026-09-21T00:00:00Z"
        />,
    );
    expect(screen.getByLabelText('Tracker panic status')).toHaveAttribute(
        'data-active',
        'true',
    );
    expect(screen.getByText('Panic alert active')).toBeVisible();
    expect(screen.getByText(/Last safety event/)).toHaveTextContent('21 Sept');
});
