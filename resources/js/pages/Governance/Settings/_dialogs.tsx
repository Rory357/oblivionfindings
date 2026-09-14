import { Link, useForm } from '@inertiajs/react';
import { AlertTriangle, Gavel, Loader2, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

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
import { formatDateLong } from '@/lib/datetime';

export interface ApprovalResolutionOption {
    id: number;
    resolution_reference: string | null;
    title: string;
    closed_at: string | null;
}

export interface RulesActivationTarget {
    profile_id: number;
    is_active: boolean;
    governing_document_reference: string | null;
    governing_document_version: string | null;
}

/**
 * Activate board voting rules. GovernanceVotingProfileService only accepts a
 * carried resolution explicitly bound to this exact profile and revision, so
 * the resolution list comes from the server (`approvalResolutions`) and the
 * service's rejection message (flashed as `error`) renders inline.
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
    const form = useForm({
        governing_document_reference: target.governing_document_reference ?? '',
        governing_document_version: target.governing_document_version ?? '',
        approved_by_resolution_id:
            resolutions.length === 1 ? String(resolutions[0].id) : '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setServerError(null);
        form.transform((values) => ({
            ...values,
            governing_document_version: values.governing_document_version || null,
        }));
        form.post('/governance/settings/rules/activate', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string | null }
                    | undefined;
                if (flash?.error) setServerError(flash.error);
                else onClose();
            },
        });
    };

    const hasResolutions = resolutions.length > 0;

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ShieldCheck className="h-4 w-4 text-primary" />
                    Activate voting rules
                </DialogTitle>
                <DialogDescription>
                    Make these rules live for board voting. Activation needs the
                    governing document they come from and the carried board
                    resolution that approved this exact version of the rules.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                {!hasResolutions ? (
                    <div className="sm:col-span-2">
                        <EmptyState
                            variant="compact"
                            icon={Gavel}
                            title="Approve these rules through a board resolution first"
                            description="No carried resolution is bound to this version of the voting rules yet. Author a decision paper bound to the rules, carry it, then return here."
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
                    </div>
                ) : (
                    <>
                        <Field
                            label="Governing document reference"
                            required
                            error={form.errors.governing_document_reference}
                        >
                            <Input
                                id="activate-document-reference"
                                value={form.data.governing_document_reference}
                                onChange={(e) =>
                                    form.setData(
                                        'governing_document_reference',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Trust Deed 2024"
                            />
                        </Field>
                        <Field
                            label="Document version"
                            error={form.errors.governing_document_version}
                        >
                            <Input
                                id="activate-document-version"
                                value={form.data.governing_document_version}
                                onChange={(e) =>
                                    form.setData(
                                        'governing_document_version',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. v1.0"
                            />
                        </Field>
                        <Field
                            label="Approving resolution"
                            required
                            span
                            error={form.errors.approved_by_resolution_id}
                        >
                            <SelectInput
                                ariaLabel="Approving resolution"
                                placeholder="Choose the carried resolution…"
                                value={form.data.approved_by_resolution_id}
                                onChange={(v) =>
                                    form.setData('approved_by_resolution_id', v)
                                }
                                options={resolutions.map((r) => ({
                                    value: String(r.id),
                                    label: `${r.resolution_reference ? `${r.resolution_reference} · ` : ''}${r.title}${r.closed_at ? ` (carried ${formatDateLong(r.closed_at)})` : ''}`,
                                }))}
                            />
                        </Field>
                    </>
                )}
                {serverError ? (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        {serverError}
                    </InfoCard>
                ) : null}
            </div>

            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                {hasResolutions ? (
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            !form.data.approved_by_resolution_id ||
                            !form.data.governing_document_reference.trim()
                        }
                    >
                        {form.processing ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <ShieldCheck className="h-4 w-4" />
                        )}
                        Activate rules
                    </Button>
                ) : null}
            </DialogFooter>
        </form>
    );
}
