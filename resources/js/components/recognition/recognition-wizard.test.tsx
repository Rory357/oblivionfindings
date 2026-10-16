import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RecognitionSubmissionError } from './recognition-receipt';
import { RecognitionWizard } from './recognition-wizard';

const { submitted, backend } = vi.hoisted(() => ({
    submitted: vi.fn(),
    backend: {
        error: null as Record<string, string> | null,
        hold: false,
        finish: null as (() => void) | null,
    },
}));
type FormData = {
    to_user_ids: string[];
    to_department_id: number | null;
    category: string;
    impact: string;
    message: string;
};
vi.mock('@inertiajs/react', () => ({
    router: { on: () => vi.fn() },
    useForm: (initial: FormData) => {
        const [data, setData] = useState(initial);
        const [errors, setErrors] = useState<Record<string, string>>({});
        const [processing, setProcessing] = useState(false);
        return {
            data,
            errors,
            processing,
            setData: (
                key: string | FormData | ((previous: FormData) => FormData),
                value?: unknown,
            ) => {
                if (typeof key === 'string')
                    setData((previous) => ({ ...previous, [key]: value }));
                else setData(key);
            },
            reset: () => setData(initial),
            clearErrors: () => setErrors({}),
            post: (
                url: string,
                options: {
                    onSuccess: () => void;
                    onError: (errors: Record<string, string>) => void;
                    onFinish: () => void;
                },
            ) => {
                submitted(url, data);
                setProcessing(true);
                const finish = () => {
                    if (backend.error) {
                        setErrors(backend.error);
                        options.onError(backend.error);
                    } else options.onSuccess();
                    setProcessing(false);
                    options.onFinish();
                };
                backend.finish = finish;
                if (!backend.hold) finish();
            },
        };
    },
}));

const props = {
    open: true,
    onClose: vi.fn(),
    employees: [{ id: 11, name: 'Aroha Bennett' }],
    teams: [{ id: 3, name: 'Learning team', member_count: 4 }],
    kudosCategories: { teamwork: 'Teamwork' },
    kudosImpacts: { good_job: 'Good Job' },
    defaults: {
        recipients: ['11'],
        category: 'teamwork',
        message: 'Thank you for making everyone feel welcome.',
    },
};
function chooseTeam() {
    fireEvent.click(screen.getByRole('button', { name: 'Whole team' }));
    fireEvent.click(screen.getByRole('radio', { name: 'Learning team' }));
}
function review() {
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Review shout-out' }));
}
beforeEach(() => {
    submitted.mockReset();
    backend.error = null;
    backend.hold = false;
    backend.finish = null;
    props.onClose.mockClear();
});
afterEach(() => vi.unstubAllGlobals());

describe('approved shout-out composer', () => {
    const receipt = {
        saved: true,
        actor_id: 7,
        records: [
            {
                kudos_id: 81,
                feed_post_id: 91,
                recipient_type: 'person',
                to_user_id: 11,
                to_department_id: null,
                reader_url:
                    '/company-hub?view=shoutouts&recognition=81#kudos-81',
            },
        ],
    };
    it('retries an uncertain picture send with the same frozen payload and request identity', async () => {
        Object.defineProperty(URL, 'createObjectURL', {
            configurable: true,
            value: vi.fn(() => 'blob:recognition-test'),
        });
        Object.defineProperty(URL, 'revokeObjectURL', {
            configurable: true,
            value: vi.fn(),
        });
        const photo = new File(['picture'], 'thanks.png', {
            type: 'image/png',
        });
        const send = vi
            .fn()
            .mockRejectedValueOnce(new Error('Disconnected'))
            .mockResolvedValue(receipt);
        render(
            <RecognitionWizard
                {...props}
                submission={{ actorId: 7, send, onView: vi.fn() }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByLabelText('Add pictures'), {
            target: { files: [photo] },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review shout-out' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Try confirming again' }),
        );
        await screen.findByRole('button', { name: 'View shout-out' });
        expect(send).toHaveBeenCalledTimes(2);
        expect(send.mock.calls[1][0]).toBe(send.mock.calls[0][0]);
        expect(send.mock.calls[0][0].client_request_id).toMatch(
            /^[0-9a-f-]{36}$/,
        );
        expect(send.mock.calls[0][0].images[0]).toBe(photo);
    });
    it('clears selected mentions when the author changes the recognition recipients', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    actor_id: 7,
                    available: true,
                    people: [{ id: 11, name: 'Aroha Bennett' }],
                }),
            }),
        );
        const send = vi.fn().mockResolvedValue({
            ...receipt,
            records: [
                {
                    ...receipt.records[0],
                    recipient_type: 'team',
                    to_user_id: null,
                    to_department_id: 3,
                },
            ],
        });
        render(
            <RecognitionWizard
                {...props}
                submission={{
                    actorId: 7,
                    send,
                    onView: vi.fn(),
                    mentionOptions: '/company-hub/recognition/mention-options',
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Mention colleagues' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: '@Aroha Bennett' }),
        );
        expect(
            screen.getByRole('button', {
                name: 'Remove mention Aroha Bennett',
            }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Change' }));
        chooseTeam();
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        await screen.findByRole('button', { name: 'View shout-out' });
        expect(send.mock.calls[0][0].mention_ids).toEqual([]);
    });
    it('opens the exact acknowledged Hub shout-out and keeps the legacy transport separate', async () => {
        const send = vi.fn().mockResolvedValue(receipt),
            onView = vi.fn();
        render(
            <RecognitionWizard
                {...props}
                submission={{ actorId: 7, send, onView }}
            />,
        );
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'View shout-out' }),
        );
        expect(send).toHaveBeenCalledWith(
            expect.objectContaining({
                to_user_ids: ['11'],
                message: props.defaults.message,
            }),
        );
        expect(onView).toHaveBeenCalledWith(receipt.records[0].reader_url);
        expect(submitted).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Recognise someone else' }),
        );
        expect(
            screen.queryByRole('button', { name: 'View shout-out' }),
        ).not.toBeInTheDocument();
    });
    it('retains the draft after a confirmed rejection and permits a corrected retry', async () => {
        const send = vi
            .fn()
            .mockRejectedValueOnce(
                new RecognitionSubmissionError(
                    'Choose a current colleague.',
                    true,
                ),
            )
            .mockResolvedValue(receipt);
        render(
            <RecognitionWizard
                {...props}
                submission={{ actorId: 7, send, onView: vi.fn() }}
            />,
        );
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Choose a current colleague.',
        );
        expect(
            screen.getByRole('article', { name: 'Your card preview' }),
        ).toHaveTextContent(props.defaults.message);
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(
            await screen.findByRole('button', { name: 'View shout-out' }),
        ).toBeVisible();
    });
    it('gives a corrected attempt a fresh send identity after a confirmed rejection', async () => {
        const send = vi
            .fn()
            .mockRejectedValueOnce(
                new RecognitionSubmissionError(
                    'Please review your message.',
                    true,
                ),
            )
            .mockResolvedValue(receipt);
        render(
            <RecognitionWizard
                {...props}
                submission={{ actorId: 7, send, onView: vi.fn() }}
            />,
        );
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        await screen.findByText('Please review your message.');
        fireEvent.click(screen.getByRole('button', { name: 'Edit message' }));
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Thank you for making the next shift easier.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review shout-out' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        await screen.findByRole('button', { name: 'View shout-out' });
        expect(send.mock.calls[1][0].message).toBe(
            'Thank you for making the next shift easier.',
        );
        expect(send.mock.calls[1][0].client_request_id).not.toBe(
            send.mock.calls[0][0].client_request_id,
        );
    });
    it.each(['network', 'wrong actor', 'malformed'])(
        'blocks blind resend after an unconfirmed %s outcome',
        async (mode) => {
            const send =
                mode === 'network'
                    ? vi.fn().mockRejectedValue(new Error('Disconnected'))
                    : vi
                          .fn()
                          .mockResolvedValue(
                              mode === 'wrong actor'
                                  ? { ...receipt, actor_id: 99 }
                                  : { saved: true },
                          );
            render(
                <RecognitionWizard
                    {...props}
                    submission={{ actorId: 7, send, onView: vi.fn() }}
                />,
            );
            review();
            fireEvent.click(
                screen.getByRole('button', { name: 'Share shout-out' }),
            );
            expect(await screen.findByRole('alert')).toHaveTextContent(
                'could not confirm',
            );
            expect(
                screen.getByRole('button', { name: 'Share shout-out' }),
            ).toBeDisabled();
            expect(
                screen.getByRole('link', {
                    name: 'Review shout-outs in a new tab',
                }),
            ).toHaveAttribute('target', '_blank');
            expect(
                screen.queryByRole('article', { name: 'Sent shout-out' }),
            ).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Close shout-out' }),
            );
            expect(
                screen.getByText(/may already have been shared/),
            ).toBeVisible();
            fireEvent.click(
                screen.getByRole('button', { name: 'Keep writing' }),
            );
            expect(send).toHaveBeenCalledTimes(1);
        },
    );
    it('blocks duplicate sends and ignores a response from an earlier opening/account', async () => {
        let finish!: (value: unknown) => void;
        const send = vi.fn().mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(
            <RecognitionWizard
                {...props}
                submission={{ actorId: 7, send, onView: vi.fn() }}
            />,
        );
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(screen.getByRole('button', { name: 'Sharing…' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Close shout-out' }),
        ).toBeDisabled();
        view.rerender(
            <RecognitionWizard
                {...props}
                submission={{ actorId: 8, send, onView: vi.fn() }}
            />,
        );
        await act(async () => finish(receipt));
        expect(
            screen.queryByRole('article', { name: 'Sent shout-out' }),
        ).not.toBeInTheDocument();
        expect(send).toHaveBeenCalledTimes(1);
    });
    it('shows Done without inventing a reader link when access is unavailable', async () => {
        const send = vi.fn().mockResolvedValue({
            ...receipt,
            records: [{ ...receipt.records[0], reader_url: null }],
        });
        render(
            <RecognitionWizard
                {...props}
                submission={{ actorId: 7, send, onView: vi.fn() }}
            />,
        );
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Done' })).toBeVisible(),
        );
        expect(
            screen.queryByRole('button', { name: 'View shout-out' }),
        ).not.toBeInTheDocument();
    });
    it('shares a message-only thank-you through the canonical endpoint', () => {
        render(
            <RecognitionWizard
                {...props}
                defaults={{
                    recipients: ['11'],
                    message: props.defaults.message,
                }}
                endpoint="/hr/my/kudos"
            />,
        );
        expect(
            screen.queryByRole('article', { name: 'Your card preview' }),
        ).not.toBeInTheDocument();
        review();
        expect(
            within(
                screen.getByRole('article', { name: 'Your card preview' }),
            ).getByText('Thank you'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(submitted).toHaveBeenCalledWith(
            '/hr/my/kudos',
            expect.objectContaining({
                category: '',
                impact: 'thank_you',
                message: props.defaults.message,
            }),
        );
        expect(
            screen.getByRole('article', { name: 'Sent shout-out' }),
        ).toHaveTextContent(props.defaults.message);
    });
    it('requires ten meaningful characters without requiring an optional company value', () => {
        render(
            <RecognitionWizard {...props} defaults={{ recipients: ['11'] }} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: '   thanks   ' },
        });
        expect(
            screen.getByRole('button', { name: 'Review shout-out' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Thanks for helping!' },
        });
        expect(
            screen.getByRole('button', { name: 'Review shout-out' }),
        ).toBeEnabled();
    });
    it('keeps the message while optional details are added, removed and reviewed', () => {
        render(
            <RecognitionWizard
                {...props}
                defaults={{
                    recipients: ['11'],
                    message: props.defaults.message,
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByText('Add a personal touch'));
        fireEvent.click(screen.getByRole('button', { name: 'Teamwork' }));
        expect(
            screen.getByRole('article', { name: 'Your card preview' }),
        ).toHaveTextContent('Teamwork');
        fireEvent.click(screen.getByRole('button', { name: 'Remove value' }));
        expect(
            screen.getByRole('article', { name: 'Your card preview' }),
        ).not.toHaveTextContent('Teamwork');
        fireEvent.click(
            screen.getByRole('button', { name: 'Review shout-out' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Edit message' }));
        expect(screen.getByRole('textbox')).toHaveValue(props.defaults.message);
    });
    it('searches supplied work metadata and keeps selected people after clearing no matches', () => {
        render(
            <RecognitionWizard
                {...props}
                defaults={{}}
                employees={[
                    {
                        id: 11,
                        name: 'Aroha Bennett',
                        role: 'Coordinator',
                        site: 'Harbour',
                        team: 'Learning',
                    },
                    {
                        id: 12,
                        name: 'Sam Taylor',
                        role: 'Support worker',
                        site: 'Garden',
                        team: 'Care',
                    },
                ]}
            />,
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search colleagues' }),
            { target: { value: 'Harbour Learning' } },
        );
        expect(
            screen.queryByRole('checkbox', { name: 'Sam Taylor' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Aroha Bennett' }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search colleagues' }),
            { target: { value: 'no-match' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Clear search' }));
        expect(
            screen.getByRole('checkbox', { name: 'Aroha Bennett' }),
        ).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: 'Sam Taylor' }),
        ).not.toBeChecked();
    });
    it('preserves the complete draft on a failed send and permits retry', () => {
        backend.error = { message: 'Sharing failed. Please try again.' };
        render(<RecognitionWizard {...props} />);
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(screen.getByRole('textbox')).toHaveValue(props.defaults.message);
        expect(screen.getByRole('alert')).toHaveTextContent('Sharing failed');
        backend.error = null;
        fireEvent.click(
            screen.getByRole('button', { name: 'Review shout-out' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(submitted).toHaveBeenCalledTimes(2);
        expect(
            screen.getByRole('article', { name: 'Sent shout-out' }),
        ).toBeVisible();
    });
    it('blocks duplicate sends and dismissal while the server is working', () => {
        backend.hold = true;
        render(<RecognitionWizard {...props} />);
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(screen.getByRole('button', { name: 'Sharing…' })).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Close shout-out' }),
        );
        expect(props.onClose).not.toHaveBeenCalled();
        expect(submitted).toHaveBeenCalledTimes(1);
        act(() => backend.finish?.());
        expect(
            screen.getByRole('article', { name: 'Sent shout-out' }),
        ).toBeVisible();
    });
    it('keeps an unsent draft until the author explicitly discards it', () => {
        render(<RecognitionWizard {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Thanks for staying to help.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Close shout-out' }),
        );
        expect(props.onClose).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep writing' }));
        expect(screen.getByRole('textbox')).toHaveValue(
            'Thanks for staying to help.',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Close shout-out' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard shout-out' }),
        );
        expect(props.onClose).toHaveBeenCalledTimes(1);
        expect(submitted).not.toHaveBeenCalled();
    });
});
describe('recognition team recipient', () => {
    it('submits a real team choice without retaining previously selected people', () => {
        render(<RecognitionWizard {...props} />);
        chooseTeam();
        expect(
            screen.getByText('4 current colleagues · one shared shout-out'),
        ).toBeVisible();
        review();
        expect(
            screen.getByText('Whole team · 4 current colleagues'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(submitted).toHaveBeenCalledWith(
            '/hr/feed/kudos',
            expect.objectContaining({
                to_user_ids: [],
                to_department_id: 3,
                category: 'teamwork',
            }),
        );
        expect(
            screen.getByText('Shout-out shared with colleagues.'),
        ).toBeVisible();
    });
    it('returns validation failures to the team chooser without losing the message', () => {
        backend.error = {
            to_department_id:
                'This team is no longer available. Choose another recipient.',
        };
        render(<RecognitionWizard {...props} />);
        chooseTeam();
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(
            screen.getByRole('radio', { name: 'Learning team' }),
        ).toBeVisible();
        expect(screen.getByText(/team is no longer available/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByRole('textbox')).toHaveValue(props.defaults.message);
    });
    it('prevents sending when the current team option is withdrawn and explains empty availability', () => {
        const view = render(<RecognitionWizard {...props} />);
        chooseTeam();
        review();
        view.rerender(<RecognitionWizard {...props} teams={[]} />);
        expect(
            screen.getByRole('button', { name: 'Share shout-out' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByText(/No teams are available/)).toBeVisible();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    });
    it('keeps legacy person sends and clears a team default before recognising someone else', () => {
        const view = render(<RecognitionWizard {...props} teams={undefined} />);
        expect(
            screen.queryByRole('button', { name: 'Whole team' }),
        ).not.toBeInTheDocument();
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        expect(submitted).toHaveBeenLastCalledWith(
            '/hr/feed/kudos',
            expect.objectContaining({
                to_user_ids: ['11'],
                to_department_id: null,
            }),
        );
        view.unmount();
        render(
            <RecognitionWizard
                {...props}
                defaults={{
                    ...props.defaults,
                    recipients: [],
                    department_id: 3,
                }}
            />,
        );
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Share shout-out' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Recognise someone else' }),
        );
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Whole team' }));
        expect(
            screen.getByRole('radio', { name: 'Learning team' }),
        ).not.toBeChecked();
    });
});
