import { Link, useForm } from '@inertiajs/react';
import { AlertTriangle, Gavel, Loader2, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { pageHasFlashError } from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Field, InfoCard, SelectInput } from '@/components/wizard/primitives';
import { formatDateLong, formatDateOnly, toDateInput } from '@/lib/datetime';
import { refSuffix } from '@/lib/governance-labels';

export interface ApprovalResolutionOption {
    id: number;
    resolution_reference: string | null;
    title: string;
    closed_at: string | null;
}

export interface ApprovalMeetingOption {
    id: number;
    title: string;
    scheduled_at: string | null;
}

export interface RulesActivationTarget {
    profile_id: number;
    is_active: boolean;
    /** `record_first_approval` before voting was ever switched on; `resolution` for saved changes. */
    mode?: 'record_first_approval' | 'resolution' | 'none' | null;
    governing_document_reference: string | null;
    governing_document_version: string | null;
}

const NONE = '__none';

function flashError(page: unknown): string {
    return String(
        (page as { props?: { flash?: { error?: unknown } } })?.props?.flash
            ?.error ?? '',
    );
}

function documentLine(target: RulesActivationTarget): string | null {
    if (!target.governing_document_reference) return null;
    return target.governing_document_version
        ? `${target.governing_document_reference}, version ${target.governing_document_version.replace(/^v(?=\d)/i, '')}`
        : target.governing_document_reference;
}

/**
 * First switch-on (owner decision, 14 September 2026): the chair or board
 * secretary records the board's EXISTING approval of its voting rules — the
 * date, where it's recorded (minutes) and, optionally, the meeting. The
 * governing document comes from the saved rules. Confirmed before it's saved.
 */
export function RecordRulesApprovalDialog({
    open,
    onClose,
    target,
    meetings,
    rulesSummary,
}: {
    open: boolean;
    onClose: () => void;
    target: RulesActivationTarget;
    meetings: ApprovalMeetingOption[];
    /** Plain lines describing the rules being approved. */
    rulesSummary: string[];
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? (
                    <RecordRulesApprovalBody
                        onClose={onClose}
                        target={target}
                        meetings={meetings}
                        rulesSummary={rulesSummary}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function RecordRulesApprovalBody({
    onClose,
    target,
    meetings,
    rulesSummary,
}: {
    onClose: () => void;
    target: RulesActivationTarget;
    meetings: ApprovalMeetingOption[];
    rulesSummary: string[];
}) {
    const [serverError, setServerError] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);
    const today = toDateInput(new Date());
    const form = useForm({
        approved_on: '',
        approval_minutes_reference: '',
        approval_meeting_id: NONE,
    });
    const errors = form.errors as Partial<Record<keyof typeof form.data, string>>;
    const document = documentLine(target);

    const missing: string[] = [];
    if (!document) missing.push('Add your governing document’s name in “How the board votes” and save it first.');
    if (!form.data.approved_on) missing.push('Enter the date the board approved these rules.');
    if (form.data.approved_on && form.data.approved_on > today) {
        missing.push('The approval date can’t be in the future.');
    }
    if (!form.data.approval_minutes_reference.trim()) {
        missing.push('Enter where the approval is recorded.');
    }

    const submit = () => {
        setServerError(null);
        form.transform((values) => ({
            approved_on: values.approved_on,
            approval_minutes_reference: values.approval_minutes_reference.trim(),
            approval_meeting_id:
                values.approval_meeting_id === NONE
                    ? null
                    : Number(values.approval_meeting_id),
        }));
        form.post('/governance/settings/rules/record-approval', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (pageHasFlashError(page)) {
                    setServerError(flashError(page));
                    return;
                }
                onClose();
            },
        });
    };

    // A calendar date — formatted without passing through the browser's timezone.
    const approvedOnLabel = formatDateOnly(form.data.approved_on, '');

    return (
        <div>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ShieldCheck className="h-4 w-4 text-primary" />
                    Record the board’s approval of these voting rules
                </DialogTitle>
                <DialogDescription>
                    Use this once, to switch on board voting with rules your
                    board has already approved — for example in its governing
                    document or at a meeting. After this, any change to the
                    rules needs a resolution the board passes.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <div className="rounded-lg border border-border p-3 sm:col-span-2">
                    <p className="text-caption font-semibold">
                        Rules being approved
                    </p>
                    <ul className="mt-1 list-inside list-disc text-sm">
                        <li>
                            {document
                                ? `Governing document: ${document}`
                                : 'Governing document: not added yet'}
                        </li>
                        {rulesSummary.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                </div>
                <Field
                    label="Date the board approved these rules"
                    required
                    error={errors.approved_on}
                >
                    <Input
                        id="record-approval-date"
                        type="date"
                        max={today}
                        value={form.data.approved_on}
                        onChange={(e) =>
                            form.setData('approved_on', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Meeting (optional)"
                    error={errors.approval_meeting_id}
                >
                    <SelectInput
                        ariaLabel="Meeting the board approved the rules at"
                        placeholder="Not linked to a meeting"
                        value={form.data.approval_meeting_id}
                        onChange={(value) =>
                            form.setData('approval_meeting_id', value)
                        }
                        options={[
                            { value: NONE, label: 'Not linked to a meeting' },
                            ...meetings.map((meeting) => ({
                                value: String(meeting.id),
                                label: meeting.scheduled_at
                                    ? `${meeting.title} (${formatDateLong(meeting.scheduled_at)})`
                                    : meeting.title,
                            })),
                        ]}
                    />
                </Field>
                <Field
                    label="Where the approval is recorded"
                    required
                    span
                    error={errors.approval_minutes_reference}
                >
                    <Input
                        id="record-approval-minutes"
                        value={form.data.approval_minutes_reference}
                        onChange={(e) =>
                            form.setData(
                                'approval_minutes_reference',
                                e.target.value,
                            )
                        }
                        placeholder="e.g. Minutes of the 12 March 2024 board meeting, item 5"
                    />
                </Field>
                {missing.length > 0 ? (
                    <InfoCard icon={AlertTriangle} tone="warn">
                        <ul className="list-inside list-disc">
                            {missing.map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
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
                <Button
                    type="button"
                    disabled={form.processing || missing.length > 0}
                    onClick={() => setConfirming(true)}
                >
                    {form.processing ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                        <ShieldCheck className="h-4 w-4" />
                    )}
                    Record approval
                </Button>
            </DialogFooter>

            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={submit}
                title="Switch on board voting?"
                description={`This records that the board approved these voting rules on ${approvedOnLabel} (${form.data.approval_minutes_reference.trim()}). Board voting will be switched on for every board member, and from now on the rules can only be changed by a resolution the board passes. This can't be undone.`}
                confirmText="Switch on voting"
                variant="default"
            />
        </div>
    );
}

/**
 * Switch on changed voting rules approved by a passed resolution linked to
 * them. GovernanceVotingProfileService only accepts a resolution linked to
 * this exact version of the rules, so the list comes from the server and its
 * refusal (flashed as `error`) is shown inline.
 */
export function ActivateRulesDialog({
    open,
    onClose,
    target,
    resolutions,
    canViewResolutions,
}: {
    open: boolean;
    onClose: () => void;
    target: RulesActivationTarget;
    resolutions: ApprovalResolutionOption[];
    canViewResolutions: boolean;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? (
                    <ActivateRulesBody
                        onClose={onClose}
                        target={target}
                        resolutions={resolutions}
                        canViewResolutions={canViewResolutions}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function ActivateRulesBody({
    onClose,
    target,
    resolutions,
    canViewResolutions,
}: {
    onClose: () => void;
    target: RulesActivationTarget;
    resolutions: ApprovalResolutionOption[];
    canViewResolutions: boolean;
}) {
    const [serverError, setServerError] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);
    const form = useForm({
        approved_by_resolution_id:
            resolutions.length === 1 ? String(resolutions[0].id) : '',
    });
    const document = documentLine(target);
    const hasResolutions = resolutions.length > 0;
    const chosen = resolutions.find(
        (r) => String(r.id) === form.data.approved_by_resolution_id,
    );

    const submit = () => {
        setServerError(null);
        form.transform((values) => ({
            approved_by_resolution_id: Number(values.approved_by_resolution_id),
        }));
        form.post('/governance/settings/rules/activate', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (pageHasFlashError(page)) setServerError(flashError(page));
                else onClose();
            },
        });
    };

    return (
        <div>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ShieldCheck className="h-4 w-4 text-primary" />
                    Switch on the changed voting rules
                </DialogTitle>
                <DialogDescription>
                    Changes to the voting rules need a resolution the board has
                    passed. Choose the resolution that approves these rules.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-4">
                {document ? (
                    <p className="text-subtle">
                        {`Governing document: ${document}`}
                    </p>
                ) : null}
                {!hasResolutions ? (
                    <EmptyState
                        variant="compact"
                        icon={Gavel}
                        title="No passed resolution approves these rules yet"
                        description="Create a resolution, choose these voting rules under “What will this resolution approve?”, and put it to the board. Once it passes, come back here."
                        action={
                            canViewResolutions ? (
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/governance/resolutions">
                                        <Gavel className="h-3.5 w-3.5" />
                                        Go to resolutions
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Field label="Resolution that approves these rules" required>
                        <SelectInput
                            ariaLabel="Resolution that approves these rules"
                            placeholder="Choose the passed resolution…"
                            value={form.data.approved_by_resolution_id}
                            onChange={(v) =>
                                form.setData('approved_by_resolution_id', v)
                            }
                            options={resolutions.map((r) => ({
                                value: String(r.id),
                                label: [
                                    r.title,
                                    r.closed_at
                                        ? `passed ${formatDateLong(r.closed_at)}`
                                        : null,
                                    refSuffix(r.resolution_reference),
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            }))}
                        />
                    </Field>
                )}
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
                {hasResolutions ? (
                    <Button
                        type="button"
                        disabled={
                            form.processing || !form.data.approved_by_resolution_id
                        }
                        onClick={() => setConfirming(true)}
                    >
                        {form.processing ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <ShieldCheck className="h-4 w-4" />
                        )}
                        Switch on these rules
                    </Button>
                ) : null}
            </DialogFooter>

            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={submit}
                title="Switch on the new voting rules?"
                description={`The rules approved by "${chosen?.title ?? 'the resolution you chose'}" will replace the current voting rules for every resolution opened for voting from now on. This can't be undone.`}
                confirmText="Switch on these rules"
                variant="default"
            />
        </div>
    );
}
