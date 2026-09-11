import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import { purgeTicketWatcherCommandsForActor } from '@/hooks/use-ticket-watcher-command';
import { approvalRecord, approvalWork } from '@/test/it-approval-fixtures';
import { router } from '@inertiajs/react';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import type { ComponentProps, ReactNode } from 'react';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ItTicketShow from './show';

const state = vi.hoisted(() => ({ url: '/it/tickets/42', userId: 8 }));
vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({
        url: state.url,
        props: { auth: { user: { id: state.userId } } },
    }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: { title: string; href: string }[];
    }) => (
        <main>
            <nav aria-label="Breadcrumb">
                {breadcrumbs.map((item) => (
                    <a key={item.href} href={item.href}>
                        {item.title}
                    </a>
                ))}
            </nav>
            {children}
        </main>
    ),
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

type Props = ComponentProps<typeof ItTicketShow>;
function pendingRequiredTaskReadiness() {
    return {
        storage_ready: true,
        prerequisites: 'ready' as const,
        completion: 'none' as const,
        blockers: [],
        warnings: [],
        can_start: true,
        can_complete: true,
        can_reopen: false,
        can_edit: true,
        can_cancel: false,
        can_restore: false,
    };
}
function fixture(): Props {
    return {
        viewer_user_id: 8,
        ticket: {
            id: 42,
            lock_version: 3,
            reference: 'IT-000042',
            title: 'Investigate the shared printer',
            description: 'The print queue is unavailable.',
            work_type: 'incident',
            service: null,
            category: 'hardware',
            subcategory: null,
            priority: 'normal',
            status: 'open',
            workflow_state: 'open',
            waiting: null,
            source: 'web',
            sla_state: 'unmeasured',
            first_response_due_at: null,
            resolution_due_at: null,
            first_responded_at: null,
            requester: {
                id: 8,
                name: 'Alex Requester',
                role: null,
                href: null,
            },
            assignee: null,
            watchers: [],
            asset: null,
            site: { id: 10, name: 'Assigned house', href: '/sites/10' },
            is_organisation_wide: false,
            provisioning_request: null,
            attachments: [
                {
                    id: 7,
                    name: 'Printer evidence.txt',
                    size: 100,
                    url: '/it/attachments/7',
                },
            ],
            csat: null,
            created_at: null,
            created_human: null,
            updated_at: null,
            resolved_at: null,
            monitoring_recovered_at: null,
            closed_at: null,
            merged_into: null,
            requires_approval: false,
            approval: null,
        },
        comments: [],
        events: [],
        assignees: [],
        approvals: [],
        task_work: {
            storage_ready: true,
            can_create: true,
            can_reorder: true,
        },
        assetOptions: [],
        deviceOptions: [],
        siteOptions: [{ id: 10, name: 'Assigned house' }],
        serviceOptions: [],
        teamOptions: [],
        kbSuggestions: [],
        mergeTargets: [],
        linked_context: {
            tasks: [],
            devices: [],
            alerts: [],
            changes: [],
            problems: [],
            major_incidents: [],
            incident_evidence: [],
        },
        can: {
            manage: false,
            linkDevices: false,
            assignApplicationWide: false,
            view: false,
            internal: false,
            comment: true,
            reopen: false,
            watching: false,
            rate: false,
            merge: false,
            requestApproval: false,
            decideApproval: false,
        },
        replyUnavailableReason: null,
    };
}

beforeEach(() => {
    clearItTicketDraftMemory();
    state.url = '/it/tickets/42';
    state.userId = 8;
});
afterEach(async () => {
    cleanup();
    // Radix restores focus on a queued task after unmount; let that cleanup
    // finish before the next independently mounted page owns focus.
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    purgeTicketWatcherCommandsForActor(8);
    vi.restoreAllMocks();
    vi.clearAllMocks();
});

describe('Ticket workspace', () => {
    it('keeps original merged work history readable and its section navigation on the original record', () => {
        const visit = vi.spyOn(router, 'get').mockImplementation(() => {});
        const props = fixture();
        props.ticket.status = 'closed';
        props.ticket.is_merged = true;
        props.ticket.merged_into = {
            id: 99,
            reference: 'IT-000099',
            title: 'Surviving request',
        };
        props.can.view = true;
        props.can.internal = true;
        props.can.comment = false;
        props.task_work = {
            storage_ready: true,
            can_create: false,
            can_reorder: false,
        };
        state.url = '/it/tickets/42/original?tab=tasks';
        render(<ItTicketShow {...props} />);

        expect(screen.getByRole('link', { name: 'IT-000099' })).toHaveAttribute(
            'href',
            '/it/tickets/99',
        );
        expect(
            screen.getByRole('heading', { name: 'Tasks & evidence' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Add task' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Approvals' })).toHaveAttribute(
            'href',
            '/it/tickets/42/original?tab=approvals',
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Approvals' }));
        expect(visit).toHaveBeenCalledWith(
            '/it/tickets/42/original',
            { tab: 'approvals' },
            expect.any(Object),
        );
    });

    it('offers authorized original history after canonical navigation without an edit action', () => {
        const props = fixture();
        props.ticket.merge_origin = {
            id: 19,
            reference: 'IT-000019',
            href: '/it/tickets/19/original',
        };
        render(<ItTicketShow {...props} />);
        expect(
            screen.getByRole('link', {
                name: 'View original record IT-000019',
            }),
        ).toHaveAttribute('href', '/it/tickets/19/original');
    });

    it('explains a merged record without inventing a link to an inaccessible survivor', () => {
        const props = fixture();
        props.ticket.status = 'closed';
        props.ticket.is_merged = true;
        props.can.comment = false;
        render(<ItTicketShow {...props} />);
        expect(
            screen.getByText(
                'This is the original merged record. The surviving ticket is not available to your current account.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByRole('link', { name: /View original record/ }),
        ).not.toBeInTheDocument();
    });

    it('keeps recorded resolution metadata private and conceals it immediately after an account change', () => {
        const props = fixture();
        props.ticket.status = 'resolved';
        props.ticket.resolution = {
            code: 'workaround',
            summary: 'Private historical resolution explanation.',
            verification: 'Private historical verification.',
        };
        const page = render(<ItTicketShow {...props} />);
        expect(
            screen.queryByRole('region', { name: 'Recorded resolution' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Private historical verification.'),
        ).not.toBeInTheDocument();
        props.can.manage = true;
        page.rerender(<ItTicketShow {...props} />);
        const resolution = screen.getByRole('region', {
            name: 'Recorded resolution',
        });
        expect(
            within(resolution).getByText('Workaround provided'),
        ).toBeVisible();
        expect(
            within(resolution).getByText('Private historical verification.'),
        ).toBeVisible();
        state.userId = 99;
        page.rerender(<ItTicketShow {...props} />);
        expect(
            screen.queryByRole('region', { name: 'Recorded resolution' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Private historical verification.'),
        ).not.toBeInTheDocument();
    });

    it('shows missing historical resolution evidence without fabricating successful checks', () => {
        const props = fixture();
        props.can.manage = true;
        props.ticket.status = 'closed';
        props.ticket.resolution = {
            code: 'legacy_fixed',
            summary: 'Recorded repair.',
            verification: null,
        };
        render(<ItTicketShow {...props} />);
        const resolution = screen.getByRole('region', {
            name: 'Recorded resolution',
        });
        expect(within(resolution).getByText('legacy fixed')).toBeVisible();
        expect(within(resolution).getByText('Not recorded')).toBeVisible();
        expect(
            within(resolution).queryByText('Service restored'),
        ).not.toBeInTheDocument();
    });

    it('keeps verified, invalidated and unverified required completions distinct in the private header', () => {
        const props = fixture();
        props.can.manage = true;
        props.can.internal = true;
        props.linked_context.tasks = ['valid', 'invalid', 'unknown'].map(
            (completion, index) => ({
                id: index + 1,
                title: `Required task ${index + 1}`,
                description: null,
                status: 'completed',
                due_at: null,
                is_required: true,
                evidence_required: false,
                evidence: null,
                completion_note: null,
                completed_at: null,
                sort_order: index,
                team: null,
                assignee: null,
                completed_by: null,
                dependencies: [],
                current_completion_id: null,
                approval: null,
                readiness: {
                    ...pendingRequiredTaskReadiness(),
                    completion: completion as 'valid' | 'invalid' | 'unknown',
                },
            }),
        );
        const page = render(<ItTicketShow {...props} />);
        const meter = screen.getByRole('link', {
            name: 'View required ticket work',
        });
        expect(meter).toHaveTextContent('1 of 3 verified complete');
        expect(meter).toHaveTextContent('1 completion needs review');
        expect(meter).toHaveTextContent('1 completion unverified');
        page.rerender(
            <ItTicketShow
                {...props}
                can={{ ...props.can, manage: false, internal: false }}
            />,
        );
        expect(
            screen.queryByRole('link', { name: 'View required ticket work' }),
        ).not.toBeInTheDocument();
    });

    it('purges an open private task editor when the same actor becomes a participant', () => {
        state.url = '/it/tickets/42?tab=tasks';
        const props = fixture();
        props.can.manage = true;
        props.can.internal = true;
        props.linked_context.tasks = [
            {
                id: 6,
                title: 'Private technician task',
                description: 'Private saved instruction',
                status: 'pending',
                due_at: null,
                is_required: true,
                evidence_required: false,
                evidence: null,
                completion_note: null,
                completed_at: null,
                sort_order: 1,
                team: null,
                assignee: null,
                completed_by: null,
                dependencies: [],
                current_completion_id: null,
                approval: null,
                readiness: pendingRequiredTaskReadiness(),
            },
        ];
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        const view = render(<ItTicketShow {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit task' }));
        expect(
            screen.getByRole('dialog', { name: 'Edit work task' }),
        ).toBeVisible();
        fireEvent.change(screen.getByRole('textbox', { name: 'Task title' }), {
            target: { value: 'Private unsent task proposal' },
        });
        view.rerender(
            <ItTicketShow
                {...props}
                can={{ ...props.can, manage: false, internal: false }}
                linked_context={fixture().linked_context}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('Private unsent task proposal'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Private technician task'),
        ).not.toBeInTheDocument();
        view.rerender(<ItTicketShow {...props} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });
    it('purges an open private approval decision when current decision permission is removed', () => {
        state.url = '/it/tickets/42?tab=approvals';
        const props = fixture();
        props.can.manage = true;
        props.can.internal = true;
        props.can.decideApproval = true;
        props.ticket.requires_approval = true;
        props.ticket.approval = {
            id: 3,
            status: 'pending',
            requested_by_name: 'Current requester',
            approver_name: null,
            reason: null,
            requested_at: null,
            decided_at: null,
        };
        props.approval_work = approvalWork({
            can_request: false,
            can_decide: true,
            total: 1,
            current: approvalRecord({
                id: 3,
                primary: { id: 8, name: 'Current manager' },
            }),
        });
        const post = vi.spyOn(axios, 'post');
        const view = render(<ItTicketShow {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /^Approve$/ }));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Decision note (optional)' }),
            { target: { value: 'Private unsent approval rationale' } },
        );
        view.rerender(
            <ItTicketShow
                {...props}
                can={{
                    ...props.can,
                    manage: false,
                    internal: false,
                    decideApproval: false,
                }}
                ticket={{ ...props.ticket, approval: null }}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('Private unsent approval rationale'),
        ).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });
    it('refreshes the whole canonical audience so narrowed Site and worker permissions remove old KB hints and linked work', async () => {
        const props = fixture();
        props.can.manage = true;
        props.can.internal = true;
        props.can.view = true;
        props.kbSuggestions = [
            { id: 91, title: 'Printer steps at Site B', category: 'hardware' },
        ];
        props.linked_context.tasks = [
            {
                id: 6,
                title: 'Private technician work',
                description: null,
                status: 'pending',
                due_at: null,
                is_required: true,
                evidence_required: false,
                evidence: null,
                completion_note: null,
                completed_at: null,
                sort_order: 1,
                team: null,
                assignee: null,
                completed_by: null,
                dependencies: [],
                current_completion_id: null,
                approval: null,
                readiness: pendingRequiredTaskReadiness(),
            },
        ];
        props.linked_context.problems = [
            {
                id: 7,
                reference: 'PRB-7',
                title: 'Private linked diagnosis',
                workflow_state: 'open',
                root_cause: null,
                workaround: null,
                known_error_at: null,
                href: '/it/problems/7',
                workspace_access: { state: 'available', message: null },
                ticket_href: '/it/tickets/42',
            },
        ];
        props.comments = [
            {
                id: 71,
                body: 'Authorized public conversation',
                is_internal: false,
                author: { id: 8, name: 'Alex Requester', is_requester: true },
                attachments: [],
                at: null,
                at_human: 'now',
                delivery: {
                    tracking: 'recorded',
                    requested: true,
                    attempt_statuses: { queued: 1 },
                    checked_at: '2026-09-09T10:00:00Z',
                    review_url: null,
                },
            },
        ];
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => {});
        const view = render(<ItTicketShow {...props} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Your reply' }), {
            target: { value: 'printer remains an unsent public reply' },
        });
        expect(screen.getByText('Printer steps at Site B')).toBeInTheDocument();
        expect(screen.getByText('Private technician work')).toBeInTheDocument();
        expect(
            screen.getByText('Private linked diagnosis'),
        ).toBeInTheDocument();
        const refreshWith = (next: Props, index: number) => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh delivery status' }),
            );
            expect(reload.mock.calls[index][0]?.only).toBeUndefined();
            const page = {
                component: 'it/tickets/show',
                props: { ...next, auth: { user: { id: 8 } } },
            };
            act(() =>
                reload.mock.calls[index][0]?.onBeforeUpdate?.(page as never),
            );
            view.rerender(<ItTicketShow {...next} />);
            act(() => reload.mock.calls[index][0]?.onSuccess?.(page as never));
        };
        const siteNarrowed = { ...props, kbSuggestions: [] };
        refreshWith(siteNarrowed, 0);
        expect(
            screen.queryByText('Printer steps at Site B'),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Private technician work')).toBeInTheDocument();
        const participant = {
            ...siteNarrowed,
            can: { ...siteNarrowed.can, manage: false, internal: false },
            linked_context: fixture().linked_context,
            assignees: [],
            teamOptions: [],
            deviceOptions: [],
        };
        refreshWith(participant, 1);
        expect(
            screen.queryByText('Private technician work'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Private linked diagnosis'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Printer steps at Site B'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Authorized public conversation'),
        ).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'printer remains an unsent public reply',
        );
    });
    it.each([419, 403])(
        'conceals reply and record data after scoped HTTP %i and only restores session work after proof',
        async (status) => {
            const props = fixture();
            props.conversation_ready = true;
            props.comments = [
                {
                    id: 71,
                    body: 'Private current ticket conversation',
                    is_internal: false,
                    author: {
                        id: 8,
                        name: 'Alex Requester',
                        is_requester: true,
                    },
                    attachments: [],
                    at: null,
                    at_human: 'now',
                    delivery: {
                        tracking: 'recorded',
                        requested: true,
                        attempt_statuses: { queued: 1 },
                        checked_at: '2026-09-09T10:00:00Z',
                        review_url: null,
                    },
                },
            ];
            const reload = vi
                .spyOn(router, 'reload')
                .mockImplementation(() => {});
            render(<ItTicketShow {...props} />);
            fireEvent.change(
                screen.getByRole('textbox', { name: 'Your reply' }),
                { target: { value: 'Retained confidential draft' } },
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh delivery status' }),
            );
            act(() =>
                document.dispatchEvent(
                    new CustomEvent('inertia:invalid', {
                        cancelable: true,
                        detail: {
                            response: {
                                status,
                                config: {
                                    headers: reload.mock.calls[0][0]?.headers,
                                },
                            },
                        },
                    }),
                ),
            );
            await waitFor(() =>
                expect(
                    screen.queryByRole('textbox', { name: 'Your reply' }),
                ).not.toBeInTheDocument(),
            );
            expect(
                screen.queryByText('Private current ticket conversation'),
            ).not.toBeInTheDocument();
            expect(screen.getByRole('alert')).toHaveFocus();
            if (status === 403) {
                await waitFor(() =>
                    expect(
                        screen.queryByText('Investigate the shared printer'),
                    ).not.toBeInTheDocument(),
                );
            } else {
                const exit = new Event('beforeunload', { cancelable: true });
                window.dispatchEvent(exit);
                expect(exit.defaultPrevented).toBe(true);
                const navigation = new CustomEvent('inertia:before', {
                    cancelable: true,
                    detail: {
                        visit: {
                            method: 'get',
                            url: new URL('http://localhost/it'),
                            preserveState: false,
                        },
                    },
                });
                act(() => document.dispatchEvent(navigation));
                expect(navigation.defaultPrevented).toBe(true);
                const confirmation = await screen.findByRole('alertdialog');
                expect(confirmation).toBeVisible();
                expect(confirmation).not.toHaveTextContent(
                    'Retained confidential draft',
                );
                fireEvent.click(
                    within(confirmation).getByRole('button', {
                        name: 'Cancel',
                    }),
                );
                fireEvent.click(
                    screen.getByRole('button', { name: 'Check access again' }),
                );
                act(() => reload.mock.calls[1][0]?.onFinish?.({} as never));
                expect(
                    screen.queryByRole('textbox', { name: 'Your reply' }),
                ).not.toBeInTheDocument();
                fireEvent.click(
                    screen.getByRole('button', { name: 'Check access again' }),
                );
                act(() =>
                    reload.mock.calls[2][0]?.onSuccess?.({
                        component: 'it/tickets/show',
                        props: {
                            auth: { user: { id: 8 } },
                            viewer_user_id: 8,
                            ticket: props.ticket,
                        },
                    } as never),
                );
                expect(
                    await screen.findByRole('textbox', { name: 'Your reply' }),
                ).toHaveValue('Retained confidential draft');
            }
        },
    );

    it('conceals the original composer before changed auth props apply and requires a full reload', async () => {
        const props = fixture();
        props.conversation_ready = true;
        props.comments = [
            {
                id: 71,
                body: 'Previous actor conversation',
                is_internal: false,
                author: { id: 8, name: 'Alex Requester', is_requester: true },
                attachments: [],
                at: null,
                at_human: 'now',
                delivery: {
                    tracking: 'recorded',
                    requested: true,
                    attempt_statuses: { queued: 1 },
                    checked_at: '2026-09-09T10:00:00Z',
                    review_url: null,
                },
            },
        ];
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => {});
        const view = render(<ItTicketShow {...props} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Your reply' }), {
            target: { value: 'Previous actor secret draft' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh delivery status' }),
        );
        act(() =>
            reload.mock.calls[0][0]?.onBeforeUpdate?.({
                component: 'it/tickets/show',
                props: {
                    auth: { user: { id: 9 } },
                    viewer_user_id: 9,
                    ticket: props.ticket,
                },
            } as never),
        );
        state.userId = 9;
        view.rerender(<ItTicketShow {...props} viewer_user_id={9} />);
        await waitFor(() =>
            expect(
                screen.queryByRole('textbox', { name: 'Your reply' }),
            ).not.toBeInTheDocument(),
        );
        expect(
            screen.queryByText('Previous actor conversation'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Reload page' }),
        ).toHaveAttribute('href', '/it/tickets/42');
    });
    it('recovers raw classification through the canonical property editor after unmount with its original version', async () => {
        state.url = '/it/tickets/42?tab=properties';
        const props = fixture();
        props.can.manage = true;
        const post = vi
            .spyOn(axios, 'post')
            .mockImplementation(async (_url, data) => {
                const request = data as Record<string, unknown>;
                return {
                    status: 200,
                    data: {
                        candidate: {
                            kind: 'memory',
                            memory_uuid: request.memory_uuid,
                            candidate_uuid: request.candidate_uuid,
                            actor_user_id: 8,
                            purpose: 'ticket_edit',
                            context_key: 'ticket:42',
                            base_ticket_version: 3,
                            current_ticket_version: 4,
                            authorized: true,
                            capabilities: { submit: false },
                            blocker: {
                                code: 'ticket_changed',
                                message:
                                    'Review the current ticket before applying this work.',
                            },
                        },
                    },
                };
            });
        const update = vi
            .spyOn(axios, 'patch')
            .mockImplementation(() => new Promise(() => {}));
        const first = render(<ItTicketShow {...props} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Subcategory' }), {
            target: { value: 'Unsent final classification character!' },
        });
        first.unmount();
        render(
            <ItTicketShow
                {...props}
                ticket={{ ...props.ticket, lock_version: 4 }}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Subcategory' }),
        ).toHaveValue('');
        expect(
            screen.queryByText('Unsent final classification character!'),
        ).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume browser work' }),
        );
        expect(
            await screen.findByText('Unsent final classification character!'),
        ).toBeInTheDocument();
        expect(post).toHaveBeenCalledWith(
            '/it/drafts/validate-local-candidate',
            expect.objectContaining({
                actor_user_id: 8,
                ticket_id: 42,
                base_ticket_version: 3,
                fields: {
                    subcategory: 'Unsent final classification character!',
                },
            }),
            expect.any(Object),
        );
        expect(
            screen.getByRole('button', { name: 'Apply my changes' }),
        ).toBeDisabled();
        expect(update).not.toHaveBeenCalled();
    });

    it('shows refreshed canonical classification when the field has not been edited', () => {
        state.url = '/it/tickets/42?tab=properties';
        const props = fixture();
        props.can.manage = true;
        const view = render(<ItTicketShow {...props} />);
        view.rerender(
            <ItTicketShow
                {...props}
                ticket={{
                    ...props.ticket,
                    subcategory: 'Current printer model',
                    lock_version: 4,
                }}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Subcategory' }),
        ).toHaveValue('Current printer model');
        expect(
            screen.queryByRole('button', { name: 'Save subcategory' }),
        ).not.toBeInTheDocument();
    });

    it('submits the version at the start of classification editing after a newer ticket refresh', () => {
        state.url = '/it/tickets/42?tab=properties';
        const props = fixture();
        props.can.manage = true;
        const update = vi
            .spyOn(axios, 'patch')
            .mockImplementation(() => new Promise(() => {}));
        const view = render(<ItTicketShow {...props} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Subcategory' }), {
            target: { value: 'Original local proposal' },
        });
        view.rerender(
            <ItTicketShow
                {...props}
                ticket={{
                    ...props.ticket,
                    subcategory: 'A concurrent edit',
                    lock_version: 4,
                }}
            />,
        );
        const subcategoryInput = screen.getByRole('textbox', {
            name: 'Subcategory',
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save subcategory' }),
        );
        expect(update).toHaveBeenCalledWith(
            '/it/tickets/42',
            expect.objectContaining({
                subcategory: 'Original local proposal',
                expected_version: 3,
                actor_user_id: 8,
            }),
            expect.any(Object),
        );
        expect(subcategoryInput).toBeDisabled();
    });

    it('retains unsaved classification text on refresh but never renders it for another actor or ticket', () => {
        state.url = '/it/tickets/42?tab=properties';
        const props = fixture();
        props.can.manage = true;
        const view = render(<ItTicketShow {...props} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Subcategory' }), {
            target: { value: 'Private local classification text' },
        });
        view.rerender(
            <ItTicketShow
                {...props}
                ticket={{ ...props.ticket, lock_version: 4 }}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Subcategory' }),
        ).toHaveValue('Private local classification text');
        state.userId = 9;
        view.rerender(<ItTicketShow {...props} />);
        expect(
            screen.queryByRole('textbox', { name: 'Subcategory' }),
        ).not.toBeInTheDocument();
        state.userId = 8;
        view.rerender(<ItTicketShow {...props} />);
        expect(
            screen.getByRole('textbox', { name: 'Subcategory' }),
        ).toHaveValue('');
        state.userId = 9;
        view.rerender(<ItTicketShow {...props} viewer_user_id={9} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Subcategory' }), {
            target: { value: 'Different actor local text' },
        });
        state.url = '/it/tickets/43?tab=properties';
        view.rerender(
            <ItTicketShow
                {...props}
                viewer_user_id={9}
                ticket={{
                    ...props.ticket,
                    id: 43,
                    subcategory: 'Canonical current value',
                }}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Subcategory' }),
        ).toHaveValue('Canonical current value');
    });

    it('returns to current server values after a matching classification commit is observed', () => {
        state.url = '/it/tickets/42?tab=properties';
        const props = fixture();
        props.can.manage = true;
        const view = render(<ItTicketShow {...props} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Subcategory' }), {
            target: { value: 'Saved model ' },
        });
        view.rerender(
            <ItTicketShow
                {...props}
                ticket={{
                    ...props.ticket,
                    subcategory: 'Saved model',
                    lock_version: 4,
                }}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Subcategory' }),
        ).toHaveValue('Saved model');
        expect(
            screen.queryByRole('button', { name: 'Save subcategory' }),
        ).not.toBeInTheDocument();
        view.rerender(
            <ItTicketShow
                {...props}
                ticket={{
                    ...props.ticket,
                    subcategory: 'Later canonical model',
                    lock_version: 5,
                }}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Subcategory' }),
        ).toHaveValue('Later canonical model');
        expect(
            screen.queryByRole('button', { name: 'Save subcategory' }),
        ).not.toBeInTheDocument();
    });

    it('keeps an unsent reply through section changes and browser Back while exposing canonical section links', () => {
        const props = fixture();
        const visit = vi.spyOn(router, 'get').mockImplementation(() => {});
        const view = render(<ItTicketShow {...props} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Your reply' }), {
            target: {
                value: 'I have checked the printer and will attach the result.',
            },
        });
        expect(screen.getByRole('tab', { name: 'Files' })).toHaveAttribute(
            'href',
            '/it/tickets/42?tab=files',
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Files' }));
        expect(visit).toHaveBeenCalledWith(
            '/it/tickets/42',
            { tab: 'files' },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
            }),
        );
        state.url = '/it/tickets/42?tab=files';
        view.rerender(<ItTicketShow {...props} />);
        expect(
            screen.queryByRole('textbox', { name: 'Your reply' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Printer evidence.txt/ }),
        ).toHaveAttribute('href', '/it/attachments/7');
        state.url = '/it/tickets/42';
        view.rerender(<ItTicketShow {...props} />);
        expect(screen.getByRole('textbox', { name: 'Your reply' })).toHaveValue(
            'I have checked the printer and will attach the result.',
        );
    });

    it('uses keyboard tab navigation and a real scoped section search', () => {
        const visit = vi.spyOn(router, 'get').mockImplementation(() => {});
        render(<ItTicketShow {...fixture()} />);
        const messages = screen.getByRole('tab', { name: 'Messages' });
        messages.focus();
        fireEvent.keyDown(messages, { key: 'ArrowRight' });
        expect(screen.getByRole('tab', { name: 'Files' })).toHaveFocus();
        expect(visit).toHaveBeenLastCalledWith(
            '/it/tickets/42',
            { tab: 'files' },
            expect.any(Object),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Find a ticket section/ }),
        );
        expect(screen.getByRole('dialog')).toBeVisible();
        fireEvent.change(
            screen.getByRole('textbox', {
                name: 'Find a section in this ticket',
            }),
            { target: { value: 'clock' } },
        );
        // Search uses section names; a query without a match must not fabricate results.
        expect(
            screen.queryByRole('option', { name: /Files/ }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Nothing matches “clock”.')).toBeVisible();
        fireEvent.change(
            screen.getByRole('textbox', {
                name: 'Find a section in this ticket',
            }),
            { target: { value: 'service' } },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Service levels, Details & links',
            }),
        );
        expect(visit).toHaveBeenLastCalledWith(
            '/it/tickets/42',
            { tab: 'sla' },
            expect.any(Object),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('opens settled work directly for an authorized technician without editing controls', () => {
        state.url = '/it/tickets/42?tab=tasks';
        const props = fixture();
        props.can.manage = true;
        props.can.internal = true;
        props.can.comment = false;
        props.ticket.status = 'resolved';
        render(<ItTicketShow {...props} />);
        expect(
            screen.getByRole('heading', { name: 'Tasks & evidence' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Add task' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('combobox', { name: 'Priority' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute(
            'href',
            '/dashboard',
        );
    });

    it('invalid section input falls back to the conversation and actor changes clear the unsent field', () => {
        state.url = '/it/tickets/42?tab=untrusted-section';
        const props = fixture();
        const view = render(<ItTicketShow {...props} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Your reply' }), {
            target: { value: 'Actor-bound unsent text' },
        });
        state.userId = 9;
        view.rerender(<ItTicketShow {...props} />);
        expect(
            screen.queryByRole('textbox', { name: 'Your reply' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Reload page' })).toBeVisible();
    });

    it('does not offer reply on settled work and gives the permitted reopen action', () => {
        const props = fixture();
        props.ticket.status = 'closed';
        props.can.comment = false;
        props.can.reopen = true;
        props.replyUnavailableReason =
            'Reopen this ticket before adding another reply.';
        render(<ItTicketShow {...props} />);
        expect(
            screen.queryByRole('textbox', { name: 'Your reply' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('This conversation is read-only'),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Reopen ticket' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Write a reply' }),
        ).not.toBeInTheDocument();
    });

    it('retains an honest failure and retry action if watching is rejected', async () => {
        const props = fixture();
        props.can.manage = true;
        props.can.manageWatchers = true;
        props.watcherOptions = [{ id: 8, name: 'Alex Requester' }];
        const patch = vi.spyOn(axios, 'patch').mockRejectedValue({
            isAxiosError: true,
            response: { status: 422 },
        });
        render(<ItTicketShow {...props} />);
        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'Ticket actions' }),
            { button: 0, ctrlKey: false },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Watch ticket' }),
        );
        expect(patch).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Add watcher' }));
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'This attempt was rejected',
            ),
        );
        expect(patch).toHaveBeenCalledExactlyOnceWith(
            '/it/tickets/42/watchers/8',
            { actor_user_id: 8, expected_version: 3, watching: true },
            expect.anything(),
        );
        expect(toast.success).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Review current watchers' }),
        ).toBeEnabled();
    });

    it('reports clipboard failure without a copied success message', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: vi
                    .fn()
                    .mockRejectedValue(new Error('Permission denied')),
            },
        });
        render(<ItTicketShow {...fixture()} />);
        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'Ticket actions' }),
            { button: 0, ctrlKey: false },
        );
        await act(async () =>
            fireEvent.click(
                await screen.findByRole('menuitem', {
                    name: 'Copy ticket link',
                }),
            ),
        );
        await waitFor(() =>
            expect(toast.error).toHaveBeenCalledWith(
                expect.stringContaining('could not be copied'),
            ),
        );
        expect(toast.success).not.toHaveBeenCalled();
    });

    it.each([
        { label: 'non-command response', id: undefined, acknowledged: false },
        {
            label: 'another ticket',
            id: 99,
            acknowledged: false,
        },
        {
            label: 'exact watcher command',
            id: 42,
            acknowledged: true,
        },
    ])(
        'confirms a watch only for the returned actor, ticket, version and desired state: $label',
        async (result) => {
            const props = fixture();
            props.can.manage = true;
            props.can.manageWatchers = true;
            props.watcherOptions = [{ id: 8, name: 'Alex Requester' }];
            vi.spyOn(router, 'reload').mockImplementation(() => {});
            vi.spyOn(axios, 'patch').mockResolvedValue({
                status: 200,
                data: result.id
                    ? {
                          status: 'committed',
                          data: {
                              id: result.id,
                              viewer_user_id: 8,
                              watcher_user_id: 8,
                              watching: true,
                              changed: true,
                              lock_version: 4,
                          },
                      }
                    : {},
            });
            render(<ItTicketShow {...props} />);
            fireEvent.pointerDown(
                screen.getByRole('button', { name: 'Ticket actions' }),
                { button: 0, ctrlKey: false },
            );
            fireEvent.click(
                await screen.findByRole('menuitem', { name: 'Watch ticket' }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Add watcher' }),
            );
            if (result.acknowledged)
                await waitFor(() =>
                    expect(screen.getByRole('status')).toHaveTextContent(
                        'The watcher was added.',
                    ),
                );
            else {
                expect(toast.success).not.toHaveBeenCalled();
                await waitFor(() =>
                    expect(screen.getByRole('alert')).toHaveTextContent(
                        'could not be confirmed',
                    ),
                );
            }
        },
    );

    it('links each header meter to an actual section while preserving unsent fields', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        const props = fixture();
        props.can.manage = true;
        render(<ItTicketShow {...props} />);
        fireEvent.click(
            screen.getByRole('link', { name: 'View required ticket work' }),
        );
        expect(visit).toHaveBeenCalledWith(
            '/it/tickets/42?tab=tasks',
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
            }),
        );
        expect(
            screen.getByRole('link', { name: 'View permitted linked records' }),
        ).toHaveTextContent('1');
    });

    it('omits private work summaries and navigation for a participant, including a copied work-section URL', () => {
        state.url = '/it/tickets/42?tab=tasks';
        render(<ItTicketShow {...fixture()} />);
        expect(
            screen.queryByRole('link', { name: 'View required ticket work' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Work' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('No required tasks recorded'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('tab', { name: 'Messages', selected: true }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Find a section' }));
        const palette = screen.getByRole('dialog');
        expect(
            within(palette).queryByText('Tasks & evidence'),
        ).not.toBeInTheDocument();
        expect(
            within(palette).queryByText('Approvals'),
        ).not.toBeInTheDocument();
    });
});
