import { retainIntakeDraft } from '@/hooks/it-intake-draft-locator';
import type {
    ItDraftAttachment,
    ItDraftMetadata,
    ItDraftPayload,
} from '@/hooks/it-ticket-draft-contract';
import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ItWizard } from '../it-wizards';

const draftPage = vi.hoisted(() => ({ enabled: true }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        props: {
            auth: { user: { id: 230 } },
            draftRecovery: { enabled: draftPage.enabled },
        },
    }),
}));
const requestUuid = 'd2285a40-65a4-442d-b33d-87b1ba708902';
const draftUuid = '9c3e9caa-4bc9-41ba-8b90-bf654120f167';
let revision: number;
let payload: ItDraftPayload;
let files: ItDraftAttachment[];
let currentRequest: string;
let currentPurpose: 'requester_intake' | 'technician_intake';
let metadata: () => ItDraftMetadata;
function page(onClose = vi.fn()) {
    return render(
        <ItWizard
            modal={{ type: 'raise' }}
            assignees={[]}
            siteOptions={[{ id: 7, name: 'Approved Site A' }]}
            onClose={onClose}
        />,
    );
}
const input = () =>
    screen.getByPlaceholderText('e.g. My work phone won’t charge');
beforeEach(() => {
    clearItTicketDraftMemory();
    draftPage.enabled = true;
    localStorage.clear();
    sessionStorage.clear();
    revision = 0;
    payload = { fields: {}, step_index: 0 };
    files = [];
    currentRequest = requestUuid;
    currentPurpose = 'requester_intake';
    metadata = () => ({
        draft_uuid: draftUuid,
        purpose: currentPurpose,
        context_key: `request:${currentRequest}`,
        audience:
            currentPurpose === 'technician_intake' ? 'internal' : 'public',
        ticket_id: null,
        request_uuid: currentRequest,
        revision,
        state: 'active',
        has_content: Object.keys(payload.fields).length > 0 || files.length > 0,
        saved_at: revision ? '2026-09-09T12:00:00Z' : null,
        expires_at: '2026-09-12T12:00:00Z',
        base_ticket_version: null,
        current_ticket_version: null,
        files: { ready: files.length, pending: 0, cleanup_pending: 0 },
        capabilities: {
            read: true,
            save: true,
            submit: true,
            discard: true,
            start_new: false,
        },
        blocker: null,
    });
    vi.spyOn(axios, 'request').mockImplementation(async (config) => {
        const candidate = config.data as ItDraftPayload & {
            request_uuid: string;
            purpose: typeof currentPurpose;
        };
        if (config.url === '/it/drafts/context') {
            currentRequest = candidate.request_uuid;
            currentPurpose = candidate.purpose;
            return { status: 200, data: { draft: metadata() } };
        }
        if (config.url?.endsWith('/resume'))
            return {
                status: 200,
                data: { draft: metadata(), payload, attachments: files },
            };
        if (config.method === 'patch') {
            revision++;
            payload = {
                fields: candidate.fields,
                step_index: candidate.step_index,
            };
            return { status: 200, data: { draft: metadata() } };
        }
        if (config.url?.endsWith('/attachments')) {
            const body = config.data as FormData;
            const file = body.get('attachment') as File;
            revision += 2;
            const attachment: ItDraftAttachment = {
                id: 91,
                upload_uuid: String(body.get('upload_uuid')),
                name: file.name,
                mime: file.type,
                size: file.size,
                state: 'ready',
                download_url: '/it/attachments/91',
            };
            files = [attachment];
            return { status: 200, data: { draft: metadata(), attachment } };
        }
        throw new Error('Unexpected fixture draft operation');
    });
    vi.spyOn(axios, 'post').mockImplementation(async (_url, data) =>
        _url === '/it/drafts/validate-local-candidate'
            ? {
                  status: 200,
                  data: {
                      candidate: {
                          kind: 'memory',
                          memory_uuid: (data as Record<string, unknown>)
                              .memory_uuid,
                          candidate_uuid: (data as Record<string, unknown>)
                              .candidate_uuid,
                          actor_user_id: 230,
                          purpose: (data as Record<string, unknown>).purpose,
                          context_key: `request:${(data as Record<string, unknown>).request_uuid}`,
                          base_ticket_version: null,
                          current_ticket_version: null,
                          authorized: true,
                          capabilities: { submit: true },
                          blocker: null,
                      },
                  },
              }
            : {
                  status: 201,
                  data: {
                      status: 'committed',
                      data: {
                          id: 9,
                          reference: 'IT-000009',
                          url: '/it/tickets/9',
                          request_uuid: (data as FormData).get('request_uuid'),
                          replayed: false,
                          viewer_user_id: 230,
                      },
                  },
              },
    );
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    localStorage.clear();
    sessionStorage.clear();
});

describe('Opt-in requester intake drafts', () => {
    it('retains an editable rejected request across Back after definitive create validation', async () => {
        draftPage.enabled = false;
        const first = page();
        fireEvent.change(input(), {
            target: { value: 'Rejected but recoverable private proposal' },
        });
        vi.mocked(axios.post).mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 422,
                data: { errors: { title: ['Please review this title.'] } },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Raise ticket' }));
        await screen.findByRole('button', { name: 'Review fields' });
        expect(sessionStorage.length).toBe(0);
        first.unmount();
        page();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume browser work' }),
        );
        await waitFor(() =>
            expect(input()).toHaveValue(
                'Rejected but recoverable private proposal',
            ),
        );
        expect(
            screen.queryByText(/earlier submission is still unconfirmed/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Raise ticket' }),
        ).toBeEnabled();
    });

    it('restores the latest text and selected File after same-document unmount with saved drafts disabled', async () => {
        draftPage.enabled = false;
        const first = page();
        fireEvent.change(input(), {
            target: { value: 'Last unsaved browser character!' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: '+ Add more details' }),
        );
        const file = new File(['Actual unsent bytes'], 'unsent-proof.txt', {
            type: 'text/plain',
        });
        fireEvent.change(
            first.container.ownerDocument.querySelector('input[type=file]')!,
            { target: { files: [file] } },
        );
        first.unmount();
        expect(axios.request).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
        expect(localStorage.length).toBe(0);
        expect(sessionStorage.length).toBe(0);
        const canonicalPost = vi.mocked(axios.post).getMockImplementation()!;
        vi.mocked(axios.post).mockImplementation(async (url, raw, config) => {
            if (url !== '/it/drafts/validate-local-candidate')
                return canonicalPost(url, raw, config);
            const body = raw as Record<string, unknown>;
            return {
                status: 200,
                data: {
                    candidate: {
                        kind: 'memory',
                        memory_uuid: body.memory_uuid,
                        candidate_uuid: body.candidate_uuid,
                        actor_user_id: body.actor_user_id,
                        purpose: body.purpose,
                        context_key: `request:${body.request_uuid}`,
                        base_ticket_version: null,
                        current_ticket_version: null,
                        authorized: true,
                        capabilities: { submit: true },
                        blocker: null,
                    },
                },
            };
        });
        page();
        expect(input()).toHaveValue('');
        expect(screen.queryByText('unsent-proof.txt')).not.toBeInTheDocument();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume browser work' }),
        );
        await waitFor(() =>
            expect(input()).toHaveValue('Last unsaved browser character!'),
        );
        expect(screen.getByText('unsent-proof.txt')).toBeVisible();
        expect(axios.request).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Raise ticket' }));
        await screen.findByText('Raised — IT-000009');
        const submitted = vi
            .mocked(axios.post)
            .mock.calls.find(([url]) => url === '/it/tickets')?.[1] as FormData;
        expect(submitted.get('attachments[0]')).toBe(file);
        expect(submitted.has('draft_uuid')).toBe(false);
    });

    it('does not resurrect explicitly discarded current browser work on close', async () => {
        draftPage.enabled = false;
        const onClose = vi.fn();
        const first = page(onClose);
        fireEvent.change(input(), {
            target: { value: 'Deliberately discarded local proposal' },
        });
        fireEvent.click(screen.getByRole('button', { name: /^Cancel$/ }));
        const confirm = await screen.findByRole('alertdialog');
        fireEvent.click(
            within(confirm).getByRole('button', { name: 'Discard ticket' }),
        );
        expect(onClose).toHaveBeenCalledOnce();
        first.unmount();
        page();
        expect(input()).toHaveValue('');
        expect(
            screen.queryByRole('button', { name: 'Resume browser work' }),
        ).not.toBeInTheDocument();
    });

    it('requires explicit Resume and restores the approved Site and text without browser content storage', async () => {
        retainIntakeDraft(230, 'requester_intake', requestUuid);
        revision = 2;
        payload = {
            fields: {
                title: 'Saved private request',
                description: 'Saved detail',
                category: 'hardware',
                impact: 'individual',
                urgency: 'normal',
                site_id: 7,
            },
            step_index: 0,
        };
        page();
        const resume = await screen.findByRole('button', {
            name: 'Resume saved draft',
        });
        expect(input()).toHaveValue('');
        expect(axios.post).not.toHaveBeenCalled();
        fireEvent.click(resume);
        await waitFor(() =>
            expect(input()).toHaveValue('Saved private request'),
        );
        expect(
            screen.getByRole('combobox', { name: 'Affected Site' }),
        ).toHaveTextContent('Approved Site A');
        expect(
            Object.values(localStorage).every((value) => value === requestUuid),
        ).toBe(true);
        expect(
            vi
                .mocked(axios.request)
                .mock.calls.filter(([config]) => config.method === 'patch'),
        ).toHaveLength(0);
    });

    it('saves the exact intake draft before creating once with original actor and a consumed-generation reference', async () => {
        page();
        fireEvent.change(input(), {
            target: { value: 'Draft-backed request' },
        });
        await screen.findByRole('button', { name: 'Save draft' });
        fireEvent.click(screen.getByRole('button', { name: 'Raise ticket' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
        expect(Object.fromEntries(body)).toMatchObject({
            title: 'Draft-backed request',
            actor_user_id: '230',
            draft_uuid: draftUuid,
            draft_revision: '1',
            draft_actor_user_id: '230',
        });
        expect(payload.fields.title).toBe('Draft-backed request');
        await screen.findByText('Raised — IT-000009');
        expect(localStorage.length).toBe(0);
    });

    it('keeps text and does not create if draft saving is rejected', async () => {
        page();
        fireEvent.change(input(), { target: { value: 'Keep this proposal' } });
        await screen.findByRole('button', { name: 'Save draft' });
        vi.mocked(axios.request).mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    errors: { 'fields.site_id': ['Site approval changed.'] },
                },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Raise ticket' }));
        await screen.findByText('Site approval changed.');
        expect(input()).toHaveValue('Keep this proposal');
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('retains a canonical locator synchronously before acknowledged Save draft and close', async () => {
        const onClose = vi.fn();
        page(onClose);
        fireEvent.change(input(), { target: { value: 'Close safely' } });
        await screen.findByRole('button', { name: 'Save draft' });
        fireEvent.click(screen.getByRole('button', { name: /^Cancel$/ }));
        const dialog = screen.getByRole('alertdialog');
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: 'Save draft and close',
            }),
        );
        await waitFor(() => expect(onClose).toHaveBeenCalledOnce());
        expect(
            localStorage.getItem(
                `it.draft-context.v1.actor.230.requester_intake.${currentRequest}`,
            ),
        ).toBe(currentRequest);
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('honours a pending W02 command before another saved draft context', async () => {
        const pending = 'f024eb01-fa17-4cdb-a057-179d056973f6';
        retainIntakeDraft(230, 'requester_intake', requestUuid);
        sessionStorage.setItem(
            'it.pending-ticket-command.v1.actor.230',
            pending,
        );
        page();
        expect(
            screen.getByText(/A previous request has not been confirmed/),
        ).toBeInTheDocument();
        expect(
            screen.queryByPlaceholderText('e.g. My work phone won’t charge'),
        ).not.toBeInTheDocument();
        expect(axios.request).not.toHaveBeenCalled();
        expect(
            sessionStorage.getItem('it.pending-ticket-command.v1.actor.230'),
        ).toBe(pending);
    });

    it('debounces current text into a saved draft and never autosubmits a ticket', async () => {
        page();
        fireEvent.change(input(), { target: { value: 'First keystrokes' } });
        await screen.findByRole('button', { name: 'Save draft' });
        fireEvent.change(input(), {
            target: { value: 'Latest complete draft' },
        });
        await waitFor(
            () => expect(payload.fields.title).toBe('Latest complete draft'),
            { timeout: 2000 },
        );
        expect(
            vi
                .mocked(axios.request)
                .mock.calls.filter(([config]) => config.method === 'patch'),
        ).toHaveLength(1);
        expect(axios.post).not.toHaveBeenCalled();
        expect(
            localStorage.getItem(
                `it.draft-context.v1.actor.230.requester_intake.${currentRequest}`,
            ),
        ).toBe(currentRequest);
    });

    it('offers honest reference-only exit after an unknown save', async () => {
        const onClose = vi.fn();
        page(onClose);
        fireEvent.change(input(), { target: { value: 'Unconfirmed draft' } });
        await screen.findByRole('button', { name: 'Save draft' });
        vi.mocked(axios.request).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 500 },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await screen.findByRole('button', { name: 'Retry original command' });
        fireEvent.click(screen.getByRole('button', { name: /^Cancel$/ }));
        const dialog = screen.getByRole('alertdialog');
        expect(
            within(dialog).getByText(
                /does not confirm that pending text or files were saved/,
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: 'Close and keep draft reference',
            }),
        );
        expect(onClose).toHaveBeenCalledOnce();
        expect(axios.post).not.toHaveBeenCalled();
        expect(
            localStorage.getItem(
                `it.draft-context.v1.actor.230.requester_intake.${currentRequest}`,
            ),
        ).toBe(currentRequest);
    });

    it('persists an intentional clear even when the form returns to its initial values', async () => {
        page();
        fireEvent.change(input(), {
            target: { value: 'Text that will be cleared' },
        });
        await screen.findByRole('button', { name: 'Save draft' });
        await waitFor(
            () =>
                expect(payload.fields.title).toBe('Text that will be cleared'),
            { timeout: 2000 },
        );
        fireEvent.change(input(), { target: { value: '' } });
        await waitFor(() => expect(payload.fields.title).toBe(''), {
            timeout: 2000,
        });
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('saves technician dimensions and preserves automatic priority through the canonical create command', async () => {
        render(
            <ItWizard
                modal={{ type: 'ticket' }}
                assignees={[]}
                siteOptions={[{ id: 7, name: 'Approved Site A' }]}
                onClose={vi.fn()}
            />,
        );
        fireEvent.change(
            screen.getByPlaceholderText(
                'e.g. Printer offline — Sunnyside Lodge',
            ),
            { target: { value: 'Technician draft' } },
        );
        await screen.findByRole('button', { name: 'Save draft' });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Log ticket' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        expect(currentPurpose).toBe('technician_intake');
        expect(payload.fields).toMatchObject({
            title: 'Technician draft',
            priority: null,
            impact: 'individual',
            urgency: 'normal',
            site_id: 7,
        });
        const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
        expect(body.get('priority')).toBeNull();
        expect(body.get('draft_uuid')).toBe(draftUuid);
        expect(body.get('actor_user_id')).toBe('230');
    });

    it('stages a real selected File once and sends only its canonical draft reference on create', async () => {
        const { container } = page();
        fireEvent.change(input(), { target: { value: 'Draft with proof' } });
        await screen.findByRole('button', { name: 'Save draft' });
        fireEvent.click(
            screen.getByRole('button', { name: '+ Add more details' }),
        );
        const file = new File(['Synthetic proof'], 'proof.txt', {
            type: 'text/plain',
        });
        fireEvent.change(
            container.ownerDocument.querySelector('input[type=file]')!,
            { target: { files: [file] } },
        );
        await screen.findByText(/Saved to draft/);
        fireEvent.click(screen.getByRole('button', { name: 'Raise ticket' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
        expect(body.get('draft_revision')).toBe(String(revision));
        expect(body.get('draft_uuid')).toBe(draftUuid);
        expect([...body.values()].some((value) => value instanceof File)).toBe(
            false,
        );
        const uploads = vi
            .mocked(axios.request)
            .mock.calls.filter(([config]) => config.data instanceof FormData);
        expect(uploads).toHaveLength(1);
        expect((uploads[0][0].data as FormData).get('attachment')).toBe(file);
    });
});
