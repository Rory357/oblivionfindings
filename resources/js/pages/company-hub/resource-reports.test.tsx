import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ResourceReports,
    type OwnResourceReport,
    type ResourceReportContext,
} from './_resource-reports';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({ router: { on: () => vi.fn() } }));
const report: OwnResourceReport = {
    id: 12,
    revision_id: 4,
    current_revision: true,
    issue: 'Broken link',
    note: 'The induction link does not open.',
    resolved: false,
    response: '',
    version: 1,
    created_at: '2026-10-13T10:00:00Z',
    updated_at: '2026-10-13T10:00:00Z',
};
const context: ResourceReportContext = {
    actor_id: 8,
    page_id: 3,
    revision_id: 4,
    reports: [],
    next_cursor: null,
};
const saved = { ...context, saved: true, report };
function start() {
    fireEvent.click(screen.getByRole('button', { name: 'Report an issue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Broken link' }));
    fireEvent.change(
        screen.getByRole('textbox', { name: 'What should we look at?' }),
        { target: { value: report.note } },
    );
}
beforeEach(() => request.mockReset());
describe('reader correction follow-up', () => {
    it('keeps unsent correction text guarded when the side panel closes', () => {
        render(<ResourceReports data={context} />);
        start();
        expect(
            screen.getByRole('dialog', {
                name: 'Help keep this resource useful',
            }),
        ).toHaveAttribute('data-slot', 'sheet-content');
        fireEvent.click(
            screen.getByRole('button', { name: 'Close corrections' }),
        );
        const leaving = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(leaving);
        expect(leaving.defaultPrevented).toBe(true);
        fireEvent.click(
            screen.getByRole('button', { name: 'Report an issue' }),
        );
        expect(
            screen.getByRole('textbox', { name: 'What should we look at?' }),
        ).toHaveValue(report.note);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'What should we look at?' }),
            { target: { value: '' } },
        );
        const cleanLeave = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(cleanLeave);
        expect(cleanLeave.defaultPrevented).toBe(false);
        expect(request).not.toHaveBeenCalled();
    });
    it('submits the current actor and publication, then displays the confirmed persistent report', async () => {
        request.mockResolvedValue(saved);
        render(<ResourceReports data={context} />);
        start();
        fireEvent.click(
            screen.getByRole('button', { name: 'Send correction' }),
        );
        expect(
            await screen.findByRole('article', { name: 'Correction 12' }),
        ).toHaveTextContent(report.note);
        expect(request).toHaveBeenCalledWith(
            '/company-hub/pages/3/reports',
            'POST',
            {
                actor_id: 8,
                revision_id: 4,
                issue: 'Broken link',
                note: report.note,
            },
        );
        expect(screen.getByRole('status')).toHaveTextContent('sent for review');
    });
    it('retains unsent text on close and preserves it after a failed send', async () => {
        request.mockRejectedValue(new Error('Please try again.'));
        render(<ResourceReports data={context} />);
        start();
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep draft and close' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Report an issue' }),
        );
        expect(screen.getByRole('textbox')).toHaveValue(report.note);
        fireEvent.click(
            screen.getByRole('button', { name: 'Send correction' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Please try again.',
        );
        expect(screen.getByRole('textbox')).toHaveValue(report.note);
        request.mockResolvedValue(saved);
        fireEvent.click(
            screen.getByRole('button', { name: 'Send correction' }),
        );
        expect(
            await screen.findByRole('article', { name: 'Correction 12' }),
        ).toBeVisible();
    });
    it('shows an editor response and identifies a report about an earlier revision', async () => {
        const updated = {
            ...context,
            reports: [
                {
                    ...report,
                    resolved: true,
                    response: 'The link has been corrected in the new guide.',
                    current_revision: false,
                    version: 2,
                },
            ],
        };
        request.mockResolvedValue(updated);
        render(<ResourceReports data={updated} />);
        fireEvent.click(screen.getByRole('button', { name: 'Your reports' }));
        expect(
            await screen.findByText(
                'The link has been corrected in the new guide.',
            ),
        ).toBeVisible();
        expect(screen.getByText('Resolved')).toBeVisible();
        expect(screen.getByText(/An earlier version/)).toBeVisible();
    });
    it('does not accept a response belonging to another actor or publication', async () => {
        request.mockResolvedValue({
            ...saved,
            actor_id: 99,
            report: { ...report, note: 'Another person’s report' },
        });
        render(<ResourceReports data={context} />);
        start();
        fireEvent.click(
            screen.getByRole('button', { name: 'Send correction' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not confirm',
        );
        expect(
            screen.queryByText('Another person’s report'),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('textbox')).toHaveValue(report.note);
    });
    it('prevents duplicate submission and dismissal until the command settles', async () => {
        let resolve!: (result: typeof saved) => void;
        request.mockReturnValue(
            new Promise((complete) => {
                resolve = complete;
            }),
        );
        render(<ResourceReports data={context} />);
        start();
        fireEvent.click(
            screen.getByRole('button', { name: 'Send correction' }),
        );
        expect(screen.getByRole('button', { name: 'Sending…' })).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep draft and close' }),
        );
        expect(screen.getByRole('dialog')).toBeVisible();
        expect(request).toHaveBeenCalledTimes(1);
        await act(async () => resolve(saved));
        expect(screen.getByRole('status')).toBeVisible();
    });
    it('loads earlier reports without duplicating rows and resets private state on account change', async () => {
        const first = { ...context, reports: [report], next_cursor: 12 };
        request.mockResolvedValueOnce(first).mockResolvedValueOnce({
            ...context,
            reports: [report, { ...report, id: 8 }],
            next_cursor: null,
        });
        const view = render(<ResourceReports data={first} />);
        fireEvent.click(screen.getByRole('button', { name: 'Your reports' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Earlier reports' }),
            ).toBeEnabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Earlier reports' }),
        );
        expect(
            await screen.findByRole('article', { name: 'Correction 8' }),
        ).toBeVisible();
        expect(
            within(
                screen.getByRole('region', { name: 'Your correction history' }),
            ).getAllByRole('article'),
        ).toHaveLength(2);
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/pages/3/reports?before=12',
            'GET',
        );
        view.rerender(<ResourceReports data={{ ...context, actor_id: 99 }} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Your reports' }),
        ).not.toBeInTheDocument();
    });
});
