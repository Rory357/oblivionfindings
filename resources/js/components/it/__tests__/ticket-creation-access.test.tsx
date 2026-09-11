import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ItWizard } from '../it-wizards';

type TicketForm = {
    title: string;
    description: string;
    category: string;
    subcategory: string;
    priority: string;
    impact: string;
    urgency: string;
    priority_reason: string;
    routing_reason: string;
    work_type: string;
    it_service_id: string;
    requester_user_id: string;
    assigned_to_user_id: string;
    asset_id: string;
    site_id: string;
    device_id: string;
    watchers: number[];
    provisioning_request_id: number | null;
    attachments: File[];
};
const mocks = vi.hoisted(() => ({
    actorId: 230,
    form: null as {
        data: TicketForm;
        reset: () => void;
        isDirty: boolean;
    } | null,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();
    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: { id: mocks.actorId } } } }),
        useForm: (data: TicketForm) => {
            const form = actual.useForm<TicketForm>(data);
            mocks.form = form;
            return form;
        },
    };
});

describe('Ticket creation access concealment', () => {
    beforeEach(() => {
        clearItTicketDraftMemory();
        sessionStorage.clear();
        mocks.form = null;
        mocks.actorId = 230;
        vi.spyOn(axios, 'post');
        vi.spyOn(axios, 'get');
    });
    afterEach(() => vi.restoreAllMocks());

    it('clears provisioning-derived defaults and every linked context after access denial, including after reset', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 403 },
        });
        render(
            <ItWizard
                modal={{
                    type: 'ticket',
                    provisioning: {
                        id: 987,
                        item: 'Private provisioning laptop',
                    },
                }}
                assignees={[]}
                siteOptions={[{ id: 9403, name: 'Approved synthetic Site' }]}
                onClose={vi.fn()}
            />,
        );
        expect(
            screen.getByDisplayValue('Issue with Private provisioning laptop'),
        ).toBeVisible();
        expect(mocks.form?.data.provisioning_request_id).toBe(987);
        expect(mocks.form?.data.site_id).toBe('9403');
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Log ticket' }));
        await screen.findByText(/Your access has changed/);
        const cleared = {
            title: '',
            description: '',
            category: 'hardware',
            subcategory: '',
            priority: 'automatic',
            impact: 'individual',
            urgency: 'normal',
            priority_reason: '',
            routing_reason: '',
            work_type: 'incident',
            it_service_id: 'unassigned',
            requester_user_id: 'unassigned',
            assigned_to_user_id: 'unassigned',
            asset_id: 'unassigned',
            site_id: 'unassigned',
            device_id: 'unassigned',
            watchers: [],
            provisioning_request_id: null,
            attachments: [],
        };
        await waitFor(() => expect(mocks.form?.data).toEqual(cleared));
        expect(
            screen.queryByText(/Private provisioning laptop/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue(/Private provisioning laptop/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Retry same request' }),
        ).not.toBeInTheDocument();
        act(() => mocks.form!.reset());
        expect(mocks.form?.data).toEqual(cleared);
        expect(mocks.form?.isDirty).toBe(false);
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(sessionStorage.length).toBe(0);
    });

    it('shows only receipt recovery when reopening and does not attribute a recovered ticket to the new provisioning context', async () => {
        const requestId = crypto.randomUUID();
        sessionStorage.setItem(
            'it.pending-ticket-command.v1.actor.230',
            requestId,
        );
        render(
            <ItWizard
                modal={{
                    type: 'ticket',
                    provisioning: {
                        id: 999,
                        item: 'Unrelated current provisioning request',
                    },
                }}
                assignees={[]}
                siteOptions={[{ id: 9403, name: 'Approved synthetic Site' }]}
                onClose={vi.fn()}
            />,
        );
        expect(
            screen.queryByText(/Unrelated current provisioning request/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue(
                /Unrelated current provisioning request/,
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Continue' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('progressbar', { name: 'Completeness' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /DetailsWhat/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Check saved request', { selector: 'span' }),
        ).toBeVisible();
        vi.mocked(axios.get).mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'committed',
                data: {
                    id: 9,
                    reference: 'IT-000009',
                    url: '/it/tickets/9',
                    request_uuid: requestId,
                    replayed: true,
                    viewer_user_id: 230,
                },
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved request' }),
        );
        expect(
            await screen.findByText(/Your saved request was found/),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Open IT-000009' }),
        ).toBeVisible();
        expect(
            screen.queryByText(/Unrelated current provisioning request/),
        ).not.toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
        expect(sessionStorage.length).toBe(0);
    });
});
