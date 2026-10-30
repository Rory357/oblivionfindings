import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubRequestError } from './_api';
import { IdeaComposer } from './_idea-composer';
import type { HubIdea } from './_types';

const { request, reload } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: request,
}));
vi.mock('@inertiajs/react', () => ({ router: { reload, on: () => vi.fn() } }));
const context = {
    actorId: 11,
    progressReady: true,
    teams: [{ id: 3, name: 'Learning' }],
    ideas: [] as HubIdea[],
};
const saved = {
    id: 24,
    title: 'Share practical tips',
    category: 'Our people',
} as HubIdea;
const choose = (label: string, option: string) => {
    fireEvent.keyDown(screen.getByRole('combobox', { name: label }), {
        key: 'ArrowDown',
    });
    fireEvent.click(screen.getByRole('option', { name: option }));
};
const fill = () => {
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Your idea in a sentence' }),
        { target: { value: 'Share practical tips' } },
    );
    fireEvent.change(
        screen.getByRole('textbox', {
            name: 'What would change, and who would it help?',
        }),
        { target: { value: 'Short workshops would help new colleagues.' } },
    );
    choose('Category', 'Our people');
    choose('Best team to help', 'Learning');
};
const preview = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Preview idea' }));
const share = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Share idea' }));

describe('Idea write, review and share', () => {
    it('allows sample writing and preview but never submits it', () => {
        const close = vi.fn();
        render(
            <IdeaComposer
                {...context}
                actorId={null}
                readOnly
                close={close}
                onView={vi.fn()}
            />,
        );
        fill();
        preview();
        expect(
            screen.getByRole('button', { name: 'Share idea' }),
        ).toBeDisabled();
        fireEvent.submit(
            screen.getByRole('button', { name: 'Share idea' }).closest('form')!,
        );
        expect(request).not.toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Back to editing' }),
        );
        expect(
            screen.getByRole('textbox', { name: 'Your idea in a sentence' }),
        ).toHaveValue('Share practical tips');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard writing' }),
        );
        expect(close).toHaveBeenCalledOnce();
    });
    beforeEach(() => {
        vi.clearAllMocks();
        request.mockReset();
        reload.mockReset();
    });

    it.each([
        ['Tiny', 'Enough detail for colleagues.'],
        ['A useful idea', 'Too brief.'],
    ])(
        'keeps short ideas in writing with useful guidance: %s',
        (title, body) => {
            render(
                <IdeaComposer {...context} close={vi.fn()} onView={vi.fn()} />,
            );
            fireEvent.change(
                screen.getByRole('textbox', {
                    name: 'Your idea in a sentence',
                }),
                { target: { value: title } },
            );
            fireEvent.change(
                screen.getByRole('textbox', {
                    name: 'What would change, and who would it help?',
                }),
                { target: { value: body } },
            );
            preview();
            expect(screen.getByRole('alert')).toHaveTextContent(
                'at least 5 characters',
            );
            expect(
                screen.getByRole('textbox', {
                    name: 'Your idea in a sentence',
                }),
            ).toHaveValue(title);
            expect(
                screen.queryByRole('article', { name: 'Idea preview' }),
            ).not.toBeInTheDocument();
            expect(request).not.toHaveBeenCalled();
        },
    );

    it('previews and edits without writing, then opens only the acknowledged idea from refreshed authorised data', async () => {
        const close = vi.fn(),
            viewIdea = vi.fn();
        request.mockResolvedValue({ saved: true, actor_id: 11, idea_id: 24 });
        const view = render(
            <IdeaComposer {...context} close={close} onView={viewIdea} />,
        );
        fill();
        preview();
        const card = within(
            screen.getByRole('article', { name: 'Idea preview' }),
        );
        expect(card.getByText('Learning')).toBeVisible();
        expect(card.getByRole('heading', { name: saved.title })).toBeVisible();
        expect(request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Back to editing' }),
        );
        expect(
            screen.getByRole('textbox', { name: 'Your idea in a sentence' }),
        ).toHaveValue(saved.title);
        expect(
            screen.getByRole('combobox', { name: 'Best team to help' }),
        ).toHaveTextContent('Learning');
        preview();
        share();
        await screen.findByText('Your idea is on the board.');
        expect(request).toHaveBeenCalledWith('/company-hub/ideas', 'POST', {
            title: saved.title,
            body: 'Short workshops would help new colleagues.',
            category: 'Our people',
            department_id: 3,
            actor_id: 11,
        });
        expect(
            screen.queryByRole('button', { name: 'View idea' }),
        ).not.toBeInTheDocument();
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: [
                    'ideas',
                    'ideaBrowse',
                    'ideaActorId',
                    'selectedIdea',
                    'ideaId',
                    'ideaUnavailable',
                ],
            }),
        );
        act(() => reload.mock.calls[0][0].onFinish());
        expect(
            screen.getByRole('button', { name: 'Refresh board' }),
        ).toBeEnabled();
        view.rerender(
            <IdeaComposer
                {...context}
                ideas={[saved]}
                close={close}
                onView={viewIdea}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'View idea' }));
        expect(viewIdea).toHaveBeenCalledWith(24);
        expect(request).toHaveBeenCalledTimes(1);
    });

    it('requires confirmation before discarding writing through the close control or Escape', () => {
        const close = vi.fn();
        render(<IdeaComposer {...context} close={close} onView={vi.fn()} />);
        fill();
        preview();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(screen.getByRole('alert')).toHaveTextContent('Leave this idea?');
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(
            screen.getByRole('article', { name: 'Idea preview' }),
        ).toHaveTextContent(saved.title);
        fireEvent.click(
            screen.getByRole('button', { name: 'Close idea composer' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard writing' }),
        );
        expect(close).toHaveBeenCalledOnce();
        expect(request).not.toHaveBeenCalled();
    });

    it('locks duplicate shares, editing and dismissal while the request is pending', async () => {
        let finish!: (value: unknown) => void;
        request.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const close = vi.fn();
        render(<IdeaComposer {...context} close={close} onView={vi.fn()} />);
        fill();
        preview();
        share();
        fireEvent.submit(
            screen.getByRole('button', { name: 'Sharing…' }).closest('form')!,
        );
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(
            screen.getByRole('button', { name: 'Close idea composer' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Back to editing' }),
        ).toBeDisabled();
        expect(request).toHaveBeenCalledOnce();
        expect(close).not.toHaveBeenCalled();
        await act(async () =>
            finish({ saved: true, actor_id: 11, idea_id: 24 }),
        );
        expect(screen.getByText('Your idea is on the board.')).toBeVisible();
    });

    it('keeps all writing after validation failure and allows a corrected retry', async () => {
        request
            .mockRejectedValueOnce(
                new HubRequestError('Choose an available team.', 422),
            )
            .mockResolvedValueOnce({ saved: true, actor_id: 11, idea_id: 24 });
        render(<IdeaComposer {...context} close={vi.fn()} onView={vi.fn()} />);
        fill();
        preview();
        share();
        await screen.findByRole('alert');
        expect(
            screen.getByRole('textbox', { name: 'Your idea in a sentence' }),
        ).toHaveValue(saved.title);
        expect(
            screen.getByRole('combobox', { name: 'Category' }),
        ).toHaveTextContent('Our people');
        choose('Best team to help', 'Not sure yet / company-wide');
        preview();
        share();
        await screen.findByText('Your idea is on the board.');
        expect(request.mock.calls[1][2].department_id).toBeNull();
    });

    it.each([
        { saved: true, actor_id: 12, idea_id: 24 },
        { saved: true, actor_id: 11, idea_id: '24' },
        { saved: false, actor_id: 11, idea_id: 24 },
    ])(
        'does not claim success or repeat an unconfirmed save: %j',
        async (result) => {
            request.mockResolvedValue(result);
            render(
                <IdeaComposer {...context} close={vi.fn()} onView={vi.fn()} />,
            );
            fill();
            preview();
            share();
            expect(await screen.findByRole('alert')).toHaveTextContent(
                'couldn’t confirm',
            );
            expect(
                screen.getByRole('button', { name: 'Share idea' }),
            ).toBeDisabled();
            expect(
                screen.queryByText('Your idea is on the board.'),
            ).not.toBeInTheDocument();
            expect(reload).not.toHaveBeenCalled();
        },
    );

    it('ignores a completed request from an unmounted account composer', async () => {
        let finish!: (value: unknown) => void;
        request.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(
            <IdeaComposer
                key={11}
                {...context}
                close={vi.fn()}
                onView={vi.fn()}
            />,
        );
        fill();
        preview();
        share();
        view.rerender(
            <IdeaComposer
                key={12}
                {...context}
                actorId={12}
                close={vi.fn()}
                onView={vi.fn()}
            />,
        );
        await act(async () =>
            finish({ saved: true, actor_id: 11, idea_id: 24 }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', {
                    name: 'Your idea in a sentence',
                }),
            ).toHaveValue(''),
        );
        expect(reload).not.toHaveBeenCalled();
        expect(
            screen.queryByText('Your idea is on the board.'),
        ).not.toBeInTheDocument();
    });

    it('keeps an uncertain share locked and confirms leaving before checking the board', async () => {
        const close = vi.fn();
        request.mockRejectedValue(new TypeError('Network connection lost'));
        render(<IdeaComposer {...context} close={close} onView={vi.fn()} />);
        fill();
        preview();
        share();
        await screen.findByRole('alert');
        expect(
            screen.getByRole('button', { name: 'Share idea' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check idea board' }),
        );
        expect(close).not.toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep this open' }));
        expect(
            screen.getByRole('article', { name: 'Idea preview' }),
        ).toHaveTextContent(saved.title);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check idea board' }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Discard writing and check board',
            }),
        );
        expect(close).toHaveBeenCalledOnce();
        expect(reload).toHaveBeenCalledOnce();
        expect(request).toHaveBeenCalledOnce();
    });
});
