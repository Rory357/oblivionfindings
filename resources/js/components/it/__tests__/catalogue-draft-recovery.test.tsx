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
import { ItServiceCatalogue, type CatalogItem } from '../it-service-catalogue';

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => () => {}), visit: vi.fn() },
}));
const item: CatalogItem = {
    id: 12,
    name: 'Private catalogue form',
    slug: 'private-form',
    description: 'Original staff instructions',
    outcome_type: 'service_request',
    category: 'other',
    default_priority: 'normal',
    requires_approval: false,
    form_schema_version: 2,
    site_options: [{ id: 21, name: 'Approved Site' }],
    form_schema: {
        fields: [
            {
                key: 'details',
                type: 'textarea',
                label: 'Request details',
                required: true,
            },
        ],
    },
};

beforeEach(() => {
    sessionStorage.clear();
    clearItTicketDraftMemory();
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('keeps typing available during autosave then conceals private details when draft authentication expires', async () => {
    let rejectSave!: (reason: unknown) => void;
    const waiting = new Promise((_, reject) => {
        rejectSave = reject;
    });
    const request = vi
        .spyOn(axios, 'request')
        .mockImplementation(async (config) => {
            if (config.method === 'patch') return waiting as never;
            const input = config.data as { request_uuid: string };
            expect(input).toMatchObject({
                actor_user_id: 3,
                purpose: 'catalogue_request',
                catalog_item_id: 12,
                schema_version: 2,
            });
            return {
                status: 200,
                data: {
                    draft: {
                        draft_uuid: '9c3e9caa-4bc9-41ba-8b90-bf654120f167',
                        purpose: 'catalogue_request',
                        context_key: `catalogue:12:version:2:request:${input.request_uuid}`,
                        audience: 'public',
                        ticket_id: null,
                        request_uuid: input.request_uuid,
                        revision: 0,
                        state: 'active',
                        has_content: false,
                        files: { ready: 0, pending: 0, cleanup_pending: 0 },
                        saved_at: null,
                        expires_at: '2027-10-01T00:00:00.000Z',
                        base_ticket_version: null,
                        current_ticket_version: null,
                        capabilities: {
                            read: true,
                            save: true,
                            submit: true,
                            discard: true,
                            start_new: false,
                        },
                        blocker: null,
                    },
                },
            };
        });
    render(
        <ItServiceCatalogue
            actorId={3}
            items={[item]}
            fieldOptions={{ employee: [], user: [], asset: [] }}
            query=""
            category={null}
            draftRecoveryEnabled
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: item.name }));
    const input = await screen.findByRole('textbox', {
        name: /Request details/,
    });
    await waitFor(() => expect(input).not.toBeDisabled());
    fireEvent.change(input, {
        target: { value: 'Private draft evidence from this employee' },
    });
    await waitFor(() =>
        expect(
            request.mock.calls.some(([config]) => config.method === 'patch'),
        ).toBe(true),
    );
    expect(
        screen.getByDisplayValue('Private draft evidence from this employee'),
    ).not.toBeDisabled();
    rejectSave({ isAxiosError: true, response: { status: 401, data: {} } });
    await waitFor(() =>
        expect(
            screen.queryByDisplayValue(
                'Private draft evidence from this employee',
            ),
        ).not.toBeInTheDocument(),
    );
    expect(
        screen.queryByText('Original staff instructions'),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('heading', { name: 'Request access and recovery' }),
    ).toBeVisible();
    expect(
        Array.from({ length: sessionStorage.length }, (_, index) =>
            sessionStorage.getItem(sessionStorage.key(index)!),
        ).join(' '),
    ).not.toContain('Private draft evidence');
});
