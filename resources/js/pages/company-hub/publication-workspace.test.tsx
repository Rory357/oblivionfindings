import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PublicationWorkspace } from './_publication-workspace';
import { hubData } from './_test-fixtures';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post, on: () => vi.fn() } }));

function workspace(
    patch: Partial<Parameters<typeof PublicationWorkspace>[0]> = {},
) {
    const props = {
        pageId: 4,
        kind: 'news',
        version: 6,
        content: hubData().home,
        readiness: {
            actor_id: 2,
            page_id: 4,
            version: 6,
            editable: true,
            content_ready: true,
            blocked_reason: null,
            blocking_revision_id: null,
            issues: [],
        },
        revisions: [
            {
                id: 9,
                state: 'approved',
                draft_version: 3,
                created_at: '2026-10-01T00:00:00Z',
                publish_at: null,
                is_live: true,
            },
        ],
        canPublish: true,
        dirty: false,
        saving: false,
        busy: false,
        sites: [{ id: 2, name: 'Support office' }],
        departments: [],
        owners: [
            { id: 2, name: 'Current editor' },
            { id: 3, name: 'Another editor' },
        ],
        owner: '2',
        currentOwner: 2,
        onOwner: vi.fn(),
        onAssign: vi.fn(),
        onContent: vi.fn(),
        onSave: vi.fn().mockResolvedValue(true),
        onBack: vi.fn(),
        onFix: vi.fn(),
        onReview: vi.fn().mockResolvedValue(true),
        onPublished: vi.fn(),
        ...patch,
    };
    return { props, ...render(<PublicationWorkspace {...props} />) };
}
beforeEach(() => post.mockReset());
function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByRole('combobox', { name: label }), {
        key: 'ArrowDown',
    });
    fireEvent.click(screen.getByRole('option', { name: option }));
}

describe('publication workspace', () => {
    it('reviews on the page and requires explicit confirmation for the exact saved version', () => {
        const { props } = workspace();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Ready for your people?' }),
        ).toHaveFocus();
        expect(
            screen.getByLabelText('Publication content preview'),
        ).toHaveAttribute('inert');
        expect(
            screen.getByText(
                'Published version 3 stays visible while you prepare this draft.',
            ),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Publish now' }));
        expect(post).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Audience')).toBeDisabled();
        const confirm = screen.getByRole('button', { name: 'Confirm' });
        fireEvent.click(confirm);
        fireEvent.click(confirm);
        expect(post).toHaveBeenCalledOnce();
        expect(post).toHaveBeenCalledWith(
            '/company-hub/manage/4/publish',
            { version: 6, publish_at: null, expires_at: null },
            expect.any(Object),
        );
        expect(
            screen.getByRole('button', { name: 'Back to draft' }),
        ).toBeDisabled();
        act(() => post.mock.calls[0][2].onSuccess());
        expect(props.onPublished).toHaveBeenCalledOnce();
    });

    it('converts an explicitly scheduled New Zealand time and validates expiry before sending', () => {
        workspace();
        fireEvent.click(
            screen.getByRole('radio', { name: /Schedule publication/ }),
        );
        fireEvent.change(
            screen.getByLabelText('Publish at (New Zealand time)'),
            { target: { value: '2099-10-14T09:00' } },
        );
        fireEvent.change(
            screen.getByLabelText('Optional expiry (New Zealand time)'),
            { target: { value: '2099-10-13T09:00' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Schedule publication' }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'expiry after publication',
        );
        expect(post).not.toHaveBeenCalled();
        fireEvent.change(
            screen.getByLabelText('Optional expiry (New Zealand time)'),
            { target: { value: '2099-10-15T09:00' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Schedule publication' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        expect(post).toHaveBeenCalledWith(
            '/company-hub/manage/4/publish',
            {
                version: 6,
                publish_at: '2099-10-13T20:00:00.000Z',
                expires_at: '2099-10-14T20:00:00.000Z',
            },
            expect.any(Object),
        );
    });

    it.each([
        { canPublish: false },
        { content: { ...hubData().home, requires_approval: true } },
    ])(
        'keeps review-only permissions on their canonical submit path %j',
        (patch) => {
            workspace(patch);
            expect(
                screen.getByRole('radio', { name: /Publish now/ }),
            ).toBeDisabled();
            expect(
                screen.getByRole('radio', { name: /Schedule publication/ }),
            ).toBeDisabled();
            expect(
                screen.queryByLabelText('Optional expiry (New Zealand time)'),
            ).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Submit for review' }),
            );
            fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
            expect(post).toHaveBeenCalledWith(
                '/company-hub/manage/4/submit',
                { version: 6 },
                expect.any(Object),
            );
        },
    );

    it('preserves timing choices after a rejected publication and guards return to the draft', () => {
        const { props } = workspace();
        fireEvent.change(
            screen.getByLabelText('Optional expiry (New Zealand time)'),
            { target: { value: '2099-10-15T09:00' } },
        );
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Publish now' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        act(() => {
            post.mock.calls[0][2].onError({
                version: 'The saved version changed.',
            });
            post.mock.calls[0][2].onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The saved version changed.',
        );
        expect(
            screen.getByLabelText('Optional expiry (New Zealand time)'),
        ).toHaveValue('2099-10-15T09:00');
        fireEvent.click(screen.getByRole('button', { name: 'Back to draft' }));
        fireEvent.click(screen.getByRole('button', { name: 'Keep reviewing' }));
        expect(props.onBack).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Back to draft' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard choices' }),
        );
        expect(props.onBack).toHaveBeenCalledOnce();
    });

    it('keeps unsaved content and ownership changes out of publication', async () => {
        const { props } = workspace({ dirty: true, owner: '3' });
        expect(
            screen.getByRole('button', { name: 'Publish now' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Update owner' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Save changes & update checks',
            }),
        );
        await waitFor(() => expect(props.onSave).toHaveBeenCalledOnce());
        choose('Audience', 'Selected sites');
        expect(props.onContent).toHaveBeenCalledWith('audience', {
            type: 'sites',
            ids: [],
        });
        expect(post).not.toHaveBeenCalled();
    });

    it('ignores a late publication callback after the workspace unmounts', () => {
        const { props, unmount } = workspace();
        fireEvent.click(screen.getByRole('button', { name: 'Publish now' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        unmount();
        act(() => {
            post.mock.calls[0][2].onSuccess();
            post.mock.calls[0][2].onFinish();
        });
        expect(props.onPublished).not.toHaveBeenCalled();
    });
    it('requires checking saved versions after an unconfirmed transport completion', async () => {
        const { props } = workspace();
        fireEvent.click(screen.getByRole('button', { name: 'Publish now' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        act(() => post.mock.calls[0][2].onFinish());
        expect(screen.getByRole('alert')).toHaveTextContent(
            'couldn’t confirm the publication outcome',
        );
        expect(
            screen.getByRole('button', { name: 'Publish now' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved versions' }),
        );
        await waitFor(() => expect(props.onReview).toHaveBeenCalledOnce());
        expect(post).toHaveBeenCalledOnce();
    });
    it('keeps uncertain publication locked when the canonical refresh fails', async () => {
        const { props } = workspace({
            onReview: vi.fn().mockResolvedValue(false),
        });
        fireEvent.click(screen.getByRole('button', { name: 'Publish now' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        act(() => post.mock.calls[0][2].onFinish());
        fireEvent.click(screen.getByRole('button', { name: 'Back to draft' }));
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'could not be refreshed',
            ),
        );
        expect(props.onBack).not.toHaveBeenCalled();
        expect(props.onReview).toHaveBeenCalledOnce();
        expect(
            screen.getByRole('button', { name: 'Publish now' }),
        ).toBeDisabled();
    });
    it('asks before changing owner when timing choices would be lost', () => {
        const { props } = workspace({ owner: '3' });
        fireEvent.change(
            screen.getByLabelText('Optional expiry (New Zealand time)'),
            { target: { value: '2099-10-15T09:00' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Update owner' }));
        expect(props.onAssign).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep reviewing' }));
        expect(
            screen.getByLabelText('Optional expiry (New Zealand time)'),
        ).toHaveValue('2099-10-15T09:00');
        fireEvent.click(screen.getByRole('button', { name: 'Update owner' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard choices' }),
        );
        expect(props.onAssign).toHaveBeenCalledOnce();
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
