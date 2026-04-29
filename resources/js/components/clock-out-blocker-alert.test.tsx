import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ClockOutBlockerAlert from '@/components/clock-out-blocker-alert';

describe('ClockOutBlockerAlert', () => {
    it('renders flashed clock-out blockers inline', () => {
        render(
            <ClockOutBlockerAlert
                blockers={[
                    {
                        key: 'tasks_pending',
                        label: 'Finish shift tasks',
                        detail: '1 shift task is still open.',
                        count: 1,
                        action_url: '#shift-tasks',
                        blocking: true,
                    },
                    {
                        key: 'meds_unsigned',
                        label: 'Sign medication records',
                        detail: '1 scheduled medication still needs a MAR entry.',
                        count: 1,
                        action_url: '/meds/today',
                        blocking: true,
                    },
                ]}
            />,
        );

        expect(screen.getByText('Before you can end this shift')).toBeVisible();
        expect(screen.getByText('Finish shift tasks')).toBeVisible();
        expect(screen.getByText('Sign medication records')).toBeVisible();
    });
});
