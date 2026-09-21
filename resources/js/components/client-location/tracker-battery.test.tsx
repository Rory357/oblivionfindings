import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import TrackerBattery from './tracker-battery';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it.each([
    ['charging', true, 'Charging', true],
    ['charge_full', true, 'Fully charged', false],
    ['stopped_charging', false, 'Not charging', false],
    ['not_charging', true, 'Not charging', false],
    [null, true, 'External power connected', false],
    [null, null, 'Charging not reported', false],
])(
    'shows the reported power state %s without inferring charging from power alone',
    (state, power, label, animated) => {
        render(
            <TrackerBattery
                battery={64}
                charging_status={state as string | null}
                external_power={power as boolean | null}
            />,
        );
        expect(screen.getByText(label as string)).toBeVisible();
        expect(
            screen.getByRole('region', { name: 'Tracker battery' }),
        ).toHaveAttribute('data-charging', String(animated));
        expect(screen.getByText('64')).toBeVisible();
    },
);

it('keeps missing or invalid battery readings unknown instead of displaying zero or full', () => {
    const { rerender } = render(
        <TrackerBattery battery={null} charging_status="charging" />,
    );
    expect(screen.getByText('Unknown')).toBeVisible();
    expect(screen.getByText('Charging')).toBeVisible();
    rerender(<TrackerBattery battery={140} charging_status={null} />);
    expect(screen.getByText('Unknown')).toBeVisible();
    expect(
        screen.getByRole('region', { name: 'Tracker battery' }),
    ).toHaveAttribute('data-charging', 'false');
    rerender(<TrackerBattery battery={0} charging_status="stopped_charging" />);
    expect(screen.getByText('0')).toBeVisible();
    expect(screen.getByText('Low battery')).toBeVisible();
});

it('keeps charging readable and stops animation when reduced motion is enabled, including a live preference change', () => {
    let reduceMotion = false;
    let change = () => {};
    const remove = vi.fn();
    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => ({
            matches: reduceMotion,
            addEventListener: (_type: string, callback: () => void) => {
                change = callback;
            },
            removeEventListener: remove,
        })),
    );
    const { unmount } = render(
        <TrackerBattery battery={64} charging_status="charging" />,
    );
    const card = screen.getByRole('region', { name: 'Tracker battery' });
    expect(card).toHaveAttribute('data-animated', 'true');
    act(() => {
        reduceMotion = true;
        change();
    });
    expect(card).toHaveAttribute('data-animated', 'false');
    expect(screen.getByText('Charging')).toBeVisible();
    expect(screen.getByText('64')).toBeVisible();
    unmount();
    expect(remove).toHaveBeenCalled();
});
