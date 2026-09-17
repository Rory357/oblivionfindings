import { useForm } from '@inertiajs/react';
import {
    Briefcase,
    CalendarX2,
    ClipboardList,
    HeartHandshake,
    Loader2,
    MoreHorizontal,
    UserRound,
    Wallet,
} from 'lucide-react';

import { pageHasFlashError } from '@/components/governance/governance-dialog-deep-link';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, TilePicker } from '@/components/wizard/primitives';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import {
    governanceStatus,
    interestTypeLabel as sharedInterestTypeLabel,
} from '@/lib/governance-labels';

export const INTEREST_TYPES = [
    {
        key: 'financial',
        description: 'Shares, loans, paid work or other money you could gain or lose',
        icon: Wallet,
    },
    {
        key: 'professional',
        description: 'A job, directorship, trusteeship or advice you give',
        icon: Briefcase,
    },
    {
        key: 'personal',
        description: 'Groups, clubs or causes you belong to',
        icon: UserRound,
    },
    {
        key: 'family',
        description: 'Someone close to you has the interest',
        icon: HeartHandshake,
    },
    {
        key: 'other',
        description: 'Anything else the board should know about',
        icon: MoreHorizontal,
    },
] as const;

export function interestTypeLabel(type: string): string {
    return sharedInterestTypeLabel(type);
}

export function interestTypeIcon(type: string) {
    return INTEREST_TYPES.find((t) => t.key === type)?.icon ?? ClipboardList;
}

/** "Since 15 Jan 2026" / "15 Jan 2026 – 3 Mar 2026". */
export function interestPeriod(interest: {
    date_from: string | null;
    date_to: string | null;
}): string {
    if (!interest.date_from) {
        return interest.date_to
            ? `Ended ${formatDateOnly(interest.date_to)}`
            : 'Start date not recorded';
    }
    return interest.date_to
        ? `${formatDateOnly(interest.date_from)} – ${formatDateOnly(interest.date_to)}`
        : `Since ${formatDateOnly(interest.date_from)}`;
}

/** One chip: Current / Ended. */
export function interestChip(isActive: boolean) {
    return governanceStatus('interest_status', isActive ? 'current' : 'ended');
}

export interface InterestRecord {
    id: number;
    board_member_id?: number;
    member_name?: string | null;
    interest_type: string;
    description: string;
    organization_name: string | null;
    nature_of_interest: string;
    date_from: string | null;
    date_to: string | null;
    is_active: boolean;
    declared_at: string | null;
    updated_at?: string | null;
    /** Server: the viewer's own declaration, or a board manager. */
    can_update?: boolean;
}

const WIDTH = { maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' };

/**
 * Declare an interest for the viewer's OWN board-member record (the only
 * record BoardInterestController::store accepts), or update the details of
 * an existing declaration when `interest` is passed. A short single-section
 * form, so it is a simple dialog (POPUP_STYLE_GUIDE.md "When to use which").
 */
export function DeclareInterestDialog({
    open,
    onClose,
    boardMemberId,
    interest = null,
}: {
    open: boolean;
    onClose: () => void;
    boardMemberId?: number | null;
    interest?: InterestRecord | null;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto" style={WIDTH}>
                {open ? (
                    <DeclareInterestBody
                        onClose={onClose}
                        boardMemberId={boardMemberId ?? null}
                        interest={interest}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function DeclareInterestBody({
    onClose,
    boardMemberId,
    interest,
}: {
    onClose: () => void;
    boardMemberId: number | null;
    interest: InterestRecord | null;
}) {
    const isEdit = interest !== null;
    const form = useForm({
        board_member_id: boardMemberId ? String(boardMemberId) : '',
        interest_type: interest?.interest_type ?? 'professional',
        description: interest?.description ?? '',
        organization_name: interest?.organization_name ?? '',
        nature_of_interest: interest?.nature_of_interest ?? '',
        date_from: interest?.date_from ?? toDateInput(new Date()),
        date_to: '',
        is_active: true,
    });

    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page: unknown) => {
            if (!pageHasFlashError(page)) onClose();
        },
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (isEdit && interest) {
            form.transform((data) => ({
                interest_type: data.interest_type,
                organization_name: data.organization_name,
                nature_of_interest: data.nature_of_interest,
                description: data.description,
                date_from: data.date_from,
            }));
            form.put(`/governance/interests/${interest.id}`, options);
            return;
        }
        form.post('/governance/interests', options);
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ClipboardList className="h-4 w-4 text-primary" />
                    {isEdit ? 'Update this declaration' : 'Declare an interest'}
                    <GovernanceTermHint term="interest" />
                </DialogTitle>
                <DialogDescription>
                    {isEdit
                        ? 'Fix or bring the details up to date. The board sees the change on the interests register.'
                        : 'Tell the board about something that could affect, or look like it affects, your board decisions. It goes on the board’s interests register.'}
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Field
                    label="Kind of interest"
                    required
                    span
                    error={form.errors.interest_type}
                >
                    <TilePicker
                        cols={3}
                        value={form.data.interest_type}
                        onChange={(v) => form.setData('interest_type', v)}
                        options={INTEREST_TYPES.map((t) => ({
                            key: t.key,
                            label: interestTypeLabel(t.key),
                            description: t.description,
                            icon: t.icon,
                        }))}
                    />
                </Field>
                <Field
                    label="Organisation or person"
                    required
                    error={form.errors.organization_name}
                >
                    <Input
                        id="interest-organization"
                        dusk="interest-organization"
                        value={form.data.organization_name}
                        onChange={(e) =>
                            form.setData('organization_name', e.target.value)
                        }
                        placeholder="e.g. Acme Advisory Ltd"
                    />
                </Field>
                <Field
                    label="Your role or connection"
                    required
                    error={form.errors.nature_of_interest}
                >
                    <Input
                        id="interest-nature"
                        dusk="interest-nature"
                        value={form.data.nature_of_interest}
                        onChange={(e) =>
                            form.setData('nature_of_interest', e.target.value)
                        }
                        placeholder='e.g. "Director" or "My sister works there"'
                    />
                </Field>
                <Field
                    label="How could it affect board decisions?"
                    required
                    span
                    error={form.errors.description}
                >
                    <Textarea
                        id="interest-description"
                        dusk="interest-description"
                        rows={3}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        placeholder="e.g. They supply cleaning services, so I would step aside from any decision about cleaning contracts."
                    />
                </Field>
                <Field
                    label="When did the interest start?"
                    required
                    error={form.errors.date_from}
                >
                    <Input
                        id="interest-date-from"
                        dusk="interest-date-from"
                        type="date"
                        value={form.data.date_from}
                        max={toDateInput(new Date())}
                        onChange={(e) => form.setData('date_from', e.target.value)}
                    />
                </Field>
                {!isEdit ? (
                    <Field
                        label="Has it already ended?"
                        hint="Leave blank if it's still current"
                        error={form.errors.date_to}
                    >
                        <Input
                            id="interest-date-to"
                            dusk="interest-date-to"
                            type="date"
                            value={form.data.date_to}
                            onChange={(e) =>
                                form.setData('date_to', e.target.value)
                            }
                        />
                    </Field>
                ) : null}
            </div>

            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing}
                    dusk="submit-interest"
                >
                    {form.processing ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : null}
                    {isEdit ? 'Save changes' : 'Declare interest'}
                </Button>
            </DialogFooter>
        </form>
    );
}

/** "This interest has ended…" — asks when it ended. */
export function EndInterestDialog({
    interest,
    onClose,
}: {
    interest: InterestRecord | null;
    onClose: () => void;
}) {
    return (
        <Dialog open={interest !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 480px)', width: 'min(92vw, 480px)' }}>
                {interest ? (
                    <EndInterestBody interest={interest} onClose={onClose} />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function EndInterestBody({
    interest,
    onClose,
}: {
    interest: InterestRecord;
    onClose: () => void;
}) {
    const today = toDateInput(new Date());
    const form = useForm({ ended_on: today });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/governance/interests/${interest.id}/end`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (!pageHasFlashError(page)) onClose();
            },
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <CalendarX2 className="h-4 w-4 text-primary" />
                    This interest has ended
                </DialogTitle>
                <DialogDescription>
                    “{interest.organization_name ?? interest.nature_of_interest}”
                    comes off the current interests register. It stays in your
                    declarations for the record.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-4">
                <Field
                    label="When did it end?"
                    required
                    error={form.errors.ended_on}
                >
                    <Input
                        id="interest-ended-on"
                        type="date"
                        value={form.data.ended_on}
                        min={interest.date_from ?? undefined}
                        max={today}
                        onChange={(e) => form.setData('ended_on', e.target.value)}
                    />
                </Field>
            </div>
            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing || !form.data.ended_on}>
                    {form.processing ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : null}
                    Mark as ended
                </Button>
            </DialogFooter>
        </form>
    );
}

/** Read-only view of someone else's declaration. */
export function InterestDetailsDialog({
    interest,
    onClose,
}: {
    interest: InterestRecord | null;
    onClose: () => void;
}) {
    const chip = interest ? interestChip(interest.is_active) : null;

    return (
        <Dialog open={interest !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 480px)', width: 'min(92vw, 480px)' }}>
                {interest ? (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <ClipboardList className="h-4 w-4 text-primary" />
                                {interest.organization_name ??
                                    interest.nature_of_interest}
                            </DialogTitle>
                            <DialogDescription>
                                {interest.member_name
                                    ? `Declared by ${interest.member_name}`
                                    : 'A declaration on the interests register'}
                                {interest.declared_at
                                    ? ` on ${formatDateOnly(interest.declared_at)}`
                                    : ''}
                                .
                            </DialogDescription>
                        </DialogHeader>
                        <dl className="mt-3 grid gap-3 text-sm">
                            <div>
                                <dt className="text-caption">Kind of interest</dt>
                                <dd>{interestTypeLabel(interest.interest_type)}</dd>
                            </div>
                            <div>
                                <dt className="text-caption">Role or connection</dt>
                                <dd>{interest.nature_of_interest}</dd>
                            </div>
                            <div>
                                <dt className="text-caption">
                                    How it could affect board decisions
                                </dt>
                                <dd className="whitespace-pre-line">
                                    {interest.description}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-caption">When</dt>
                                <dd>{interestPeriod(interest)}</dd>
                            </div>
                            {chip ? (
                                <div>
                                    <dt className="text-caption">Status</dt>
                                    <dd>{chip.label}</dd>
                                </div>
                            ) : null}
                        </dl>
                        <DialogFooter className="mt-5">
                            <Button type="button" variant="outline" onClick={onClose}>
                                Close
                            </Button>
                        </DialogFooter>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
