import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import {
    DateTimeField,
    localDateTimeLabel,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ClipboardList,
    FileText,
    History,
    ListChecks,
    MessageSquareText,
    Siren,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import {
    Choices,
    HARMS,
    labelFor,
    nzLocal,
    personName,
    Picker,
    REACH,
    Stage,
    TYPES,
    type ErrorRecord,
    type Person,
} from './_shared';

type Command =
    | 'review'
    | 'notes'
    | 'resolve'
    | 'actions'
    | 'disclosure'
    | 'close'
    | 'reopen'
    | 'accounts'
    | 'complete'
    | 'link-incident';
type CommandData = Record<string, string | number | string[] | null>;
const titles: Record<Command, string> = {
    review: 'Triage the error',
    notes: 'Add an investigation note',
    resolve: 'Record the investigation outcome',
    actions: 'Add an action',
    disclosure: 'Telling the person',
    close: 'Close the error',
    reopen: 'Reopen the error',
    accounts: 'Add your account',
    complete: 'Complete the action',
    'link-incident': 'Raise a linked incident',
};
const SECTIONS = [
    {
        key: 'report',
        label: 'This error',
        blurb: 'What happened',
        icon: AlertTriangle,
    },
    {
        key: 'accounts',
        label: 'Accounts',
        blurb: 'Added, never edited',
        icon: MessageSquareText,
    },
    {
        key: 'triage',
        label: 'Triage',
        blurb: 'Owner and due time',
        icon: ClipboardList,
    },
    {
        key: 'notes',
        label: 'Investigation',
        blurb: 'Notes and outcome',
        icon: FileText,
    },
    {
        key: 'actions',
        label: 'Actions',
        blurb: 'Accountable next steps',
        icon: ListChecks,
    },
    {
        key: 'disclosure',
        label: 'Telling the person',
        blurb: 'Who, when and how',
        icon: UserRound,
    },
    {
        key: 'incident',
        label: 'Incident',
        blurb: 'Its own close checks',
        icon: Siren,
    },
    {
        key: 'history',
        label: 'History',
        blurb: 'Every step, kept',
        icon: History,
    },
];
export function ErrorDetail({
    error: e,
    canManage,
    canReadInvestigation,
    canRecord,
    staff,
    onClose,
}: {
    error: ErrorRecord;
    canManage: boolean;
    canReadInvestigation: boolean;
    canRecord: boolean;
    staff: Person[];
    onClose: () => void;
}) {
    const [section, setSection] = useState(0);
    const [command, setCommand] = useState<Command | null>(null);
    const [review, setReview] = useState(false);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [data, setData] = useState<CommandData>({});
    const [initialData, setInitialData] = useState<CommandData>({});
    const [pendingLeave, setPendingLeave] = useState<
        'dialog' | 'command' | null
    >(null);
    const [actionId, setActionId] = useState<number | null>(null);
    const [confirm, setConfirm] = useState(false);
    const [preview, setPreview] = useState<PreviewFile | null>(null);
    const field = (key: string) =>
        typeof data[key] === 'string' ? (data[key] as string) : '';
    const set = (key: string, value: string | number | string[]) =>
        setData((d) => ({ ...d, [key]: value }));
    const start = (kind: Command, action?: number) => {
        setCommand(kind);
        setReview(false);
        setErrors({});
        setActionId(action ?? null);
        const nextData: CommandData =
            kind === 'review'
                ? {
                      error_type: e.error_type,
                      owner_id: e.owner?.id ?? null,
                      investigation_due_at: nzLocal(e.investigation_due_at),
                      reached_client: e.reached_client ?? 'unknown',
                      harm_level: HARMS.some(([v]) => v === e.harm_level)
                          ? e.harm_level
                          : 'unknown',
                  }
                : kind === 'actions'
                  ? { owner_id: e.owner?.id ?? null, due_at: '' }
                  : kind === 'disclosure'
                    ? { state: 'not_yet', who: [] }
                    : {};
        setData(nextData);
        setInitialData(nextData);
    };
    const finishLeave = (target: 'dialog' | 'command') => {
        if (saving) return;
        setPendingLeave(null);
        if (target === 'dialog') onClose();
        else {
            setCommand(null);
            setReview(false);
            setErrors({});
        }
    };
    const requestLeave = (target: 'dialog' | 'command') => {
        if (saving) return;
        if (command && JSON.stringify(data) !== JSON.stringify(initialData)) {
            setPendingLeave(target);
        } else finishLeave(target);
    };
    const submit = () => {
        if (!command) return;
        if (!navigator.onLine) {
            setErrors({
                connection:
                    'You’re offline. Reconnect to save; your entries stay here.',
            });
            return;
        }
        setSaving(true);
        const endpoint =
            command === 'complete' ? `actions/${actionId}/complete` : command;
        router.post(`/emar/errors/${e.id}/${endpoint}`, data, {
            preserveScroll: true,
            onSuccess: () => {
                setCommand(null);
                setReview(false);
                setConfirm(false);
            },
            onError: (errs) => {
                setErrors(errs);
                setReview(false);
                setConfirm(false);
            },
            onFinish: () => setSaving(false),
        });
    };
    const text = (key: string, label: string, required = true) => (
        <div>
            <Label htmlFor={`error-${key}`}>
                {label}
                {required ? '' : ' (optional)'}
            </Label>
            <Textarea
                id={`error-${key}`}
                value={field(key)}
                onChange={(ev) => set(key, ev.target.value)}
                maxLength={5000}
                rows={4}
            />
        </div>
    );
    const current = SECTIONS[section].key;
    const opened = e.stage !== 'closed';
    const accounts = e.entries.filter((entry) =>
        ['reported', 'account'].includes(entry.kind),
    );
    const disclosure = e.entries
        .filter((entry) => entry.kind === 'disclosure')
        .at(-1);
    const drawEntries = (kinds: string[]) =>
        e.entries
            .filter((entry) => kinds.includes(entry.kind))
            .map((entry) => (
                <ReviewCard
                    key={entry.id}
                    icon={MessageSquareText}
                    title={`${entry.by} · ${formatDateTime(entry.at)}`}
                >
                    <p className="text-sm break-words whitespace-pre-wrap">
                        {entry.text}
                    </p>
                    {entry.data.immediate_action ? (
                        <ReviewRow
                            label="Immediate action"
                            value={String(entry.data.immediate_action)}
                        />
                    ) : null}
                    {entry.data.preventive_actions ? (
                        <ReviewRow
                            label="Preventive actions"
                            value={String(entry.data.preventive_actions)}
                        />
                    ) : null}
                </ReviewCard>
            ));
    const form = () => (
        <div className="flex flex-col gap-5">
            {command === 'review' ? (
                <>
                    <Choices
                        label="What went wrong?"
                        value={field('error_type')}
                        options={TYPES}
                        onChange={(value) => set('error_type', value)}
                    />
                    <Choices
                        label="Did it reach the person?"
                        value={field('reached_client')}
                        options={REACH}
                        onChange={(v) => set('reached_client', v)}
                    />
                    <Choices
                        label="How much harm?"
                        value={field('harm_level')}
                        options={HARMS}
                        onChange={(v) => set('harm_level', v)}
                    />
                    <Picker
                        label="Investigation owner"
                        value={
                            typeof data.owner_id === 'number'
                                ? data.owner_id
                                : null
                        }
                        options={staff}
                        onChange={(id) => set('owner_id', id)}
                    />
                    <DateTimeField
                        id="investigation-due"
                        label="Investigation due"
                        value={field('investigation_due_at')}
                        onChange={(v) => set('investigation_due_at', v)}
                    />
                    {text('review_notes', 'Triage note')}
                    {!e.incident && (
                        <>
                            {text(
                                'immediate_action',
                                'Immediate action taken',
                                false,
                            )}
                            <p className="text-caption">
                                If the harm requires a serious linked incident,
                                record what was actually done before raising it.
                            </p>
                        </>
                    )}
                </>
            ) : null}
            {command === 'notes' || command === 'accounts'
                ? text(
                      'text',
                      command === 'accounts'
                          ? 'Your account'
                          : 'Investigation note',
                  )
                : null}
            {command === 'resolve' ? (
                <>
                    {text('outcome', 'What did the investigation find?')}
                    {text(
                        'preventive_actions',
                        'What will prevent this happening again?',
                    )}
                </>
            ) : null}
            {command === 'actions' ? (
                <>
                    {text('description', 'What needs to be done?')}
                    <Picker
                        label="Action owner"
                        value={
                            typeof data.owner_id === 'number'
                                ? data.owner_id
                                : null
                        }
                        options={staff}
                        onChange={(id) => set('owner_id', id)}
                    />
                    <DateTimeField
                        id="action-due"
                        label="Action due"
                        value={field('due_at')}
                        onChange={(v) => set('due_at', v)}
                    />
                </>
            ) : null}
            {command === 'link-incident' ? (
                <>
                    {text('immediate_action', 'Immediate action taken', false)}
                    <p className="text-caption">
                        A serious linked incident needs an actual immediate
                        action in this record. The incident receives a neutral
                        summary.
                    </p>
                </>
            ) : null}
            {command === 'complete'
                ? text('completion_note', 'What was done?')
                : null}
            {command === 'close' ? (
                <>
                    {text('close_note', 'Close note')}
                    {e.sac.enabled && e.reached_client !== 'no' ? (
                        <>
                            <Choices
                                label="Confirm the SAC rating"
                                value={field('confirmed_sac')}
                                options={
                                    (e.harm_level === 'severe'
                                        ? [
                                              ['1', 'SAC 1'],
                                              ['2', 'SAC 2'],
                                          ]
                                        : [
                                              ['1', 'SAC 1'],
                                              ['2', 'SAC 2'],
                                              ['3', 'SAC 3'],
                                              ['4', 'SAC 4'],
                                          ]) as [string, string][]
                                }
                                onChange={(value) =>
                                    set('confirmed_sac', value)
                                }
                            />
                            <p className="text-caption">
                                {e.sac.proposed
                                    ? `Settings suggests SAC ${e.sac.proposed}. Confirm the rating for this error; the suggestion is not a confirmation.`
                                    : e.harm_level === 'severe'
                                      ? 'For severe or permanent harm, explicitly confirm SAC 1 or SAC 2.'
                                      : 'Confirm the rating for this error.'}
                            </p>
                        </>
                    ) : null}
                    <p className="text-caption">
                        The note stays in this error. A linked incident receives
                        a neutral summary and closes only when its own checks
                        pass. This error can be reopened with a reason.
                    </p>
                </>
            ) : null}
            {command === 'reopen'
                ? text('reason', 'Why does this need to be reopened?')
                : null}
            {command === 'disclosure' ? (
                <>
                    <Choices
                        label="Has the person been told?"
                        value={field('state')}
                        options={[
                            ['not_yet', 'Not yet'],
                            ['told', 'Told'],
                        ]}
                        onChange={(v) => set('state', v)}
                    />
                    {field('state') === 'told' ? (
                        <>
                            <fieldset>
                                <legend className="text-sm font-medium">
                                    Who was told?
                                </legend>
                                {[
                                    ['person', 'The person'],
                                    ['whanau', 'Whānau'],
                                ].map(([key, label]) => (
                                    <Label
                                        key={key}
                                        className="frontline-tap flex items-center gap-2"
                                    >
                                        <Checkbox
                                            checked={
                                                Array.isArray(data.who) &&
                                                data.who.includes(key)
                                            }
                                            onCheckedChange={(checked) =>
                                                set(
                                                    'who',
                                                    checked
                                                        ? [
                                                              ...(Array.isArray(
                                                                  data.who,
                                                              )
                                                                  ? data.who
                                                                  : []),
                                                              key,
                                                          ]
                                                        : (Array.isArray(
                                                              data.who,
                                                          )
                                                              ? data.who
                                                              : []
                                                          ).filter(
                                                              (v) => v !== key,
                                                          ),
                                                )
                                            }
                                        />
                                        {label}
                                    </Label>
                                ))}
                            </fieldset>
                            {Array.isArray(data.who) &&
                                data.who.includes('whanau') && (
                                    <div>
                                        <Label htmlFor="whanau-name">
                                            Whānau member’s name
                                        </Label>
                                        <Input
                                            id="whanau-name"
                                            value={field('whanau_name')}
                                            onChange={(ev) =>
                                                set(
                                                    'whanau_name',
                                                    ev.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                )}
                            <div>
                                <Label htmlFor="disclosure-by">
                                    Who told them?
                                </Label>
                                <Input
                                    id="disclosure-by"
                                    value={field('by')}
                                    onChange={(ev) =>
                                        set('by', ev.target.value)
                                    }
                                />
                            </div>
                            <DateTimeField
                                id="disclosure-at"
                                label="When they were told"
                                value={field('at')}
                                onChange={(v) => set('at', v)}
                            />
                            {text('how', 'How were they told?')}
                        </>
                    ) : (
                        text('reason', 'Why not yet?')
                    )}
                </>
            ) : null}
        </div>
    );
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={() => requestLeave('dialog')}
                title={`${e.ref} · ${personName(e)}`}
                description="Medication error record and append-only investigation history."
                railIcon={AlertTriangle}
                railTitle={e.ref}
                railSub={personName(e)}
                steps={
                    command
                        ? [
                              {
                                  key: 'details',
                                  label: titles[command],
                                  blurb: 'Record the details',
                                  icon: FileText,
                              },
                              {
                                  key: 'review',
                                  label: 'Review',
                                  blurb: 'Check and save',
                                  icon: Check,
                              },
                          ]
                        : SECTIONS
                }
                stepIndex={command ? (review ? 1 : 0) : section}
                onStepClick={(i) => {
                    if (!saving) {
                        if (command) setReview(false);
                        else setSection(i);
                    }
                }}
                sequential={!!command}
                headerLabel={!command ? SECTIONS[section].label : undefined}
                pct={command ? (review ? 100 : 50) : 100}
                pctLabel={command ? 'Completeness' : 'Record'}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={saving}
                        onClick={() =>
                            command
                                ? review
                                    ? setReview(false)
                                    : requestLeave('command')
                                : requestLeave('dialog')
                        }
                    >
                        {command ? 'Back' : 'Done'}
                    </Button>
                }
                footerEnd={
                    command ? (
                        <Button
                            disabled={
                                saving ||
                                (command === 'close' &&
                                    e.sac.enabled &&
                                    e.reached_client !== 'no' &&
                                    !field('confirmed_sac'))
                            }
                            onClick={() =>
                                review
                                    ? command === 'close'
                                        ? setConfirm(true)
                                        : submit()
                                    : setReview(true)
                            }
                        >
                            {saving ? 'Saving…' : review ? 'Save' : 'Review'}
                        </Button>
                    ) : canManage && opened ? (
                        <Button
                            disabled={
                                !e.can_close || e.close_blockers.length > 0
                            }
                            onClick={() => start('close')}
                        >
                            Close error
                        </Button>
                    ) : e.stage === 'closed' && e.can_reopen ? (
                        <Button
                            variant="outline"
                            onClick={() => start('reopen')}
                        >
                            Reopen with a reason
                        </Button>
                    ) : null
                }
            >
                <WizardStepPane>
                    <div className="flex flex-col gap-5">
                        {Object.keys(errors).length > 0 && (
                            <div role="alert" className="text-status-critical">
                                {Object.values(errors).map((error, i) => (
                                    <p key={i}>{error}</p>
                                ))}
                            </div>
                        )}
                        {command ? (
                            review ? (
                                <ReviewCard
                                    icon={FileText}
                                    title={titles[command]}
                                    onEdit={() => setReview(false)}
                                >
                                    {Object.entries(data)
                                        .filter(
                                            ([, v]) =>
                                                v !== null &&
                                                v !== '' &&
                                                (!Array.isArray(v) ||
                                                    v.length > 0),
                                        )
                                        .map(([key, value]) => (
                                            <ReviewRow
                                                key={key}
                                                label={key.replaceAll('_', ' ')}
                                                value={
                                                    key === 'owner_id'
                                                        ? staff.find(
                                                              (p) =>
                                                                  p.id ===
                                                                  value,
                                                          )?.name
                                                        : key ===
                                                            'reached_client'
                                                          ? labelFor(
                                                                REACH,
                                                                String(value),
                                                            )
                                                          : key === 'harm_level'
                                                            ? labelFor(
                                                                  HARMS,
                                                                  String(value),
                                                              )
                                                            : [
                                                                    'due_at',
                                                                    'investigation_due_at',
                                                                    'at',
                                                                ].includes(key)
                                                              ? localDateTimeLabel(
                                                                    String(
                                                                        value,
                                                                    ),
                                                                )
                                                              : Array.isArray(
                                                                      value,
                                                                  )
                                                                ? value
                                                                      .map(
                                                                          (
                                                                              v,
                                                                          ) =>
                                                                              v ===
                                                                              'person'
                                                                                  ? 'The person'
                                                                                  : 'Whānau',
                                                                      )
                                                                      .join(
                                                                          ', ',
                                                                      )
                                                                : String(value)
                                                }
                                            />
                                        ))}
                                </ReviewCard>
                            ) : (
                                form()
                            )
                        ) : (
                            <>
                                {current === 'report' && (
                                    <>
                                        <ReviewCard
                                            icon={AlertTriangle}
                                            title={labelFor(
                                                TYPES,
                                                e.error_type,
                                            )}
                                        >
                                            <ReviewRow
                                                label="Person"
                                                value={personName(e)}
                                            />
                                            <ReviewRow
                                                label="Medicine"
                                                value={
                                                    e.medication?.name ??
                                                    'Not linked to one chart medicine'
                                                }
                                            />
                                            <ReviewRow
                                                label="Happened"
                                                value={formatDateTime(
                                                    e.occurred_at,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Reported"
                                                value={`${e.reported_by_user?.name ?? 'Unknown'} · ${formatDateTime(e.reported_at)}`}
                                            />
                                            <ReviewRow
                                                label="Reach"
                                                value={labelFor(
                                                    REACH,
                                                    e.reached_client,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Harm"
                                                value={labelFor(
                                                    HARMS,
                                                    e.harm_level,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Stage"
                                                value={
                                                    <Stage value={e.stage} />
                                                }
                                            />
                                            <p className="mt-3 text-sm break-words whitespace-pre-wrap">
                                                {e.description}
                                            </p>
                                            <ReviewRow
                                                label="Immediate action"
                                                value={e.immediate_action}
                                            />
                                            <ReviewRow
                                                label="Contributing factors"
                                                value={e.contributing_factors}
                                            />
                                        </ReviewCard>
                                        {e.mar_url && (
                                            <Button variant="outline" asChild>
                                                <a href={e.mar_url}>
                                                    Open medication record
                                                </a>
                                            </Button>
                                        )}
                                        {e.attachments.map((file) => (
                                            <div
                                                key={file.id}
                                                className="flex flex-wrap items-center gap-2"
                                            >
                                                <span className="min-w-0 break-words">
                                                    {file.file_name}
                                                </span>
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        setPreview({
                                                            id: file.id,
                                                            name: file.file_name,
                                                            mime: file.mime_type,
                                                            bytes: file.file_size,
                                                            previewUrl:
                                                                file.download_url,
                                                            downloadUrl:
                                                                file.download_url,
                                                        })
                                                    }
                                                >
                                                    View
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <a href={file.download_url}>
                                                        Download
                                                    </a>
                                                </Button>
                                            </div>
                                        ))}
                                    </>
                                )}
                                {current === 'accounts' && (
                                    <>
                                        {accounts.length ? (
                                            drawEntries(['reported', 'account'])
                                        ) : (
                                            <p className="text-sm break-words whitespace-pre-wrap">
                                                {e.description}
                                            </p>
                                        )}
                                        {opened && canRecord && (
                                            <Button
                                                onClick={() =>
                                                    start('accounts')
                                                }
                                            >
                                                Add your account
                                            </Button>
                                        )}
                                    </>
                                )}
                                {current === 'triage' && (
                                    <>
                                        <ReviewCard
                                            icon={ClipboardList}
                                            title="Accountability"
                                        >
                                            <ReviewRow
                                                label="Triage due"
                                                value={formatDateTime(
                                                    e.triage_due_at,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Owner"
                                                value={
                                                    e.owner?.name ??
                                                    'Not assigned'
                                                }
                                            />
                                            <ReviewRow
                                                label="Investigation due"
                                                value={formatDateTime(
                                                    e.investigation_due_at,
                                                )}
                                            />
                                        </ReviewCard>
                                        {drawEntries(['triaged'])}
                                        {canManage && opened && (
                                            <Button
                                                onClick={() => start('review')}
                                            >
                                                {e.owner
                                                    ? 'Update triage / owner'
                                                    : 'Triage the error'}
                                            </Button>
                                        )}
                                    </>
                                )}
                                {current === 'notes' && (
                                    <>
                                        {canReadInvestigation ? (
                                            <>
                                                {drawEntries([
                                                    'note',
                                                    'investigation_completed',
                                                    'immediate_action',
                                                ])}
                                                {e.review_notes && (
                                                    <ReviewCard
                                                        icon={History}
                                                        title="Historical review"
                                                    >
                                                        <p className="break-words whitespace-pre-wrap">
                                                            {e.review_notes}
                                                        </p>
                                                        <ReviewRow
                                                            label="Outcome"
                                                            value={e.outcome}
                                                        />
                                                        <ReviewRow
                                                            label="Preventive actions"
                                                            value={
                                                                e.preventive_actions
                                                            }
                                                        />
                                                    </ReviewCard>
                                                )}
                                                {opened && canManage && (
                                                    <div className="flex flex-wrap gap-2">
                                                        <Button
                                                            onClick={() =>
                                                                start('notes')
                                                            }
                                                        >
                                                            Add a note
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            disabled={!e.owner}
                                                            onClick={() =>
                                                                start('resolve')
                                                            }
                                                        >
                                                            Record outcome
                                                        </Button>
                                                    </div>
                                                )}
                                            </>
                                        ) : (
                                            <p className="text-subtle">
                                                The owner looks into this error.
                                                Investigation details are held
                                                in the permitted record.
                                            </p>
                                        )}
                                    </>
                                )}
                                {current === 'actions' && (
                                    <>
                                        {e.actions.length ? (
                                            e.actions.map((a) => (
                                                <ReviewCard
                                                    key={a.id}
                                                    icon={ListChecks}
                                                    title={`Action ${a.id}`}
                                                >
                                                    <p className="text-sm break-words whitespace-pre-wrap">
                                                        {a.description}
                                                    </p>
                                                    <ReviewRow
                                                        label="Owner"
                                                        value={a.owner?.name}
                                                    />
                                                    <ReviewRow
                                                        label="Due"
                                                        value={formatDateTime(
                                                            a.due_at,
                                                        )}
                                                    />
                                                    <ReviewRow
                                                        label="Completed"
                                                        value={formatDateTime(
                                                            a.completed_at,
                                                        )}
                                                    />
                                                    {a.completion_note && (
                                                        <ReviewRow
                                                            label="What was done"
                                                            value={
                                                                a.completion_note
                                                            }
                                                        />
                                                    )}
                                                    {!a.completed_at &&
                                                        canManage &&
                                                        opened && (
                                                            <Button
                                                                onClick={() =>
                                                                    start(
                                                                        'complete',
                                                                        a.id,
                                                                    )
                                                                }
                                                            >
                                                                Mark done
                                                            </Button>
                                                        )}
                                                </ReviewCard>
                                            ))
                                        ) : (
                                            <p className="text-subtle">
                                                No actions recorded yet.
                                            </p>
                                        )}
                                        {canManage && opened && (
                                            <Button
                                                disabled={!e.owner}
                                                onClick={() => start('actions')}
                                            >
                                                Add an action
                                            </Button>
                                        )}
                                    </>
                                )}
                                {current === 'disclosure' && (
                                    <>
                                        <ReviewCard
                                            icon={UserRound}
                                            title="Telling the person"
                                        >
                                            <ReviewRow
                                                label="Status"
                                                value={
                                                    e.reached_client === 'no'
                                                        ? 'Not needed — near miss'
                                                        : disclosure?.data
                                                                .state ===
                                                            'told'
                                                          ? 'Told'
                                                          : 'Not recorded as told'
                                                }
                                            />
                                            {disclosure &&
                                                Object.entries(
                                                    disclosure.data,
                                                ).map(([key, value]) => (
                                                    <ReviewRow
                                                        key={key}
                                                        label={key.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}
                                                        value={
                                                            Array.isArray(value)
                                                                ? value.join(
                                                                      ', ',
                                                                  )
                                                                : String(
                                                                      value ??
                                                                          '',
                                                                  )
                                                        }
                                                    />
                                                ))}
                                        </ReviewCard>
                                        {canManage && opened && (
                                            <Button
                                                onClick={() =>
                                                    start('disclosure')
                                                }
                                            >
                                                Record telling the person
                                            </Button>
                                        )}
                                    </>
                                )}
                                {current === 'incident' && (
                                    <>
                                        <p className="text-subtle">
                                            {e.incident
                                                ? e.incident.ready_to_close
                                                    ? 'Ready to close — medication error closed. Incidents still applies its own checks.'
                                                    : `Linked incident ${e.incident.ref} · ${e.incident.status}`
                                                : 'No linked incident.'}
                                        </p>
                                        {e.incident ? (
                                            <Button variant="outline" asChild>
                                                <a
                                                    href={`/incidents/${e.incident.id}`}
                                                >
                                                    Open in Incidents
                                                </a>
                                            </Button>
                                        ) : (
                                            canManage &&
                                            opened && (
                                                <Button
                                                    onClick={() =>
                                                        start('link-incident')
                                                    }
                                                >
                                                    Raise one linked incident
                                                </Button>
                                            )
                                        )}
                                    </>
                                )}
                                {current === 'history' && (
                                    <>
                                        <p className="text-caption">
                                            Historical fields stay intact. Every
                                            new account, note and workflow
                                            change is appended.
                                        </p>
                                        {e.entries.map((entry) => (
                                            <ReviewCard
                                                key={entry.id}
                                                icon={History}
                                                title={`${entry.kind.replaceAll('_', ' ')} · ${entry.by}`}
                                            >
                                                <ReviewRow
                                                    label="Recorded"
                                                    value={formatDateTime(
                                                        entry.at,
                                                    )}
                                                />
                                                {[
                                                    'closed',
                                                    'reopened',
                                                    'separate_report',
                                                ].includes(entry.kind) && (
                                                    <p className="text-sm break-words whitespace-pre-wrap">
                                                        {entry.text}
                                                    </p>
                                                )}
                                            </ReviewCard>
                                        ))}
                                    </>
                                )}
                                {canManage &&
                                    opened &&
                                    e.close_blockers.length > 0 && (
                                        <ReviewCard
                                            icon={AlertTriangle}
                                            title="Before closing"
                                        >
                                            {e.close_blockers.map((blocker) => (
                                                <p
                                                    className="text-sm"
                                                    key={blocker}
                                                >
                                                    {blocker}
                                                </p>
                                            ))}
                                        </ReviewCard>
                                    )}
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog
                frontline
                open={pendingLeave !== null}
                description="Your saved error record is kept. Only the unsaved details you entered here will be discarded."
                onKeepEditing={() => setPendingLeave(null)}
                onDiscard={() => {
                    if (pendingLeave) finishLeave(pendingLeave);
                }}
            />
            <ConfirmDialog
                open={confirm}
                onClose={() => {
                    if (!saving) setConfirm(false);
                }}
                onConfirm={submit}
                processing={saving}
                variant="default"
                title="Close this error?"
                description="The error is kept with its history. The linked incident closes only if Incidents permits it and every blocker is cleared. You can reopen the error with a reason."
                confirmText="Close error"
            />
            <FilePreviewDialog
                file={preview}
                onClose={() => setPreview(null)}
            />
        </>
    );
}
