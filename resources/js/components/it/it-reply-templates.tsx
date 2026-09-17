import { ConfirmDialog } from '@/components/confirm-dialog';
import { ItAutomationRegister } from '@/components/it/it-automation-register';
import { EntityChip, EntityStatusChip } from '@/components/lists/entity-cells';
import type { MenuItem } from '@/components/lists/entity-menu';
import type { EntityTableColumn } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { router, useForm } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    MessageSquareText,
    Pencil,
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

function reviewOverdue(template: ReplyTemplateRow): boolean {
    return (
        template.is_active &&
        template.review_due_at !== null &&
        template.review_due_at < new Date().toISOString().slice(0, 10)
    );
}

/** Governed reusable replies on the shared register contract. */
export function ItReplyTemplates({
    templates,
    total,
    placeholders,
    layout,
    creating,
    onCreatingChange,
}: {
    templates: ReplyTemplateRow[];
    total: number;
    placeholders: Record<string, string>;
    layout: 'cards' | 'table';
    creating: boolean;
    onCreatingChange: (open: boolean) => void;
}) {
    const [editing, setEditing] = useState<ReplyTemplateRow | null>(null);
    const [archiving, setArchiving] = useState<ReplyTemplateRow | null>(null);

    const setActive = (template: ReplyTemplateRow, active: boolean) =>
        router.post(
            `/it/setup/reply-templates/${template.id}/active`,
            { active, lock_version: template.lock_version },
            { preserveScroll: true },
        );

    const actionsFor = (template: ReplyTemplateRow): MenuItem[] => [
        { label: 'Edit', icon: Pencil, onClick: () => setEditing(template) },
        template.is_active
            ? {
                  label: 'Archive',
                  icon: Archive,
                  onClick: () => setArchiving(template),
              }
            : {
                  label: 'Restore',
                  icon: ArchiveRestore,
                  onClick: () => setActive(template, true),
              },
    ];

    const state = (template: ReplyTemplateRow) => (
        <EntityStatusChip
            variant={
                !template.is_active
                    ? 'neutral'
                    : reviewOverdue(template)
                      ? 'warning'
                      : 'success'
            }
        >
            {!template.is_active
                ? 'Archived'
                : reviewOverdue(template)
                  ? 'Review overdue'
                  : 'Active'}
        </EntityStatusChip>
    );

    const columns: EntityTableColumn<ReplyTemplateRow>[] = [
        { key: 'state', label: 'Status', width: '130px', cell: state },
        {
            key: 'audience',
            label: 'Audience',
            width: '110px',
            cell: (template) => (
                <EntityChip>
                    {template.audience === 'internal' ? 'Internal' : 'Public'}
                </EntityChip>
            ),
        },
        {
            key: 'owner',
            label: 'Owner',
            width: '1fr',
            cell: (template) => (
                <span className="text-xs text-muted-foreground">
                    {template.owner?.name ?? '—'}
                </span>
            ),
        },
        {
            key: 'review',
            label: 'Review due',
            width: '120px',
            cell: (template) => (
                <span className="text-xs text-muted-foreground">
                    {template.review_due_at ?? '—'}
                </span>
            ),
        },
        {
            key: 'version',
            label: 'Version',
            width: '90px',
            cell: (template) => (
                <EntityChip>v{template.lock_version}</EntityChip>
            ),
        },
    ];

    return (
        <>
            <ItAutomationRegister
                title="Reply templates"
                rows={templates}
                total={total}
                layout={layout}
                icon={MessageSquareText}
                subline={(template) => template.body}
                chips={(template) => (
                    <>
                        {state(template)}
                        <EntityChip>
                            {template.audience === 'internal'
                                ? 'Internal note'
                                : 'Public reply'}
                        </EntityChip>
                        <EntityChip>v{template.lock_version}</EntityChip>
                        {template.review_due_at && (
                            <EntityChip>
                                Review {template.review_due_at}
                            </EntityChip>
                        )}
                    </>
                )}
                meridian={(template) =>
                    !template.is_active
                        ? 'warning'
                        : reviewOverdue(template)
                          ? 'warning'
                          : 'success'
                }
                muted={(template) => !template.is_active}
                footer={(template) => ({
                    personName: template.owner?.name,
                    primary: template.owner?.name ?? 'No owner',
                    secondary: 'Template owner',
                })}
                columns={columns}
                actionsFor={actionsFor}
                onOpen={setEditing}
                emptyCopy="No reply templates yet. Use the header action to create a governed, reusable reply."
            />

            {(creating || editing) && (
                <ReplyTemplateDialog
                    template={editing}
                    placeholders={placeholders}
                    onClose={() => {
                        onCreatingChange(false);
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
                    if (archiving) setActive(archiving, false);
                    setArchiving(null);
                }}
            />
        </>
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
