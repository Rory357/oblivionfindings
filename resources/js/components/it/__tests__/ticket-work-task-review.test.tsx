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

import { Button } from '@/components/ui/button';
import type {
    ItWorkTaskRecord,
    ItWorkTaskReview,
} from '@/hooks/it-work-task-command';
import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    initialWorkTaskFields,
    useItWorkTaskEditor,
} from '@/hooks/use-it-work-task-editor';
import { taskReadiness } from '@/test/it-work-task-fixtures';
import { TicketWorkTaskRecovery } from '../ticket-work-task-recovery';

const original: ItWorkTaskRecord = {
    id: 10,
    title: 'Original saved task',
    description: 'Original description',
    status: 'pending',
    due_at: null,
    is_required: false,
    evidence_required: false,
    evidence: null,
    completion_note: null,
    completed_at: null,
    sort_order: 10,
    team: null,
    assignee: null,
    completed_by: null,
    dependencies: [],
    approval: null,
    current_completion_id: null,
    readiness: taskReadiness({ can_cancel: true }),
};
const current: ItWorkTaskRecord = {
    ...original,
    title: 'Current saved task title',
    description: 'Current saved description\nSecond line',
    status: 'blocked',
    due_at: '2026-09-09T01:30:00Z',
    is_required: true,
    evidence_required: true,
    team: { id: 3, name: 'Current Infrastructure Team' },
    assignee: { id: 8, name: 'Current Technician' },
    completion_note: 'Current recorded completion note',
    evidence: ['Current evidence A', 'Current evidence B'],
    completed_at: '2026-09-08T23:00:00Z',
    completed_by: { id: 7, name: 'Recorded Completer' },
    dependencies: [
        { id: 9, title: 'Current cancelled prerequisite', status: 'cancelled' },
    ],
};
const prerequisite: ItWorkTaskRecord = {
    ...original,
    id: 9,
    title: 'Current cancelled prerequisite',
    status: 'cancelled',
    sort_order: 0,
};
function response(
    tasks: ItWorkTaskRecord[] = [prerequisite, current],
    overrides: Record<string, unknown> = {},
) {
    return {
        status: 200,
        data: {
            viewer_user_id: 7,
            ticket: {
                id: 42,
                lock_version: 5,
                status: 'open',
                merged_into: null,
            },
            can: { manage: true },
            linked_context: { tasks },
            assignees: [{ id: 8, name: 'Current Technician' }],
            teamOptions: [{ id: 3, name: 'Current Infrastructure Team' }],
            approvals: [],
            ...overrides,
        },
    };
}
function Harness({
    operation = 'update',
    onAdopt = () => {},
}: {
    operation?: 'create' | 'update' | 'reorder';
    onAdopt?: (review: ItWorkTaskReview) => void;
}) {
    const editor = useItWorkTaskEditor({
        actorId: 7,
        ticketId: 42,
        taskId: operation === 'update' ? original.id : null,
        operation,
        version: 4,
        initialFields:
            operation === 'reorder'
                ? { ordered_ids: [original.id, prerequisite.id] }
                : initialWorkTaskFields(
                      operation,
                      operation === 'update' ? original : null,
                  ),
        canManage: true,
        onCommitted: () => {},
    });
    return (
        <>
            <Button
                type="button"
                onClick={() => void editor.command.reviewCurrent()}
            >
                Load current work
            </Button>
            <Button
                type="button"
                onClick={() => editor.setField('title', 'My retained proposal')}
            >
                Change proposal title
            </Button>
            <Button type="button" onClick={() => editor.submit()}>
                Send proposal
            </Button>
            <output aria-label="Proposal version">{editor.baseVersion}</output>
            {!editor.concealed && (
                <>
                    <output aria-label="Proposed title">
                        {editor.fields.title}
                    </output>
                    <output aria-label="Proposed order">
                        {editor.fields.ordered_ids?.join(',')}
                    </output>
                </>
            )}
            <TicketWorkTaskRecovery
                editor={editor}
                taskId={operation === 'update' ? original.id : null}
                onAdopt={onAdopt}
            />
        </>
    );
}
async function loadReview() {
    fireEvent.click(screen.getByRole('button', { name: 'Load current work' }));
    return await screen.findByRole('region', { name: 'Current task review' });
}
function row(label: string): HTMLElement {
    const review = screen.getByRole('region', { name: 'Current task review' });
    const parent = within(review).getByText(label, {
        exact: true,
    }).parentElement;
    if (!parent) throw new Error('The approved review row is missing.');
    return parent;
}

describe('Current work task review', () => {
    beforeEach(() => {
        clearItTicketDraftMemory();
        sessionStorage.clear();
        vi.spyOn(axios, 'get').mockResolvedValue(response());
        vi.spyOn(axios, 'request').mockRejectedValue({
            isAxiosError: true,
            response: { status: 500 },
        });
    });
    afterEach(() => {
        cleanup();
        clearItTicketDraftMemory();
        sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it('shows every current editable value and evidence before explicit adoption without overwriting the proposal', async () => {
        const onAdopt = vi.fn();
        render(<Harness onAdopt={onAdopt} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change proposal title' }),
        );
        expect(
            screen.queryByRole('region', { name: 'Current task review' }),
        ).not.toBeInTheDocument();
        await loadReview();

        expect(row('Title')).toHaveTextContent(current.title);
        expect(row('Description')).toHaveTextContent(
            'Current saved description Second line',
        );
        expect(row('Status')).toHaveTextContent('blocked');
        expect(row('Assigned technician')).toHaveTextContent(
            'Current Technician',
        );
        expect(row('Responsible team')).toHaveTextContent(
            'Current Infrastructure Team',
        );
        expect(row('Due (New Zealand time)')).toHaveTextContent(
            /Wed 9 Sept?, 1:30 pm/,
        );
        expect(row('Required before settlement')).toHaveTextContent('Yes');
        expect(row('Completion evidence required')).toHaveTextContent('Yes');
        expect(row('Prerequisites')).toHaveTextContent(
            'Current cancelled prerequisite · cancelled',
        );
        expect(row('Order position')).toHaveTextContent('2 of 2');
        expect(row('Completion note')).toHaveTextContent(
            'Current recorded completion note',
        );
        expect(row('Evidence references')).toHaveTextContent(
            'Current evidence A',
        );
        expect(row('Evidence references')).toHaveTextContent(
            'Current evidence B',
        );
        expect(row('Completed by')).toHaveTextContent('Recorded Completer');
        expect(row('Completed (New Zealand time)')).toHaveTextContent(
            /Wed 9 Sept?, 11:00 am/,
        );
        expect(screen.getByLabelText('Proposed title')).toHaveTextContent(
            'My retained proposal',
        );
        expect(screen.getByLabelText('Proposal version')).toHaveTextContent(
            '4',
        );
        expect(onAdopt).not.toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', { name: 'Use reviewed version' }),
        );
        expect(onAdopt).toHaveBeenCalledTimes(1);
        expect(onAdopt.mock.calls[0][0]).toMatchObject({
            version: 5,
            viewerId: 7,
            ticketId: 42,
        });
        expect(screen.getByLabelText('Proposed title')).toHaveTextContent(
            'My retained proposal',
        );
        expect(screen.getByLabelText('Proposal version')).toHaveTextContent(
            '5',
        );
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('shows the current saved sequence separately from a retained reorder proposal', async () => {
        render(<Harness operation="reorder" />);
        await loadReview();
        const order = screen.getByRole('list', {
            name: 'Current saved task order',
        });
        expect(
            within(order)
                .getAllByRole('listitem')
                .map((item) => item.textContent),
        ).toEqual([
            'Current cancelled prerequisite · cancelled',
            'Current saved task title · blocked',
        ]);
        expect(screen.getByLabelText('Proposed order')).toHaveTextContent(
            '10,9',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Use reviewed version' }),
        );
        expect(screen.getByLabelText('Proposed order')).toHaveTextContent(
            '10,9',
        );
        expect(screen.getByLabelText('Proposal version')).toHaveTextContent(
            '5',
        );
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('labels absent ownership, dates, requirements and evidence instead of implying they are unchanged', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce(
            response([{ ...original, description: null }]),
        );
        render(<Harness />);
        await loadReview();
        expect(row('Assigned technician')).toHaveTextContent('Unassigned');
        expect(row('Responsible team')).toHaveTextContent('Unassigned');
        expect(row('Due (New Zealand time)')).toHaveTextContent('Not set');
        expect(row('Required before settlement')).toHaveTextContent('No');
        expect(row('Completion evidence required')).toHaveTextContent('No');
        expect(row('Prerequisites')).toHaveTextContent('None');
        expect(row('Completion note')).toHaveTextContent(
            'No completion note recorded.',
        );
        expect(row('Evidence references')).toHaveTextContent('None recorded');
        expect(row('Completed by')).toHaveTextContent('Not recorded');
        expect(row('Completed (New Zealand time)')).toHaveTextContent(
            'Not recorded',
        );
    });

    it('shows the approved empty state when there is no current saved task order', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce(response([]));
        render(<Harness operation="create" />);
        await loadReview();
        expect(
            screen.getByText('No saved tasks in the current register'),
        ).toBeVisible();
        expect(
            screen.queryByRole('list', { name: 'Current saved task order' }),
        ).not.toBeInTheDocument();
    });

    it('does not reveal any expanded review values for another session actor', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce(
            response(undefined, { viewer_user_id: 88 }),
        );
        render(<Harness />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Load current work' }),
        );
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(1));
        await screen.findByText(
            /Your access changed. Private task work has been removed/,
        );
        expect(
            screen.queryByRole('region', { name: 'Current task review' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Current Infrastructure Team'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Current evidence A'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Use reviewed version' }),
        ).not.toBeInTheDocument();
    });

    it('conceals previously reviewed values if a new current read finds the session expired', async () => {
        render(<Harness />);
        await loadReview();
        vi.mocked(axios.get).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 419 },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Load current work' }),
        );
        await screen.findByText(
            /Sign in with the same account, then retry the current task review/,
        );
        expect(
            screen.queryByRole('region', { name: 'Current task review' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Current Infrastructure Team'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Current evidence A'),
        ).not.toBeInTheDocument();
    });

    it('keeps adoption disabled for a settled ticket while allowing authorized current values to be reviewed', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce(
            response(undefined, {
                ticket: {
                    id: 42,
                    lock_version: 5,
                    status: 'resolved',
                    merged_into: null,
                },
            }),
        );
        const onAdopt = vi.fn();
        render(<Harness onAdopt={onAdopt} />);
        await loadReview();
        expect(row('Responsible team')).toHaveTextContent(
            'Current Infrastructure Team',
        );
        expect(
            screen.getByRole('button', { name: 'Use reviewed version' }),
        ).toBeDisabled();
        expect(onAdopt).not.toHaveBeenCalled();
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('does not adopt a new version while the original command outcome is unknown', async () => {
        const onAdopt = vi.fn();
        render(<Harness onAdopt={onAdopt} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Change proposal title' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Send proposal' }));
        await screen.findByRole('button', { name: 'Retry original command' });
        await loadReview();
        expect(row('Prerequisites')).toHaveTextContent(
            'Current cancelled prerequisite',
        );
        expect(
            screen.getByRole('button', { name: 'Use reviewed version' }),
        ).toBeDisabled();
        expect(screen.getByLabelText('Proposal version')).toHaveTextContent(
            '4',
        );
        expect(onAdopt).not.toHaveBeenCalled();
        expect(axios.request).toHaveBeenCalledTimes(1);
    });
});
