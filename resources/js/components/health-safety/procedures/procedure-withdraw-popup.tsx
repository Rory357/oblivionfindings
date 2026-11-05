/**
 * Withdraw draft (decided 6 Nov 2026): a procedure draft made by mistake, and never published, leaves the register
 * with a required reason. Nothing is deleted: the record, its versions and its history stay, and the reason is kept
 * with them. The server decides who may do it (the same people who may edit the draft) and checks again on save.
 */
import { PopupField, PopupForm } from '@/components/popup';
import { Textarea } from '@/components/ui/textarea';
import { PROCEDURE_REGISTER } from '@/lib/health-safety/procedure-navigation';
import { useForm } from '@inertiajs/react';
import { FileX2 } from 'lucide-react';
import { useEffect } from 'react';
import type { ProcedureWorkspaceDetail } from './types';

export function ProcedureWithdrawPopup({
    open,
    detail: d,
    onClose,
}: {
    open: boolean;
    detail: ProcedureWorkspaceDetail;
    onClose: () => void;
}) {
    const form = useForm<{ reason: string }>({ reason: '' });
    useEffect(() => {
        if (!open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    const errors = form.errors as Record<string, string | undefined>;
    const problem = errors.status ?? errors.expected_version ?? errors.expected_lock_version;
    const blank = form.data.reason.trim() === '';

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            icon={FileX2}
            title="Withdraw this draft?"
            description="It leaves the register. Nothing is deleted."
            context={{
                title: [d.reference_number, d.title].filter(Boolean).join(' · '),
                note: `Version ${d.version} · never published`,
            }}
            error={problem}
            submitLabel="Withdraw draft"
            processingLabel="Withdrawing…"
            processing={form.processing}
            submitDisabled={blank}
            onSubmit={() => {
                form.transform((data) => ({
                    reason: data.reason,
                    expected_version: d.expected_version,
                    expected_lock_version: d.lock_version,
                }));
                form.post(`${PROCEDURE_REGISTER}/${d.id}/withdraw`, {
                    preserveScroll: true,
                    onSuccess: () => onClose(),
                });
            }}
            dirty={form.isDirty}
            draftName="this reason"
            testId="procedure-withdraw-popup"
        >
            <PopupField
                label="Why are you withdrawing it?"
                htmlFor="procedure-withdraw-reason"
                required
                hint="Needed. The draft, its versions and its history are kept with your reason. People who can edit drafts can still open it."
                error={errors.reason}
            >
                <Textarea
                    id="procedure-withdraw-reason"
                    rows={3}
                    maxLength={5000}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
            </PopupField>
        </PopupForm>
    );
}
