import { ItProvisioningList } from '@/components/it/it-provisioning-list';
import { ItWizard, type RequestRow } from '@/components/it/it-wizards';
import { ProvisioningRequestFiles } from '@/components/it/provisioning-request-files';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { auth: { user: { id: 3 } } } }),
}));

const row: RequestRow = {
    id: 4,
    item: 'Synthetic headset request',
    employee: { name: 'Synthetic worker', role: null },
    type: 'equipment',
    status: 'done',
    priority: 'normal',
    due_date: null,
    assignee: null,
    task_key: null,
    action: null,
    category: null,
    responsible_team: null,
    stage: null,
    dependency_request_ids: [],
    approval_required: false,
    approval_status: null,
    approver: null,
    evidence_required: false,
    evidence_summary: null,
    failure_reason: null,
    fulfiller_context: {},
    workflow: null,
    external_ref: null,
    notes: null,
    from_onboarding: false,
    sign_off_required: false,
    created: null,
    fulfilled: null,
    linked_ticket: null,
    linked_ticket_count: 0,
    attachments: [
        {
            id: 8,
            name: 'request.txt',
            size: 1024,
            url: '/it/attachments/8',
            catalogue_field_label: 'Supporting files',
            is_internal: false,
        },
        {
            id: 9,
            name: 'verification.txt',
            size: 2048,
            url: '/it/attachments/9',
            catalogue_field_label: 'IT evidence',
            is_internal: true,
        },
    ],
};

it.each(['cards', 'table'] as const)(
    'opens files from a completed %s row without requiring management controls',
    async (view) => {
        render(
            <ItProvisioningList
                rows={[row]}
                view={view}
                actionsFor={() => []}
                selected={new Set()}
                onSelect={vi.fn()}
                canManage={false}
                today="2026-09-13"
            />,
        );
        const trigger = screen.getByRole('button', {
            name: `View 2 submitted files for ${row.item}`,
        });
        trigger.focus();
        fireEvent.click(trigger);
        const dialog = screen.getByRole('dialog', { name: 'Request files' });
        expect(
            within(dialog).getByRole('link', {
                name: 'request.txt (opens in a new tab)',
            }),
        ).toHaveAttribute('href', '/it/attachments/8');
        const internal = within(dialog).getByRole('link', {
            name: 'verification.txt (opens in a new tab)',
        });
        expect(internal).toHaveAttribute('target', '_blank');
        expect(internal).toHaveAttribute('href', '/it/attachments/9');
        expect(within(dialog).getByText('Internal IT')).toBeVisible();
        expect(within(dialog).getByText(/Supporting files ·/)).toBeVisible();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        fireEvent.keyDown(dialog, { key: 'Escape' });
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        await waitFor(() => expect(trigger).toHaveFocus());
    },
);

it('removes a file dialog when a fresh server projection no longer grants file access', () => {
    const { rerender } = render(
        <ProvisioningRequestFiles item={row.item} files={row.attachments} />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: /View 2 submitted files/ }),
    );
    expect(
        screen.getByRole('link', { name: /verification.txt/ }),
    ).toBeVisible();
    rerender(<ProvisioningRequestFiles item={row.item} files={[]} />);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

it('makes the same original files available while recording fulfilment', () => {
    render(
        <ItWizard
            modal={{ type: 'fulfil', request: { ...row, status: 'pending' } }}
            assignees={[]}
            onClose={vi.fn()}
        />,
    );
    const files = screen.getByRole('region', { name: 'Submitted files' });
    expect(
        within(files).getByRole('link', { name: /verification.txt/ }),
    ).toHaveAttribute('href', '/it/attachments/9');
    expect(within(files).getByText('Internal IT')).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Mark fulfilled' }),
    ).toBeVisible();
});
