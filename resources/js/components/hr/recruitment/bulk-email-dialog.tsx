/* eslint-disable no-restricted-syntax -- Bulk candidate email: a single-form
 * dialog posting to the bulk-email endpoint, with reusable saved templates.
 * Native inputs; semantic tokens. */
import { router, useForm } from '@inertiajs/react';
import { Loader2, Trash2 } from 'lucide-react';
import { useId, useState, type FormEvent } from 'react';
import { toast } from 'sonner';

import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';

/** Server rules for a saved template (CandidateController::storeEmailTemplate). */
const TEMPLATE_NAME_MAX = 255;
const TEMPLATE_SUBJECT_MAX = 255;
const TEMPLATE_BODY_MAX = 10000;

type TemplateErrors = { name?: string; message?: string };

export type EmailTemplate = {
    id: number;
    name: string;
    subject: string;
    body: string;
};

export function BulkEmailDialog({
    open,
    onClose,
    candidateIds,
    templates,
    canManage,
}: {
    open: boolean;
    onClose: () => void;
    candidateIds: number[];
    templates: EmailTemplate[];
    canManage: boolean;
}) {
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [templateId, setTemplateId] = useState('');
    const form = useForm({});

    const count = candidateIds.length;
    const canSend = subject.trim() !== '' && body.trim() !== '' && count > 0;
    const canSaveTemplate = subject.trim() !== '' && body.trim() !== '';

    const applyTemplate = (id: string) => {
        setTemplateId(id);
        const t = templates.find((x) => String(x.id) === id);
        if (t) {
            setSubject(t.subject);
            setBody(t.body);
        }
    };

    // "Save as template" opens a small dialog for the name; the request is
    // sent only from that dialog's submit button.
    const nameFieldId = useId();
    const [templateOpen, setTemplateOpen] = useState(false);
    const [templateName, setTemplateName] = useState('');
    const [templateErrors, setTemplateErrors] = useState<TemplateErrors>({});
    const [savingTemplate, setSavingTemplate] = useState(false);

    const openTemplateDialog = () => {
        setTemplateName('');
        setTemplateErrors({});
        setTemplateOpen(true);
    };

    const validateTemplate = (name: string): TemplateErrors => {
        if (name === '') return { name: 'Enter a name for the template.' };
        if (name.length > TEMPLATE_NAME_MAX)
            return {
                name: `Keep the name to ${TEMPLATE_NAME_MAX} characters or fewer.`,
            };
        if (
            templates.some(
                (t) => t.name.trim().toLowerCase() === name.toLowerCase(),
            )
        )
            return {
                name: 'A template with this name already exists. Choose another name.',
            };
        if (subject.trim().length > TEMPLATE_SUBJECT_MAX)
            return {
                message: `The subject is too long to save. Keep it to ${TEMPLATE_SUBJECT_MAX} characters or fewer.`,
            };
        if (body.trim().length > TEMPLATE_BODY_MAX)
            return {
                message: `The message is too long to save. Keep it to ${TEMPLATE_BODY_MAX.toLocaleString('en-NZ')} characters or fewer.`,
            };
        return {};
    };

    const saveTemplate = (e: FormEvent) => {
        e.preventDefault();
        const name = templateName.trim();
        const errors = validateTemplate(name);
        setTemplateErrors(errors);
        if (errors.name || errors.message) return;
        router.post(
            '/hr/recruitment/email-templates',
            { name, subject: subject.trim(), body: body.trim() },
            {
                preserveScroll: true,
                onStart: () => setSavingTemplate(true),
                onSuccess: (page) => {
                    const f = (page.props as { flash?: { error?: string } })
                        .flash;
                    if (f?.error) {
                        toast.error('Could not save template', {
                            description: f.error,
                        });
                        return;
                    }
                    toast.success('Template saved');
                    setTemplateOpen(false);
                },
                onError: (errors) =>
                    setTemplateErrors({
                        name: errors.name,
                        message:
                            errors.subject ??
                            errors.body ??
                            (errors.name
                                ? undefined
                                : 'Could not save the template. Try again.'),
                    }),
                onFinish: () => setSavingTemplate(false),
            },
        );
    };

    const deleteTemplate = (id: string) => {
        router.delete(`/hr/recruitment/email-templates/${id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (templateId === id) setTemplateId('');
                toast.success('Template removed');
            },
        });
    };

    const submit = () => {
        form.transform(() => ({
            candidate_ids: candidateIds,
            subject: subject.trim(),
            body: body.trim(),
        }));
        form.post('/hr/recruitment/candidates/bulk-email', {
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (
                    page.props as {
                        flash?: { error?: string; success?: string };
                    }
                ).flash;
                if (f?.error) {
                    toast.error('Could not send', { description: f.error });
                    return;
                }
                toast.success(
                    f?.success ?? `Message sent to ${count} candidate(s)`,
                );
                setSubject('');
                setBody('');
                setTemplateId('');
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Email {count} candidate{count === 1 ? '' : 's'}
                    </DialogTitle>
                    <DialogDescription>
                        Sends the same message to each selected candidate's
                        personal email. Candidates without an email on file are
                        skipped.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    {templates.length > 0 ? (
                        <div>
                            <Label className="mb-1.5 block text-sm font-semibold">
                                Start from a template{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <div className="flex items-center gap-2">
                                <select
                                    value={templateId}
                                    onChange={(e) =>
                                        applyTemplate(e.target.value)
                                    }
                                    className="h-9 flex-1 rounded-md border border-border bg-card px-2.5 text-[13px] outline-none focus:border-primary"
                                >
                                    <option value="">No template</option>
                                    {templates.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name}
                                        </option>
                                    ))}
                                </select>
                                {canManage && templateId !== '' ? (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            deleteTemplate(templateId)
                                        }
                                        aria-label="Delete template"
                                        className="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-border text-muted-foreground hover:bg-muted"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </button>
                                ) : null}
                            </div>
                        </div>
                    ) : null}

                    <div>
                        <Label className="mb-1.5 block text-sm font-semibold">
                            Subject
                        </Label>
                        <input
                            value={subject}
                            onChange={(e) => setSubject(e.target.value)}
                            placeholder="e.g. An update on your application"
                            className="h-9 w-full rounded-md border border-border bg-card px-3 text-[13px] outline-none focus:border-primary"
                        />
                    </div>
                    <div>
                        <Label className="mb-1.5 block text-sm font-semibold">
                            Message
                        </Label>
                        <textarea
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            rows={7}
                            placeholder="Write your message. Each line is sent as its own paragraph; a greeting and sign-off are added automatically."
                            className="w-full resize-y rounded-md border border-border bg-card p-2.5 text-[13px] outline-none focus:border-primary"
                        />
                    </div>
                </div>

                <DialogFooter className="sm:justify-between">
                    {canManage ? (
                        <button
                            type="button"
                            onClick={openTemplateDialog}
                            disabled={!canSaveTemplate}
                            className="h-9 rounded-md border border-border bg-card px-3 text-[13px] font-semibold hover:bg-muted disabled:opacity-50"
                        >
                            Save as template
                        </button>
                    ) : (
                        <span />
                    )}
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="h-9 rounded-md border border-border bg-card px-4 text-[13px] font-semibold hover:bg-muted"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!canSend || form.processing}
                            className="h-9 rounded-md bg-primary-fill px-4 text-[13px] font-bold text-primary-fill-foreground disabled:opacity-50"
                        >
                            Send to {count}
                        </button>
                    </div>
                </DialogFooter>

                <Dialog
                    open={templateOpen}
                    onOpenChange={(o) => {
                        if (!o && !savingTemplate) setTemplateOpen(false);
                    }}
                >
                    <DialogContent className="max-w-md">
                        <form onSubmit={saveTemplate} className="grid gap-4">
                            <DialogHeader>
                                <DialogTitle>Save as a template</DialogTitle>
                                <DialogDescription>
                                    Saves this subject and message so anyone who
                                    emails candidates can start from it next
                                    time.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-1.5">
                                <Label htmlFor={nameFieldId}>
                                    Template name
                                </Label>
                                <Input
                                    id={nameFieldId}
                                    value={templateName}
                                    onChange={(e) => {
                                        setTemplateName(e.target.value);
                                        if (templateErrors.name)
                                            setTemplateErrors({});
                                    }}
                                    maxLength={TEMPLATE_NAME_MAX}
                                    placeholder="e.g. Interview invitation"
                                    aria-invalid={Boolean(templateErrors.name)}
                                    autoFocus
                                />
                                <InputError message={templateErrors.name} />
                                <InputError message={templateErrors.message} />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setTemplateOpen(false)}
                                    disabled={savingTemplate}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={savingTemplate}>
                                    {savingTemplate ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    ) : null}
                                    Save template
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </DialogContent>
        </Dialog>
    );
}

export default BulkEmailDialog;
