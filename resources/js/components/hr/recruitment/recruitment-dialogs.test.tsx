import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BulkEmailDialog } from './bulk-email-dialog';
import { TagManagerDialog } from './tag-manager-dialog';

// Saving a template and removing a tag used browser prompt()/confirm(), and
// deleting a saved template sent straight away. Each now opens a dialog over
// the recruitment dialog, and only that dialog's button sends the request.

type VisitOptions = {
    onStart?: () => void;
    onFinish?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onSuccess?: (page: { props: object }) => void;
};

const router = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router,
    useForm: () => ({
        transform: vi.fn(),
        post: vi.fn(),
        processing: false,
    }),
}));
vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

const settle = (_url: string, _data: unknown, options?: VisitOptions) => {
    options?.onStart?.();
    options?.onSuccess?.({ props: {} });
    options?.onFinish?.();
};

beforeEach(() => {
    router.post.mockReset();
    router.post.mockImplementation(settle);
    router.delete.mockReset();
});

describe('Recruitment › Email candidates: save as template', () => {
    const renderEmail = () =>
        render(
            <BulkEmailDialog
                open
                onClose={vi.fn()}
                candidateIds={[1, 2]}
                templates={[
                    {
                        id: 9,
                        name: 'Interview invitation',
                        subject: 'Your interview',
                        body: 'See you soon',
                    },
                ]}
                canManage
            />,
        );

    const writeMessage = () => {
        fireEvent.change(
            screen.getByPlaceholderText('e.g. An update on your application'),
            { target: { value: '  Next steps  ' } },
        );
        fireEvent.change(screen.getByPlaceholderText(/Write your message/), {
            target: { value: 'Thanks for applying.' },
        });
    };

    const templateDialog = () =>
        screen.getByRole('dialog', { name: 'Save as a template' });

    const openTemplateDialog = () => {
        renderEmail();
        writeMessage();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save as template' }),
        );
    };

    it('opens a name dialog instead of saving straight away', () => {
        openTemplateDialog();

        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(templateDialog()).getByLabelText('Template name'),
        ).toBeInTheDocument();
    });

    it('needs a name before saving', () => {
        openTemplateDialog();

        fireEvent.click(
            within(templateDialog()).getByRole('button', {
                name: 'Save template',
            }),
        );

        expect(router.post).not.toHaveBeenCalled();
        expect(templateDialog()).toHaveTextContent(
            'Enter a name for the template.',
        );
    });

    it('refuses a name that another template already uses', () => {
        openTemplateDialog();

        fireEvent.change(
            within(templateDialog()).getByLabelText('Template name'),
            { target: { value: 'interview INVITATION' } },
        );
        fireEvent.click(
            within(templateDialog()).getByRole('button', {
                name: 'Save template',
            }),
        );

        expect(router.post).not.toHaveBeenCalled();
        expect(templateDialog()).toHaveTextContent(
            'A template with this name already exists.',
        );
    });

    it('limits the name to the server maximum of 255 characters', () => {
        openTemplateDialog();

        expect(
            within(templateDialog()).getByLabelText('Template name'),
        ).toHaveAttribute('maxLength', '255');
    });

    it('Cancel saves nothing', async () => {
        openTemplateDialog();

        fireEvent.click(
            within(templateDialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Save as a template' }),
            ).not.toBeInTheDocument(),
        );
        // The email dialog underneath stays open.
        expect(
            screen.getByRole('dialog', { name: 'Email 2 candidates' }),
        ).toBeInTheDocument();
    });

    it('saves the template with the same payload as before', async () => {
        openTemplateDialog();

        fireEvent.change(
            within(templateDialog()).getByLabelText('Template name'),
            { target: { value: '  Next steps update  ' } },
        );
        fireEvent.click(
            within(templateDialog()).getByRole('button', {
                name: 'Save template',
            }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/hr/recruitment/email-templates',
            {
                name: 'Next steps update',
                subject: 'Next steps',
                body: 'Thanks for applying.',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Save as a template' }),
            ).not.toBeInTheDocument(),
        );
    });

    it('shows the server error under the name and stays open', () => {
        router.post.mockImplementation(
            (_url: string, _data: unknown, options?: VisitOptions) => {
                options?.onStart?.();
                options?.onError?.({
                    name: 'The name has already been taken.',
                });
                options?.onFinish?.();
            },
        );
        openTemplateDialog();

        fireEvent.change(
            within(templateDialog()).getByLabelText('Template name'),
            { target: { value: 'Follow-up' } },
        );
        fireEvent.click(
            within(templateDialog()).getByRole('button', {
                name: 'Save template',
            }),
        );

        expect(templateDialog()).toHaveTextContent(
            'The name has already been taken.',
        );
    });
});

describe('Recruitment › Email candidates: delete a saved template', () => {
    const renderEmail = () =>
        render(
            <BulkEmailDialog
                open
                onClose={vi.fn()}
                candidateIds={[1, 2]}
                templates={[
                    {
                        id: 9,
                        name: 'Interview invitation',
                        subject: 'Your interview',
                        body: 'See you soon',
                    },
                ]}
                canManage
            />,
        );

    const confirmDialog = () => screen.getByRole('alertdialog');

    const openDelete = () => {
        renderEmail();
        fireEvent.change(screen.getByRole('combobox'), {
            target: { value: '9' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Delete template' }),
        );
    };

    it('asks before deleting a saved template and sends nothing yet', () => {
        openDelete();

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(confirmDialog()).getByText('Delete this template?'),
        ).toBeInTheDocument();
        expect(confirmDialog()).toHaveTextContent(
            '“Interview invitation” is deleted for everyone who emails candidates.',
        );
        expect(confirmDialog()).toHaveTextContent("This can't be undone.");
    });

    it('Cancel deletes nothing and keeps the email and its message', async () => {
        openDelete();

        fireEvent.click(
            within(confirmDialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.delete).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.getByRole('dialog', { name: 'Email 2 candidates' }),
        ).toBeInTheDocument();
        expect(
            screen.getByPlaceholderText('e.g. An update on your application'),
        ).toHaveValue('Your interview');
    });

    it('deletes the template with the same request once confirmed', async () => {
        router.delete.mockImplementation(
            (_url: string, options?: VisitOptions) => {
                options?.onStart?.();
                options?.onSuccess?.({ props: {} });
                options?.onFinish?.();
            },
        );
        openDelete();

        fireEvent.click(
            within(confirmDialog()).getByRole('button', {
                name: 'Delete template',
            }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/hr/recruitment/email-templates/9',
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(screen.getByRole('combobox')).toHaveValue('');
    });
});

describe('Recruitment › Manage tags: remove a tag', () => {
    const renderTags = () =>
        render(
            <TagManagerDialog
                open
                onClose={vi.fn()}
                tags={[
                    { tag: 'rehire', count: 3 },
                    { tag: 'weekend', count: 1 },
                ]}
                canManage
            />,
        );

    const dialog = () => screen.getByRole('alertdialog');

    it('asks before removing a tag from every candidate', () => {
        renderTags();
        fireEvent.click(
            screen.getByRole('button', { name: 'Delete tag rehire' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Remove this tag?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            '“rehire” comes off the 3 candidates that carry it',
        );
    });

    it('Cancel removes nothing and keeps Manage tags open', async () => {
        renderTags();
        fireEvent.click(
            screen.getByRole('button', { name: 'Delete tag rehire' }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.getByRole('dialog', { name: 'Manage tags' }),
        ).toBeInTheDocument();
    });

    it('removes the chosen tag once confirmed', async () => {
        renderTags();
        fireEvent.click(
            screen.getByRole('button', { name: 'Delete tag weekend' }),
        );
        expect(dialog()).toHaveTextContent(
            '“weekend” comes off the 1 candidate that carries it',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Remove tag' }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/hr/recruitment/tags/delete',
            { tag: 'weekend' },
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});
