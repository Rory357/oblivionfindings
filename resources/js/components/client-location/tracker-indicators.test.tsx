import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import TrackerIndicators from './tracker-indicators';

afterEach(cleanup);
const time = '2026-09-21T00:00:00Z';

it('keeps unknown motion and unavailable fall detection separate from device activity', () => {
    render(<TrackerIndicators status="active" />);
    expect(screen.getByLabelText('Device status: active')).toBeVisible();
    expect(
        screen.getByRole('button', { name: /^Motion: Unknown/ }),
    ).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: /^Fall detection: Unavailable/ }),
    );
    expect(
        screen.getByText(
            /Sensor support and whether detection is enabled are not confirmed/,
        ),
    ).toBeVisible();
    expect(screen.queryByText(/No falls|Detection enabled/)).toBeNull();
});

it('shows reported motion and a tappable report time, without inferring motion from active status', () => {
    const view = render(
        <TrackerIndicators
            status="active"
            motion_status="moving"
            motion_reported_at={time}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: /^Motion: In motion/ }));
    expect(screen.getByText('The tracker reported movement.')).toBeVisible();
    expect(screen.getByText(/^Reported Mon/)).toHaveTextContent('21 Sept');
    view.rerender(
        <TrackerIndicators
            status="active"
            motion_status="stationary"
            motion_reported_at={time}
        />,
    );
    expect(
        screen.getByRole('button', { name: /^Motion: Stationary/ }),
    ).toBeVisible();
    view.rerender(
        <TrackerIndicators
            status="active"
            motion_status="moving"
            motion_reported_at="invalid"
        />,
    );
    expect(
        screen.getByRole('button', { name: /^Motion: Unknown/ }),
    ).toBeVisible();
});

it('distinguishes a recorded fall from a man-down event and does not claim an unresolved alert', () => {
    const view = render(
        <TrackerIndicators
            status="active"
            fall_report_type="fall_detected"
            fall_reported_at={time}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: /^Fall detection: Fall reported/ }),
    );
    expect(screen.getByText(/A fall was reported by the device/)).toBeVisible();
    expect(
        screen.getByText(/does not show whether the alert has been resolved/),
    ).toBeVisible();
    view.rerender(
        <TrackerIndicators
            status="active"
            fall_report_type="man_down"
            fall_reported_at={time}
        />,
    );
    expect(
        screen.getByRole('button', {
            name: /^Fall detection: Man-down report/,
        }),
    ).toBeVisible();
    expect(screen.getByText(/not confirmation of a fall/)).toBeVisible();
    view.rerender(
        <TrackerIndicators status="active" fall_report_type="fall_detected" />,
    );
    expect(
        screen.getByRole('button', { name: /^Fall detection: Unavailable/ }),
    ).toBeVisible();
});
