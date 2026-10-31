import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ActionShow, { type CorrectiveActionWorkspace } from './show';

const state = vi.hoisted(() => ({
    url: '/health-safety/corrective-actions/42',
    post: vi.fn(),
    replace: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        href,
        ...props
    }: {
        children: ReactNode;
        href: string;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ url: state.url, props: { flash: {} } }),
    router: {
        replace: state.replace,
        post: state.post,
        on: () => () => {},
        visit: vi.fn(),
    },
    useForm: function useForm(initial: Record<string, unknown>) {
        const [data, setData] = useState(initial);
        return {
            data,
            errors: {},
            processing: false,
            isDirty: JSON.stringify(initial) !== JSON.stringify(data),
            setData: (key: string, value: unknown) =>
                setData((previous) => ({ ...previous, [key]: value })),
            post: (url: string, options: unknown) =>
                state.post(url, data, options),
        };
    },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));

function workspace(): CorrectiveActionWorkspace {
    return {
        action: {
            id: 42,
            reference_number: 'CA-0042',
            title: 'Repair uneven flooring',
            description: 'Repair the marked area.',
            action_type: 'corrective',
            priority: 'high',
            status: 'in_progress',
            owner: { id: 12, name: 'Action Owner' },
            assigned_to_name: 'Action Owner',
            due_date: '2026-10-15',
            is_overdue: false,
            completed_at: null,
            completed_by_user_id: null,
            completed_by_name: null,
            can_verify: false,
            verified_at: null,
            verified_by_name: null,
            effectiveness_confirmed: null,
            closed_at: null,
            hs_investigation_id: null,
            recommendation_index: null,
            recommendation: null,
            source: { type: 'restricted' },
            source_task: null,
            evidence: {
                can_upload: true,
                completion_notes: null,
                legacy_paths: [],
                completed_by: null,
                completed_at: null,
                load_state: 'loaded',
                attachments: [],
            },
            rework: { latest_reason: null },
            history: [],
        },
        review: {
            id: 77,
            reference_number: 'HS-0077',
            status: 'corrective_action',
            site_name: 'Kauri House',
            url: '/health-safety/events/77?section=actions',
        },
        source_task_url: null,
        ownership: {
            revision: 0,
            state: 'assigned',
            can_assign: true,
            history: [],
        },
        can: {
            start: false,
            complete: true,
            verify: false,
            return: false,
            close: false,
        },
    };
}
afterEach(cleanup);
beforeEach(() => {
    state.url = '/health-safety/corrective-actions/42';
    vi.clearAllMocks();
});

describe('Permanent corrective action', () => {
    it('counts retained ownership handovers in History and does not claim there is no history', () => {
        state.url += '?section=history';
        const data = workspace();
        data.ownership.history = [
            {
                id: 1,
                previous_owner: 'Previous owner',
                owner: 'Current owner',
                changed_by: 'Coordinator',
                changed_at: '2026-10-08T01:00:00Z',
                reason: 'Agreed cover.',
            },
        ];
        render(<ActionShow workspace={data} />);
        expect(
            within(screen.getByRole('tab', { name: /History/ })).getByText(
                '1',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Agreed cover.')).toBeInTheDocument();
        expect(
            screen.queryByText('No action history is available.'),
        ).not.toBeInTheDocument();
    });
    it('keeps authorized action work available when its review has closed', () => {
        const data = workspace();
        data.review.status = 'closed';
        render(<ActionShow workspace={data} />);
        expect(
            screen.getByText(/The safety review is closed/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Mark complete' }));
        expect(state.replace).toHaveBeenCalledWith(
            expect.objectContaining({
                url: expect.stringContaining(
                    '/corrective-actions/42?action=complete',
                ),
            }),
        );
        expect(state.post).not.toHaveBeenCalled();
    });
    it('distinguishes a damaged source link from a permission restriction', () => {
        const data = workspace();
        data.action.source = { type: 'unavailable' };
        render(<ActionShow workspace={data} />);
        expect(
            screen.getByText(
                'The source task link needs review. Ask the H&S team to check the linked records.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', {
                name: 'Open source operational task',
            }),
        ).not.toBeInTheDocument();
    });
    it('opens a record page with independent source access and the exact filtered return', () => {
        state.url +=
            '?return_to=%2Fhealth-safety%2Fcorrective-actions%3Ftab%3Doverdue%26site_id%3D3';
        render(<ActionShow workspace={workspace()} />);
        // The record header (pg-hsn-43): the action's title, its reference in the sub-line.
        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Repair uneven flooring',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(/CA-0042 · Kauri House/)).toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getAllByText('Action Owner')).not.toHaveLength(0);
        expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute(
            'href',
            '/health-safety/corrective-actions?tab=overdue&site_id=3',
        );
        expect(
            screen.getByRole('link', { name: 'Open safety review' }),
        ).toHaveAttribute('href', '/health-safety/events/77?section=actions');
        expect(
            screen.queryByRole('link', {
                name: 'Open source operational task',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'The source operational task requires separate access.',
            ),
        ).toBeInTheDocument();
    });

    it('does not turn a deep link into verification authority', () => {
        state.url += '?action=verify';
        const data = workspace();
        data.action.status = 'completed';
        data.can.complete = false;
        render(<ActionShow workspace={data} />);
        expect(
            screen.getByRole('heading', { name: 'This action is unavailable' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Verify action' }),
        ).not.toBeInTheDocument();
    });

    it('keeps a completion draft when cancelling section navigation', () => {
        state.url += '?action=complete';
        render(<ActionShow workspace={workspace()} />);
        const notes = screen.getByRole('textbox', { name: /What was done/ });
        fireEvent.change(notes, {
            target: { value: 'The repair needs a final inspection.' },
        });
        fireEvent.click(screen.getByRole('tab', { name: 'History' }));
        const prompt = screen.getByRole('alertdialog');
        expect(prompt).toHaveTextContent('Discard unsaved safety work?');
        fireEvent.click(within(prompt).getByRole('button', { name: 'Cancel' }));
        expect(notes).toHaveValue('The repair needs a final inspection.');
        expect(state.replace).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('tab', { name: 'History' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Discard and continue',
            }),
        );
        expect(state.replace).toHaveBeenCalledWith(
            expect.objectContaining({
                url: expect.stringContaining(
                    '/corrective-actions/42?section=history',
                ),
            }),
        );
    });

    it('submits the completion to the same canonical action', () => {
        state.url += '?action=complete';
        render(<ActionShow workspace={workspace()} />);
        fireEvent.change(
            screen.getByRole('textbox', { name: /What was done/ }),
            { target: { value: 'Repair inspected and confirmed.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Mark complete' }));
        expect(state.post).toHaveBeenCalledWith(
            '/health-safety/events/77/corrective-actions/42/complete',
            { completion_notes: 'Repair inspected and confirmed.' },
            expect.any(Object),
        );
    });

    it('asks before recording a start and uses the current action permission', () => {
        const data = workspace();
        data.action.status = 'open';
        data.can = { ...data.can, start: true, complete: false };
        render(<ActionShow workspace={data} />);
        fireEvent.click(screen.getByRole('button', { name: 'Start action' }));
        expect(state.post).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Start action',
            }),
        );
        expect(state.post).toHaveBeenCalledWith(
            '/health-safety/events/77/corrective-actions/42/start',
            {},
            expect.any(Object),
        );
    });
});
