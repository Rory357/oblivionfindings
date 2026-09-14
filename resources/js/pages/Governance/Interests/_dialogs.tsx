import { useForm } from '@inertiajs/react';
import {
    Briefcase,
    ClipboardList,
    HeartHandshake,
    Loader2,
    MoreHorizontal,
    UserRound,
    Wallet,
} from 'lucide-react';

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
import { formatDateOnly } from '@/lib/datetime';

export const INTEREST_TYPES = [
    {
        key: 'financial',
        label: 'Financial',
        description: 'Shares, loans, paid roles',
        icon: Wallet,
    },
    {
        key: 'professional',
        label: 'Professional',
        description: 'Employment, directorships, advice',
        icon: Briefcase,
    },
    {
        key: 'personal',
        label: 'Personal',
        description: 'Memberships and affiliations',
        icon: UserRound,
    },
    {
        key: 'family',
        label: 'Family',
        description: 'Interests held by relatives',
        icon: HeartHandshake,
    },
    {
        key: 'other',
        label: 'Other',
        description: 'Anything else the board should know',
        icon: MoreHorizontal,
    },
] as const;

export function interestTypeLabel(type: string): string {
    return INTEREST_TYPES.find((t) => t.key === type)?.label ?? type;
}

export function interestTypeIcon(type: string) {
    return INTEREST_TYPES.find((t) => t.key === type)?.icon ?? ClipboardList;
}

export function interestPeriod(interest: {
    date_from: string | null;
    date_to: string | null;
}): string {
    return `${formatDateOnly(interest.date_from)} → ${
        interest.date_to ? formatDateOnly(interest.date_to) : 'ongoing'
    }`;
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
}

/**
 * Declare a conflict/interest for the viewer's OWN board-member record — the
 * only record BoardInterestController::store accepts. A short single-section
 * form, so it is a simple dialog (POPUP_STYLE_GUIDE.md "When to use which").
 */
export function DeclareInterestDialog({
    open,
    onClose,
    boardMemberId,
}: {
    open: boolean;
    onClose: () => void;
    boardMemberId: number;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? (
                    <DeclareInterestBody
                        onClose={onClose}
                        boardMemberId={boardMemberId}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function DeclareInterestBody({
    onClose,
    boardMemberId,
}: {
    onClose: () => void;
    boardMemberId: number;
}) {
    const form = useForm({
        board_member_id: String(boardMemberId),
        interest_type: 'professional',
        description: '',
        organization_name: '',
        nature_of_interest: '',
        date_from: new Date().toISOString().split('T')[0],
        date_to: '',
        is_active: true,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/governance/interests', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string | null }
                    | undefined;
                if (!flash?.error) onClose();
            },
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ClipboardList className="h-4 w-4 text-primary" />
                    Declare an interest
                </DialogTitle>
                <DialogDescription>
                    Record an interest that could conflict with your board
                    duties. It is added to the board interests register.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Field
                    label="Interest type"
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
                            label: t.label,
                            description: t.description,
                            icon: t.icon,
                        }))}
                    />
                </Field>
                <Field
                    label="Organisation"
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
                    label="Nature of interest"
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
                        placeholder="e.g. Advisory panel member"
                    />
                </Field>
                <Field
                    label="Description"
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
                        placeholder="How the interest relates to the organisation and any decisions it could affect."
                    />
                </Field>
                <Field label="From" required error={form.errors.date_from}>
                    <Input
                        id="interest-date-from"
                        dusk="interest-date-from"
                        type="date"
                        value={form.data.date_from}
                        onChange={(e) => form.setData('date_from', e.target.value)}
                    />
                </Field>
                <Field
                    label="To"
                    hint="Leave blank if ongoing"
                    error={form.errors.date_to}
                >
                    <Input
                        id="interest-date-to"
                        dusk="interest-date-to"
                        type="date"
                        value={form.data.date_to}
                        onChange={(e) => form.setData('date_to', e.target.value)}
                    />
                </Field>
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
                    Submit declaration
                </Button>
            </DialogFooter>
        </form>
    );
}
