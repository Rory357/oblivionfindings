import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { router, useForm } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    MessageSquareText,
    Pencil,
    Plus,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';

export interface ReplyTemplateRow {
    id: number;
    name: string;
    audience: 'public' | 'internal';
    body: string;
    owner: { id: number; name: string } | null;
    review_due_at: string | null;
    is_active: boolean;
    lock_version: number;
    version_count: number;
    updated_at: string | null;
}

/** Governed reusable replies: create, revise (versioned) and retire. */
export function ItReplyTemplates({
    templates,
    placeholders,
}: {
    templates: ReplyTemplateRow[];
    placeholders: Record<string, string>;
}) {
    const [editing, setEditing] = useState<ReplyTemplateRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [archiving, setArchiving] = useState<ReplyTemplateRow | null>(null);

    return (
        <section aria-label="Reply templates" className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    Reusable replies technicians can insert into a ticket
                    conversation. Placeholders fill from the ticket when
                    inserted; an unresolved placeholder blocks insertion.
                </p>
                <Button onClick={() => setCreating(true)}>
                    <Plus className="h-4 w-4" /> New template
                </Button>
            </div>

            {templates.length === 0 ? (
                <EmptyState
                    icon={MessageSquareText}
                    title="No reply templates yet"
                    description="Create governed, reusable replies your technicians can insert into ticket conversations."
                    action={
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="h-4 w-4" /> New template
                        </Button>
                    }
                />
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {templates.map((template) => (
                        <article
                            key={template.id}
                            className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <h3 className="text-sm font-semibold">
                                    {template.name}
                                </h3>
                                <div className="flex shrink-0 gap-1.5">
                                    <StatusBadge
                                        variant={
                                            template.audience === 'internal'
                                                ? 'warning'
                                                : 'info'
                                        }
                                        label={
                                            template.audience === 'internal'
                                                ? 'Internal'
                                                : 'Public'
                                        }
                                    />
                                    {!template.is_active && (
                                        <StatusBadge
                                            variant="neutral"
                                            label="Archived"
                                        />
                                    )}
                                </div>
                            </div>
                            <p className="line-clamp-3 text-sm whitespace-pre-line text-muted-foreground">
                                {template.body}
                            </p>
                            <p className="mt-auto text-xs text-muted-foreground">
                                {template.owner
                                    ? `Owned by ${template.owner.name}`
                                    : 'No owner'}
                                {template.review_due_at
                                    ? ` · review ${template.review_due_at}`
                                    : ''}
                                {` · v${template.lock_version}`}
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setEditing(template)}
                                >
                                    <Pencil className="h-3.5 w-3.5" /> Edit
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        if (template.is_active) {
                                            setArchiving(template);
                                        } else {
                                            router.post(
                                                `/it/setup/reply-templates/${template.id}/active`,
                                                {
                                                    active: true,
                                                    lock_version:
                                                        template.lock_version,
                                                },
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    {template.is_active ? (
                                        <>
                                            <Archive className="h-3.5 w-3.5" />{' '}
                                            Archive
                                        </>
                                    ) : (
                                        <>
                                            <ArchiveRestore className="h-3.5 w-3.5" />{' '}
                                            Restore
                                        </>
                                    )}
                                </Button>
                            </div>
                        </article>
                    ))}
                </div>
            )}

            {(creating || editing) && (
                <ReplyTemplateDialog
                    template={editing}
                    placeholders={placeholders}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}

            <ConfirmDialog
                open={archiving !== null}
                title="Archive reply template?"
                description="Technicians can no longer insert it. Its history is retained and it can be restored later."
                confirmText="Archive template"
                onClose={() => setArchiving(null)}
                onConfirm={() => {
                    if (archiving) {
                        router.post(
                            `/it/setup/reply-templates/${archiving.id}/active`,
                            {
                                active: false,
                                lock_version: archiving.lock_version,
                            },
                            { preserveScroll: true },
                        );
                    }
                    setArchiving(null);
                }}
            />
        </section>
    );
}

function ReplyTemplateDialog({
    template,
    placeholders,
    onClose,
}: {
    template: ReplyTemplateRow | null;
    placeholders: Record<string, string>;
    onClose: () => void;
}) {
    const form = useForm({
        name: template?.name ?? '',
        audience: template?.audience ?? 'public',
        body: template?.body ?? '',
        review_due_at: template?.review_due_at ?? '',
        ...(template ? { lock_version: template.lock_version } : {}),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (template) {
            form.patch(`/it/setup/reply-templates/${template.id}`, options);
        } else {
            form.post('/it/setup/reply-templates', options);
        }
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title={template ? 'Edit reply template' : 'New reply template'}
            description="A governed reusable reply with safe ticket placeholders."
            railIcon={MessageSquareText}
            railTitle="Reply template"
            railSub="Governed reusable reply"
            steps={[
                {
                    key: 'template',
                    label: 'Template',
                    blurb: 'Name, audience and body',
                    icon: MessageSquareText,
                },
            ]}
            stepIndex={0}
            onStepClick={() => undefined}
            pct={100}
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose} type="button">
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={
                            form.processing ||
                            form.data.name.trim() === '' ||
                            form.data.body.trim() === ''
                        }
                    >
                        {template ? 'Save changes' : 'Create template'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <form onSubmit={submit} className="grid gap-3.5">
                    <label className="grid gap-1.5 text-sm font-medium">
                        Name
                        <Input
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            maxLength={120}
                        />
                        {form.errors.name && (
                            <p
                                role="alert"
                                className="text-sm font-normal text-status-critical"
                            >
                                {form.errors.name}
                            </p>
                        )}
                    </label>
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <label className="grid gap-1.5 text-sm font-medium">
                            Audience
                            <Select
                                value={form.data.audience}
                                onValueChange={(value) =>
                                    form.setData(
                                        'audience',
                                        value as 'public' | 'internal',
                                    )
                                }
                            >
                                <SelectTrigger aria-label="Audience">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="public">
                                        Public reply — the requester sees it
                                    </SelectItem>
                                    <SelectItem value="internal">
                                        Internal note — technicians only
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </label>
                        <label className="grid gap-1.5 text-sm font-medium">
                            Review due
                            <Input
                                type="date"
                                value={form.data.review_due_at ?? ''}
                                onChange={(event) =>
                                    form.setData(
                                        'review_due_at',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                    </div>
                    <label className="grid gap-1.5 text-sm font-medium">
                        Body
                        <Textarea
                            rows={8}
                            value={form.data.body}
                            onChange={(event) =>
                                form.setData('body', event.target.value)
                            }
                            placeholder="Kia ora {{requester.first_name}}, …"
                        />
                        {form.errors.body && (
                            <p
                                role="alert"
                                className="text-sm font-normal text-status-critical"
                            >
                                {form.errors.body}
                            </p>
                        )}
                        {(form.errors as Record<string, string>)
                            .lock_version && (
                            <p
                                role="alert"
                                className="text-sm font-normal text-status-critical"
                            >
                                {
                                    (form.errors as Record<string, string>)
                                        .lock_version
                                }
                            </p>
                        )}
                    </label>
                    <div className="space-y-1.5">
                        <p className="text-xs font-medium text-muted-foreground">
                            Placeholders — click to add. Anything else is
                            rejected.
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {Object.entries(placeholders).map(
                                ([key, description]) => (
                                    // eslint-disable-next-line no-restricted-syntax -- compact monospace chip, not a standard action button
                                    <button
                                        key={key}
                                        type="button"
                                        title={description}
                                        onClick={() =>
                                            form.setData(
                                                'body',
                                                `${form.data.body}{{${key}}}`,
                                            )
                                        }
                                        className="rounded-md border border-border bg-muted px-2 py-1 font-mono text-xs hover:bg-accent"
                                    >
                                        {`{{${key}}}`}
                                    </button>
                                ),
                            )}
                        </div>
                    </div>
                </form>
            </WizardStepPane>
        </WizardShell>
    );
}
