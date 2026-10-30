import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    EditAvailabilityDialog,
    availabilityMinutes,
} from './edit-availability-dialog';
vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), delete: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/components/fleet-assets/maintenance/time-picker', () => ({
    TimePicker: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (v: string) => void;
    }) => (
        <label>
            {label}
            <input
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
    ),
}));
const props = {
    open: true,
    onOpenChange: vi.fn(),
    staff: { id: 7, name: 'Preview worker', email: 'worker@demo.test' },
    blocks: [],
};
beforeEach(() => vi.resetAllMocks());
describe('availability review and recovery', () => {
    it('shows next-day carryover as the same saved block', () => {
        render(
            <EditAvailabilityDialog
                {...props}
                blocks={[
                    {
                        id: 1,
                        day_of_week: 1,
                        start_time: '22:15',
                        end_time: '07:00',
                        ends_next_day: true,
                    },
                ]}
            />,
        );
        expect(
            screen.getByText('Continues from Monday until 07:00'),
        ).toBeVisible();
        expect(
            screen.getAllByRole('button', { name: /Remove Monday/ }),
        ).toHaveLength(1);
    });

    it('calculates overnight and rejects malformed or more-than-day intervals', () => {
        expect(availabilityMinutes('22:00', '07:00', true)).toBe(540);
        expect(availabilityMinutes('09:00', '09:00', true)).toBe(1440);
        expect(availabilityMinutes('09:00', '17:00', true)).toBeGreaterThan(
            1440,
        );
        expect(availabilityMinutes('99:00', '17:00', false)).toBeNaN();
    });
    it('sends the explicit overnight flag after review and retains validation errors', () => {
        render(<EditAvailabilityDialog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add block' }));
        fireEvent.change(screen.getByLabelText('Start time'), {
            target: { value: '22:00' },
        });
        fireEvent.change(screen.getByLabelText('End time'), {
            target: { value: '07:00' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', { name: /Ends next day/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review block' }));
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save availability' }),
        );
        expect(router.post).toHaveBeenCalledWith(
            '/staff/7/availability',
            {
                day_of_week: 1,
                starts_at: '22:00',
                ends_at: '07:00',
                ends_next_day: true,
            },
            expect.any(Object),
        );
        const options = vi.mocked(router.post).mock.calls[0][2]!;
        act(() => {
            options.onError?.({
                ends_at: 'This block overlaps an existing block.',
            });
            options.onFinish?.({} as never);
        });
        expect(screen.getByRole('alert')).toHaveTextContent('overlaps');
        expect(screen.getByText('Monday 22:00')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save availability' }),
        ).toBeEnabled();
    });
    it('guards an edited draft when closing', () => {
        render(<EditAvailabilityDialog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add block' }));
        fireEvent.change(screen.getByLabelText('Start time'), {
            target: { value: '22:00' },
        });
        fireEvent.click(screen.getByText('Close', { selector: 'button' }));
        expect(
            screen.getByText('Discard this availability draft?'),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(props.onOpenChange).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Start time')).toHaveValue('22:00');
    });
});

const savedReceipt = {
    action: 'create',
    staff_id: 7,
    availability_id: 51,
    day_of_week: 1,
    starts_at: '09:00',
    ends_at: '17:00',
    ends_next_day: false,
};
const pageWithReceipt = (receipt: unknown) =>
    ({ props: { flash: { staff_availability_result: receipt } } }) as never;
const savedBlock = {
    id: 51,
    day_of_week: 1,
    start_time: '09:00',
    end_time: '17:00',
    ends_next_day: false,
};
const snapshot = (
    blocks: (typeof savedBlock)[],
    canManage = true,
    staffId = 7,
) =>
    ({
        props: {
            staffAvailabilitySummary: {
                staff: [
                    {
                        id: staffId,
                        can_manage: canManage,
                        staff_availability: blocks,
                    },
                ],
            },
        },
    }) as never;
function submitDefault() {
    fireEvent.click(screen.getByRole('button', { name: 'Add block' }));
    fireEvent.click(screen.getByRole('button', { name: 'Review block' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save availability' }));
    return vi.mocked(router.post).mock.calls.at(-1)![2]!;
}
function completeRead(page: never) {
    const read = vi.mocked(router.reload).mock.calls.at(-1)![0]!;
    act(() => {
        read.onSuccess?.(page);
        read.onFinish?.({} as never);
    });
}
describe('availability command outcomes', () => {
    it('shows success only for the committed matching block and refreshes the parent pattern', () => {
        const onSaved = vi.fn();
        render(<EditAvailabilityDialog {...props} onSaved={onSaved} />);
        const write = submitDefault();
        act(() => {
            write.onSuccess?.(pageWithReceipt(savedReceipt));
            write.onFinish?.({} as never);
        });
        expect(screen.getByText('Availability saved')).toBeVisible();
        expect(router.reload).toHaveBeenCalledTimes(1);
        completeRead(snapshot([savedBlock]));
        expect(onSaved).toHaveBeenCalledOnce();
    });
    it.each([
        ['missing', null],
        ['different staff', { ...savedReceipt, staff_id: 8 }],
        ['different interval', { ...savedReceipt, ends_at: '18:00' }],
        ['different action', { ...savedReceipt, action: 'delete' }],
        ['malformed identity', { ...savedReceipt, availability_id: '51' }],
        [
            'missing overnight flag',
            { ...savedReceipt, ends_next_day: undefined },
        ],
    ])(
        'holds a %s receipt without claiming success or resending',
        (_, result) => {
            render(<EditAvailabilityDialog {...props} />);
            const write = submitDefault();
            act(() => {
                write.onSuccess?.(pageWithReceipt(result));
                write.onFinish?.({} as never);
            });
            expect(
                screen.queryByText('Availability saved'),
            ).not.toBeInTheDocument();
            expect(screen.getByRole('alert')).toHaveTextContent(
                'could not confirm',
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Save availability' }),
            );
            expect(router.post).toHaveBeenCalledOnce();
            expect(screen.getByText('Monday 09:00')).toBeVisible();
        },
    );
    it('blocks a same-tick second write', () => {
        render(<EditAvailabilityDialog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add block' }));
        fireEvent.click(screen.getByRole('button', { name: 'Review block' }));
        const save = screen.getByRole('button', { name: 'Save availability' });
        act(() => {
            fireEvent.click(save);
            fireEvent.click(save);
        });
        expect(router.post).toHaveBeenCalledOnce();
    });
    it.each(['cancel', 'finish', 'empty-error', 'throw'])(
        'retains uncertainty after %s and guards closing',
        (event) => {
            if (event === 'throw')
                vi.mocked(router.post).mockImplementationOnce(() => {
                    throw new Error('disconnected');
                });
            render(<EditAvailabilityDialog {...props} />);
            const write = submitDefault();
            if (event !== 'throw')
                act(() => {
                    if (event === 'cancel') write.onCancel?.();
                    if (event === 'empty-error') write.onError?.({});
                    write.onFinish?.({} as never);
                });
            expect(
                screen.getByRole('button', { name: 'Save availability' }),
            ).toBeDisabled();
            fireEvent.click(screen.getByText('Close', { selector: 'button' }));
            expect(
                screen.getByText('Close with an unconfirmed result?'),
            ).toBeVisible();
            expect(
                screen.getByText(/The change may have been saved/),
            ).toBeVisible();
        },
    );
    it('checks current records before permitting another attempt and retains entries', () => {
        render(<EditAvailabilityDialog {...props} />);
        const write = submitDefault();
        act(() => {
            write.onFinish?.({} as never);
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check weekly pattern' }),
        );
        completeRead(snapshot([]));
        expect(screen.getByRole('status')).toHaveTextContent(
            'No matching block',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add block' }));
        expect(screen.getByLabelText('Start time')).toHaveValue('09:00');
        expect(router.post).toHaveBeenCalledOnce();
    });
    it('shows a found block without attributing a successful write to an uncertain request', () => {
        render(<EditAvailabilityDialog {...props} />);
        const write = submitDefault();
        act(() => {
            write.onFinish?.({} as never);
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check weekly pattern' }),
        );
        completeRead(snapshot([savedBlock]));
        expect(screen.getByRole('status')).toHaveTextContent(
            'A matching block',
        );
        expect(
            screen.queryByText('Availability saved'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Remove Monday/ }),
        ).toBeVisible();
    });
    it('keeps writes held if the refreshed pattern belongs to a different person', () => {
        render(<EditAvailabilityDialog {...props} />);
        const write = submitDefault();
        act(() => {
            write.onFinish?.({} as never);
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check weekly pattern' }),
        );
        completeRead(snapshot([], true, 8));
        expect(
            screen.getByRole('button', { name: 'Save availability' }),
        ).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'could not be checked',
        );
    });
    it('honours edit permission lost during recovery', () => {
        render(<EditAvailabilityDialog {...props} />);
        const write = submitDefault();
        act(() => {
            write.onFinish?.({} as never);
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check weekly pattern' }),
        );
        completeRead(snapshot([], false));
        expect(
            screen.getByRole('button', { name: 'Add block' }),
        ).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'can no longer change',
        );
    });
    it('ignores callbacks after closing and opening for another person', () => {
        const view = render(<EditAvailabilityDialog {...props} />);
        const write = submitDefault();
        view.rerender(<EditAvailabilityDialog {...props} open={false} />);
        view.rerender(
            <EditAvailabilityDialog
                {...props}
                staff={{ ...props.staff, id: 8, name: 'Another worker' }}
            />,
        );
        act(() => {
            write.onSuccess?.(pageWithReceipt(savedReceipt));
            write.onFinish?.({} as never);
        });
        expect(
            screen.queryByText('Availability saved'),
        ).not.toBeInTheDocument();
        expect(router.reload).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Add block' })).toBeEnabled();
    });
    it('removes only the exact committed block and retains other blocks', () => {
        render(
            <EditAvailabilityDialog
                {...props}
                blocks={[savedBlock, { ...savedBlock, id: 52, day_of_week: 2 }]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Remove Monday/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Remove block' }));
        const write = vi.mocked(router.delete).mock.calls[0][1]!;
        act(() => {
            write.onSuccess?.(
                pageWithReceipt({ ...savedReceipt, action: 'delete' }),
            );
            write.onFinish?.({} as never);
        });
        completeRead(snapshot([{ ...savedBlock, id: 52, day_of_week: 2 }]));
        expect(
            screen.queryByRole('button', { name: /Remove Monday/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Remove Tuesday/ }),
        ).toBeVisible();
        expect(screen.getByRole('status')).toHaveTextContent('block removed');
    });
    it('holds a delete acknowledgement for the wrong block', () => {
        render(<EditAvailabilityDialog {...props} blocks={[savedBlock]} />);
        fireEvent.click(screen.getByRole('button', { name: /Remove Monday/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Remove block' }));
        const write = vi.mocked(router.delete).mock.calls[0][1]!;
        act(() => {
            write.onSuccess?.(
                pageWithReceipt({
                    ...savedReceipt,
                    action: 'delete',
                    availability_id: 52,
                }),
            );
            write.onFinish?.({} as never);
        });
        expect(
            screen.getByRole('button', { name: /Remove Monday/ }),
        ).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'could not confirm',
        );
    });
});

it('prevents adding an exact existing block while allowing a different overlapping interval', () => {
    render(<EditAvailabilityDialog {...props} blocks={[savedBlock]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Add block' }));
    fireEvent.click(screen.getByRole('button', { name: 'Review block' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save availability' }));
    expect(router.post).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toHaveTextContent(
        'exact block is already',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add block' }));
    fireEvent.change(screen.getByLabelText('End time'), {
        target: { value: '18:00' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Review block' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save availability' }));
    expect(router.post).toHaveBeenCalledOnce();
});

it('shows an externally refreshed pattern while preserving an edited draft', () => {
    const view = render(<EditAvailabilityDialog {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Add block' }));
    fireEvent.change(screen.getByLabelText('Start time'), {
        target: { value: '10:30' },
    });
    view.rerender(<EditAvailabilityDialog {...props} blocks={[savedBlock]} />);
    expect(screen.getByLabelText('Start time')).toHaveValue('10:30');
    fireEvent.click(
        screen.getByRole('button', {
            name: /Weekly pattern/,
        }),
    );
    expect(screen.getByRole('button', { name: /Remove Monday/ })).toBeVisible();
});
it('preserves the draft and immediately disables writes when row permission changes', () => {
    const view = render(<EditAvailabilityDialog {...props} canManage />);
    fireEvent.click(screen.getByRole('button', { name: 'Add block' }));
    fireEvent.change(screen.getByLabelText('Start time'), {
        target: { value: '10:30' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Review block' }));
    view.rerender(<EditAvailabilityDialog {...props} canManage={false} />);
    expect(
        screen.getByRole('button', { name: 'Save availability' }),
    ).toBeDisabled();
    expect(screen.getByText('Monday 10:30')).toBeVisible();
    expect(screen.getByRole('alert')).toHaveTextContent('can no longer change');
    expect(router.post).not.toHaveBeenCalled();
});
