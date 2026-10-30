import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubRequestError } from './_api';
import type { EditorialPage } from './_editorial';
import { ResourceCare } from './_resource-care';
import { ResourceReview, type ReviewableReport } from './_resource-review';

const { request, reload } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: request,
}));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { reload, on: () => vi.fn() },
}));
const page: EditorialPage = {
    id: 3,
    title: 'Welcome guide',
    kind: 'knowledge',
    owner: 'Learning team',
    version: 4,
    status: 'published',
    show_in_navigation: false,
    navigation_order: 0,
    planned_for: null,
    review_on: '2027-01-14',
    publish_at: null,
    expires_at: null,
    readers: 2,
    comments: 0,
    reactions: 0,
    helpful: 1,
};
const report: ReviewableReport = {
    id: 12,
    issue: 'Broken link',
    note: 'The arrival form has moved.',
    created_at: '2026-10-14T01:00:00Z',
    resolved: false,
    response: '',
    version: 2,
    page,
};
const receipt = {
    saved: true,
    actor_id: 11,
    page_id: 3,
    report_id: 12,
    report_version: 3,
    page_version: 4,
    review_on: '2027-01-14',
};
const write = (text = 'The link has been corrected.') =>
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Response to the reporter' }),
        { target: { value: text } },
    );
beforeEach(() => {
    request.mockReset();
    reload.mockReset();
});

describe('owner correction review', () => {
    it.each(['2027-03-20', ''])(
        'saves the response and changed draft date together using the original page version: %s',
        async (date) => {
            request.mockResolvedValue({
                ...receipt,
                page_version: 5,
                review_on: date || null,
            });
            const saved = vi.fn();
            const view = render(
                <ResourceReview
                    actorId={11}
                    report={report}
                    close={vi.fn()}
                    onSaved={saved}
                />,
            );
            fireEvent.change(screen.getByLabelText('Next review date'), {
                target: { value: date },
            });
            write();
            view.rerender(
                <ResourceReview
                    actorId={11}
                    report={{
                        ...report,
                        version: 8,
                        page: { ...page, version: 9 },
                    }}
                    close={vi.fn()}
                    onSaved={saved}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Respond & resolve' }),
            );
            expect(request).toHaveBeenCalledWith(
                '/company-hub/manage/3/reports/12',
                'PUT',
                {
                    resolved: true,
                    response: 'The link has been corrected.',
                    version: 2,
                    actor_id: 11,
                    review_on: date || null,
                    page_version: 4,
                },
            );
            await waitFor(() => expect(saved).toHaveBeenCalledOnce());
        },
    );
    it.each(['review', 'scheduled', 'archived'])(
        'keeps date changes locked but allows a response for a %s page',
        async (status) => {
            request.mockResolvedValue(receipt);
            const saved = vi.fn();
            render(
                <ResourceReview
                    actorId={11}
                    report={{ ...report, page: { ...page, status } }}
                    close={vi.fn()}
                    onSaved={saved}
                />,
            );
            expect(screen.getByLabelText('Next review date')).toBeDisabled();
            write();
            fireEvent.click(
                screen.getByRole('button', { name: 'Respond & resolve' }),
            );
            await waitFor(() => expect(saved).toHaveBeenCalledOnce());
            expect(request.mock.calls[0][2]).not.toHaveProperty('review_on');
            expect(request.mock.calls[0][2]).not.toHaveProperty('page_version');
        },
    );
    it('protects a date-only edit on cancel and retains both values after rejected validation', async () => {
        request.mockRejectedValue(
            new HubRequestError('Choose a valid review date.', 422),
        );
        const close = vi.fn();
        render(
            <ResourceReview
                actorId={11}
                report={report}
                close={close}
                onSaved={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByLabelText('Next review date'), {
            target: { value: '2027-03-20' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('alert')).toHaveTextContent('review date');
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        write();
        fireEvent.click(
            screen.getByRole('button', { name: 'Respond & resolve' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Choose a valid review date.',
        );
        expect(screen.getByLabelText('Next review date')).toHaveValue(
            '2027-03-20',
        );
        expect(
            screen.getByRole('textbox', { name: 'Response to the reporter' }),
        ).toHaveValue('The link has been corrected.');
        expect(
            screen.getByRole('button', { name: 'Respond & resolve' }),
        ).toBeEnabled();
    });
    it.each([
        { actor_id: 23 },
        { report_id: 13 },
        { page_version: 9 },
        { review_on: '2027-04-21' },
    ])(
        'requires a matching confirmation for a date write: %o',
        async (mismatch) => {
            request.mockResolvedValue({
                ...receipt,
                page_version: 5,
                review_on: '2027-03-20',
                ...mismatch,
            });
            const saved = vi.fn();
            render(
                <ResourceReview
                    actorId={11}
                    report={report}
                    close={vi.fn()}
                    onSaved={saved}
                />,
            );
            fireEvent.change(screen.getByLabelText('Next review date'), {
                target: { value: '2027-03-20' },
            });
            write();
            fireEvent.click(
                screen.getByRole('button', { name: 'Respond & resolve' }),
            );
            expect(await screen.findByRole('alert')).toHaveTextContent(
                'could not confirm',
            );
            expect(saved).not.toHaveBeenCalled();
            expect(
                screen.getByRole('button', { name: 'Respond & resolve' }),
            ).toBeDisabled();
        },
    );
    it('does not retry an uncertain transport or let an old account completion refresh the new account', async () => {
        request.mockRejectedValueOnce(new Error('Connection lost.'));
        const view = render(
            <ResourceCare
                actorId={11}
                pages={[{ ...page, reports: [report] }]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review report' }));
        write();
        fireEvent.click(
            screen.getByRole('button', { name: 'Respond & resolve' }),
        );
        await screen.findByRole('alert');
        expect(
            screen.getByRole('button', { name: 'Respond & resolve' }),
        ).toBeDisabled();
        view.rerender(
            <ResourceCare
                actorId={12}
                pages={[{ ...page, reports: [report] }]}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });
    it('shows the original correction in a side panel and preserves writing when cancellation is declined', () => {
        const close = vi.fn();
        render(
            <ResourceReview
                actorId={11}
                report={report}
                close={close}
                onSaved={vi.fn()}
            />,
        );
        const panel = screen.getByRole('dialog', {
            name: 'Review a correction',
        });
        expect(panel).toHaveAttribute('data-slot', 'sheet-content');
        expect(within(panel).getByText(report.note)).toBeVisible();
        expect(
            within(panel).getByRole('heading', { name: page.title }),
        ).toBeVisible();
        expect(
            within(panel).getByRole('link', {
                name: 'Open resource editor',
            }),
        ).toHaveAttribute('href', '/company-hub/manage/3/edit');
        write();
        fireEvent.keyDown(panel, { key: 'Escape' });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Discard this response?',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(
            screen.getByRole('textbox', { name: 'Response to the reporter' }),
        ).toHaveValue('The link has been corrected.');
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard response' }),
        );
        expect(close).toHaveBeenCalledOnce();
        expect(request).not.toHaveBeenCalled();
    });

    it('sends the captured report version once and locks dismissal until confirmed', async () => {
        let finish!: (value: unknown) => void;
        request.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const saved = vi.fn(),
            close = vi.fn();
        render(
            <ResourceReview
                actorId={11}
                report={report}
                close={close}
                onSaved={saved}
            />,
        );
        write();
        fireEvent.click(
            screen.getByRole('button', { name: 'Respond & resolve' }),
        );
        fireEvent.submit(
            screen
                .getByRole('button', { name: 'Saving response…' })
                .closest('form')!,
        );
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(request).toHaveBeenCalledOnce();
        expect(request).toHaveBeenCalledWith(
            '/company-hub/manage/3/reports/12',
            'PUT',
            {
                resolved: true,
                response: 'The link has been corrected.',
                version: 2,
                actor_id: 11,
            },
        );
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(close).not.toHaveBeenCalled();
        await act(async () => finish(receipt));
        expect(saved).toHaveBeenCalledOnce();
    });

    it('preserves a stale response and requires reload instead of silently changing versions', async () => {
        request.mockRejectedValue(
            new HubRequestError('This report has changed.', 409),
        );
        render(
            <ResourceReview
                actorId={11}
                report={report}
                close={vi.fn()}
                onSaved={vi.fn()}
            />,
        );
        write();
        fireEvent.click(
            screen.getByRole('button', { name: 'Respond & resolve' }),
        );
        await screen.findByRole('alert');
        expect(
            screen.getByRole('textbox', { name: 'Response to the reporter' }),
        ).toHaveValue('The link has been corrected.');
        expect(
            screen.getByRole('button', { name: 'Respond & resolve' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Reload current report' }),
        ).toBeVisible();
    });

    it('does not claim success from an unconfirmed response and can reopen a resolved report', async () => {
        request.mockResolvedValue({ saved: false });
        const saved = vi.fn();
        render(
            <ResourceReview
                actorId={11}
                report={{
                    ...report,
                    resolved: true,
                    response: 'Previously fixed.',
                }}
                close={vi.fn()}
                onSaved={saved}
            />,
        );
        write('The new form link is also unavailable.');
        fireEvent.click(screen.getByRole('button', { name: 'Reopen report' }));
        await screen.findByRole('alert');
        expect(request).toHaveBeenCalledWith(
            '/company-hub/manage/3/reports/12',
            'PUT',
            {
                resolved: false,
                response: 'The new form link is also unavailable.',
                version: 2,
                actor_id: 11,
            },
        );
        expect(saved).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Reopen report' }),
        ).toBeDisabled();
    });

    it('drops a withdrawn report and ignores the old completion without refreshing another page', async () => {
        let finish!: (value: unknown) => void;
        request.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(
            <ResourceCare
                actorId={11}
                pages={[{ ...page, reports: [report] }]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review report' }));
        write();
        fireEvent.click(
            screen.getByRole('button', { name: 'Respond & resolve' }),
        );
        view.rerender(<ResourceCare actorId={11} pages={[]} />);
        await act(async () => finish(receipt));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
        view.rerender(
            <ResourceCare
                actorId={11}
                pages={[{ ...page, reports: [report] }]}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('returns the confirmed result to Resource care and refreshes the canonical list', async () => {
        request.mockResolvedValue(receipt);
        render(
            <ResourceCare
                actorId={11}
                pages={[{ ...page, reports: [report] }]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review report' }));
        write();
        fireEvent.click(
            screen.getByRole('button', { name: 'Respond & resolve' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Your response has been saved and the correction resolved.',
        );
        expect(reload).toHaveBeenCalledWith({
            only: ['editorial'],
            preserveScroll: true,
        });
    });
});

// Workflow tests isolate the picker; date-fields.test.tsx exercises the actual shared calendar and clock.
vi.mock('./_date-fields', () => ({
    HubDateField: ({
        id,
        label,
        value,
        onChange,
        disabled,
    }: {
        id?: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
        disabled?: boolean;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
    HubDateTimeField: ({
        id,
        label,
        value,
        onChange,
        disabled,
    }: {
        id?: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
        disabled?: boolean;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
