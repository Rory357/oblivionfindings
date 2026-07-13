import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const dailyNoteHarness = vi.hoisted(() => ({
    delete: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        router: dailyNoteHarness,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (keyOrValues: keyof T | T, value?: unknown) => {
                    if (typeof keyOrValues === 'object') {
                        setDataState(keyOrValues);
                        return;
                    }
                    setDataState((current) => ({
                        ...current,
                        [keyOrValues]: value,
                    }));
                },
            };
        },
    };
});

import {
    DailyNoteWizard,
    dailyNoteValuesFromRecord,
} from '@/pages/operations/clients/dialogs/daily-note-wizard';

describe('daily note draft resume', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('hydrates the complete editable draft in worker-local time', () => {
        expect(
            dailyNoteValuesFromRecord({
                id: 42,
                type: 'progress_note',
                category: 'goal_progress',
                subject: 'Swimming goal',
                goal: 'Swim independently',
                body: 'Practised one length with support.',
                occurred_at: '2026-07-07T10:15:00.000Z',
                shift_id: 9,
                mood_rating: 8,
                behaviour_tags: ['confident', 'engaged'],
                concerns_flags: ['fatigue'],
                follow_up_action: 'Try two lengths next week',
                follow_up_due_at: '2026-07-08T22:30:00.000Z',
                visibility: 'internal',
                appears_on_timeline: true,
                is_draft: true,
                is_flagged: false,
                flagged_reason: null,
                contact_person: null,
                contact_relationship: null,
                contact_method: null,
                attachments: [{ name: 'pool-plan.pdf', size: 1200 }],
            }),
        ).toMatchObject({
            type: 'progress_note',
            category: 'goal_progress',
            subject: 'Swimming goal',
            goal: 'Swim independently',
            body: 'Practised one length with support.',
            occurred_at: '2026-07-07T22:15',
            shift_id: '9',
            mood_rating: 8,
            behaviour_tags: ['confident', 'engaged'],
            concerns_flags: ['fatigue'],
            follow_up_action: 'Try two lengths next week',
            follow_up_due_at: '2026-07-09T10:30',
            is_draft: true,
            attachments: [{ name: 'pool-plan.pdf', size: 1200 }],
        });
    });

    it('submits an existing draft through its canonical update endpoint', () => {
        render(
            <DailyNoteWizard
                clientId={5}
                open
                onOpenChange={vi.fn()}
                note={{
                    id: 42,
                    type: 'daily_note',
                    category: 'activity',
                    subject: 'Pool visit',
                    body: 'Practised floating independently.',
                    occurred_at: '2026-07-07T10:15:00.000Z',
                    visibility: 'internal',
                    is_draft: true,
                    appears_on_timeline: true,
                    can: { update: true },
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByLabelText('What happened?')).toHaveValue(
            'Practised floating independently.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.click(screen.getByRole('button', { name: 'Submit Note' }));

        expect(dailyNoteHarness.put).toHaveBeenCalledWith(
            '/operations/clients/5/daily-notes/42',
            expect.objectContaining({
                body: 'Practised floating independently.',
                is_draft: false,
                type: 'daily_note',
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(dailyNoteHarness.post).not.toHaveBeenCalled();
    });

    it('keeps the draft open and shows the first server validation error', () => {
        dailyNoteHarness.put.mockImplementationOnce(
            (
                _url: string,
                _payload: unknown,
                options: { onError?: (errors: Record<string, string>) => void },
            ) => options.onError?.({ body: 'Write at least two characters.' }),
        );

        render(
            <DailyNoteWizard
                clientId={5}
                open
                onOpenChange={vi.fn()}
                note={{
                    id: 42,
                    type: 'daily_note',
                    category: 'activity',
                    body: 'x',
                    is_draft: true,
                    can: { update: true },
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.click(screen.getByRole('button', { name: 'Submit Note' }));

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Write at least two characters.',
        );
    });

    it('requires confirmation before discarding an author-owned draft', () => {
        render(
            <DailyNoteWizard
                clientId={5}
                open
                onOpenChange={vi.fn()}
                note={{
                    id: 42,
                    type: 'daily_note',
                    category: 'activity',
                    body: 'Draft detail',
                    is_draft: true,
                    can: { update: true, delete: true },
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        expect(
            screen.getByRole('alertdialog', { name: 'Discard draft?' }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));

        expect(dailyNoteHarness.delete).toHaveBeenCalledWith(
            '/operations/clients/5/daily-notes/42',
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
