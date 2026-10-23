import { Button } from '@/components/ui/button';
import { Choices, KnowList, Log, Note, QuietLine, Surface } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    useSafetyDraftState,
    useSafetyForm,
} from '@/hooks/use-safety-workspace-drafts';
import { formatDate, formatDateTimeLong } from '@/lib/datetime';
import { AlertCircle, CheckCircle2, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    confirmsIndependenceReceipt,
    independenceModeLabel,
    type IndependenceCommand,
    type IndependenceHistory,
    type IndependenceMode,
    type IndependencePolicy,
} from './incident-independence';

type Props = {
    policy: IndependencePolicy;
    history: IndependenceHistory;
    canManage: boolean;
    actorId: number;
    discard: (action: () => void) => void;
};

export function IncidentIndependencePanel(props: Props) {
    return <ActorIndependencePanel key={props.actorId} {...props} />;
}

function ActorIndependencePanel({
    policy,
    history,
    canManage,
    actorId,
    discard,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [saved, setSaved] = useState(false);
    // Independent incident review, drawn as the approved settings drawing (24c): both choices side by side,
    // the one in use marked, each showing what the people named in a report can and can't do.
    const chosen = history.data.find(
        (row) => row.revision === policy.policy_revision && row.integrity === 'recorded',
    );
    const status =
        policy.origin === 'organisation_setting'
            ? 'Organisation decision recorded'
            : policy.origin === 'application_default_unreviewed'
              ? 'Application default · not reviewed'
              : 'Needs review';
    const chosenMeta = (mode: IndependenceMode) =>
        policy.mode !== mode ? undefined : policy.reviewed_at ? (
            <>
                Chosen{chosen?.actor?.name ? <> by <b>{chosen.actor.name}</b></> : null} ·{' '}
                {formatDateTimeLong(policy.reviewed_at)} · revision {policy.policy_revision}
            </>
        ) : (
            'Organisation review has not been recorded.'
        );
    return (
        <section aria-label="Independent incident review" className="flex flex-col gap-5">
            <Choices
                title="Independent incident review"
                sub={
                    <>
                        When a report names the usual manager or another staff member as involved ·{' '}
                        <StatusBadge variant={policy.configuration_valid ? 'info' : 'warning'} size="sm">
                            {policy.configuration_valid ? (
                                <ShieldCheck aria-hidden className="size-3" />
                            ) : (
                                <AlertCircle aria-hidden className="size-3" />
                            )}
                            {status}
                        </StatusBadge>
                    </>
                }
                options={[
                    {
                        title: independenceModeLabel('block_involved_access'),
                        badge: 'Recommended',
                        on: policy.mode === 'block_involved_access',
                        text: 'During independent review, named people cannot read the report, receive its notifications, own it or manage it. Other people still need the usual permission and Site access.',
                        canTitle: 'People named in the report',
                        can: [
                            { label: 'Can’t read it', state: 'success' },
                            { label: 'Can’t get its notifications', state: 'success' },
                            { label: 'Can’t own it', state: 'success' },
                            { label: 'Can’t manage it', state: 'success' },
                        ],
                        meta: chosenMeta('block_involved_access'),
                    },
                    {
                        title: independenceModeLabel('assignment_only'),
                        on: policy.mode === 'assignment_only',
                        text: 'Named people cannot own or manage the report. Existing read and notification permissions still apply, so those people may be able to read it and receive notifications.',
                        canTitle: 'People named in the report',
                        can: [
                            { label: 'May still read it', state: 'warning' },
                            { label: 'May still get notifications', state: 'warning' },
                            { label: 'Can’t own it', state: 'success' },
                            { label: 'Can’t manage it', state: 'success' },
                        ],
                        meta: chosenMeta('assignment_only'),
                    },
                ]}
                actions={
                    !editing && canManage && policy.policy_revision !== null ? (
                        <Button
                            className="frontline-tap frontline-focus"
                            variant="outline"
                            onClick={() => {
                                setSaved(false);
                                setEditing(true);
                            }}
                        >
                            Edit independent review
                        </Button>
                    ) : undefined
                }
            />
            {policy.mode === null ? (
                <Note tone="warning" icon={AlertCircle}>
                    {independenceModeLabel(policy.mode)}. Organisation review has not been recorded.
                </Note>
            ) : null}
            {saved ? (
                <p role="status" className="text-subtle flex items-center gap-2">
                    <CheckCircle2 aria-hidden className="size-4" />
                    Independent review policy saved.
                </p>
            ) : null}
            {editing ? (
                <Surface title="Edit independent review">
                    <div className="p-[18px]">
                        <IndependenceEditor
                            policy={policy}
                            canManage={canManage}
                            actorId={actorId}
                            discard={discard}
                            onClose={(accepted) => {
                                setEditing(false);
                                setSaved(accepted);
                            }}
                        />
                    </div>
                </Surface>
            ) : null}
            <KnowList
                title="Good to know"
                items={[
                    { text: 'Each report retains the policy accepted when independent handling is first recorded.' },
                    { text: 'Changing this setting does not rewrite existing reports, reassign their owner or resolve their independent review.' },
                    { tone: 'none', text: 'People who are not yet identified remain marked unknown.' },
                ]}
            />
            <div className="flex flex-col gap-3">
                {history.data.length ? (
                    <Log
                        title="Independent review policy history"
                        limit={50}
                        items={history.data.map((row) => ({
                            key: row.id,
                            time: row.recorded_at ? formatDate(row.recorded_at) : '',
                            tone: row.integrity === 'recorded' ? 'info' : 'warning',
                            title: `Revision ${row.revision} · ${independenceModeLabel(row.mode)}`,
                            sub:
                                row.integrity === 'recorded'
                                    ? [
                                          row.actor?.name ?? 'Recorded decision-maker unavailable',
                                          row.recorded_at ? formatDateTimeLong(row.recorded_at) : 'Time unavailable',
                                          row.reason,
                                      ]
                                          .filter(Boolean)
                                          .join(' · ')
                                    : 'This retained decision needs repair. Its contents cannot be confirmed.',
                        }))}
                    />
                ) : (
                    <QuietLine title="Independent review policy history" text="No organisation decision has been recorded." />
                )}
                {history.last_page > 1 ? <LaravelPagination links={history.links} /> : null}
            </div>
        </section>
    );
}

function IndependenceEditor({
    policy,
    canManage,
    actorId,
    discard,
    onClose,
}: Omit<Props, 'history'> & { onClose: (saved: boolean) => void }) {
    const [initial] = useState<IndependenceCommand>(() => ({
        mode: policy.mode ?? '',
        expected_policy_revision: policy.policy_revision ?? 0,
        request_uuid: crypto.randomUUID(),
        reason: '',
    }));
    const form = useSafetyForm(initial);
    const [uncertain, setUncertain] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const active = useRef(true);
    const current = useRef({
        actorId,
        canManage,
        uuid: form.data.request_uuid,
    });
    current.current = { actorId, canManage, uuid: form.data.request_uuid };
    useEffect(() => {
        active.current = true;
        return () => {
            active.current = false;
        };
    }, []);
    const blocked =
        form.processing || !canManage || policy.policy_revision === null;
    useSafetyDraftState({
        dirty: form.isDirty,
        busy: form.processing,
        uncertain,
    });
    const change = (mode: IndependenceCommand['mode'], reason: string) => {
        if (
            blocked ||
            uncertain ||
            (mode === form.data.mode && reason === form.data.reason)
        )
            return;
        form.setData({
            ...form.data,
            mode,
            reason,
            request_uuid: crypto.randomUUID(),
        });
        form.clearErrors();
        setMessage(null);
    };
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                if (blocked || form.data.mode === '') return;
                const submitted = structuredClone(form.data);
                const submittingActor = actorId;
                const acceptsResponse = () =>
                    active.current &&
                    current.current.actorId === submittingActor &&
                    current.current.canManage &&
                    current.current.uuid === submitted.request_uuid;
                let returned = false;
                setUncertain(true);
                setMessage(null);
                form.put('/health-safety/settings/incident-independence', {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (!acceptsResponse()) return;
                        returned = true;
                        const responseActor = page.props.auth as
                            | { user?: { id?: number } }
                            | undefined;
                        if (
                            page.props.independence_viewer_id ===
                                submittingActor &&
                            responseActor?.user?.id === submittingActor &&
                            confirmsIndependenceReceipt(
                                page.props.health_safety_independence_receipt,
                                submitted,
                                submittingActor,
                            )
                        ) {
                            setUncertain(false);
                            onClose(true);
                        } else {
                            setMessage(
                                'The policy save could not be confirmed. Your original entries are retained. Retry unchanged to check the same submission.',
                            );
                        }
                    },
                    onError: () => {
                        if (!acceptsResponse()) return;
                        returned = true;
                        setUncertain(false);
                        setMessage(
                            'The policy was not confirmed. Review the errors; your entries are retained.',
                        );
                    },
                    onFinish: () => {
                        if (!acceptsResponse() || returned) return;
                        setUncertain(true);
                        setMessage(
                            'The response was interrupted. The policy may already be recorded. Retry unchanged to confirm your original submission.',
                        );
                    },
                });
            }}
        >
            {!canManage ? (
                <p
                    role="alert"
                    className="text-subtle text-status-warning-foreground"
                >
                    Your settings access has changed. Your entries remain in
                    this tab; saving is disabled.
                </p>
            ) : null}
            {policy.policy_revision !== form.data.expected_policy_revision ? (
                <p
                    role="alert"
                    className="text-subtle text-status-warning-foreground"
                >
                    The policy revision changed. Your entries remain. Review the
                    current policy before starting again.
                </p>
            ) : null}
            <fieldset disabled={blocked || uncertain} className="space-y-4">
                <legend className="text-subtle font-medium">
                    Protection for people named as involved
                </legend>
                <label className="text-subtle block space-y-2">
                    Independent review protection
                    <select
                        className="frontline-tap frontline-focus text-subtle w-full rounded-md border border-input bg-background px-3"
                        value={form.data.mode}
                        onChange={(event) =>
                            change(
                                event.target.value as IndependenceMode,
                                form.data.reason,
                            )
                        }
                    >
                        <option value="" disabled>
                            Choose a policy
                        </option>
                        <option value="block_involved_access">
                            Restrict access and assignment (recommended)
                        </option>
                        <option value="assignment_only">
                            Restrict assignment only
                        </option>
                    </select>
                </label>
                <p className="text-subtle text-muted-foreground">
                    {form.data.mode === 'block_involved_access'
                        ? 'During independent review, named people cannot read the report, receive its notifications, own it or manage it. Other people still need the usual permission and Site access.'
                        : form.data.mode === 'assignment_only'
                          ? 'Named people cannot own or manage the report. Existing read and notification permissions still apply, so those people may be able to read it and receive notifications.'
                          : 'Choose the organisation policy explicitly.'}
                </p>
                <label className="text-subtle block space-y-2">
                    Reason for this independent review policy
                    <Textarea
                        className="frontline-focus"
                        required
                        maxLength={4000}
                        value={form.data.reason}
                        onChange={(event) =>
                            change(form.data.mode, event.target.value)
                        }
                    />
                </label>
            </fieldset>
            {Object.values(form.errors).length ? (
                <ul
                    role="alert"
                    className="text-subtle space-y-1 text-status-warning-foreground"
                >
                    {Object.entries(form.errors).map(([key, error]) => (
                        <li key={key}>{error}</li>
                    ))}
                </ul>
            ) : null}
            {message ? (
                <p
                    role="alert"
                    className="text-subtle text-status-warning-foreground"
                >
                    {message}
                </p>
            ) : null}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap frontline-focus"
                    type="submit"
                    disabled={blocked || form.data.mode === ''}
                >
                    {form.processing
                        ? 'Saving…'
                        : 'Save independent review policy'}
                </Button>
                <Button
                    className="frontline-tap frontline-focus"
                    type="button"
                    variant="outline"
                    disabled={form.processing}
                    onClick={() => discard(() => onClose(false))}
                >
                    Cancel editing
                </Button>
            </div>
        </form>
    );
}
