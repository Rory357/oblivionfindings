import {
    act,
    fireEvent,
    render,
    renderHook,
    screen,
} from '@testing-library/react';
import axios from 'axios';
import { describe, expect, it, vi } from 'vitest';
import {
    BoundedTable,
    multipart,
    NzDateTime,
    ReviewWizard,
    useCommand,
} from './_shared';
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: any) => <a {...props}>{children}</a>,
    usePage: () => ({ props: { auth: { user: {} } } }),
    router: {},
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: any) => <>{children}</>,
}));
vi.mock('axios', () => ({
    default: {
        request: vi.fn(),
        isAxiosError: (e: unknown) =>
            !!(e as { isAxiosError?: boolean })?.isAxiosError,
    },
}));
describe('connected action certainty', () => {
    it('allows only one in-flight mutation and preserves an uncertain outcome', async () => {
        let reject!: (e: unknown) => void;
        vi.mocked(axios.request).mockImplementationOnce(
            () =>
                new Promise((_, no) => {
                    reject = no;
                }),
        );
        const { result } = renderHook(() => useCommand());
        let first!: Promise<unknown>;
        act(() => {
            first = result.current.run('/local', { request_key: 'same' });
        });
        await act(async () => {
            expect(
                await result.current.run('/local', { request_key: 'other' }),
            ).toBeNull();
        });
        await act(async () => {
            reject(new Error('connection lost'));
            await first;
        });
        expect(result.current.uncertain).toBe(true);
        expect(result.current.error).toContain('could not be confirmed');
        await act(async () => {
            expect(await result.current.run('/local', {})).toBeNull();
        });
        expect(axios.request).toHaveBeenCalledTimes(1);
    });
    it('allows correction after a known validation rejection', async () => {
        vi.mocked(axios.request)
            .mockRejectedValueOnce({
                isAxiosError: true,
                response: {
                    status: 422,
                    data: { errors: { reference: ['Reference is required.'] } },
                },
            })
            .mockResolvedValueOnce({ data: { success: true } });
        const { result } = renderHook(() => useCommand());
        await act(async () => {
            await result.current.run('/local', {});
        });
        expect(result.current.error).toContain('Reference is required.');
        expect(result.current.uncertain).toBe(false);
        await act(async () => {
            expect(
                await result.current.run('/local', { reference: 'Checked' }),
            ).toEqual({ success: true });
        });
    });
    it('serialises true/false and nested source files without losing fields', () => {
        const file = new File(['written'], 'source.pdf', {
            type: 'application/pdf',
        });
        const data = multipart({
            source: { type: 'written', read_back_confirmed: false },
            source_confirmed: true,
            source_file: file,
            prescription: { dose_times: ['09:00', '17:00'], end_date: null },
        });
        expect(data.get('source[read_back_confirmed]')).toBe('0');
        expect(data.get('source_confirmed')).toBe('1');
        expect(data.get('prescription[dose_times][1]')).toBe('17:00');
        expect(data.get('source_file')).toBe(file);
        expect(data.get('prescription[end_date]')).toBe('');
    });
});
describe('bounded desktop records and NZ time', () => {
    it('shows twenty-five records per page and reaches the last record', () => {
        render(
            <BoundedTable
                rows={Array.from({ length: 53 }, (_, i) => ({
                    id: i,
                    name: 'Record ' + i,
                }))}
                identity={(r) => ({ name: r.name })}
                columns={[]}
            />,
        );
        expect(screen.getByText('Record 0')).toBeInTheDocument();
        expect(screen.queryByText('Record 25')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByText('Record 25')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByText('Record 52')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    });
    it('makes repeated Auckland wall time require an explicit occurrence', () => {
        render(
            <NzDateTime
                id="expiry"
                label="Access ends"
                value="2027-04-04T02:30"
                onChange={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('combobox', {
                name: 'Which occurrence of this repeated time?',
            }),
        ).toBeInTheDocument();
    });
    it('identifies the Auckland skipped spring time', () => {
        render(
            <NzDateTime
                id="expiry"
                label="Access ends"
                value="2026-09-27T02:30"
                onChange={vi.fn()}
            />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'daylight-saving gap',
        );
    });
});

it('prevents skipping required wizard steps and displays rounded progress', () => {
    render(
        <ReviewWizard
            title="Test review"
            description="Details"
            steps={[
                {
                    label: 'Identity',
                    valid: false,
                    content: <p>Complete identity</p>,
                },
                { label: 'Limits', valid: true, content: <p>Choose limits</p> },
            ]}
            review={[]}
            onClose={vi.fn()}
            onSave={vi.fn()}
            busy={false}
            error=""
            saved={false}
        />,
    );
    expect(screen.getByRole('button', { name: /^Limits/ })).toBeDisabled();
    expect(screen.getByRole('button', { name: /^Review/ })).toBeDisabled();
    expect(screen.getByRole('progressbar', { name: 'Steps' })).toHaveAttribute(
        'aria-valuenow',
        '33',
    );
});
