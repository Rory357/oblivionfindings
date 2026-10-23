import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    useSafetyDraftState,
    useSafetyForm,
} from '@/hooks/use-safety-workspace-drafts';
import { formatDateTimeLong } from '@/lib/datetime';
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
    return (
        <section aria-label="Independent incident review" className="space-y-5">
            <Card>
                <CardContent className="space-y-4 p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="space-y-2">
                            <h2 className="text-section-title">
                                Independent incident review
                            </h2>
                            <StatusBadge
                                variant={
                                    policy.configuration_valid
                                        ? 'info'
                                        : 'warning'
                                }
                            >
                                {policy.configuration_valid ? (
                                    <ShieldCheck
                                        aria-hidden
                                        className="size-3"
                                    />
                                ) : (
                                    <AlertCircle
                                        aria-hidden
                                        className="size-3"
                                    />
                                )}
                                {policy.origin === 'organisation_setting'
                                    ? 'Organisation decision recorded'
                                    : policy.origin ===
                                        'application_default_unreviewed'
                                      ? 'Application default · not reviewed'
                                      : 'Needs review'}
                            </StatusBadge>
                            <p className="text-subtle">
                                {independenceModeLabel(policy.mode)}
                            </p>
                            <p className="text-subtle text-muted-foreground">
                                Choose what happens when a report identifies the
                                usual manager or another staff member as
                                involved. Named people cannot own or manage that
                                report. Access and notification protection
                                follows the choice below.
                            </p>
                        </div>
                        {!editing &&
                        canManage &&
                        policy.policy_revision !== null ? (
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
                        ) : null}
                    </div>
                    <p className="text-subtle text-muted-foreground">
                        Each report retains the policy accepted when independent
                        handling is first recorded. Changing this setting does
                        not rewrite existing reports, reassign their owner or
                        resolve their independent review. People who are not yet
                        identified remain marked unknown.
                    </p>
                    {policy.reviewed_at ? (
                        <p className="text-caption text-muted-foreground">
                            Organisation review recorded{' '}
                            {formatDateTimeLong(policy.reviewed_at)} · revision{' '}
                            {policy.policy_revision}
                        </p>
                    ) : (
                        <p className="text-caption text-muted-foreground">
                            Organisation review has not been recorded.
                        </p>
                    )}
                    {saved ? (
                        <p
                            role="status"
                            className="text-subtle flex items-center gap-2"
                        >
                            <CheckCircle2 aria-hidden className="size-4" />
                            Independent review policy saved.
                        </p>
                    ) : null}
                    {editing ? (
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
                    ) : null}
                </CardContent>
            </Card>
            <div className="space-y-3">
                <h3 className="text-section-title">
                    Independent review policy history
                </h3>
                {history.data.length ? (
                    <ul className="space-y-3">
                        {history.data.map((row) => (
                            <li key={row.id}>
                                <Card>
                                    <CardContent className="space-y-2 p-4">
                                        <p className="text-subtle">
                                            Revision {row.revision} ·{' '}
                                            {independenceModeLabel(row.mode)}
                                        </p>
                                        {row.integrity === 'recorded' ? (
                                            <>
                                                <p className="text-caption text-muted-foreground">
                                                    {row.actor?.name ??
                                                        'Recorded decision-maker unavailable'}{' '}
                                                    ·{' '}
                                                    {row.recorded_at
                                                        ? formatDateTimeLong(
                                                              row.recorded_at,
                                                          )
                                                        : 'Time unavailable'}
                                                </p>
                                                <p className="text-subtle whitespace-pre-wrap">
                                                    {row.reason}
                                                </p>
                                            </>
                                        ) : (
                                            <p className="text-subtle text-status-warning-foreground">
                                                This retained decision needs
                                                repair. Its contents cannot be
                                                confirmed.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-subtle text-muted-foreground">
                        No organisation decision has been recorded.
                    </p>
                )}
                {history.last_page > 1 ? (
                    <LaravelPagination links={history.links} />
                ) : null}
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
