import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import StaffStatus from './staff-status';

const SHIFT_STATES = [
    'upcoming',
    'starting-soon',
    'active',
    'on-break',
    'ending-soon',
    'late',
    'completed',
    'missed',
    'returned-timesheet',
] as const;

describe('<StaffStatus kind="shift">', () => {
    it.each(SHIFT_STATES)(
        'renders state %s without crashing',
        (state) => {
            const { container } = render(
                <StaffStatus kind="shift" state={state} size="sm" />,
            );
            expect(container).toBeTruthy();
            expect(container.firstChild).not.toBeNull();
        },
    );

    it.each(SHIFT_STATES)(
        'matches snapshot for state %s',
        (state) => {
            const { container } = render(
                <StaffStatus kind="shift" state={state} size="sm" />,
            );
            expect(container.firstChild).toMatchSnapshot();
        },
    );
});
