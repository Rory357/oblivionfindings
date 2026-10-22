import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Field, Segmented } from '@/components/wizard/primitives';
import { formatDateOnly } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    BellRing,
    CircleSlash,
    Eye,
    Flag,
    Lock,
    Mail,
    PauseCircle,
    Plus,
    Users,
    Workflow,
    X,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { SettingsChecklists } from './settings-checklists';
import { MessageTemplateSettings } from './settings-messages';
import {
    defaultWorkflowTransitions,
    editConfiguration,
    referenceFor,
    type Choice,
    type ChoiceList,
    type ReferralConfiguration,
    type SettingsAreaProps,
} from './settings-types';
import {
    AlwaysOn,
    ConfigDialog,
    DialogRow,
    GroupGrid,
    Nested,
    NestedRow,
    Note,
    NumberRow,
    OnOff,
    OverviewGrid,
    SettingGroup,
    SettingRow,
    SwitchRow,
    TextRow,
} from './settings-ui';
import { SettingsWorkflow } from './settings-workflow';

const stageMeaning: Record<string, string> = {
    new: 'Central intake receives and acknowledges the enquiry',
    triage: 'Confirm safe contact, authority and the accountable owner',
    assessment: 'Understand support needs, preferences and suitability',
    funding_assistance: 'Prepare, apply and follow up with the right agency',
    ready_for_handover: 'Handover checks pass and the receiving site is ready',
    on_hold: 'Waiting, with an owner and a scheduled review',
    converted: 'Linked to the person’s record',
    closed: 'Closed with a reason; history is kept',
};
const days = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

type Dialog =
    | { kind: 'calendar' }
    | { kind: 'transitions' }
    | { kind: 'checklists' }
    | { kind: 'stage'; index: number }
    | { kind: 'choice'; list: ChoiceList; index: number | null }
    | { kind: 'preview'; row: Notice };

type Notice = {
    key: string;
    title: string;
    recipients: string;
    inapp: 'always' | 'none';
    email: 'never' | 'personal' | 'setting';
    subject: string;
    body: string;
};

const choiceGroups: {
    list: ChoiceList;
    title: string;
    caption: string;
    noun: string;
    icon: typeof PauseCircle;
}[] = [
    {
        list: 'hold',
        title: 'Hold reasons',
        caption:
            'Why a referral is waiting. Every hold still needs an owner and a review date.',
        noun: 'hold reason',
        icon: PauseCircle,
    },
    {
        list: 'close',
        title: 'Close reasons',
        caption:
            'Why a referral closed. A funding decline doesn’t close a referral on its own.',
        noun: 'close reason',
        icon: CircleSlash,
    },
    {
        list: 'priority',
        title: 'Priorities',
        caption:
            'Help staff sort work. A priority doesn’t create after-hours cover.',
        noun: 'priority',
        icon: Flag,
    },
];

/** Workflow: ownership and targets, stages, reasons, notifications and delivery. */
export function WorkflowSettings({
    value,
    onChange,
    readOnly,
    settings,
    tab,
    jump,
}: SettingsAreaProps) {
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const context = useEntityContextMenu<Notice>();
    const edit = (change: (copy: ReferralConfiguration) => void) =>
        editConfiguration(value, onChange, change);
    const followUp = value.follow_up ?? {
        manager_after_days: 3,
        day_basis: 'calendar' as const,
        office_hours_only: false,
    };
    const calendar = value.business_calendar;
    const transitions =
        value.workflow?.transitions ?? defaultWorkflowTransitions();
    const checklists = value.checklists ?? [];
    const choices = (list: ChoiceList): Choice[] => value.choices?.[list] ?? [];
    const protectedIds = (list: ChoiceList) =>
        settings.protected_choices?.[list] ?? [];
    const builtIn = (list: ChoiceList) =>
        settings.built_in_choices?.[list] ?? [];
    const hours = calendar.opening_time
        ? `${calendar.opening_time} to ${calendar.closing_time}`
        : `Closes ${calendar.closing_time} · opening time not recorded`;
    const workingDays =
        calendar.weekdays
            .slice()
            .sort()
            .map((day) => days[day - 1].slice(0, 3))
            .join(', ') || 'None';
    const notices: Notice[] = [
        {
            key: 'intake',
            title: 'New referral to review',
            recipients:
                'The assigned owner, or everyone who can assign referrals when nobody is assigned yet',
            inapp: 'always',
            email: 'never',
            subject: 'Review referral intake',
            body: 'New work is available in your permitted Referrals queue.',
        },
        {
            key: 'overdue',
            title: 'Overdue follow-up',
            recipients: 'The person who owns the follow-up',
            inapp: 'always',
            email: 'personal',
            subject: 'Referral follow-up needs attention',
            body: 'Open the referral to review the overdue work and agree the next action.',
        },
        {
            key: 'manager',
            title: 'Manager escalation',
            recipients: `Managers who can open the referral, once it is ${plural(followUp.manager_after_days, followUp.day_basis === 'business' ? 'working day' : 'day')} overdue`,
            inapp: 'always',
            email: 'personal',
            subject: 'Referral follow-up needs attention',
            body: 'Open the referral to review the overdue work and agree the next action.',
        },
        {
            key: 'handover',
            title: 'Onboarding handover',
            recipients: 'The onboarding owner for the new person',
            inapp: 'always',
            email: 'never',
            subject: 'Review onboarding handover',
            body: 'New work is available in your permitted onboarding queue.',
        },
        {
            key: 'receipt',
            title: 'Enquiry receipt',
            recipients:
                'The person who enquired, when email is their safe way to be contacted',
            inapp: 'none',
            email: 'setting',
            subject: 'Your enquiry has been received',
            body: 'We received your enquiry. Your reference is shown here. Our team will review it and contact you using the preferences you supplied. This message confirms receipt only. Please do not reply with personal documents.',
        },
    ];

    if (tab === 'overview')
        return (
            <OverviewGrid
                title="Workflow"
                caption="Ownership, targets, stages, reasons and notifications"
                cards={[
                    {
                        key: 'targets',
                        icon: Users,
                        title: 'Ownership and targets',
                        rows: [
                            ['Receiving team', value.intake.team],
                            [
                                'First contact',
                                plural(
                                    value.intake.first_contact_business_days,
                                    'working day',
                                ),
                            ],
                            [
                                'Manager escalation',
                                `After ${plural(followUp.manager_after_days, followUp.day_basis === 'business' ? 'working day' : 'day')}`,
                            ],
                        ],
                        cta: 'Review ownership and targets',
                        onReview: () => jump('workflow', 'configuration'),
                    },
                    {
                        key: 'stages',
                        icon: Workflow,
                        title: 'Stages and checklists',
                        rows: [
                            ['Stages', `${value.stages.length} protected`],
                            ['Allowed stage changes', `${transitions.length}`],
                            [
                                'Checklists',
                                `${checklists.filter((item) => item.enabled).length} in use`,
                            ],
                        ],
                        cta: 'Review stages',
                        onReview: () => jump('workflow', 'configuration'),
                    },
                    {
                        key: 'reasons',
                        icon: PauseCircle,
                        title: 'Reasons and priorities',
                        rows: choiceGroups.map(
                            ({ list, title }): [string, string] => [
                                title,
                                `${choices(list).filter((item) => item.enabled).length} of ${choices(list).length} on`,
                            ],
                        ),
                        cta: 'Review reasons and priorities',
                        onReview: () => jump('workflow', 'configuration'),
                    },
                    {
                        key: 'notifications',
                        icon: BellRing,
                        title: 'Notifications and delivery',
                        rows: [
                            ['In-app notices', 'Always sent to staff'],
                            [
                                'Enquiry receipts by email',
                                value.public.acknowledgement_enabled
                                    ? 'On'
                                    : 'Off',
                            ],
                            [
                                'Reminders',
                                followUp.office_hours_only
                                    ? 'Opening hours only'
                                    : 'Any time',
                            ],
                            [
                                'Message templates',
                                `${(value.communications?.templates ?? []).filter((template) => template.enabled).length} of ${(value.communications?.templates ?? []).length} on`,
                            ],
                        ],
                        cta: 'Review notifications',
                        onReview: () => jump('workflow', 'notifications'),
                    },
                ]}
            />
        );

    if (tab === 'notifications') {
        const actions = (row: Notice): MenuItem[] => [
            {
                label: 'Preview message',
                icon: Eye,
                onClick: () => setDialog({ kind: 'preview', row }),
            },
        ];
        const channel = (row: Notice, which: 'inapp' | 'email'): ReactNode => {
            const mode = which === 'inapp' ? row.inapp : row.email;
            const name = `${row.title} ${which === 'inapp' ? 'in-app' : 'email'}`;
            if (mode === 'none')
                return <span className="text-caption">Not applicable</span>;
            if (mode === 'personal')
                return (
                    <Link
                        href="/settings/notifications"
                        className="text-caption inline-flex items-center gap-1 text-primary hover:underline"
                    >
                        Each person’s choice
                        <ArrowUpRight className="size-3" />
                    </Link>
                );
            if (mode === 'setting')
                return (
                    <OnOff
                        label={name}
                        checked={value.public.acknowledgement_enabled}
                        disabled={readOnly}
                        onChange={(enabled) =>
                            edit((copy) => {
                                copy.public.acknowledgement_enabled = enabled;
                            })
                        }
                    />
                );
            return (
                <span
                    className="inline-flex items-center gap-2"
                    title={
                        mode === 'always'
                            ? 'Always sent so new and overdue work is never missed'
                            : 'Not sent by email'
                    }
                >
                    <OnOff
                        label={`${name} (fixed)`}
                        checked={mode === 'always'}
                        disabled
                        onChange={() => undefined}
                    />
                    <Lock
                        className="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                </span>
            );
        };
        return (
            <div className="space-y-5">
                <ListCaption
                    title="Follow-up notifications"
                    caption={`${notices.length} notices · who receives them is separate from how they’re delivered`}
                />
                <EntityTable<Notice>
                    rows={notices}
                    rowKey={(row) => row.key}
                    identityLabel="Notice"
                    identity={(row) => ({
                        icon: BellRing,
                        name: row.title,
                        subline: row.recipients,
                    })}
                    identityWidth="2.6fr"
                    rowHeight="content"
                    minWidth={760}
                    actionsFor={actions}
                    onOpen={(row) => setDialog({ kind: 'preview', row })}
                    onRowContextMenu={context.open}
                    columns={[
                        {
                            key: 'inapp',
                            label: 'In-app',
                            width: '1fr',
                            cell: (row) => (
                                <div onClick={(e) => e.stopPropagation()}>
                                    {channel(row, 'inapp')}
                                </div>
                            ),
                        },
                        {
                            key: 'email',
                            label: 'Email',
                            width: '1.2fr',
                            cell: (row) => (
                                <div onClick={(e) => e.stopPropagation()}>
                                    {channel(row, 'email')}
                                </div>
                            ),
                        },
                    ]}
                />
                {context.ctx && (
                    <EntityContextMenu
                        x={context.ctx.x}
                        y={context.ctx.y}
                        icon={BellRing}
                        title={context.ctx.record.title}
                        items={actions(context.ctx.record)}
                        onClose={context.close}
                    />
                )}
                <Note>
                    Locked switches are fixed safeguards: staff are always told
                    in the app about new and overdue work. Email copies of
                    reminders follow each person’s own notification choices.
                    Notices never include assessment or funding details.
                </Note>
                {dialog?.kind === 'preview' && (
                    <SettingsModal
                        title={`Preview: ${dialog.row.title}`}
                        description="The wording is fixed and contains no personal, assessment or funding details."
                        onClose={() => setDialog(null)}
                    >
                        <Nested icon={Mail} title={dialog.row.subject}>
                            <NestedRow
                                label="Recipients"
                                value={dialog.row.recipients}
                            />
                            <NestedRow
                                label="Delivery"
                                value={
                                    dialog.row.email === 'setting'
                                        ? value.public.acknowledgement_enabled
                                            ? settings.delivery
                                                  ?.email_configured
                                                ? 'Email'
                                                : 'Email, once sending is set up'
                                            : 'Not sent while receipts are off'
                                        : dialog.row.email === 'personal'
                                          ? 'In the app, plus email if the person chose it'
                                          : 'In the app'
                                }
                            />
                            <NestedRow
                                label="Message"
                                value={dialog.row.body}
                            />
                        </Nested>
                    </SettingsModal>
                )}
            </div>
        );
    }

    if (tab === 'delivery')
        return (
            <div className="space-y-5">
                {/* Approved mockup: the message template sits first on Delivery. */}
                <MessageTemplateSettings
                    value={value}
                    onChange={onChange}
                    readOnly={readOnly}
                    settings={settings}
                    tab={tab}
                    jump={jump}
                />
                <GroupGrid>
                    <SettingGroup
                        icon={Mail}
                        title="Delivery channels"
                        caption="How notices reach people. Who receives them is set on the Notifications tab."
                    >
                        <SettingRow
                            label="Email sending"
                            hint="Set up by your system administrator. Without it, receipts aren’t sent and staff see them as not sent on the referral."
                            control={
                                settings.delivery?.email_configured ? (
                                    <StatusBadge variant="success" size="sm">
                                        Set up
                                    </StatusBadge>
                                ) : (
                                    <StatusBadge variant="warning" size="sm">
                                        Not set up
                                    </StatusBadge>
                                )
                            }
                        />
                        <SettingRow
                            label="Text messages"
                            hint="Staff texts from a referral need an SMS provider, set up by your system administrator. Until then staff can only send emails."
                            control={
                                settings.delivery?.sms_configured ? (
                                    <StatusBadge variant="success" size="sm">
                                        Set up
                                    </StatusBadge>
                                ) : (
                                    <StatusBadge variant="warning" size="sm">
                                        Not set up
                                    </StatusBadge>
                                )
                            }
                        />
                        <SwitchRow
                            label="Send enquiry receipts by email"
                            hint="Only to people who chose email as their safe contact and gave no safe-time limit. Their reference is always shown on screen."
                            checked={value.public.acknowledgement_enabled}
                            disabled={readOnly}
                            onChange={(enabled) =>
                                edit((copy) => {
                                    copy.public.acknowledgement_enabled =
                                        enabled;
                                })
                            }
                        />
                        <SwitchRow
                            label="Send reminders only during opening hours"
                            hint="Overdue reminders wait until the working calendar says you’re open."
                            checked={followUp.office_hours_only}
                            disabled={readOnly}
                            onChange={(office_hours_only) =>
                                edit((copy) => {
                                    copy.follow_up = {
                                        ...followUp,
                                        office_hours_only,
                                    };
                                })
                            }
                        />
                    </SettingGroup>
                    <SettingGroup
                        icon={BellRing}
                        title="Message wording"
                        caption="Notices are short and generic so they’re safe on any screen."
                    >
                        <SettingRow
                            label="Notice wording"
                            hint="Fixed wording with no names, assessment or funding details. Staff messages to people use the message templates above."
                            control={<AlwaysOn>Fixed wording</AlwaysOn>}
                        />
                        <SettingRow
                            label="Failed or held delivery"
                            hint="Quiet hours and failed delivery never remove a follow-up. It stays overdue on the referral until someone acts."
                            control={<AlwaysOn>Always applies</AlwaysOn>}
                        />
                    </SettingGroup>
                </GroupGrid>
            </div>
        );

    return (
        <div className="space-y-5">
            <GroupGrid>
                <SettingGroup
                    icon={Users}
                    title="Ownership and targets"
                    caption="Central intake owns unassigned work until triage. Targets are company targets, not government deadlines."
                >
                    <TextRow
                        label="Receiving team"
                        value={value.intake.team}
                        disabled={readOnly}
                        maxLength={120}
                        onChange={(team) =>
                            edit((copy) => {
                                copy.intake.team = team;
                            })
                        }
                    />
                    <NumberRow
                        label="First contact target"
                        hint="Counted in working days from the working calendar. Due at closing time."
                        unit="working days"
                        min={1}
                        max={30}
                        value={value.intake.first_contact_business_days}
                        disabled={readOnly}
                        onChange={(target) =>
                            edit((copy) => {
                                copy.intake.first_contact_business_days =
                                    target;
                            })
                        }
                    />
                    <NumberRow
                        label="Waiting-case review target"
                        hint="Saved with each version for reporting. Holds don’t use it yet: staff set each hold’s review date themselves."
                        unit="working days"
                        min={1}
                        max={90}
                        value={value.intake.hold_review_business_days}
                        disabled={readOnly}
                        onChange={(target) =>
                            edit((copy) => {
                                copy.intake.hold_review_business_days = target;
                            })
                        }
                    />
                    <NumberRow
                        label="Tell a manager when a follow-up is overdue by"
                        hint="Managers who can open the referral are told in the app."
                        unit={
                            followUp.day_basis === 'business'
                                ? 'working days'
                                : 'days'
                        }
                        min={1}
                        max={30}
                        value={followUp.manager_after_days}
                        disabled={readOnly}
                        onChange={(manager_after_days) =>
                            edit((copy) => {
                                copy.follow_up = {
                                    ...followUp,
                                    manager_after_days,
                                };
                            })
                        }
                    />
                    <SettingRow
                        label="Count overdue time in"
                        hint="Working days skip closed days and holidays."
                        control={
                            <Segmented
                                value={followUp.day_basis}
                                onChange={(day_basis) =>
                                    !readOnly &&
                                    edit((copy) => {
                                        copy.follow_up = {
                                            ...followUp,
                                            day_basis,
                                        };
                                    })
                                }
                                options={[
                                    {
                                        value: 'calendar',
                                        label: 'Calendar days',
                                        disabled: readOnly,
                                    },
                                    {
                                        value: 'business',
                                        label: 'Working days',
                                        disabled: readOnly,
                                    },
                                ]}
                            />
                        }
                    />
                    <DialogRow
                        label="Working calendar"
                        hint={`${workingDays} · ${hours} · ${plural(calendar.holidays.filter(Boolean).length, 'non-working date')}`}
                        action={readOnly ? 'Review calendar' : 'Edit calendar'}
                        onOpen={() => setDialog({ kind: 'calendar' })}
                    />
                </SettingGroup>
                <SettingGroup
                    icon={Workflow}
                    title="Workflow stages"
                    caption="The core stages and their safeguards are protected. You can rename them."
                >
                    {value.stages.map((stage, index) => (
                        <SettingRow
                            key={stage.id}
                            label={
                                <span className="inline-flex items-center gap-2">
                                    <span className="text-caption tabular-nums">
                                        {String(index + 1).padStart(2, '0')}
                                    </span>
                                    {stage.label}
                                </span>
                            }
                            hint={stageMeaning[stage.id]}
                            control={
                                <span className="inline-flex items-center gap-2">
                                    <Lock
                                        className="size-3.5 text-muted-foreground"
                                        aria-label="Protected stage"
                                    />
                                    {!readOnly && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setDialog({
                                                    kind: 'stage',
                                                    index,
                                                })
                                            }
                                        >
                                            Rename
                                        </Button>
                                    )}
                                </span>
                            }
                        />
                    ))}
                    <DialogRow
                        label="Allowed stage changes"
                        hint={`${transitions.length} allowed · roles and evidence each change needs`}
                        action={readOnly ? 'Review changes' : 'Edit changes'}
                        onOpen={() => setDialog({ kind: 'transitions' })}
                    />
                    <DialogRow
                        label="Preparation checklists"
                        hint={`${checklists.filter((item) => item.enabled).length} in use · ${checklists.filter((item) => !item.enabled).length} archived`}
                        action={
                            readOnly ? 'Review checklists' : 'Edit checklists'
                        }
                        onOpen={() => setDialog({ kind: 'checklists' })}
                    />
                </SettingGroup>
                {choiceGroups.map((group) => (
                    <SettingGroup
                        key={group.list}
                        icon={group.icon}
                        title={group.title}
                        caption={group.caption}
                        actions={
                            readOnly ? undefined : (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setDialog({
                                            kind: 'choice',
                                            list: group.list,
                                            index: null,
                                        })
                                    }
                                >
                                    <Plus className="size-4" />
                                    Add {group.noun}
                                </Button>
                            )
                        }
                    >
                        {choices(group.list).map((choice, index) => (
                            <SettingRow
                                key={choice.id}
                                label={choice.label}
                                hint={
                                    builtIn(group.list).includes(choice.id)
                                        ? 'Standard choice'
                                        : 'Added by your organisation'
                                }
                                control={
                                    <span className="inline-flex items-center gap-3">
                                        {protectedIds(group.list).includes(
                                            choice.id,
                                        ) ? (
                                            <AlwaysOn />
                                        ) : (
                                            <OnOff
                                                label={`Offer “${choice.label}”`}
                                                checked={choice.enabled}
                                                disabled={readOnly}
                                                onChange={(enabled) =>
                                                    edit((copy) => {
                                                        copy.choices![
                                                            group.list
                                                        ][index].enabled =
                                                            enabled;
                                                    })
                                                }
                                            />
                                        )}
                                        {!readOnly && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setDialog({
                                                        kind: 'choice',
                                                        list: group.list,
                                                        index,
                                                    })
                                                }
                                            >
                                                Rename
                                            </Button>
                                        )}
                                    </span>
                                }
                            />
                        ))}
                    </SettingGroup>
                ))}
            </GroupGrid>
            <Note>
                Reasons and priorities can be renamed or turned off, never
                removed, because referrals may already use them. Referrals keep
                the choice recorded on them.
            </Note>
            {dialog?.kind === 'calendar' && (
                <CalendarDialog
                    value={calendar}
                    readOnly={readOnly}
                    onClose={() => setDialog(null)}
                    onApply={(next) =>
                        edit((copy) => {
                            copy.business_calendar = next;
                        })
                    }
                />
            )}
            {dialog?.kind === 'transitions' && (
                <ConfigDialog
                    title="Allowed stage changes"
                    description="Each referral follows the rules of the version it started on. A responsible role adds a requirement; it never grants access."
                    initial={value}
                    readOnly={readOnly}
                    width={900}
                    onClose={() => setDialog(null)}
                    onApply={onChange}
                >
                    {(draft, set) => (
                        <SettingsWorkflow
                            embedded
                            value={draft}
                            onChange={set}
                            roles={settings.roles}
                            disabled={readOnly}
                            readOnly={readOnly}
                        />
                    )}
                </ConfigDialog>
            )}
            {dialog?.kind === 'checklists' && (
                <ConfigDialog
                    title="Preparation checklists"
                    description="Reusable preparation steps. They never replace consent, funding or service-start approvals."
                    initial={value}
                    readOnly={readOnly}
                    width={900}
                    onClose={() => setDialog(null)}
                    onApply={onChange}
                >
                    {(draft, set) => (
                        <SettingsChecklists
                            embedded
                            value={draft}
                            onChange={set}
                            roles={settings.roles}
                            disabled={readOnly}
                            readOnly={readOnly}
                        />
                    )}
                </ConfigDialog>
            )}
            {dialog?.kind === 'stage' && (
                <ConfigDialog
                    title="Rename stage"
                    description={`${stageMeaning[value.stages[dialog.index].id]}. The stage’s safeguards stay the same.`}
                    initial={value.stages[dialog.index].label}
                    width={480}
                    onClose={() => setDialog(null)}
                    validate={(label) =>
                        label.trim() ? null : 'Give the stage a name.'
                    }
                    onApply={(label) =>
                        edit((copy) => {
                            copy.stages[dialog.index].label = label.trim();
                        })
                    }
                >
                    {(label, set) => (
                        <Field label="Stage name" required>
                            <Input
                                value={label}
                                maxLength={80}
                                onChange={(event) => set(event.target.value)}
                            />
                        </Field>
                    )}
                </ConfigDialog>
            )}
            {dialog?.kind === 'choice' && (
                <ChoiceDialog
                    group={
                        choiceGroups.find((item) => item.list === dialog.list)!
                    }
                    list={choices(dialog.list)}
                    index={dialog.index}
                    onClose={() => setDialog(null)}
                    onApply={(next) =>
                        edit((copy) => {
                            copy.choices = {
                                hold: choices('hold'),
                                close: choices('close'),
                                priority: choices('priority'),
                                ...copy.choices,
                                [dialog.list]: next,
                            };
                        })
                    }
                />
            )}
        </div>
    );
}

function ChoiceDialog({
    group,
    list,
    index,
    onClose,
    onApply,
}: {
    group: (typeof choiceGroups)[number];
    list: Choice[];
    index: number | null;
    onClose: () => void;
    onApply: (list: Choice[]) => void;
}) {
    const current = index === null ? null : list[index];
    return (
        <ConfigDialog
            title={
                current ? `Rename “${current.label}”` : `Add a ${group.noun}`
            }
            description={
                current
                    ? 'Referrals already using it show the new name.'
                    : 'New choices start turned on. You can turn them off later, but not remove them once published.'
            }
            initial={current?.label ?? ''}
            width={480}
            applyLabel={current ? 'Apply to draft' : `Add ${group.noun}`}
            onClose={onClose}
            validate={(label) =>
                !label.trim()
                    ? 'Give it a name.'
                    : list.some(
                            (item, position) =>
                                position !== index &&
                                item.label.trim().toLowerCase() ===
                                    label.trim().toLowerCase(),
                        )
                      ? 'Another choice already has this name.'
                      : null
            }
            onApply={(label) =>
                onApply(
                    current
                        ? list.map((item, position) =>
                              position === index
                                  ? { ...item, label: label.trim() }
                                  : item,
                          )
                        : [
                              ...list,
                              {
                                  id: referenceFor(
                                      label,
                                      list.map((item) => item.id),
                                  ),
                                  label: label.trim(),
                                  enabled: true,
                              },
                          ],
                )
            }
        >
            {(label, set) => (
                <Field label="Name" required>
                    <Input
                        value={label}
                        maxLength={120}
                        onChange={(event) => set(event.target.value)}
                    />
                </Field>
            )}
        </ConfigDialog>
    );
}

function CalendarDialog({
    value,
    readOnly,
    onClose,
    onApply,
}: {
    value: ReferralConfiguration['business_calendar'];
    readOnly: boolean;
    onClose: () => void;
    onApply: (next: ReferralConfiguration['business_calendar']) => void;
}) {
    return (
        <ConfigDialog
            title="Working calendar"
            description="Targets and reminders count working days and opening hours from this calendar. Due dates already recorded stay as they are."
            initial={{
                ...value,
                holidays: value.holidays.filter((date) =>
                    /^\d{4}-\d{2}-\d{2}$/.test(date.trim()),
                ),
            }}
            readOnly={readOnly}
            onClose={onClose}
            validate={(draft) => {
                if (!draft.weekdays.length)
                    return 'Choose at least one working day.';
                if (
                    draft.opening_time &&
                    draft.opening_time >= draft.closing_time
                )
                    return 'Choose a closing time after the opening time.';
                return null;
            }}
            onApply={(draft) => {
                // "Not recorded" stays absent: writing null over a missing key would show as an unsaved change.
                const { opening_time, ...rest } = draft;
                onApply({
                    ...rest,
                    ...(opening_time
                        ? { opening_time }
                        : 'opening_time' in value
                          ? { opening_time: null }
                          : {}),
                    holidays: [...new Set(draft.holidays)].sort(),
                });
            }}
        >
            {(draft, set) => (
                <>
                    <Field label="Time zone">
                        <Segmented
                            value={draft.timezone}
                            onChange={(timezone) =>
                                !readOnly && set({ ...draft, timezone })
                            }
                            options={[
                                {
                                    value: 'Pacific/Auckland',
                                    label: 'New Zealand mainland',
                                    disabled: readOnly,
                                },
                                {
                                    value: 'Pacific/Chatham',
                                    label: 'Chatham Islands',
                                    disabled: readOnly,
                                },
                            ]}
                        />
                    </Field>
                    <fieldset
                        disabled={readOnly}
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        <div className="space-y-1.5">
                            <p className="text-[13px] font-medium">
                                Opening time{' '}
                                <span className="text-caption">
                                    Needed for working-day reminders
                                </span>
                            </p>
                            <TimePicker
                                id="calendar-opening"
                                label="Opening time"
                                value={draft.opening_time ?? ''}
                                onChange={(opening_time) =>
                                    set({ ...draft, opening_time })
                                }
                            />
                            {draft.opening_time && !readOnly ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        set({ ...draft, opening_time: null })
                                    }
                                >
                                    Opening time not known
                                </Button>
                            ) : !draft.opening_time ? (
                                <p className="text-caption">Not recorded</p>
                            ) : null}
                        </div>
                        <div className="space-y-1.5">
                            <p className="text-[13px] font-medium">
                                Closing time
                            </p>
                            <TimePicker
                                id="calendar-closing"
                                label="Closing time"
                                value={draft.closing_time}
                                onChange={(closing_time) =>
                                    set({ ...draft, closing_time })
                                }
                            />
                        </div>
                    </fieldset>
                    <fieldset disabled={readOnly} className="space-y-2">
                        <legend className="text-[13px] font-medium">
                            Working days
                        </legend>
                        <div className="flex flex-wrap gap-x-5 gap-y-2">
                            {days.map((day, index) => (
                                <label
                                    key={day}
                                    className="text-subtle flex items-center gap-2"
                                >
                                    <Checkbox
                                        checked={draft.weekdays.includes(
                                            index + 1,
                                        )}
                                        onCheckedChange={(checked) =>
                                            set({
                                                ...draft,
                                                weekdays:
                                                    checked === true
                                                        ? [
                                                              ...draft.weekdays,
                                                              index + 1,
                                                          ].sort()
                                                        : draft.weekdays.filter(
                                                              (item) =>
                                                                  item !==
                                                                  index + 1,
                                                          ),
                                            })
                                        }
                                    />
                                    {day}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <NonWorkingDates
                        dates={draft.holidays}
                        readOnly={readOnly}
                        onChange={(holidays) => set({ ...draft, holidays })}
                    />
                </>
            )}
        </ConfigDialog>
    );
}

/** Non-working dates, picked with the approved date picker and listed in date order. */
function NonWorkingDates({
    dates,
    readOnly,
    onChange,
}: {
    dates: string[];
    readOnly: boolean;
    onChange: (dates: string[]) => void;
}) {
    const [picked, setPicked] = useState('');
    return (
        <div className="space-y-2">
            <p className="text-[13px] font-medium">
                Non-working dates{' '}
                <span className="text-caption">
                    Include the public holidays you observe
                </span>
            </p>
            {dates.length ? (
                <ul className="flex flex-wrap gap-2">
                    {[...dates].sort().map((date) => (
                        <li
                            key={date}
                            className="inline-flex items-center gap-1 rounded-md bg-muted py-1 pr-1 pl-2.5 text-[13px]"
                        >
                            {formatDateOnly(date)}
                            {!readOnly && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-6 px-1.5"
                                    aria-label={`Remove ${formatDateOnly(date)}`}
                                    onClick={() =>
                                        onChange(
                                            dates.filter(
                                                (item) => item !== date,
                                            ),
                                        )
                                    }
                                >
                                    <X className="size-3.5" />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-caption">No non-working dates yet.</p>
            )}
            {!readOnly && (
                <DatePicker
                    id="calendar-add-date"
                    label="Add a non-working date"
                    value={picked}
                    onChange={(date) => {
                        if (date && !dates.includes(date))
                            onChange([...dates, date]);
                        setPicked('');
                    }}
                />
            )}
        </div>
    );
}
