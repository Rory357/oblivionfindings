import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { recognitionFormData } from './recognition-receipt';
import {
    RecognitionImagePreview,
    RecognitionSocialFields,
} from './recognition-social-fields';

const props = {
    files: [],
    mentionIds: [],
    onFiles: vi.fn(),
    onMentions: vi.fn(),
    insertMention: vi.fn(),
};
beforeEach(() => {
    vi.clearAllMocks();
    Object.defineProperty(URL, 'createObjectURL', {
        configurable: true,
        value: vi.fn(() => 'blob:private-picture'),
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
        configurable: true,
        value: vi.fn(),
    });
});
afterEach(() => vi.unstubAllGlobals());

describe('recognition pictures and mentions', () => {
    it('keeps HR-only composers away from Hub people endpoints and validates pictures before adding them', () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        render(<RecognitionSocialFields {...props} />);
        expect(fetch).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Mention colleagues' }),
        ).not.toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Add pictures'), {
            target: {
                files: [
                    new File(['<svg/>'], 'unsafe.svg', {
                        type: 'image/svg+xml',
                    }),
                ],
            },
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'JPEG, PNG or WebP',
        );
        expect(props.onFiles).not.toHaveBeenCalled();
        const photo = new File(['picture bytes'], 'thanks.png', {
            type: 'image/png',
        });
        fireEvent.change(screen.getByLabelText('Add pictures'), {
            target: { files: [photo] },
        });
        expect(props.onFiles).toHaveBeenCalledWith([photo]);
    });
    it('bounds picture count and size while preserving already selected pictures', () => {
        const photo = new File(['picture'], 'selected.jpg', {
            type: 'image/jpeg',
        });
        const view = render(
            <RecognitionSocialFields
                {...props}
                files={[photo, photo, photo, photo]}
            />,
        );
        fireEvent.change(screen.getByLabelText('Add pictures'), {
            target: { files: [photo] },
        });
        expect(screen.getByRole('alert')).toHaveTextContent('up to 4');
        expect(props.onFiles).not.toHaveBeenCalled();
        view.rerender(<RecognitionSocialFields {...props} files={[]} />);
        const oversized = new File(['picture'], 'large.png', {
            type: 'image/png',
        });
        Object.defineProperty(oversized, 'size', {
            value: 5 * 1024 * 1024 + 1,
        });
        fireEvent.change(screen.getByLabelText('Add pictures'), {
            target: { files: [oversized] },
        });
        expect(screen.getByRole('alert')).toHaveTextContent('5 MB');
        expect(props.onFiles).not.toHaveBeenCalled();
    });
    it('revokes local preview URLs after removal or close', () => {
        const photo = new File(['picture'], 'preview.png', {
            type: 'image/png',
        });
        const view = render(<RecognitionImagePreview files={[photo]} />);
        expect(
            screen.getByRole('img', { name: 'Attached picture: preview.png' }),
        ).toHaveAttribute('src', 'blob:private-picture');
        view.unmount();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith(
            'blob:private-picture',
        );
    });
    it('selects only options returned for the current signed-in actor', async () => {
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
        render(
            <RecognitionSocialFields
                {...props}
                actorId={7}
                mentionOptions="/company-hub/recognition/mention-options"
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Mention colleagues' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: '@Aroha Bennett' }),
        );
        expect(props.onMentions).toHaveBeenCalledWith([11]);
        expect(props.insertMention).toHaveBeenCalledWith({
            id: 11,
            name: 'Aroha Bennett',
        });
    });
    it('does not expose or select a stale account response', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    actor_id: 99,
                    available: true,
                    people: [{ id: 11, name: 'Hidden old account colleague' }],
                }),
            }),
        );
        render(
            <RecognitionSocialFields
                {...props}
                actorId={7}
                mentionOptions="/company-hub/recognition/mention-options"
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Mention colleagues' }),
        );
        await waitFor(() =>
            expect(screen.getByRole('status')).toHaveTextContent(
                'temporarily unavailable',
            ),
        );
        expect(
            screen.queryByText('@Hidden old account colleague'),
        ).not.toBeInTheDocument();
        expect(props.onMentions).not.toHaveBeenCalled();
    });
    it('encodes pictures mentions and the retry identity in the canonical multipart receipt', () => {
        const photo = new File(['picture'], 'thank-you.webp', {
            type: 'image/webp',
        });
        const form = recognitionFormData(
            {
                to_user_ids: ['11'],
                to_department_id: null,
                category: '',
                impact: 'thank_you',
                message: 'Thank you for helping.',
                mention_ids: [12],
                images: [photo],
                client_request_id: '8c933431-965c-42b3-b5be-99bb3e8097ba',
            },
            7,
        );
        expect(form.get('actor_id')).toBe('7');
        expect(form.get('hub_receipt')).toBe('1');
        expect(form.getAll('to_user_ids[]')).toEqual(['11']);
        expect(form.getAll('mention_ids[]')).toEqual(['12']);
        expect(form.get('images[]')).toBe(photo);
        expect(form.get('client_request_id')).toBe(
            '8c933431-965c-42b3-b5be-99bb3e8097ba',
        );
    });
});
