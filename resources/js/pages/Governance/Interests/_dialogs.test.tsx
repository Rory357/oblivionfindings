import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const transform = React.useRef<(values: T) => unknown>((v) => v);
            return {
                data,
                errors: {},
                processing: false,
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: (next: (values: T) => unknown) => {
                    transform.current = next;
                },
                post: (url: string) => inertia.post(url, transform.current(data)),
                put: (url: string) => inertia.put(url, transform.current(data)),
            };
        },
    };
});

import {
    DeclareInterestDialog,
    EndInterestDialog,
    interestPeriod,
    type InterestRecord,
} from './_dialogs';
import { declarationBlockedReason } from './MyInterests';

const interest: InterestRecord = {
    id: 21,
    board_member_id: 3,
    member_name: 'Aroha Member',
    interest_type: 'professional',
    description: 'They bid for our cleaning contract.',
    organization_name: 'Acme Cleaning Ltd',
    nature_of_interest: 'Director',
    date_from: '2024-03-01',
    date_to: null,
    is_active: true,
    declared_at: '2026-01-10',
    can_update: true,
};

describe('interest declarations', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.put.mockReset();
    });
    afterEach(cleanup);

    it('uses plain field labels', () => {
        render(<DeclareInterestDialog open onClose={vi.fn()} boardMemberId={3} />);

        expect(screen.getByLabelText(/Organisation or person/)).toBeTruthy();
        expect(screen.getByLabelText(/Your role or connection/)).toBeTruthy();
        expect(screen.getByLabelText(/How could it affect board decisions\?/)).toBeTruthy();
        expect(screen.getByLabelText(/When did the interest start\?/)).toBeTruthy();
        expect(screen.queryByText(/Nature of interest/)).toBeNull();
    });

    it('updates an existing declaration with the same form, prefilled', () => {
        render(<DeclareInterestDialog open onClose={vi.fn()} interest={interest} />);

        const role = screen.getByLabelText(/Your role or connection/) as HTMLInputElement;
        expect(role.value).toBe('Director');
        fireEvent.change(role, { target: { value: 'Chair of the board' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/interests/21');
        expect(payload).toMatchObject({
            nature_of_interest: 'Chair of the board',
            organization_name: 'Acme Cleaning Ltd',
            date_from: '2024-03-01',
        });
        expect(payload).not.toHaveProperty('board_member_id');
    });

    it('records when an interest ended', () => {
        render(<EndInterestDialog interest={interest} onClose={vi.fn()} />);

        fireEvent.change(screen.getByLabelText(/When did it end\?/), {
            target: { value: '2026-08-31' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Mark as ended' }));

        expect(inertia.post).toHaveBeenCalledWith('/governance/interests/21/end', {
            ended_on: '2026-08-31',
        });
    });

    it('describes periods and blocked states plainly', () => {
        expect(interestPeriod({ date_from: '2024-03-01', date_to: null })).toBe('Since 1 Mar 2024');
        expect(interestPeriod({ date_from: '2024-03-01', date_to: '2026-08-31' })).toBe(
            '1 Mar 2024 – 31 Aug 2026',
        );
        expect(declarationBlockedReason(false, true)).toContain(
            'Ask the board secretary to add you as a board member.',
        );
        expect(declarationBlockedReason(true, true)).toBeNull();
    });
});
