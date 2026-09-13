import { useForm } from '@inertiajs/react';
import {
    CalendarRange,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Crown,
    Eye,
    Loader2,
    NotebookPen,
    UserCheck,
    UserRound,
    Users,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';

export const BOARD_ROLES = [
    {
        key: 'chair',
        label: 'Chair',
        description: 'Leads the board and its meetings',
        icon: Crown,
    },
    {
        key: 'secretary',
        label: 'Secretary',
        description: 'Agendas, minutes and records',
        icon: NotebookPen,
    },
    {
        key: 'treasurer',
        label: 'Treasurer',
        description: 'Financial oversight lead',
        icon: Wallet,
    },
    {
        key: 'member',
        label: 'Member',
        description: 'Voting board member',
        icon: UserRound,
    },
    {
        key: 'observer',
        label: 'Observer',
        description: 'Attends without a vote',
        icon: Eye,
    },
] as const;

export function boardRoleLabel(role: string | null | undefined): string {
    return BOARD_ROLES.find((r) => r.key === role)?.label ?? (role ?? '—');
}

export interface BoardMemberUser {
    id: number;
    name: string;
    email: string;
}

export interface BoardMemberRecord {
    id: number;
    user: BoardMemberUser | null;
    board_role: string;
    term_start: string | null;
    term_end: string | null;
    is_active: boolean;
}

/** Eloquent date casts arrive as ISO instants; inputs want YYYY-MM-DD. */
export function dateOnly(value: string | null | undefined): string {
    return value ? value.slice(0, 10) : '';
}

type StepKey = 'member' | 'term' | 'review';

const STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'member',
        label: 'Member & role',
        blurb: 'Who is appointed and their seat',
        icon: UserCheck,
    },
    {
        key: 'term',
        label: 'Term',
        blurb: 'Start, end & standing',
        icon: CalendarRange,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the appointment',
        icon: ClipboardCheck,
    },
];

const FIELD_STEP: Record<string, StepKey> = {
    user_id: 'member',
    board_role: 'member',
    term_start: 'term',
    term_end: 'term',
    is_active: 'term',
};

interface MemberForm {
    user_id: string;
    board_role: string;
    term_start: string;
    term_end: string;
    is_active: boolean;
}

export function BoardMemberWizardDialog({
    open,
    onClose,
    availableUsers,
    member = null,
}: {
    open: boolean;
    onClose: () => void;
    /** Staff not already on the board (add only). */
    availableUsers: BoardMemberUser[];
    /** Present = edit (prefilled); absent = appoint. */
    member?: BoardMemberRecord | null;
}) {
    return open ? (
        <BoardMemberWizardBody
            onClose={onClose}
            availableUsers={availableUsers}
            member={member}
        />
    ) : null;
}

function BoardMemberWizardBody({
    onClose,
    availableUsers,
    member,
}: {
    onClose: () => void;
    availableUsers: BoardMemberUser[];
    member: BoardMemberRecord | null;
}) {
    const isEdit = member !== null;
    const form = useForm<MemberForm>({
        user_id: member?.user ? String(member.user.id) : '',
        board_role: member?.board_role ?? 'member',
        term_start: dateOnly(member?.term_start),
        term_end: dateOnly(member?.term_end),
        is_active: member?.is_active ?? true,
    });
    const { data, setData, processing } = form;
    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const current = STEPS[stepIndex];
    const err = (name: keyof MemberForm) =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];

    const memberName = isEdit
        ? (member?.user?.name ?? 'Board member')
        : (availableUsers.find((u) => String(u.id) === data.user_id)?.name ??
          '');

    const pct = useMemo(() => {
        const checks = [
            data.user_id,
            data.board_role,
            data.term_start,
            data.term_end,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data]);

    const validate = (key: StepKey): Record<string, string> => {
        const errors: Record<string, string> = {};
        if (key === 'member') {
            if (!isEdit && !data.user_id)
                errors.user_id = 'Choose the person to appoint.';
            if (!data.board_role) errors.board_role = 'Choose a board role.';
        }
        if (key === 'term') {
            if (!isEdit && !data.term_start)
                errors.term_start = 'Set the term start date.';
            if (
                data.term_start &&
                data.term_end &&
                data.term_end <= data.term_start
            )
                errors.term_end = 'The term must end after it starts.';
        }
        return errors;
    };

    const goTo = (key: StepKey) => {
        const idx = STEPS.findIndex((s) => s.key === key);
        if (idx >= 0) setStepIndex(idx);
    };

    const next = () => {
        const errors = validate(current.key);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const submit = () => {
        const all = { ...validate('member'), ...validate('term') };
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(FIELD_STEP[Object.keys(all)[0]] ?? 'member');
            return;
        }
        setClientErrors({});
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (response: { props: Record<string, unknown> }) => {
                const flash = response.props.flash as
                    | { error?: string | null }
                    | undefined;
                if (!flash?.error) setDone(true);
            },
            onError: (errors: Record<string, string>) => {
                const first = Object.keys(errors)[0];
                if (first) goTo(FIELD_STEP[first] ?? 'member');
            },
        };

        if (isEdit && member) {
            form.transform((values) => ({
                board_role: values.board_role,
                is_active: values.is_active,
                // term_end is validated "after:term_start", so the stored
                // start travels with it; clearing the end date is not
                // supported by the update endpoint and is simply not sent.
                ...(values.term_end
                    ? { term_end: values.term_end, term_start: values.term_start }
                    : {}),
            }));
            form.put(`/governance/admin/board-members/${member.id}`, options);
        } else {
            form.transform((values) => ({
                user_id: values.user_id,
                board_role: values.board_role,
                term_start: values.term_start,
                term_end: values.term_end || null,
            }));
            form.post('/governance/admin/board-members', options);
        }
    };

    const isReview = current.key === 'review';
    const roleDef = BOARD_ROLES.find((r) => r.key === data.board_role);

    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={isEdit ? 'Edit board appointment' : 'Appoint board member'}
                description={
                    isEdit
                        ? 'Update this board member’s role, term end and standing.'
                        : 'Appoint a staff member to the board with a role and term.'
                }
                railIcon={Users}
                railTitle={isEdit ? 'Edit appointment' : 'Appoint member'}
                railSub={isEdit ? memberName : 'Board & members'}
                steps={STEPS}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                maxHeight="min(88vh, 640px)"
                success={
                    done ? (
                        <WizardSuccessPane
                            title={
                                isEdit ? 'Appointment updated' : 'Member appointed'
                            }
                            blurb={
                                isEdit
                                    ? `${memberName}’s appointment has been saved.`
                                    : `${memberName || 'The new member'} has been appointed as ${roleDef?.label.toLowerCase() ?? 'a member'}.`
                            }
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStepIndex((i) => Math.max(i - 1, 0))}
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
                            Cancel
                        </Button>
                        {isReview ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="h-4 w-4" />
                                )}
                                {isEdit ? 'Save appointment' : 'Appoint to board'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane key={current.key}>
                    {current.key === 'member' ? (
                        <div className="grid gap-4">
                            {isEdit ? (
                                <InfoCard icon={UserCheck}>
                                    <span className="font-medium">
                                        {memberName}
                                    </span>
                                    {member?.user?.email ? (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {member.user.email}
                                        </span>
                                    ) : null}
                                    <br />
                                    The appointed person can’t be changed —
                                    remove and re-appoint instead.
                                </InfoCard>
                            ) : (
                                <Field
                                    label="Staff member"
                                    required
                                    error={err('user_id')}
                                >
                                    <SelectInput
                                        ariaLabel="Staff member"
                                        placeholder={
                                            availableUsers.length > 0
                                                ? 'Choose a staff member…'
                                                : 'Everyone eligible is already on the board'
                                        }
                                        value={data.user_id}
                                        onChange={(v) => setData('user_id', v)}
                                        options={availableUsers.map((u) => ({
                                            value: String(u.id),
                                            label: `${u.name} (${u.email})`,
                                        }))}
                                    />
                                </Field>
                            )}
                            <Field
                                label="Board role"
                                required
                                error={err('board_role')}
                            >
                                <TilePicker
                                    cols={3}
                                    value={data.board_role}
                                    onChange={(v) => setData('board_role', v)}
                                    options={BOARD_ROLES.map((r) => ({
                                        key: r.key,
                                        label: r.label,
                                        description: r.description,
                                        icon: r.icon,
                                    }))}
                                />
                            </Field>
                        </div>
                    ) : null}

                    {current.key === 'term' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Term start"
                                required={!isEdit}
                                error={err('term_start')}
                            >
                                <Input
                                    id="member-term-start"
                                    type="date"
                                    value={data.term_start}
                                    disabled={isEdit}
                                    onChange={(e) =>
                                        setData('term_start', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Term end"
                                hint="Leave blank if ongoing"
                                error={err('term_end')}
                            >
                                <Input
                                    id="member-term-end"
                                    type="date"
                                    value={data.term_end}
                                    onChange={(e) =>
                                        setData('term_end', e.target.value)
                                    }
                                />
                            </Field>
                            {isEdit ? (
                                <Field label="Standing" span error={err('is_active')}>
                                    <Segmented
                                        value={data.is_active ? 'active' : 'inactive'}
                                        onChange={(v) =>
                                            setData('is_active', v === 'active')
                                        }
                                        options={[
                                            { value: 'active', label: 'Active' },
                                            { value: 'inactive', label: 'Inactive' },
                                        ]}
                                    />
                                </Field>
                            ) : null}
                        </div>
                    ) : null}

                    {isReview ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard
                                icon={UserCheck}
                                title="Member & role"
                                onEdit={() => goTo('member')}
                            >
                                <ReviewRow label="Member" value={memberName} />
                                <ReviewRow label="Role" value={roleDef?.label} />
                            </ReviewCard>
                            <ReviewCard
                                icon={CalendarRange}
                                title="Term"
                                onEdit={() => goTo('term')}
                            >
                                <ReviewRow
                                    label="Starts"
                                    value={formatDateOnly(data.term_start, '')}
                                />
                                <ReviewRow
                                    label="Ends"
                                    value={
                                        data.term_end
                                            ? formatDateOnly(data.term_end)
                                            : 'Ongoing'
                                    }
                                />
                                <ReviewRow
                                    label="Standing"
                                    value={data.is_active ? 'Active' : 'Inactive'}
                                />
                            </ReviewCard>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <ConfirmDialog
                open={confirmClose}
                onClose={() => setConfirmClose(false)}
                onConfirm={onClose}
                title="Discard this draft?"
                description="Any details entered for this appointment will be lost."
                confirmText="Discard"
            />
        </>
    );
}
