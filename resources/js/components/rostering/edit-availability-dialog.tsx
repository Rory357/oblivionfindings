import { ConfirmDialog } from '@/components/confirm-dialog';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { WORKER_TIMEZONE } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { CalendarCheck, Check, Clock, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    availabilityResult,
    availabilitySnapshot,
    matchesAvailability,
    type AvailabilityCommand,
} from './availability-outcome';

export type EditAvailabilityBlock = {
    id: number;
    day_of_week: number;
    start_time: string;
    end_time: string;
    ends_next_day?: boolean;
};
export type EditAvailabilityDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    staff: {
        id: number;
        name: string;
        email: string;
        role?: string | null;
    } | null;
    blocks: EditAvailabilityBlock[];
    canManage?: boolean;
    onSaved?: () => void;
    workerTimezone?: string;
    reloadKeys?: string[];
};
const DAYS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];
const STEPS = [
    {
        key: 'pattern',
        label: 'Weekly pattern',
        blurb: 'Review existing availability',
        icon: CalendarCheck,
    },
    {
        key: 'times',
        label: 'Add a block',
        blurb: 'Choose the day and exact times',
        icon: Clock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: Check,
    },
];
export function continuingAvailability(
    blocks: EditAvailabilityBlock[],
    day: number,
): EditAvailabilityBlock[] {
    return blocks.filter(
        (block) =>
            block.ends_next_day &&
            block.end_time.slice(0, 5) > '00:00' &&
            (block.day_of_week + 1) % 7 === day,
    );
}
export function availabilityMinutes(
    start: string,
    end: string,
    overnight: boolean,
): number {
    if (![start, end].every((value) => /^([01]\d|2[0-3]):[0-5]\d$/.test(value)))
        return NaN;
    const minute = (value: string) =>
        Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5));
    return minute(end) - minute(start) + (overnight ? 1440 : 0);
}
export function EditAvailabilityDialog(props: EditAvailabilityDialogProps) {
    return props.open && props.staff ? (
        <AvailabilityEditor
            key={props.staff.id}
            {...props}
            staff={props.staff}
        />
    ) : null;
}

function AvailabilityEditor({
    open,
    onOpenChange,
    staff,
    blocks,
    onSaved,
    canManage = true,
    workerTimezone = WORKER_TIMEZONE,
    reloadKeys = ['staffAvailabilitySummary'],
}: EditAvailabilityDialogProps & {
    staff: NonNullable<EditAvailabilityDialogProps['staff']>;
}) {
    const [step, setStep] = useState(0);
    const [day, setDay] = useState('1');
    const [start, setStart] = useState('09:00');
    const [end, setEnd] = useState('17:00');
    const [overnight, setOvernight] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [remove, setRemove] = useState<EditAvailabilityBlock | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);
    const [visibleBlocks, setVisibleBlocks] = useState(blocks);
    const [uncertain, setUncertain] = useState<AvailabilityCommand | null>(
        null,
    );
    const [notice, setNotice] = useState<string | null>(null);
    const [refreshNeeded, setRefreshNeeded] = useState(false);
    const [editable, setEditable] = useState(true);
    const pending = useRef(false);
    const active = useRef(true);
    const operation = useRef(0);
    const observedBlocks = useRef(blocks);
    useEffect(() => {
        active.current = true;
        return () => {
            active.current = false;
            operation.current += 1;
        };
    }, []);
    useEffect(() => {
        // Accept external pattern refreshes without discarding an in-progress draft
        // or replacing the records held while a command is still uncertain.
        if (
            observedBlocks.current !== blocks &&
            !pending.current &&
            !uncertain &&
            !refreshNeeded
        ) {
            observedBlocks.current = blocks;
            setVisibleBlocks(blocks);
        }
    }, [blocks, processing, uncertain, refreshNeeded]);
    const duration = availabilityMinutes(start, end, overnight);
    const valid = Number.isFinite(duration) && duration > 0 && duration <= 1440;
    const locked =
        processing || !!uncertain || refreshNeeded || !editable || !canManage;
    const close = () => {
        if (pending.current) return;
        if (dirty || uncertain) setDiscard(true);
        else onOpenChange(false);
    };
    const changeStep = (next: number) => {
        if (
            pending.current ||
            uncertain ||
            refreshNeeded ||
            !editable ||
            !canManage
        )
            return;
        if (next === 2 && !valid) {
            setError(
                'Choose a positive interval of at most 24 hours. For overnight availability, select Ends next day.',
            );
            setStep(1);
            return;
        }
        setError(null);
        setStep(next);
    };
    const readPattern = () => {
        if (pending.current) return;
        pending.current = true;
        setProcessing(true);
        setError(null);
        const token = ++operation.current;
        const current = () => active.current && operation.current === token;
        let settled = false;
        const failed = () => {
            if (!current() || settled) return;
            settled = true;
            setRefreshNeeded(true);
            setError(
                'The weekly pattern could not be checked. Your entries are still here. Check again before making another change.',
            );
        };
        const finish = () => {
            if (!current()) return;
            if (!settled) failed();
            pending.current = false;
            setProcessing(false);
        };
        try {
            router.reload({
                only: [...new Set([...reloadKeys, 'user', 'canManage'])],
                preserveScroll: true,
                onSuccess: (page) => {
                    if (!current() || settled) return;
                    const snapshot = availabilitySnapshot(page, staff.id);
                    if (!snapshot) {
                        failed();
                        return;
                    }
                    settled = true;
                    setVisibleBlocks(snapshot.blocks);
                    setEditable(snapshot.canManage);
                    setRefreshNeeded(false);
                    if (uncertain) {
                        const found =
                            uncertain.action === 'create'
                                ? snapshot.blocks.some((block) =>
                                      matchesAvailability(block, uncertain),
                                  )
                                : snapshot.blocks.some(
                                      (block) =>
                                          block.id ===
                                          uncertain.availability_id,
                                  );
                        setNotice(
                            uncertain.action === 'create'
                                ? found
                                    ? 'A matching block is in the weekly pattern. Review it before adding another.'
                                    : 'No matching block is in the weekly pattern. Review your entries before trying again.'
                                : found
                                  ? 'The block is still in the weekly pattern. Review it before trying again.'
                                  : 'The block is no longer in the weekly pattern.',
                        );
                        if (uncertain.action === 'create' && found)
                            setDirty(false);
                        setUncertain(null);
                        setRemove(null);
                        setStep(0);
                    }
                    onSaved?.();
                },
                onError: failed,
                onCancel: failed,
                onFinish: finish,
            });
        } catch {
            failed();
            finish();
        }
    };
    const command = (expected: AvailabilityCommand) => {
        if (pending.current || locked) return;
        pending.current = true;
        setProcessing(true);
        setError(null);
        setNotice(null);
        const token = ++operation.current;
        const current = () => active.current && operation.current === token;
        let settled = false;
        let committed = false;
        const unknown = () => {
            if (!current() || settled) return;
            settled = true;
            setUncertain(expected);
            setRemove(null);
            setError(
                'We could not confirm the result. Check the weekly pattern before trying again. Your entries are still here.',
            );
        };
        const finish = () => {
            if (!current()) return;
            if (!settled) unknown();
            pending.current = false;
            setProcessing(false);
            if (committed) readPattern();
        };
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                if (!current() || settled) return;
                const result = availabilityResult(page, expected);
                if (!result) {
                    unknown();
                    return;
                }
                settled = true;
                committed = true;
                setRemove(null);
                if (result.action === 'create') {
                    setVisibleBlocks((previous) => [
                        ...previous.filter(
                            (block) => block.id !== result.availability_id,
                        ),
                        {
                            id: result.availability_id,
                            day_of_week: result.day_of_week,
                            start_time: result.starts_at,
                            end_time: result.ends_at,
                            ends_next_day: result.ends_next_day,
                        },
                    ]);
                    setDirty(false);
                    setSaved(true);
                } else {
                    setVisibleBlocks((previous) =>
                        previous.filter(
                            (block) => block.id !== result.availability_id,
                        ),
                    );
                    setNotice(
                        'Availability block removed. Existing shifts stay assigned.',
                    );
                }
                // Require a fresh pattern before the next write; a redirect alone is not a read receipt.
                setRefreshNeeded(true);
            },
            onError: (errors: Record<string, string>) => {
                if (!current() || settled) return;
                const messages = Object.values(errors).filter(
                    (value) => typeof value === 'string' && value.trim(),
                );
                if (!messages.length) {
                    unknown();
                    return;
                }
                settled = true;
                setError(messages.join(' '));
            },
            onCancel: unknown,
            onFinish: finish,
        };
        try {
            if (expected.action === 'create') {
                router.post(
                    `/staff/${staff.id}/availability`,
                    {
                        day_of_week: expected.day_of_week,
                        starts_at: expected.starts_at,
                        ends_at: expected.ends_at,
                        ends_next_day: expected.ends_next_day,
                    },
                    options,
                );
            } else
                router.delete(
                    `/staff/${staff.id}/availability/${expected.availability_id}`,
                    options,
                );
        } catch {
            unknown();
            finish();
        }
    };
    const save = () => {
        if (!valid) return;
        if (
            visibleBlocks.some((block) =>
                matchesAvailability(block, {
                    action: 'create',
                    staff_id: staff.id,
                    day_of_week: Number(day),
                    starts_at: start,
                    ends_at: end,
                    ends_next_day: overnight,
                }),
            )
        ) {
            setError(
                'This exact block is already in the weekly pattern. Review it or change the times before adding another.',
            );
            setStep(0);
            return;
        }
        command({
            action: 'create',
            staff_id: staff.id,
            day_of_week: Number(day),
            starts_at: start,
            ends_at: end,
            ends_next_day: overnight,
        });
    };
    const removeBlock = () => {
        if (!remove) return;
        command({
            action: 'delete',
            staff_id: staff.id,
            availability_id: remove.id,
            day_of_week: remove.day_of_week,
            starts_at: remove.start_time,
            ends_at: remove.end_time,
            ends_next_day: Boolean(remove.ends_next_day),
        });
    };
    return (
        <>
            <WizardShell
                open={open}
                onClose={close}
                title={`Availability — ${staff.name}`}
                description="Review weekly availability and add exact working times."
                railIcon={CalendarCheck}
                railTitle="Availability"
                railSub={staff.name}
                steps={STEPS}
                stepIndex={step}
                onStepClick={changeStep}
                sequential={false}
                pct={step === 2 ? 100 : step === 1 && valid ? 75 : 0}
                footerStart={
                    <span className="text-caption">
                        Times in {workerTimezone}
                    </span>
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            onClick={close}
                            disabled={processing}
                        >
                            Close
                        </Button>
                        {step === 0 ? (
                            <Button
                                disabled={locked}
                                onClick={() => changeStep(1)}
                            >
                                <Plus className="size-4" />
                                Add block
                            </Button>
                        ) : step === 1 ? (
                            <Button
                                onClick={() => changeStep(2)}
                                disabled={locked}
                            >
                                Review block
                            </Button>
                        ) : (
                            <Button onClick={save} disabled={locked || !valid}>
                                {processing ? 'Saving…' : 'Save availability'}
                            </Button>
                        )}
                    </>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Availability saved"
                            blurb={
                                <>
                                    {`${DAYS[Number(day)]} ${start} to ${overnight ? DAYS[(Number(day) + 1) % 7] + ' ' : ''}${end}. Existing shifts have not been changed.`}
                                    {error && (
                                        <span
                                            role="alert"
                                            className="mt-2 block text-status-warning"
                                        >
                                            The block was saved, but the weekly
                                            pattern could not be refreshed. Use
                                            View weekly pattern to check it.
                                        </span>
                                    )}
                                </>
                            }
                            actions={
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setSaved(false);
                                            setStep(0);
                                            if (refreshNeeded) readPattern();
                                        }}
                                    >
                                        View weekly pattern
                                    </Button>
                                    <Button
                                        disabled={processing}
                                        onClick={close}
                                    >
                                        Done
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-4 p-5">
                    {error && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {error}
                        </p>
                    )}
                    {notice && (
                        <p role="status" className="text-subtle">
                            {notice}
                        </p>
                    )}
                    {(!editable || !canManage) && (
                        <p role="alert" className="text-subtle">
                            You can no longer change this person's availability.
                        </p>
                    )}
                    {(uncertain || refreshNeeded || !editable) && (
                        <Button
                            variant="outline"
                            disabled={processing}
                            onClick={readPattern}
                        >
                            {processing ? 'Checking…' : 'Check weekly pattern'}
                        </Button>
                    )}
                    {step === 0 ? (
                        <>
                            <h2 className="text-section-title">
                                Weekly availability
                            </h2>
                            <p className="text-subtle">
                                These are the times this person has said they
                                can work. Leave, other duties and eligibility
                                are checked separately.
                            </p>
                            <div className="divide-y rounded-lg border">
                                {[1, 2, 3, 4, 5, 6, 0].map((index) => (
                                    <div key={index} className="space-y-2 p-3">
                                        <h3 className="text-sm font-semibold">
                                            {DAYS[index]}
                                        </h3>
                                        {continuingAvailability(
                                            visibleBlocks,
                                            index,
                                        ).map((block) => (
                                            <p
                                                key={`continued-${block.id}`}
                                                className="text-caption"
                                            >
                                                Continues from{' '}
                                                {DAYS[block.day_of_week]} until{' '}
                                                {block.end_time}
                                            </p>
                                        ))}
                                        {visibleBlocks.filter(
                                            (block) =>
                                                block.day_of_week === index,
                                        ).length ? (
                                            visibleBlocks
                                                .filter(
                                                    (block) =>
                                                        block.day_of_week ===
                                                        index,
                                                )
                                                .map((block) => (
                                                    <div
                                                        key={block.id}
                                                        className="flex items-center justify-between gap-3"
                                                    >
                                                        <span className="text-sm">
                                                            {block.start_time}–
                                                            {block.end_time}
                                                            {block.ends_next_day
                                                                ? ` · ends ${DAYS[(index + 1) % 7]}`
                                                                : ''}
                                                        </span>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="frontline-tap"
                                                            disabled={locked}
                                                            onClick={() => {
                                                                setError(null);
                                                                setRemove(
                                                                    block,
                                                                );
                                                            }}
                                                            aria-label={`Remove ${DAYS[index]} ${block.start_time} to ${block.end_time}`}
                                                        >
                                                            <Trash2 className="size-4" />
                                                            Remove
                                                        </Button>
                                                    </div>
                                                ))
                                        ) : continuingAvailability(
                                              visibleBlocks,
                                              index,
                                          ).length ? null : (
                                            <p className="text-caption">
                                                No times supplied
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </>
                    ) : null}
                    {step === 1 ? (
                        <>
                            <h2 className="text-section-title">
                                When can they work?
                            </h2>
                            <div className="space-y-2">
                                <Label htmlFor="availability-day">
                                    Starting day
                                </Label>
                                <Select
                                    disabled={locked}
                                    value={day}
                                    onValueChange={(value) => {
                                        setDay(value);
                                        setDirty(true);
                                    }}
                                >
                                    <SelectTrigger id="availability-day">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[1, 2, 3, 4, 5, 6, 0].map((index) => (
                                            <SelectItem
                                                key={index}
                                                value={String(index)}
                                            >
                                                {DAYS[index]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="availability-start">
                                        Start time
                                    </Label>
                                    <TimePicker
                                        timezone={workerTimezone}
                                        id="availability-start"
                                        label="Start time"
                                        value={start}
                                        onChange={(value) => {
                                            if (pending.current || locked)
                                                return;
                                            setStart(value);
                                            setDirty(true);
                                        }}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="availability-end">
                                        End time
                                    </Label>
                                    <TimePicker
                                        timezone={workerTimezone}
                                        id="availability-end"
                                        label="End time"
                                        value={end}
                                        onChange={(value) => {
                                            if (pending.current || locked)
                                                return;
                                            setEnd(value);
                                            setDirty(true);
                                        }}
                                    />
                                </div>
                            </div>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="availability-next-day"
                                    disabled={locked}
                                    checked={overnight}
                                    onCheckedChange={(value) => {
                                        setOvernight(value === true);
                                        setDirty(true);
                                    }}
                                />
                                <Label htmlFor="availability-next-day">
                                    Ends next day ({DAYS[(Number(day) + 1) % 7]}
                                    )
                                </Label>
                            </div>
                            <p className="text-subtle">
                                Use Ends next day for an overnight block, such
                                as Monday 22:00 to Tuesday 07:00. Blocks can
                                cover up to 24 hours.
                            </p>
                        </>
                    ) : null}
                    {step === 2 ? (
                        <ReviewCard
                            icon={Clock}
                            title="Availability to add"
                            onEdit={() => changeStep(1)}
                        >
                            <ReviewRow
                                label="Staff member"
                                value={staff.name}
                            />
                            <ReviewRow
                                label="Starts"
                                value={`${DAYS[Number(day)]} ${start}`}
                            />
                            <ReviewRow
                                label="Ends"
                                value={`${DAYS[(Number(day) + (overnight ? 1 : 0)) % 7]} ${end}`}
                            />
                            <ReviewRow
                                label="Time zone"
                                value={workerTimezone}
                            />
                            <ReviewRow label="Repeats" value="Every week" />
                        </ReviewCard>
                    ) : null}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    setDirty(false);
                    onOpenChange(false);
                }}
                title={
                    uncertain
                        ? 'Close with an unconfirmed result?'
                        : 'Discard this availability draft?'
                }
                description={
                    uncertain
                        ? 'The change may have been saved. Closing loses these entries. Check the weekly pattern before making the same change again.'
                        : 'The new block has not been saved. Existing availability will stay as it is.'
                }
                confirmText={uncertain ? 'Close anyway' : 'Discard draft'}
                cancelText="Keep editing"
            />
            <ConfirmDialog
                open={!!remove}
                onClose={() => {
                    if (!pending.current) {
                        setRemove(null);
                        setError(null);
                    }
                }}
                onConfirm={removeBlock}
                processing={processing}
                title="Remove availability block?"
                description={
                    <>
                        <p>
                            This removes{' '}
                            {remove
                                ? `${DAYS[remove.day_of_week]} ${remove.start_time}–${remove.end_time}${remove.ends_next_day ? ' (ends next day)' : ''}`
                                : ''}{' '}
                            from the weekly pattern. Existing shifts stay
                            assigned.
                        </p>
                        {error && (
                            <p
                                role="alert"
                                className="mt-2 text-status-critical"
                            >
                                {error}
                            </p>
                        )}
                    </>
                }
                confirmText="Remove block"
            />
        </>
    );
}
export default EditAvailabilityDialog;
