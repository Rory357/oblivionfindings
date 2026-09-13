import type { ItDraftMetadata } from '@/hooks/it-ticket-draft-contract';
import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { TicketReplyComposer } from '../ticket-reply-composer';

const draftIds = {
    public_reply: '560fda43-afaf-4a7b-afad-f928bec51110',
    internal_note: '560fda43-afaf-4a7b-afad-f928bec51111',
};
type Purpose = keyof typeof draftIds;

/** Contract transport only; the component, command, saved draft and RAM hooks are real. */
function transport() {
    const currentIds = { ...draftIds };
    const drafts = new Map<string, ItDraftMetadata>();
    for (const purpose of Object.keys(draftIds) as Purpose[]) {
        drafts.set(draftIds[purpose], {
            draft_uuid: draftIds[purpose],
            purpose,
            context_key: 'ticket:9',
            audience: purpose === 'internal_note' ? 'internal' : 'public',
            ticket_id: 9,
            request_uuid: null,
            revision: 0,
            state: 'active',
            has_content: false,
            saved_at: null,
            expires_at: '2026-10-01T00:00:00Z',
            base_ticket_version: 1,
            current_ticket_version: 1,
            files: { ready: 0, pending: 0, cleanup_pending: 0 },
            capabilities: {
                read: true,
                save: true,
                submit: true,
                discard: true,
                start_new: false,
            },
            blocker: null,
        });
    }
    let version = 1;
    let commentId = 0;
    const uploads: File[] = [];
    vi.spyOn(axios, 'request').mockImplementation(async (config) => {
        const data = config.data as Record<string, unknown>;
        const id =
            config.url === '/it/drafts/context'
                ? currentIds[data.purpose as Purpose]
                : config.url!.split('/')[3];
        const draft = drafts.get(id);
        if (!draft)
            throw new Error(`Unexpected draft transport: ${config.url}`);
        if (config.url?.endsWith('/attachments')) {
            const form = config.data as FormData;
            const file = form.get('attachment') as File;
            expect(Number(form.get('expected_revision'))).toBe(draft.revision);
            uploads.push(file);
            draft.revision++;
            draft.files.ready++;
            await new Promise((resolve) => setTimeout(resolve, 25));
            return {
                status: 200,
                data: {
                    draft: structuredClone(draft),
                    attachment: {
                        id: 1,
                        upload_uuid: form.get('upload_uuid'),
                        name: file.name,
                        mime: file.type,
                        size: file.size,
                        state: 'ready',
                        download_url: '/it/attachments/1',
                    },
                },
            };
        } else if (config.method === 'patch') {
            expect(data.expected_revision).toBe(draft.revision);
            draft.revision++;
            draft.has_content = true;
            draft.saved_at = '2026-09-10T04:00:00Z';
            draft.base_ticket_version = Number(data.base_ticket_version);
        } else if (config.url?.endsWith('/start-new')) {
            expect(draft.state).toBe('consumed');
            drafts.delete(id);
            draft.draft_uuid = crypto.randomUUID();
            currentIds[draft.purpose as Purpose] = draft.draft_uuid;
            drafts.set(draft.draft_uuid, draft);
            draft.revision++;
            draft.state = 'active';
            draft.has_content = false;
            draft.files.ready = 0;
            draft.saved_at = null;
            draft.base_ticket_version = version;
            draft.capabilities = {
                read: true,
                save: true,
                submit: true,
                discard: true,
                start_new: false,
            };
        } else {
            expect(config.url).toBe('/it/drafts/context');
        }
        draft.current_ticket_version = version;
        return { status: 200, data: { draft: structuredClone(draft) } };
    });
    const posts = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (url, raw) => {
            expect(url).toBe('/it/tickets/9/comments');
            expect(raw).toBeInstanceOf(FormData);
            const form = raw as FormData;
            const draft = drafts.get(String(form.get('draft_uuid')))!;
            expect(Number(form.get('draft_revision'))).toBe(draft.revision);
            expect(Number(form.get('expected_version'))).toBe(version);
            // Allow the real pending-command render before its network acknowledgement.
            await new Promise((resolve) => setTimeout(resolve, 25));
            const submittedRevision = draft.revision;
            draft.revision++;
            draft.state = 'consumed';
            draft.has_content = false;
            draft.capabilities = {
                read: false,
                save: false,
                submit: false,
                discard: false,
                start_new: true,
            };
            version++;
            commentId++;
            return {
                status: 201,
                data: {
                    status: 'committed',
                    data: {
                        id: 9,
                        canonical_ticket_id: 9,
                        comment_id: commentId,
                        viewer_user_id: 7,
                        request_uuid: form.get('request_uuid'),
                        is_internal: form.get('is_internal') === '1',
                        lock_version: version,
                        replayed: false,
                        delivery: { requested: false, attempt_statuses: {} },
                        draft: {
                            draft_uuid: draft.draft_uuid,
                            submitted_revision: submittedRevision,
                            revision: draft.revision,
                            state: 'consumed',
                        },
                    },
                },
            };
        });
    return { posts, uploads };
}

beforeEach(() => {
    clearItTicketDraftMemory();
    sessionStorage.clear();
});
afterEach(() => {
    cleanup();
    clearItTicketDraftMemory();
    sessionStorage.clear();
    vi.restoreAllMocks();
});

it.each(['text', 'saved file', 'retained selected file'] as const)(
    'settles an acknowledged internal note and starts the next note without uncertain RAM: %s',
    async (mode) => {
        const { posts, uploads } = transport();
        const composer = (enabled: boolean) => (
            <TicketReplyComposer
                actorId={7}
                ticketId={9}
                expectedVersion={1}
                draftsEnabled={enabled}
                canInternal
            />
        );
        const view = render(composer(mode !== 'retained selected file'));
        fireEvent.click(screen.getByRole('button', { name: 'Internal note' }));
        const input = screen.getByRole('textbox', {
            name: 'Internal note',
        });
        await waitFor(() => expect(input).toBeEnabled());
        const file = new File(
            ['Synthetic attachment evidence'],
            'investigation.txt',
            { type: 'text/plain' },
        );
        if (mode !== 'text') {
            const picker = Array.from(
                view.container.querySelectorAll<HTMLInputElement>(
                    'input[type="file"]',
                ),
            ).find((element) => !element.closest('[hidden]'))!;
            fireEvent.change(picker, { target: { files: [file] } });
            if (mode === 'saved file') {
                await screen.findByText('investigation.txt');
                await waitFor(() => expect(input).toBeEnabled());
            } else {
                // A currently selected File survives enabling persisted drafts in this host.
                view.rerender(composer(true));
                await waitFor(() => expect(input).toBeEnabled());
            }
        }
        fireEvent.change(input, {
            target: { value: 'First internal investigation note.' },
        });
        fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true });
        await waitFor(() => expect(posts).toHaveBeenCalledTimes(1));
        await waitFor(() =>
            expect(
                screen.getByRole('button', {
                    name: 'Add another internal note',
                }),
            ).toBeEnabled(),
        );
        expect(
            screen.queryByText(/earlier result unconfirmed/),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Add another internal note',
            }),
        );
        await waitFor(() => expect(input).toBeEnabled());
        expect(input).toHaveValue('');
        expect(input).toHaveFocus();
        fireEvent.change(input, {
            target: { value: 'Second distinct internal note.' },
        });
        fireEvent.keyDown(input, { key: 'Enter', ctrlKey: true });
        await waitFor(() => expect(posts).toHaveBeenCalledTimes(2));
        const commands = posts.mock.calls.map((call) => call[1] as FormData);
        expect(commands.map((command) => command.get('body'))).toEqual([
            'First internal investigation note.',
            'Second distinct internal note.',
        ]);
        expect(commands[0].get('request_uuid')).not.toBe(
            commands[1].get('request_uuid'),
        );
        if (mode === 'saved file') {
            expect(uploads).toEqual([file]);
            expect(commands[0].getAll('attachments[]')).toHaveLength(0);
        } else if (mode === 'retained selected file') {
            expect(commands[0].getAll('attachments[]')).toEqual([file]);
        }
        expect(commands[1].getAll('attachments[]')).toHaveLength(0);
    },
);
