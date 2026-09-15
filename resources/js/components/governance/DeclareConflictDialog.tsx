import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    DollarSign,
    HelpCircle,
    Loader2,
    Scale,
    Users,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { pageHasFlashError } from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, TilePicker } from '@/components/wizard/primitives';
import { formatDateLong } from '@/lib/datetime';
import { conflictTypeLabel } from '@/lib/governance-labels';

import {
    buildConflictPayload,
    CONFLICT_DESCRIPTION_MIN,
    CONFLICT_TYPE_DESCRIPTIONS,
    CONFLICT_TYPES,
    stepAsideConsequence,
    type ConflictFormValues,
} from './resolution-voting';

/** A member's recorded declaration on one resolution (server payload). */
export interface ExistingConflict {
    id?: number;
    declaration_type: string;
    declaration_text?: string | null;
    withdrew_from_voting: boolean;
    withdrew_from_discussion?: boolean | null;
    declared_at?: string | null;
}

export interface DeclareConflictDialogProps {
    isOpen: boolean;
    onClose: () => void;
    resolutionId: number;
    resolutionTitle: string;
    /** The member's current declaration, when updating it. */
    existing?: ExistingConflict | null;
    /** The member already voted: stepping aside removes that vote. */
    hasVoted?: boolean;
    /** The rule the engine applies (unanimous changes the consequence). */
    appliedThreshold?: string | null;
}

const TYPE_ICONS = {
    material: DollarSign,
    related: Users,
    prejudicial: Scale,
    other: HelpCircle,
} as const;

/**
 * The ONE conflict-of-interest dialog, used by the Resolutions record page
 * and the meeting workspace. It posts exactly the fields the server
 * validates (`type`, `description`, `withdraw_from_voting`,
 * `withdraw_from_discussion`), shows validation and refusal messages inline,
 * and only closes when the declaration was really recorded.
 */
export function DeclareConflictDialog(props: DeclareConflictDialogProps) {
    const { isOpen, onClose } = props;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {isOpen ? <DeclareConflictBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function DeclareConflictBody({
    onClose,
    resolutionId,
    resolutionTitle,
    existing = null,
    hasVoted = false,
    appliedThreshold = null,
}: DeclareConflictDialogProps) {
    const [serverError, setServerError] = useState<string | null>(null);
    const form = useForm<ConflictFormValues>({
        type: existing?.declaration_type ?? 'material',
        description: existing?.declaration_text ?? '',
        withdraw_from_voting: existing ? Boolean(existing.withdrew_from_voting) : true,
        withdraw_from_discussion: Boolean(existing?.withdrew_from_discussion),
    });

    const length = form.data.description.trim().length;
    const tooShort = length < CONFLICT_DESCRIPTION_MIN;
    const errors = form.errors as Partial<Record<keyof ConflictFormValues, string>>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (tooShort) return;
        setServerError(null);
        form.transform((values) => buildConflictPayload(values));
        form.post(`/governance/resolutions/${resolutionId}/conflict`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (pageHasFlashError(page)) {
                    const flash = (page as { props?: { flash?: { error?: unknown } } })
                        .props?.flash;
                    setServerError(String(flash?.error ?? ''));
                    return;
                }
                onClose();
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <AlertTriangle className="h-4 w-4 text-status-warning" />
                    {existing
                        ? 'Update your conflict of interest'
                        : 'Tell the board about a conflict of interest'}
                </DialogTitle>
                <DialogDescription>
                    About the resolution “{resolutionTitle}”.
                    {existing?.declared_at
                        ? ` You first declared this on ${formatDateLong(existing.declared_at)}.`
                        : ' If you, your family or an organisation you’re involved with could gain or lose from this decision, tell the board.'}
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-4">
                <Field label="What kind of conflict is it?" required error={errors.type}>
                    <TilePicker
                        value={form.data.type}
                        onChange={(value) => form.setData('type', value)}
                        options={CONFLICT_TYPES.map((key) => ({
                            key,
                            label: conflictTypeLabel(key),
                            description: CONFLICT_TYPE_DESCRIPTIONS[key],
                            icon: TYPE_ICONS[key],
                        }))}
                    />
                </Field>

                <Field
                    label="What is the conflict?"
                    required
                    hint={`At least ${CONFLICT_DESCRIPTION_MIN} characters`}
                    error={
                        errors.description ??
                        (length > 0 && tooShort
                            ? `Add a little more detail about the conflict (${length} of ${CONFLICT_DESCRIPTION_MIN} characters).`
                            : undefined)
                    }
                >
                    <Textarea
                        id={`conflict-description-${resolutionId}`}
                        rows={4}
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        placeholder="e.g. My sister is a director of the company quoting for this contract."
                    />
                </Field>

                <div className="grid gap-3">
                    <Label className="flex items-start gap-2.5 font-normal">
                        <Checkbox
                            checked={form.data.withdraw_from_voting}
                            onCheckedChange={(checked) =>
                                form.setData('withdraw_from_voting', checked === true)
                            }
                            className="mt-0.5"
                        />
                        <span>
                            <span className="font-medium">Step aside from the vote</span>
                            <span className="text-caption block">
                                You won’t vote, and your name is recorded as stepping aside.
                            </span>
                        </span>
                    </Label>
                    <Label className="flex items-start gap-2.5 font-normal">
                        <Checkbox
                            checked={form.data.withdraw_from_discussion}
                            onCheckedChange={(checked) =>
                                form.setData('withdraw_from_discussion', checked === true)
                            }
                            className="mt-0.5"
                        />
                        <span>
                            <span className="font-medium">Step aside from the discussion</span>
                            <span className="text-caption block">
                                Leave the meeting while the board talks about it.
                            </span>
                        </span>
                    </Label>
                    {errors.withdraw_from_voting ? (
                        <p className="text-xs text-status-critical">{errors.withdraw_from_voting}</p>
                    ) : null}
                </div>

                <InfoCard icon={Scale}>{stepAsideConsequence(appliedThreshold)}</InfoCard>

                {hasVoted && form.data.withdraw_from_voting ? (
                    <InfoCard icon={AlertTriangle} tone="warn">
                        You’ve already voted on this resolution. Stepping aside removes your vote.
                    </InfoCard>
                ) : null}

                {serverError ? (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        <span role="alert">{serverError}</span>
                    </InfoCard>
                ) : null}
            </div>

            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing || tooShort}>
                    {form.processing ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                    {existing ? 'Update declaration' : 'Declare conflict'}
                </Button>
            </DialogFooter>
        </form>
    );
}
