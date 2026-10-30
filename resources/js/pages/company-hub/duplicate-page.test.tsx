import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DuplicateHubPage, type HubCopyContext } from './_duplicate-page';

const { request, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({ router: { visit, on: () => () => {} } }));
const context: HubCopyContext = {
    actor_id: 2,
    page_id: 4,
    version: 3,
    can_duplicate: true,
    source: 'draft',
    revision_id: null,
    media_versions: { '7': 2 },
    blocked_reason: null,
};
const props = {
    pageId: 4,
    version: 3,
    actorId: 2,
    title: 'Our handbook',
    dirty: false,
    onSave: vi.fn(),
    onClose: vi.fn(),
};
beforeEach(() => {
    vi.clearAllMocks();
    request.mockResolvedValue(context);
});
describe('Independent private page copy', () => {
    it('uses the checked source and media versions, prevents duplicate submission and opens only the confirmed private copy', async () => {
        render(<DuplicateHubPage {...props} />);
        const button = screen.getByRole('button', {
            name: 'Create private copy',
        });
        await waitFor(() => expect(button).toBeEnabled());
        let resolve!: (result: unknown) => void;
        request.mockImplementation(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        fireEvent.click(button);
        fireEvent.click(button);
        expect(request).toHaveBeenCalledTimes(2);
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/4/duplicate',
            'POST',
            {
                actor_id: 2,
                version: 3,
                source: 'draft',
                revision_id: null,
                media_versions: { '7': 2 },
            },
        );
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        await act(async () =>
            resolve({ page_id: 21, edit_url: '/company-hub/manage/21/edit' }),
        );
        expect(visit).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Open private copy' }),
        );
        expect(visit).toHaveBeenCalledWith('/company-hub/manage/21/edit');
    });
    it('rechecks the exact published revision when the source changes', async () => {
        render(<DuplicateHubPage {...props} />);
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Create private copy' }),
            ).toBeEnabled(),
        );
        request.mockResolvedValue({
            ...context,
            source: 'published',
            revision_id: 19,
        });
        fireEvent.click(
            screen.getByRole('radio', { name: /Published version/ }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Create private copy' }),
            ).toBeEnabled(),
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/4/duplicate?source=published',
            'GET',
        );
        request.mockResolvedValue({
            page_id: 22,
            edit_url: '/company-hub/manage/22/edit',
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Create private copy' }),
        );
        await screen.findByRole('button', { name: 'Open private copy' });
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/manage/4/duplicate',
            'POST',
            expect.objectContaining({ source: 'published', revision_id: 19 }),
        );
    });
    it.each([
        { actor_id: 9 },
        { version: 4 },
        { page_id: 5 },
        { source: 'published' },
    ])('blocks stale or mismatched copy context %j', async (patch) => {
        request.mockResolvedValue({ ...context, ...patch });
        render(<DuplicateHubPage {...props} />);
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'has changed',
        );
        expect(
            screen.getByRole('button', { name: 'Create private copy' }),
        ).toBeDisabled();
    });
    it('requires unsaved page edits to be saved before any copy request', async () => {
        props.onSave.mockResolvedValue(false);
        render(<DuplicateHubPage {...props} dirty />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Save latest changes' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'have not been saved',
        );
        expect(request.mock.calls.every(([, method]) => method === 'GET')).toBe(
            true,
        );
    });
    it('does not follow an unexpected destination or retry a failed mutation without a new check', async () => {
        render(<DuplicateHubPage {...props} />);
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Create private copy' }),
            ).toBeEnabled(),
        );
        request.mockResolvedValue({
            page_id: 21,
            edit_url: 'https://other.test/edit',
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Create private copy' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(visit).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Create private copy' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Check again' }),
        ).toBeEnabled();
    });
});
