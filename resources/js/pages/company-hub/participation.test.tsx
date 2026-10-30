import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubPoll } from './_poll';
import { PollDeadline, pollInstant } from './_poll-time';
import { hubData } from './_test-fixtures';
import type { HubBlock, HubProps } from './_types';

const { request, reload, page } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
    page: { props: { auth: { user: { id: 11 } } } },
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    router: { reload },
    usePage: () => page,
}));
const block: HubBlock = {
    id: 'next',
    type: 'poll',
    title: 'Have your say',
    question: 'What next?',
    options: ['Walk', 'Lunch'],
};
const activity = {
    page_id: 7,
    actor_id: 11,
    revision_id: 8,
    polls: {
        next: {
            key: 'question-key',
            version: 2,
            total: null,
            mine: null,
            counts: null,
            closed: false,
            results_visible: false,
            can_manage: true,
            closes_at: null,
        },
    },
    reactions: {},
    comments: [],
} satisfies NonNullable<HubProps['participation']>;
type Poll = NonNullable<HubProps['participation']>['polls'][string];
const data = (poll: Partial<Poll> = {}) =>
    hubData({
        participation: {
            ...activity,
            polls: { next: { ...activity.polls.next, ...poll } },
        },
    });
const ack = {
    saved: true,
    page_id: 7,
    block_id: 'next',
    key: 'question-key',
    version: 2,
};

describe('Company Hub poll participation', () => {
    it('shows closed sample results to the matching viewer while keeping all commands unavailable', () => {
        render(
            <HubPoll
                block={block}
                data={{
                    ...data({
                        closed: true,
                        results_visible: true,
                        counts: [3, 5],
                        total: 8,
                    }),
                    demo: true,
                }}
            />,
        );
        expect(screen.getByText('3 votes')).toBeVisible();
        expect(screen.getByText('5 votes')).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: /Reopen poll|Change answer|Vote/,
            }),
        ).not.toBeInTheDocument();
        expect(request).not.toHaveBeenCalled();
    });
    beforeEach(() => {
        request.mockReset();
        reload.mockReset();
        page.props.auth.user.id = 11;
    });
    it('keeps totals hidden before voting and sends the current actor, question and version once', async () => {
        let resolve!: (value: unknown) => void;
        request.mockReturnValue(
            new Promise((done) => {
                resolve = done;
            }),
        );
        render(<HubPoll block={block} data={data()} />);
        expect(screen.queryByText(/0 responses|0%/)).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Cast my vote' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('radio', { name: 'Lunch' }));
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cast my vote' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cast my vote' }));
        expect(request).toHaveBeenCalledTimes(1);
        expect(request).toHaveBeenCalledWith(
            '/company-hub/pages/7/interactions',
            'POST',
            {
                actor_id: 11,
                revision_id: 8,
                poll_key: 'question-key',
                kind: 'vote',
                block_id: 'next',
                choice: 1,
                poll_version: 2,
            },
        );
        await act(async () => resolve(ack));
        expect(screen.getByRole('status')).toHaveTextContent(
            'Your vote was counted.',
        );
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['participation', 'selectedPage', 'home'],
            }),
        );
    });
    it('keeps a failed answer selected and does not claim an unconfirmed success', async () => {
        request.mockResolvedValue({ ...ack, page_id: 99 });
        render(<HubPoll block={block} data={data()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Walk' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cast my vote' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(screen.getByRole('radio', { name: 'Walk' })).toBeChecked();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });
    it('shows real rounded results, allows explicit answer changes and keeps closed results readable', () => {
        const { rerender } = render(
            <HubPoll
                block={block}
                data={data({
                    total: 3,
                    mine: 0,
                    counts: [2, 1],
                    results_visible: true,
                })}
            />,
        );
        expect(screen.getByText('67%')).toBeVisible();
        expect(screen.getByText('2 votes')).toBeVisible();
        expect(screen.getByText('Your vote')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Change my answer' }),
        );
        expect(screen.getByRole('radio', { name: 'Walk' })).toBeChecked();
        expect(
            screen.getByRole('button', { name: 'Save my answer' }),
        ).toBeDisabled();
        rerender(
            <HubPoll
                block={block}
                data={data({
                    total: 0,
                    counts: [0, 0],
                    results_visible: true,
                    closed: true,
                })}
            />,
        );
        expect(
            screen.getByText('0 responses · Percentages rounded'),
        ).toBeVisible();
        expect(screen.queryByRole('radio')).not.toBeInTheDocument();
    });
    it('requires explicit confirmation to close voting', async () => {
        request.mockResolvedValue({ ...ack, version: 3 });
        render(<HubPoll block={block} data={data()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Close poll' }));
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep as it is' }));
        expect(
            screen.queryByRole('button', { name: 'Confirm close' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Close poll' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm close' }));
        await waitFor(() => expect(reload).toHaveBeenCalled());
        expect(request).toHaveBeenCalledWith(
            '/company-hub/pages/7/polls/next/state',
            'PUT',
            {
                actor_id: 11,
                revision_id: 8,
                poll_key: 'question-key',
                action: 'close',
                version: 2,
            },
        );
    });
    it('requires an exact future deadline to reopen an elapsed poll and keeps it after failure', async () => {
        request.mockRejectedValue(
            new Error('The poll changed. Refresh before editing.'),
        );
        render(
            <HubPoll
                block={block}
                data={data({ closed: true, closes_at: '2020-10-01T00:00:00Z' })}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reopen poll' }));
        expect(
            screen.getByRole('button', { name: 'Confirm reopen' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Closing date and time'), {
            target: { value: '2099-10-21T17:00' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm reopen' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'The poll changed',
        );
        expect(screen.getByLabelText('Closing date and time')).toHaveValue(
            '2099-10-21T17:00',
        );
        expect(request).toHaveBeenCalledWith(
            '/company-hub/pages/7/polls/next/state',
            'PUT',
            expect.objectContaining({ closes_at: '2099-10-21T17:00:00+13:00' }),
        );
    });
    it('prevents preview/changed-account writes and drops obsolete responses', async () => {
        let resolve!: (value: unknown) => void;
        request.mockReturnValue(
            new Promise((done) => {
                resolve = done;
            }),
        );
        const { rerender } = render(
            <HubPoll block={block} data={data()} preview />,
        );
        expect(screen.getByRole('radio', { name: 'Walk' })).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Close poll' }),
        ).not.toBeInTheDocument();
        rerender(<HubPoll block={block} data={data()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Walk' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cast my vote' }));
        page.props.auth.user.id = 22;
        rerender(
            <HubPoll
                block={block}
                data={data({
                    total: 4,
                    counts: [3, 1],
                    mine: 0,
                    results_visible: true,
                })}
            />,
        );
        await act(async () => resolve(ack));
        expect(reload).not.toHaveBeenCalled();
        expect(screen.queryByText('75%')).not.toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Walk' })).toBeDisabled();
    });
    it('does not expose totals even if an unvoted fixture accidentally contains them', () => {
        render(
            <HubPoll
                block={block}
                data={data({
                    total: 99,
                    counts: [50, 49],
                    mine: null,
                    results_visible: true,
                    can_manage: false,
                })}
            />,
        );
        expect(screen.queryByText(/99 responses|51%/)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Close poll' }),
        ).not.toBeInTheDocument();
    });
    it('applies exact NZ times and handles daylight-saving gaps and duplicate clock readings', () => {
        const onChange = vi.fn();
        render(<PollDeadline value="2026-10-21" onChange={onChange} />);
        expect(onChange).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText('Closing date and time'), {
            target: { value: '2026-10-21T17:00' },
        });
        expect(onChange).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Set closing time' }),
        );
        expect(onChange).toHaveBeenCalledWith('2026-10-21T17:00:00+13:00');
        expect(pollInstant('2026-09-27T02:30', '')).toBe('');
        expect(pollInstant('2026-04-05T02:30', '')).toBe('');
        expect(pollInstant('2026-04-05T02:30', '+12:00')).toBe(
            '2026-04-05T02:30:00+12:00',
        );
    });
});

// Workflow tests isolate the picker; date-fields.test.tsx exercises the actual shared calendar and clock.
vi.mock('./_date-fields', () => ({
    HubDateField: ({
        id,
        label,
        value,
        onChange,
        disabled,
    }: {
        id?: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
        disabled?: boolean;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
    HubDateTimeField: ({
        id,
        label,
        value,
        onChange,
        disabled,
    }: {
        id?: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
        disabled?: boolean;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
