import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CreateHubPageDialog, type CreateHubPageInitial } from './_dialogs';
import type { HubStarterTemplate } from './_types';

const { submit, state } = vi.hoisted(() => ({
    submit: vi.fn(),
    state: { templates: [] as HubStarterTemplate[], actor: 11 },
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            starterTemplates: state.templates,
            auth: { user: { id: state.actor } },
        },
    }),
    useForm: (initial: CreateHubPageInitial) => {
        const [data, setData] = useState(initial);
        const [errors, setErrors] = useState<Record<string, string>>({});
        return {
            data,
            errors,
            processing: false,
            setData: (
                key:
                    | string
                    | ((data: CreateHubPageInitial) => CreateHubPageInitial),
                value?: string,
            ) =>
                typeof key === 'function'
                    ? setData(key)
                    : setData((old) => ({ ...old, [key]: value })),
            post: (
                url: string,
                options: { onSuccess: () => void; onFinish: () => void },
            ) => submit(url, data, { ...options, onError: setErrors }),
        };
    },
    router: { on: () => vi.fn() },
}));

describe('canonical page starter choices', () => {
    beforeEach(() => {
        submit.mockReset();
        state.actor = 11;
        state.templates = [
            {
                key: 'story',
                label: 'Story',
                kind: 'news',
                icon: 'newspaper',
                purpose: 'A story with an image.',
                block_types: ['text', 'image', 'text'],
            },
            {
                key: 'event-recap',
                label: 'Event recap',
                kind: 'news',
                icon: 'images',
                purpose: 'Thank the people who made it happen.',
                block_types: ['image', 'text', 'shoutouts'],
            },
            {
                key: 'resource-collection',
                label: 'Resource collection',
                kind: 'knowledge',
                icon: 'library',
                purpose: 'Bring related guidance together.',
                block_types: ['text', 'resources', 'links'],
            },
        ];
    });
    it('uses server starter purposes/compositions, creates the selected starter and permits a separate page type', async () => {
        render(<CreateHubPageDialog open close={vi.fn()} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        const dialog = within(
            screen.getByRole('region', { name: 'Create a Company Hub page' }),
        );
        expect(
            dialog.getByText('Thank the people who made it happen.'),
        ).toBeVisible();
        fireEvent.click(dialog.getByRole('radio', { name: 'Event recap' }));
        expect(
            dialog.getByRole('combobox', { name: 'Page type' }),
        ).toHaveTextContent('News story');
        fireEvent.click(
            dialog.getByRole('radio', { name: 'Resource collection' }),
        );
        expect(
            dialog.getByRole('combobox', { name: 'Page type' }),
        ).toHaveTextContent('Knowledge resource');
        fireEvent.keyDown(dialog.getByRole('combobox', { name: 'Page type' }), {
            key: 'ArrowDown',
        });
        fireEvent.click(
            await screen.findByRole('option', { name: 'Company page' }),
        );
        fireEvent.change(dialog.getByLabelText('What is this page called?'), {
            target: { value: 'Getting started' },
        });
        fireEvent.click(
            dialog.getByRole('button', {
                name: 'Create draft & open designer',
            }),
        );
        expect(submit).toHaveBeenCalledWith(
            '/company-hub/pages',
            {
                title: 'Getting started',
                template: 'resource-collection',
                kind: 'page',
            },
            expect.any(Object),
        );
    });
    it('keeps team-source identity and restricts its update to the supported story starters', () => {
        render(
            <CreateHubPageDialog
                open
                close={vi.fn()}
                initial={{
                    title: 'Team update',
                    kind: 'news',
                    template: 'story',
                    source_page_id: 6,
                    source_revision_id: 2,
                }}
            />,
        );
        expect(
            screen.queryByRole('radio', { name: 'Resource collection' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Page type/)).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Create draft & open designer',
            }),
        );
        expect(submit).toHaveBeenCalledWith(
            '/company-hub/pages',
            expect.objectContaining({
                source_page_id: 6,
                source_revision_id: 2,
                kind: 'news',
                template: 'story',
            }),
            expect.any(Object),
        );
    });
    it('protects an unfinished title and starter selection with keep editing and discard choices', () => {
        const close = vi.fn();
        render(<CreateHubPageDialog open close={close} />);
        fireEvent.change(screen.getByLabelText('What is this page called?'), {
            target: { value: 'Our next chapter' },
        });
        fireEvent.click(screen.getByRole('radio', { name: 'Event recap' }));
        fireEvent.click(screen.getByRole('button', { name: 'Back to hub' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Discard this page setup?',
        );
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('What is this page called?')).toHaveValue(
            'Our next chapter',
        );
        expect(
            screen.getByRole('radio', { name: 'Event recap' }),
        ).toBeChecked();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        expect(close).toHaveBeenCalledOnce();
        expect(submit).not.toHaveBeenCalled();
    });
    it('treats a changed starter alone as unsaved and a prefilled team setup as clean', () => {
        const close = vi.fn();
        const view = render(<CreateHubPageDialog open close={close} />);
        fireEvent.click(
            screen.getByRole('radio', { name: 'Resource collection' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(close).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toBeVisible();
        view.unmount();
        render(
            <CreateHubPageDialog
                open
                close={close}
                initial={{
                    title: 'Team update',
                    kind: 'news',
                    template: 'story',
                    source_page_id: 6,
                    source_revision_id: 2,
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(close).toHaveBeenCalledOnce();
        expect(submit).not.toHaveBeenCalled();
    });
    it('retains setup after a failed create and blocks duplicate submission and close while pending', () => {
        const close = vi.fn();
        render(<CreateHubPageDialog open close={close} />);
        fireEvent.change(screen.getByLabelText('What is this page called?'), {
            target: { value: 'Our next chapter' },
        });
        const create = screen.getByRole('button', {
            name: 'Create draft & open designer',
        });
        fireEvent.click(create);
        fireEvent.click(create);
        expect(submit).toHaveBeenCalledOnce();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Back to hub' }),
        ).toBeDisabled();
        expect(
            screen.getByLabelText('What is this page called?'),
        ).toBeDisabled();
        expect(
            screen.getByRole('combobox', { name: 'Page type' }),
        ).toBeDisabled();
        act(() => {
            submit.mock.calls[0][2].onError({
                title: 'Please choose another title.',
            });
            submit.mock.calls[0][2].onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Please choose another title.',
        );
        expect(screen.getByLabelText('What is this page called?')).toHaveValue(
            'Our next chapter',
        );
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(create);
        expect(submit).toHaveBeenCalledTimes(2);
        act(() => submit.mock.calls[1][2].onSuccess());
        expect(close).toHaveBeenCalledOnce();
        expect(close).toHaveBeenCalledWith(false);
    });
    it('does not close a new account’s workspace after an old request completes', () => {
        const close = vi.fn();
        const view = render(<CreateHubPageDialog open close={close} />);
        fireEvent.change(screen.getByLabelText('What is this page called?'), {
            target: { value: 'First account draft' },
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Create draft & open designer',
            }),
        );
        state.actor = 12;
        view.rerender(<CreateHubPageDialog open close={close} />);
        act(() => {
            submit.mock.calls[0][2].onSuccess();
            submit.mock.calls[0][2].onFinish();
        });
        expect(close).not.toHaveBeenCalled();
        expect(screen.getByLabelText('What is this page called?')).toHaveValue(
            '',
        );
        expect(
            screen.getByLabelText('What is this page called?'),
        ).toBeEnabled();
    });
});
