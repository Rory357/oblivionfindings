import { Button } from '@/components/ui/button';
import { ExportDialog } from '@/pages/emar/reports/_export-dialog';
import type { ExportContext } from '@/pages/emar/reports/_types';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RoundSheetExportDialog } from './round-sheet-export-dialog';

vi.mock('@/components/people-locations/record-picker', () => ({
    RecordPicker: ({
        label,
        value,
        options,
        onChange,
    }: {
        label: string;
        value: string;
        options: { value: string; label: string }[];
        onChange: (value: string) => void;
    }) => (
        <select
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">Choose</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    ),
}));
vi.mock('@/components/hr/leave-calendar-range', () => ({
    LeaveCalendarRange: () => <div aria-label="Date selection" />,
}));

const option = {
    type: 'round_sheet',
    label: 'round sheet',
    format: 'PDF',
    description: 'A printable medication round.',
    allowed: true,
};
const context: ExportContext = {
    filters: {
        view: 'exports',
        report: '',
        sub: '',
        period: 'custom',
        date_from: '2026-10-04',
        date_to: '2026-10-04',
        site_id: 3,
        client_id: null,
        kind: '',
        q: '',
    },
    sites: [{ id: 3, name: 'Synthetic House' }],
    people: [],
    finance: false,
    purposes: { shift_handover: 'Shift handover', other: 'Other' },
    exports: [option],
    selected_round: {
        id: 27,
        name: 'Evening medicines',
        site_id: 3,
        date: '2026-10-04',
    },
};
const fetchMock = vi.fn();

beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockReset();
    vi.stubGlobal(
        'URL',
        class extends URL {
            static createObjectURL = vi.fn(() => 'blob:round-sheet');
            static revokeObjectURL = vi.fn();
        },
    );
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
        () => undefined,
    );
});
afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('audited round-sheet export', () => {
    it('keeps focus in the loaded wizard and returns to the original print button', async () => {
        let finish!: (response: Response) => void;
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        );
        function Journey() {
            const [open, setOpen] = useState(false);
            return (
                <main>
                    <Button type="button" onClick={() => setOpen(true)}>
                        Print round
                    </Button>
                    {open && (
                        <RoundSheetExportDialog
                            roundId={27}
                            onClose={() => setOpen(false)}
                        />
                    )}
                </main>
            );
        }
        render(<Journey />);
        const opener = screen.getByRole('button', { name: 'Print round' });
        opener.focus();
        fireEvent.click(opener);
        screen.getByRole('button', { name: 'Cancel' }).focus();
        finish(new Response(JSON.stringify(context), { status: 200 }));
        await screen.findByText('Evening medicines');
        await waitFor(() =>
            expect(screen.getByRole('dialog')).toContainElement(
                document.activeElement as HTMLElement,
            ),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(opener).toHaveFocus());
    });
    it('loads the exact round, locks its scope and requires purpose before downloading', async () => {
        fetchMock.mockResolvedValueOnce(
            new Response(JSON.stringify(context), { status: 200 }),
        );
        render(<RoundSheetExportDialog roundId={27} onClose={vi.fn()} />);
        expect(
            await screen.findByText('Evening medicines'),
        ).toBeInTheDocument();
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/emar/reports/export-options?type=round_sheet&round_id=27',
        );
        expect(
            screen.queryByRole('combobox', { name: 'House' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Date selection'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        fireEvent.change(screen.getByRole('combobox', { name: 'Purpose' }), {
            target: { value: 'other' },
        });
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Describe the purpose'), {
            target: { value: 'Shift handover copy' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText('Evening medicines')).toBeInTheDocument();
        fetchMock.mockResolvedValueOnce(
            new Response('synthetic-pdf', {
                status: 200,
                headers: {
                    'Content-Disposition':
                        'attachment; filename="round-27.pdf"',
                },
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Make file' }));
        expect(await screen.findByText('File ready')).toBeInTheDocument();
        expect(fetchMock.mock.calls[1][0]).toBe('/emar/reports/export');
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toMatchObject({
            round_id: 27,
            site_id: 3,
            date_from: '2026-10-04',
            date_to: '2026-10-04',
            purpose: 'other',
            purpose_detail: 'Shift handover copy',
        });
        expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledOnce();
    });

    it('does not substitute all rounds when the selected round is unavailable', async () => {
        fetchMock.mockResolvedValueOnce(
            new Response(JSON.stringify({ ...context, selected_round: null }), {
                status: 200,
            }),
        );
        render(<RoundSheetExportDialog roundId={27} onClose={vi.fn()} />);
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'not available',
        );
        expect(
            screen.queryByRole('button', { name: 'Continue' }),
        ).not.toBeInTheDocument();
        fetchMock.mockResolvedValueOnce(
            new Response(JSON.stringify(context), { status: 200 }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(
            await screen.findByText('Evening medicines'),
        ).toBeInTheDocument();
    });

    it('cancels an outstanding context request when closed', async () => {
        fetchMock.mockImplementationOnce(() => new Promise(() => undefined));
        const page = render(
            <RoundSheetExportDialog roundId={27} onClose={vi.fn()} />,
        );
        await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
        const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
        expect(signal.aborted).toBe(false);
        page.unmount();
        expect(signal.aborted).toBe(true);
    });

    it('still requires one house for a generic report without a selected round', () => {
        render(
            <ExportDialog
                option={option}
                props={{
                    ...context,
                    selected_round: null,
                    filters: { ...context.filters, site_id: null },
                }}
                online
                onClose={vi.fn()}
            />,
        );
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        expect(screen.getByLabelText('Date selection')).toBeInTheDocument();
        fireEvent.change(screen.getByRole('combobox', { name: 'House' }), {
            target: { value: '3' },
        });
        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
    });
});
