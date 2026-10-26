/* Settings › Referrals › Workflow › Delivery & channels: the approved staff
 * messages: a template list (kebab + right-click from one MenuItem[], row
 * click opens it), an editor dialog with a live preview on synthetic
 * details, and on/off instead of removal once a template is published. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, TilePicker } from '@/components/wizard/primitives';
import {
    Eye,
    Mail,
    MessageSquareText,
    Pencil,
    Plus,
    Power,
    Trash2,
    UserCheck,
    UserRound,
    Users,
} from 'lucide-react';
import { useId, useRef, useState } from 'react';
import {
    allowanceValues,
    audienceHints,
    audienceLabels,
    channelLabels,
    EMAIL_BODY_MAX,
    EMAIL_SUBJECT_MAX,
    MESSAGE_FIELDS,
    messageFieldInfo,
    renderTemplate,
    sampleValues,
    SMS_MAX_PARTS,
    smsParts,
    smsWide,
    STANDARD_TEMPLATE_IDS,
    templateFields,
    templateProblem,
    type MessageField,
    type MessageTemplate,
} from './message-templates';
import {
    editConfiguration,
    referenceFor,
    type ReferralConfiguration,
    type SettingsAreaProps,
} from './settings-types';
import { ConfigDialog, Nested, NestedRow, Note, OnOff } from './settings-ui';

type Dialog =
    | { kind: 'edit'; index: number | null }
    | { kind: 'preview'; index: number }
    | { kind: 'remove'; index: number };

const blank: MessageTemplate = {
    id: '',
    label: '',
    channel: 'email',
    audience: 'person',
    subject: '',
    body: '',
    enabled: true,
};

const iconFor = (template: Pick<MessageTemplate, 'channel'>) =>
    template.channel === 'sms' ? MessageSquareText : Mail;

export function MessageTemplateSettings({
    value,
    onChange,
    readOnly,
    settings,
}: SettingsAreaProps) {
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const context = useEntityContextMenu<MessageTemplate>();
    const templates = value.communications?.templates ?? [];
    const published = new Set(
        (settings.configuration.communications?.templates ?? []).map(
            (template) => template.id,
        ),
    );
    const linkLength = settings.delivery?.link_length ?? 120;
    const edit = (change: (copy: ReferralConfiguration) => void) =>
        editConfiguration(value, onChange, (copy) => {
            copy.communications ??= { templates: [] };
            change(copy);
        });
    const indexOf = (template: MessageTemplate) =>
        templates.findIndex((item) => item.id === template.id);
    const toggle = (index: number, enabled: boolean) =>
        edit((copy) => {
            copy.communications!.templates[index].enabled = enabled;
        });
    const actions = (template: MessageTemplate): MenuItem[] => {
        const index = indexOf(template);
        const items: MenuItem[] = [
            {
                label: readOnly ? 'Open template' : 'Edit template',
                icon: Pencil,
                onClick: () => setDialog({ kind: 'edit', index }),
            },
            {
                label: 'Preview with sample details',
                icon: Eye,
                onClick: () => setDialog({ kind: 'preview', index }),
            },
        ];
        if (readOnly) return items;
        return [
            ...items,
            {
                label: template.enabled ? 'Turn off' : 'Turn on',
                icon: Power,
                onClick: () => toggle(index, !template.enabled),
            },
            {
                label: 'Remove template',
                icon: Trash2,
                danger: true,
                onClick: () => setDialog({ kind: 'remove', index }),
                disabled: published.has(template.id)
                    ? 'Published templates can’t be removed. Turn it off instead.'
                    : undefined,
            },
        ];
    };
    const on = templates.filter((template) => template.enabled).length;

    return (
        <div className="space-y-5">
            <ListCaption
                title="Message templates"
                caption={`${on} of ${templates.length} on · staff choose one when they message someone from a referral`}
                right={
                    readOnly ? undefined : (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setDialog({ kind: 'edit', index: null })
                            }
                        >
                            <Plus className="size-4" />
                            Add template
                        </Button>
                    )
                }
            />
            <EntityTable<MessageTemplate>
                rows={templates}
                rowKey={(template) => template.id}
                identityLabel="Template"
                identity={(template) => ({
                    icon: iconFor(template),
                    name: template.label,
                    subline:
                        template.channel === 'email'
                            ? (template.subject ?? '')
                            : template.body,
                    // Standard templates start off: someone reviews the wording, then switches them on.
                    extra:
                        !template.enabled &&
                        STANDARD_TEMPLATE_IDS.includes(template.id) ? (
                            <StatusBadge variant="info" size="sm">
                                Ready to review
                            </StatusBadge>
                        ) : undefined,
                })}
                identityWidth="2.4fr"
                minWidth={760}
                actionsFor={actions}
                onOpen={(template) =>
                    setDialog({ kind: 'edit', index: indexOf(template) })
                }
                onRowContextMenu={context.open}
                mutedFor={(template) => !template.enabled}
                columns={[
                    {
                        key: 'channel',
                        label: 'Sent by',
                        width: '1fr',
                        cell: (template) => channelLabels[template.channel],
                    },
                    {
                        key: 'audience',
                        label: 'Sent to',
                        width: '1fr',
                        cell: (template) => audienceLabels[template.audience],
                    },
                    {
                        key: 'enabled',
                        label: 'Offered to staff',
                        width: '1fr',
                        cell: (template) => (
                            <div onClick={(event) => event.stopPropagation()}>
                                <OnOff
                                    label={`Offer “${template.label}”`}
                                    checked={template.enabled}
                                    disabled={readOnly}
                                    onChange={(enabled) =>
                                        toggle(indexOf(template), enabled)
                                    }
                                />
                            </div>
                        ),
                    },
                ]}
            />
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    icon={iconFor(context.ctx.record)}
                    title={context.ctx.record.label}
                    items={actions(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            <Note>
                The standard templates start turned off: review their wording,
                then switch on the ones you want. Templates can only use the
                merge fields listed in the editor, so names, the reference and
                your own contact details are the only details added. Staff can
                make a short edit before sending, with the merge fields locked.
                A message is only sent when the recipient’s saved contact
                preferences allow it. Published templates can’t be removed: turn
                one off instead.
            </Note>
            {dialog?.kind === 'edit' && (
                <TemplateDialog
                    template={
                        dialog.index === null ? blank : templates[dialog.index]
                    }
                    published={
                        dialog.index !== null &&
                        published.has(templates[dialog.index].id)
                    }
                    others={templates.filter(
                        (_, index) => index !== dialog.index,
                    )}
                    configuration={value}
                    linkLength={linkLength}
                    readOnly={readOnly}
                    onClose={() => setDialog(null)}
                    onApply={(next) =>
                        edit((copy) => {
                            const list = copy.communications!.templates;
                            if (dialog.index === null)
                                list.push({
                                    ...next,
                                    id: referenceFor(
                                        next.label,
                                        list.map((item) => item.id),
                                    ),
                                });
                            else list[dialog.index] = next;
                        })
                    }
                />
            )}
            {dialog?.kind === 'preview' && (
                <PreviewDialog
                    template={templates[dialog.index]}
                    configuration={value}
                    onClose={() => setDialog(null)}
                />
            )}
            <ConfirmDialog
                open={dialog?.kind === 'remove'}
                onClose={() => setDialog(null)}
                onConfirm={() => {
                    if (dialog?.kind !== 'remove') return;
                    const index = dialog.index;
                    edit((copy) => {
                        copy.communications!.templates.splice(index, 1);
                    });
                    setDialog(null);
                }}
                variant="destructive"
                title="Remove this template?"
                description="It hasn’t been published, so no messages use it. Its wording is removed from this draft."
                confirmText="Remove template"
                cancelText="Keep template"
            />
        </div>
    );
}

function TemplateDialog({
    template,
    published,
    others,
    configuration,
    linkLength,
    readOnly,
    onClose,
    onApply,
}: {
    template: MessageTemplate;
    published: boolean;
    others: MessageTemplate[];
    configuration: ReferralConfiguration;
    linkLength: number;
    readOnly: boolean;
    onClose: () => void;
    onApply: (template: MessageTemplate) => void;
}) {
    return (
        <ConfigDialog<MessageTemplate>
            title={
                template.id
                    ? readOnly
                        ? template.label
                        : `Edit “${template.label}”`
                    : 'Add a message template'
            }
            description="Only the merge fields below can be used, so no health, funding or other private details are ever added. Staff check a preview before anything is sent."
            initial={template}
            readOnly={readOnly}
            width={900}
            applyLabel={template.id ? 'Apply to draft' : 'Add template'}
            onClose={onClose}
            validate={(draft) => {
                if (!draft.label.trim()) return 'Give the template a name.';
                if (
                    others.some(
                        (item) =>
                            item.label.trim().toLowerCase() ===
                            draft.label.trim().toLowerCase(),
                    )
                )
                    return 'Another template already has this name.';
                return templateProblem(draft, configuration.public, linkLength);
            }}
            onApply={(draft) =>
                onApply({
                    ...draft,
                    label: draft.label.trim(),
                    subject:
                        draft.channel === 'email'
                            ? (draft.subject ?? '').trim()
                            : null,
                })
            }
        >
            {(draft, set) => (
                <TemplateEditor
                    draft={draft}
                    set={set}
                    published={published}
                    configuration={configuration}
                    linkLength={linkLength}
                    readOnly={readOnly}
                />
            )}
        </ConfigDialog>
    );
}

function TemplateEditor({
    draft,
    set,
    published,
    configuration,
    linkLength,
    readOnly,
}: {
    draft: MessageTemplate;
    set: (next: MessageTemplate) => void;
    published: boolean;
    configuration: ReferralConfiguration;
    linkLength: number;
    readOnly: boolean;
}) {
    const id = useId();
    const subjectRef = useRef<HTMLInputElement>(null);
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const lastFocus = useRef<'subject' | 'body'>('body');
    const sample = sampleValues(configuration.public);
    const lockedReason = published
        ? 'Published templates keep how they’re sent and who they go to. Add a new template instead.'
        : null;
    const insert = (field: MessageField) => {
        const target =
            draft.channel === 'email' && lastFocus.current === 'subject'
                ? 'subject'
                : 'body';
        const element =
            target === 'subject' ? subjectRef.current : bodyRef.current;
        const text = (target === 'subject' ? draft.subject : draft.body) ?? '';
        const start = element?.selectionStart ?? text.length;
        const end = element?.selectionEnd ?? text.length;
        const token = `{${field}}`;
        set({
            ...draft,
            [target]: text.slice(0, start) + token + text.slice(end),
        });
        requestAnimationFrame(() => {
            element?.focus();
            element?.setSelectionRange(
                start + token.length,
                start + token.length,
            );
        });
    };
    const longest = renderTemplate(
        draft.body,
        allowanceValues(configuration.public, linkLength),
    );
    const parts = smsParts(longest);
    const used = new Set(
        templateFields(`${draft.subject ?? ''} ${draft.body}`),
    );

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
            <fieldset disabled={readOnly} className="min-w-0 space-y-4">
                <Field label="Name" required>
                    <Input
                        value={draft.label}
                        maxLength={120}
                        onChange={(event) =>
                            set({ ...draft, label: event.target.value })
                        }
                    />
                </Field>
                <div className="space-y-1.5">
                    <p id={`${id}-channel`} className="text-sm font-medium">
                        Sent by
                    </p>
                    <TilePicker
                        cols={2}
                        labelledBy={`${id}-channel`}
                        value={draft.channel}
                        onChange={(channel) =>
                            !readOnly &&
                            set({
                                ...draft,
                                channel: channel as MessageTemplate['channel'],
                                subject:
                                    channel === 'email'
                                        ? (draft.subject ?? '')
                                        : null,
                            })
                        }
                        options={(['email', 'sms'] as const).map((key) => ({
                            key,
                            label: channelLabels[key],
                            icon: key === 'email' ? Mail : MessageSquareText,
                            description:
                                key === 'email'
                                    ? 'For people whose safe contact is email'
                                    : `For mobiles, up to ${SMS_MAX_PARTS} text parts`,
                            disabled:
                                lockedReason && key !== draft.channel
                                    ? lockedReason
                                    : null,
                        }))}
                    />
                </div>
                <div className="space-y-1.5">
                    <p id={`${id}-audience`} className="text-sm font-medium">
                        Sent to
                    </p>
                    <TilePicker
                        cols={3}
                        labelledBy={`${id}-audience`}
                        value={draft.audience}
                        onChange={(audience) =>
                            !readOnly &&
                            set({
                                ...draft,
                                audience:
                                    audience as MessageTemplate['audience'],
                            })
                        }
                        options={(
                            ['person', 'referrer', 'contact'] as const
                        ).map((key) => ({
                            key,
                            label: audienceLabels[key],
                            icon:
                                key === 'person'
                                    ? UserRound
                                    : key === 'referrer'
                                      ? UserCheck
                                      : Users,
                            description: audienceHints[key],
                            disabled:
                                lockedReason && key !== draft.audience
                                    ? lockedReason
                                    : null,
                        }))}
                    />
                </div>
                {draft.channel === 'email' && (
                    <Field label="Subject" required>
                        <Input
                            ref={subjectRef}
                            value={draft.subject ?? ''}
                            maxLength={EMAIL_SUBJECT_MAX}
                            onFocus={() => {
                                lastFocus.current = 'subject';
                            }}
                            onChange={(event) =>
                                set({ ...draft, subject: event.target.value })
                            }
                        />
                    </Field>
                )}
                <Field label="Message" required>
                    <Textarea
                        ref={bodyRef}
                        rows={9}
                        value={draft.body}
                        maxLength={EMAIL_BODY_MAX}
                        onFocus={() => {
                            lastFocus.current = 'body';
                        }}
                        onChange={(event) =>
                            set({ ...draft, body: event.target.value })
                        }
                    />
                </Field>
                {draft.channel === 'sms' && (
                    <p className="text-caption" aria-live="polite">
                        {`With typical details: ${longest.length} characters · ${parts} of ${SMS_MAX_PARTS} text parts`}
                        {smsWide(longest)
                            ? ' · macrons, curly quotes or emoji make each part shorter'
                            : ''}
                    </p>
                )}
                {!readOnly && (
                    <div className="space-y-2">
                        <p className="text-sm font-medium">
                            Merge fields{' '}
                            <span className="text-caption">
                                Added where your cursor is
                            </span>
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {MESSAGE_FIELDS.map((field) => (
                                <Button
                                    key={field}
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    title={messageFieldInfo[field].hint}
                                    onClick={() => insert(field)}
                                >
                                    <Plus className="size-3.5" />
                                    {messageFieldInfo[field].label}
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
                <div className="flex items-center justify-between gap-4 rounded-lg bg-muted px-4 py-3">
                    <div>
                        <p className="text-sm font-medium">Offered to staff</p>
                        <p className="text-caption">
                            Turned-off templates stay in history.
                        </p>
                    </div>
                    <OnOff
                        label="Offer this template to staff"
                        checked={draft.enabled}
                        disabled={readOnly}
                        onChange={(enabled) => set({ ...draft, enabled })}
                    />
                </div>
            </fieldset>
            <Nested icon={Eye} title="Preview with sample details">
                <NestedRow
                    label="Sent by"
                    value={channelLabels[draft.channel]}
                />
                <NestedRow
                    label="Sent to"
                    value={`${audienceLabels[draft.audience]} (sample: ${sample.first_name})`}
                />
                {draft.channel === 'email' && (
                    <NestedRow
                        label="Subject"
                        value={renderTemplate(draft.subject, sample)}
                    />
                )}
                <p className="mt-3 rounded-md bg-card p-3 text-sm break-words whitespace-pre-wrap">
                    {renderTemplate(draft.body, sample) || (
                        <span className="text-muted-foreground">
                            The message appears here as you write it.
                        </span>
                    )}
                </p>
                <p className="text-caption mt-3">
                    {used.size
                        ? `Uses ${[...used]
                              .map(
                                  (field) =>
                                      messageFieldInfo[field as MessageField]
                                          ?.label ?? `{${field}}`,
                              )
                              .join(', ')
                              .toLowerCase()}.`
                        : 'Uses no merge fields.'}{' '}
                    Sample details only: no real person.
                </p>
            </Nested>
        </div>
    );
}

function PreviewDialog({
    template,
    configuration,
    onClose,
}: {
    template: MessageTemplate;
    configuration: ReferralConfiguration;
    onClose: () => void;
}) {
    const sample = sampleValues(configuration.public);
    const body = renderTemplate(template.body, sample);
    return (
        <SettingsModal
            title={`Preview: ${template.label}`}
            description="Shown with sample details only, never a real person. Staff see the real details before they send."
            width={720}
            onClose={onClose}
        >
            <Nested
                icon={iconFor(template)}
                title={channelLabels[template.channel]}
            >
                <NestedRow
                    label="Sent to"
                    value={audienceLabels[template.audience]}
                />
                <NestedRow
                    label="Offered to staff"
                    value={template.enabled ? 'On' : 'Off'}
                />
                {template.channel === 'email' && (
                    <NestedRow
                        label="Subject"
                        value={renderTemplate(template.subject, sample)}
                    />
                )}
                <p className="mt-3 rounded-md bg-card p-3 text-sm break-words whitespace-pre-wrap">
                    {body}
                </p>
                {template.channel === 'sms' && (
                    <p className="text-caption mt-3">
                        {`${body.length} characters with these sample details · ${smsParts(body)} text ${smsParts(body) === 1 ? 'part' : 'parts'}`}
                    </p>
                )}
            </Nested>
        </SettingsModal>
    );
}
