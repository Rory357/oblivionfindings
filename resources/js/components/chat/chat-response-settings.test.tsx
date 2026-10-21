import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ChatResponseClock } from './chat-response-clock';
import {
    ChatResponseSettings,
    responsePolicyChanges,
    type ResponsePolicy,
} from './chat-response-settings';

function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByLabelText(label), { key: 'Enter' });
    fireEvent.click(screen.getByRole('option', { name: option }));
}
const empty: ResponsePolicy = {
    response_expectation: '',
    response_schedule: null,
    site_overrides: {},
};
const sites = [
    { id: 8, name: 'Kōwhai House' },
    { id: 9, name: 'Tōtara service' },
];

describe('response settings', () => {
    it('distinguishes site inheritance from an explicit no-promise override', () => {
        const change = vi.fn();
        function Harness() {
            const [value, setValue] = useState<ResponsePolicy>({
                ...empty,
                response_schedule: {
                    timezone: 'Pacific/Auckland',
                    response_minutes: 240,
                    weekly: [{ day: 1, start: '08:00', end: '18:00' }],
                    holidays: [],
                },
            });
            return (
                <ChatResponseSettings
                    value={value}
                    sites={sites}
                    dirty={false}
                    onReview={() => {}}
                    onChange={(next) => {
                        change(next);
                        setValue(next);
                    }}
                />
            );
        }
        render(<Harness />);
        choose('Policy scope', 'Kōwhai House');
        expect(screen.getByLabelText('Response expectation')).toHaveTextContent(
            'Inherited organisation default',
        );
        choose('Response expectation', 'No response expectation configured');
        expect(
            change.mock.lastCall?.[0].site_overrides['8'].response_schedule,
        ).toBeNull();
        expect(
            change.mock.lastCall?.[0].response_schedule.response_minutes,
        ).toBe(240);
        expect(change.mock.lastCall?.[0].site_overrides['9']).toBeUndefined();
        choose('Response expectation', 'Inherited organisation default');
        expect(change.mock.lastCall?.[0].site_overrides['8']).toBeUndefined();
    });

    it('keeps unsaved scope and offers review before switching', () => {
        const review = vi.fn();
        render(
            <ChatResponseSettings
                value={empty}
                sites={sites}
                dirty
                onReview={review}
                onChange={() => {}}
            />,
        );
        choose('Policy scope', 'Kōwhai House');
        expect(screen.getByLabelText('Policy scope')).toHaveTextContent(
            'Organisation defaults',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
        expect(review).toHaveBeenCalledOnce();
    });

    it('reviews each affected site with actual before and after values', () => {
        const changes = responsePolicyChanges(
            empty,
            {
                ...empty,
                site_overrides: {
                    '8': { response_schedule: null },
                    '9': {
                        response_expectation: 'Please contact our care team.',
                    },
                },
            },
            sites,
        );
        expect(changes).toEqual([
            {
                label: 'Kōwhai House · Response hours',
                before: 'Use organisation default: No response expectation configured',
                after: 'No response expectation configured',
            },
            {
                label: 'Tōtara service · Response wording',
                before: 'Use organisation default: No additional wording',
                after: 'Please contact our care team.',
            },
        ]);
    });

    it('does not show a deadline from free text or restricted history', () => {
        const { rerender } = render(
            <ChatResponseClock wording="We usually reply within four hours." />,
        );
        expect(
            screen.getByText('No response expectation configured'),
        ).toBeVisible();
        rerender(
            <ChatResponseClock
                clock={{
                    state: 'history_restricted',
                    due_at: null,
                    working_minutes: 240,
                    timezone: 'Pacific/Auckland',
                }}
            />,
        );
        expect(
            screen.getByText(
                'Response timing is outside your available history',
            ),
        ).toBeVisible();
        expect(screen.queryByText(/Overdue/)).toBeNull();
    });
});
