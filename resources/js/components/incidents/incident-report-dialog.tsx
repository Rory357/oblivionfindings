import DraftResumePrompt from '@/components/draft-resume-prompt';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    FieldErr,
    InfoCard,
    Ring,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useIncidentReportDraft } from '@/hooks/use-incident-report-draft';
import { formatTime } from '@/lib/datetime';
import { useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Clock3,
    CloudOff,
    Eye,
    HeartPulse,
    HelpCircle,
    ListTodo,
    LoaderCircle,
    type LucideIcon,
    Pill,
    Plus,
    Search,
    ShieldAlert,
    ShieldQuestion,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export type IncidentReportEntryContext =
    | 'incidents'
    | 'health_safety'
    | 'control_room';
export type IncidentReportClient = {
    id: number;
    first_name: string;
    last_name: string;
    site_id?: number | null;
};
export type IncidentReportSite = { id: number; name: string };
type StaffOpt = { id: number; name: string };
type FollowupDraft = {
    notes: string;
    assigned_to_user_id: string;
    due_at: string;
};

type Mode = 'incident' | 'near_miss';

function errorStepKey(errorKey: string, isNearMiss: boolean): string {
    const rootKey = errorKey.split('.')[0];

    if (
        ['type', 'client_id', 'site_id', 'shift_id', 'template_id'].includes(
            rootKey,
        )
    ) {
        return 'people';
    }

    if (['occurred_at', 'description'].includes(rootKey)) {
        return 'what';
    }

    if (
        ['potential_severity', 'potential_consequence', 'hazard'].includes(
            rootKey,
        )
    ) {
        return 'could';
    }

    if (
        [
            'severity',
            'reported_severity',
            'harm_or_injury',
            'consequence',
            'immediate_action_taken',
            'witnesses',
            'injured_person_name',
            'injured_person_role',
            'injured_person_age',
            'injury_body_part',
            'injury_nature',
            'injury_classification',
            'medical_treatment_type',
        ].includes(rootKey)
    ) {
        return isNearMiss && rootKey === 'immediate_action_taken'
            ? 'could'
            : 'severity';
    }

    if (
        [
            'is_notifiable',
            'site_preserved',
            'worksafe_reference',
            'worksafe_notification_status',
        ].includes(rootKey)
    ) {
        return 'notifiable';
    }

    if (rootKey === 'followups') {
        return 'followups';
    }

    return 'review';
}

function firstInvalidStepIndex(
    errors: Record<string, string>,
    steps: Array<{ key: string }>,
    isNearMiss: boolean,
): number {
    const invalidStepIndexes = Object.entries(errors)
        .filter(([, message]) => message.trim() !== '')
        .map(([errorKey]) =>
            steps.findIndex(
                (step) => step.key === errorStepKey(errorKey, isNearMiss),
            ),
        )
        .filter((index) => index >= 0);

    return invalidStepIndexes.length > 0
        ? Math.min(...invalidStepIndexes)
        : steps.length - 1;
}

function splitOccurredAt(value?: string | null): {
    occurred_date: string;
    occurred_time: string;
} {
    const match = value?.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);

    return {
        occurred_date: match?.[1] ?? '',
        occurred_time: match?.[2] ?? '',
    };
}

export type IncidentReportDefaults = Partial<{
    type: string;
    client_id: number | null;
    site_id: number | null;
    shift_id: number | null;
    occurred_at: string;
    description: string;
    severity: string;
    potential_severity: string;
    potential_consequence: string;
    hazard: string;
    immediate_action_taken: string;
    witnesses: string;
    harm_or_injury: string;
    consequence: string;
    reported_severity: string;
    is_notifiable: boolean;
    worksafe_reference: string;
    worksafe_notification_status: string;
    site_preserved: boolean;
    followups: FollowupDraft[];
}>;

type IncidentReportResult = {
    result: 'draft' | 'submitted';
    incident_reference?: string | null;
    hs_reference?: string | null;
    handover_state?: string | null;
    incident_url?: string | null;
};

type ReportForm = {
    type: string;
    client_id: string;
    site_id: string;
    shift_id: string;
    occurred_date: string;
    occurred_time: string;
    description: string;
    severity: string;
    potential_severity: string;
    potential_consequence: string;
    hazard: string;
    immediate_action_taken: string;
    witnesses: string;
    harm_or_injury: string;
    consequence: string;
    is_notifiable: boolean;
    worksafe_reference: string;
    worksafe_notification_status: string;
    site_preserved: boolean;
    followups: FollowupDraft[];
    stay: boolean;
};

type SetData = <K extends keyof ReportForm>(
    key: K,
    value: ReportForm[K],
) => void;

export function IncidentReportDialog({
    open,
    onClose,
    mode,
    entryContext = 'incidents',
    clients,
    sites = [],
    staff,
    defaults,
    canManageFollowups = false,
    onOpenIncident,
}: {
    open: boolean;
    onClose: () => void;
    mode: Mode;
    entryContext?: IncidentReportEntryContext;
    clients: IncidentReportClient[];
    sites?: IncidentReportSite[];
    staff: StaffOpt[];
    defaults?: IncidentReportDefaults;
    canManageFollowups?: boolean;
    onOpenIncident?: (url: string) => void;
}) {
    const isNearMiss = mode === 'near_miss';
    const page = usePage();
    const userId = Number(
        (page.props as { auth?: { user?: { id?: number } } }).auth?.user?.id ??
            0,
    );
    const occurrence = splitOccurredAt(defaults?.occurred_at);
    const [stepIndex, setStepIndex] = useState(0);
    const [result, setResult] = useState<IncidentReportResult | null>(null);
    const [closePrompt, setClosePrompt] = useState(false);
    const [closeBusy, setCloseBusy] = useState(false);

    const form = useForm<ReportForm>({
        type: isNearMiss ? 'near_miss' : (defaults?.type ?? ''),
        client_id: defaults?.client_id ? String(defaults.client_id) : '',
        site_id: defaults?.site_id ? String(defaults.site_id) : '',
        shift_id: defaults?.shift_id ? String(defaults.shift_id) : '',
        occurred_date: occurrence.occurred_date,
        occurred_time: occurrence.occurred_time,
        description: defaults?.description ?? '',
        severity: defaults?.reported_severity ?? defaults?.severity ?? 'low',
        potential_severity: defaults?.potential_severity ?? '',
        potential_consequence: defaults?.potential_consequence ?? '',
        hazard: defaults?.hazard ?? '',
        immediate_action_taken: defaults?.immediate_action_taken ?? '',
        witnesses: defaults?.witnesses ?? '',
        harm_or_injury: defaults?.harm_or_injury ?? '',
        consequence: defaults?.consequence ?? '',
        is_notifiable: defaults?.is_notifiable ?? false,
        worksafe_reference: defaults?.worksafe_reference ?? '',
        worksafe_notification_status:
            defaults?.worksafe_notification_status ?? '',
        site_preserved: defaults?.site_preserved ?? false,
        followups: defaults?.followups ?? [],
        stay: true,
    });
    const d = form.data;
    const validationMessages = useMemo(
        () =>
            Array.from(
                new Set(
                    Object.values(form.errors)
                        .map((message) => message?.trim())
                        .filter((message): message is string =>
                            Boolean(message),
                        ),
                ),
            ),
        [form.errors],
    );

    const clientOptions = clients.map((c) => ({
        value: String(c.id),
        label: `${c.first_name} ${c.last_name}`.trim(),
    }));
    const siteOptions = sites.map((site) => ({
        value: String(site.id),
        label: site.name,
    }));

    /* ---- step model (branches on mode) ---- */
    const steps = useMemo(
        () =>
            isNearMiss
                ? [
                      {
                          key: 'people',
                          label: 'Who & where',
                          blurb: 'Blame-free — thanks for reporting',
                          icon: Users,
                      },
                      {
                          key: 'what',
                          label: 'What happened',
                          blurb: 'The near miss',
                          icon: Eye,
                      },
                      {
                          key: 'could',
                          label: 'What could have happened',
                          blurb: 'Potential & hazard',
                          icon: AlertTriangle,
                      },
                      {
                          key: 'notifiable',
                          label: 'Dangerous occurrence',
                          blurb: 'Quick WorkSafe check',
                          icon: ShieldQuestion,
                      },
                      {
                          key: 'followups',
                          label: 'Follow-ups',
                          blurb: 'Optional tasks',
                          icon: ListTodo,
                      },
                      {
                          key: 'review',
                          label: 'Review',
                          blurb: 'Submit',
                          icon: CheckCircle2,
                      },
                  ]
                : [
                      {
                          key: 'people',
                          label: 'Type & people',
                          blurb: 'What and who',
                          icon: ClipboardList,
                      },
                      {
                          key: 'what',
                          label: 'What happened',
                          blurb: 'Describe it',
                          icon: Search,
                      },
                      {
                          key: 'severity',
                          label: 'Severity & actions',
                          blurb: 'Impact & response',
                          icon: Activity,
                      },
                      {
                          key: 'notifiable',
                          label: 'WorkSafe check',
                          blurb: 'NZ HSWA notifiable',
                          icon: ShieldAlert,
                      },
                      {
                          key: 'followups',
                          label: 'Follow-ups',
                          blurb: 'Assign tasks',
                          icon: ListTodo,
                      },
                      {
                          key: 'review',
                          label: 'Review',
                          blurb: 'Submit',
                          icon: CheckCircle2,
                      },
                  ],
        [isNearMiss],
    );
    const stepKey = steps[stepIndex].key;
    const lastIndex = steps.length - 1;
    const hasReportContent = Boolean(
        (!isNearMiss && d.type) ||
        d.client_id ||
        d.site_id ||
        d.shift_id ||
        d.occurred_date !== occurrence.occurred_date ||
        d.occurred_time !== occurrence.occurred_time ||
        d.description.trim() ||
        d.potential_severity ||
        d.potential_consequence.trim() ||
        d.hazard.trim() ||
        d.immediate_action_taken.trim() ||
        d.witnesses.trim() ||
        d.harm_or_injury.trim() ||
        d.consequence.trim() ||
        d.is_notifiable ||
        d.worksafe_reference.trim() ||
        d.followups.length > 0,
    );
    const hasDraftScope = Boolean(d.client_id || d.site_id || d.shift_id);
    const draftRecovery = useIncidentReportDraft<ReportForm>({
        userId,
        open,
        enabled: !result && userId > 0 && hasReportContent && hasDraftScope,
        mode,
        entryContext,
        stepIndex,
        form: d,
        onRestore: (draft) => {
            for (const key of Object.keys(d) as Array<keyof ReportForm>) {
                const savedValue = draft.form[key];
                const fallbackValue = d[key];
                const restoredValue =
                    savedValue === null && typeof fallbackValue === 'string'
                        ? ''
                        : (savedValue ?? fallbackValue);
                form.setData(key, restoredValue as ReportForm[typeof key]);
            }
            form.clearErrors();
            setStepIndex(Math.min(lastIndex, Math.max(0, draft.step_index)));
        },
    });

    /* ---- completeness ---- */
    const pct = useMemo(() => {
        const checks = [
            !!d.client_id,
            isNearMiss ? true : !!d.type,
            !!d.description,
            isNearMiss ? !!d.potential_severity : !!d.severity,
            true, // notifiable step always "answered" (boolean)
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [d, isNearMiss]);

    /* ---- per-step gate ---- */
    const stepValid = (key: string): boolean => {
        switch (key) {
            case 'people':
                return !!d.client_id && (isNearMiss || !!d.type);
            case 'what':
                return !!d.description.trim();
            case 'severity':
                return !!d.severity;
            case 'could':
                return !!d.potential_severity;
            default:
                return true;
        }
    };

    const postReport = (intent: 'draft' | 'submit') => {
        form.transform((data) => {
            const {
                occurred_date: occurredDate,
                occurred_time: occurredTime,
                ...payload
            } = data;
            const reportedSeverity =
                data.severity === 'critical' ? 'critical' : null;
            const followups = data.followups
                .filter((followup) => followup.notes.trim())
                .map((followup) => ({
                    notes: followup.notes,
                    ...(canManageFollowups
                        ? {
                              assigned_to_user_id:
                                  followup.assigned_to_user_id === ''
                                      ? null
                                      : Number(followup.assigned_to_user_id),
                          }
                        : {}),
                    due_at: followup.due_at || null,
                }));

            return {
                ...payload,
                intent,
                report_request_uuid: draftRecovery.requestUuid,
                client_id: data.client_id ? Number(data.client_id) : null,
                site_id: data.site_id ? Number(data.site_id) : null,
                shift_id: data.shift_id ? Number(data.shift_id) : null,
                occurred_at: occurredDate
                    ? `${occurredDate}T${occurredTime || '00:00'}`
                    : null,
                severity: reportedSeverity ? 'high' : data.severity,
                reported_severity: reportedSeverity,
                worksafe_notification_status: data.is_notifiable
                    ? data.worksafe_notification_status || 'pending'
                    : null,
                worksafe_reference: data.is_notifiable
                    ? data.worksafe_reference || null
                    : null,
                followups,
            };
        });
        form.post('/incidents', {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                setResult(null);
                setStepIndex(firstInvalidStepIndex(errors, steps, isNearMiss));
            },
            onSuccess: (pg) => {
                const flash = (
                    pg.props as {
                        flash?: {
                            error?: string;
                            incident_report_result?: IncidentReportResult;
                        };
                    }
                ).flash;
                if (!flash?.error && flash?.incident_report_result) {
                    if (flash.incident_report_result.result === 'submitted') {
                        draftRecovery.consume();
                    }
                    setResult(flash.incident_report_result);
                }
            },
        });
    };

    const submit = (intent: 'draft' | 'submit') => {
        if (intent === 'draft' && userId > 0) {
            void draftRecovery.saveNow().then((saved) => {
                if (saved) postReport(intent);
            });
            return;
        }

        postReport(intent);
    };

    const reset = () => {
        form.reset();
        form.clearErrors();
        setStepIndex(0);
        setResult(null);
        draftRecovery.beginNew();
    };

    const requestClose = () => {
        if (result) {
            onClose();
            return;
        }

        if (draftRecovery.resumeAvailable || draftRecovery.recoveryBlocked) {
            onClose();
            return;
        }

        if (
            draftRecovery.status === 'loading' ||
            draftRecovery.hasSavedDraft ||
            (hasReportContent && draftRecovery.hasUnsavedChanges)
        ) {
            setClosePrompt(true);
            return;
        }

        onClose();
    };

    const saveAndClose = async () => {
        setCloseBusy(true);
        const saved = await draftRecovery.saveNow();
        setCloseBusy(false);
        if (!saved) return;

        setClosePrompt(false);
        onClose();
    };

    const discardAndClose = async () => {
        setCloseBusy(true);
        const discarded = await draftRecovery.discard();
        setCloseBusy(false);
        if (!discarded) return;

        setClosePrompt(false);
        onClose();
        draftRecovery.beginNew();
    };

    const discardRecoveredDraft = async () => {
        const discarded = await draftRecovery.discard();
        if (!discarded) return;

        if (draftRecovery.recoveryBlocked) {
            form.reset();
            form.clearErrors();
            setStepIndex(0);
        }
        draftRecovery.beginNew();
    };

    const draftStatus = !draftRecovery.loaded ? (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />
            Checking for a saved draft…
        </span>
    ) : draftRecovery.status === 'saving' ? (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />
            Saving securely…
        </span>
    ) : draftRecovery.status === 'saved' && draftRecovery.savedAt ? (
        <span className="flex items-center gap-2 text-xs text-status-success">
            <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
            Saved securely at {formatTime(draftRecovery.savedAt)}
        </span>
    ) : draftRecovery.resumeAvailable ? (
        <span className="flex items-center gap-2 text-xs text-status-warning">
            <Clock3 className="h-4 w-4" aria-hidden="true" />
            Saved report found
        </span>
    ) : draftRecovery.recoveryBlocked ? (
        <span className="flex items-center gap-2 text-xs text-status-warning">
            <CloudOff className="h-4 w-4" aria-hidden="true" />
            Recovery paused
        </span>
    ) : draftRecovery.status === 'error' ||
      draftRecovery.status === 'session_expired' ? (
        <span className="flex items-center gap-2 text-xs text-status-critical">
            <CloudOff className="h-4 w-4" aria-hidden="true" />
            Not saved yet
        </span>
    ) : draftRecovery.status === 'dirty' ? (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <Clock3 className="h-4 w-4" aria-hidden="true" />
            Waiting to save…
        </span>
    ) : null;

    useEffect(() => {
        if (!open) {
            setClosePrompt(false);
            setCloseBusy(false);
        }
    }, [open]);

    /* ---- follow-up rows ---- */
    const addFollowup = () =>
        form.setData('followups', [
            ...d.followups,
            { notes: '', assigned_to_user_id: '', due_at: '' },
        ]);
    const updateFollowup = (i: number, patch: Partial<FollowupDraft>) =>
        form.setData(
            'followups',
            d.followups.map((f, idx) => (idx === i ? { ...f, ...patch } : f)),
        );
    const removeFollowup = (i: number) =>
        form.setData(
            'followups',
            d.followups.filter((_, idx) => idx !== i),
        );

    const isAwaitingHsAcceptance =
        result?.handover_state === 'awaiting_hs_acceptance' ||
        result?.handover_state === 'awaiting_acceptance';
    const success = result ? (
        <WizardSuccessPane
            title={
                result.result === 'draft'
                    ? 'Draft saved'
                    : isNearMiss
                      ? 'Near miss submitted'
                      : 'Incident submitted'
            }
            blurb={
                <span className="flex flex-col items-center gap-2">
                    {result.result === 'draft' ? (
                        <span>
                            {result.incident_reference ? (
                                <>
                                    Reference{' '}
                                    <span className="font-semibold text-foreground">
                                        {result.incident_reference}
                                    </span>
                                    .{' '}
                                </>
                            ) : null}
                            You can return to finish and submit it later.
                        </span>
                    ) : (
                        <>
                            <span>
                                {result.incident_reference ? (
                                    <>
                                        Incident{' '}
                                        <span className="font-semibold text-foreground">
                                            {result.incident_reference}
                                        </span>
                                    </>
                                ) : (
                                    'The incident'
                                )}{' '}
                                has been submitted.
                                {result.hs_reference ? (
                                    <>
                                        {' '}
                                        H&amp;S reference{' '}
                                        <span className="font-semibold text-foreground">
                                            {result.hs_reference}
                                        </span>
                                        .
                                    </>
                                ) : null}
                            </span>
                            {isAwaitingHsAcceptance ? (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-status-warning-bg px-3 py-1 font-semibold text-status-warning">
                                    <Clock3
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    <span>Awaiting H&amp;S acceptance</span>
                                </span>
                            ) : null}
                        </>
                    )}
                </span>
            }
            actions={
                <>
                    {result.result === 'draft' ? (
                        <Button
                            onClick={() => submit('submit')}
                            disabled={form.processing}
                        >
                            Submit incident
                        </Button>
                    ) : null}
                    {result.incident_url && onOpenIncident ? (
                        <Button
                            onClick={() => onOpenIncident(result.incident_url!)}
                        >
                            Open incident
                        </Button>
                    ) : null}
                    <Button variant="outline" onClick={reset}>
                        Report another
                    </Button>
                    <Button variant="ghost" onClick={requestClose}>
                        Done
                    </Button>
                </>
            }
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={open}
                onClose={requestClose}
                title={isNearMiss ? 'Report a near miss' : 'Report an incident'}
                description={
                    isNearMiss
                        ? 'A blame-free, under-a-minute near-miss report.'
                        : 'Report an incident for review.'
                }
                railIcon={isNearMiss ? Eye : ShieldAlert}
                railTitle={isNearMiss ? 'Near miss' : 'Incident report'}
                railSub={
                    isNearMiss ? 'Leading safety indicator' : 'System of record'
                }
                steps={steps}
                stepIndex={stepIndex}
                onStepClick={(i) => setStepIndex(i)}
                pct={pct}
                footerStart={
                    !result ? (
                        <div
                            className="flex items-center gap-3"
                            role="status"
                            aria-live="polite"
                        >
                            <Ring pct={pct} size={40} />
                            {draftStatus}
                        </div>
                    ) : undefined
                }
                footerEnd={
                    result ? undefined : (
                        <div className="flex items-center gap-2">
                            {stepIndex > 0 ? (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setStepIndex((i) => Math.max(0, i - 1))
                                    }
                                >
                                    Back
                                </Button>
                            ) : null}
                            {stepIndex < lastIndex ? (
                                <Button
                                    onClick={() =>
                                        setStepIndex((i) =>
                                            Math.min(lastIndex, i + 1),
                                        )
                                    }
                                    disabled={
                                        !draftRecovery.loaded ||
                                        draftRecovery.resumeAvailable ||
                                        draftRecovery.recoveryBlocked ||
                                        !stepValid(stepKey)
                                    }
                                >
                                    Next
                                </Button>
                            ) : (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => submit('draft')}
                                        disabled={
                                            !draftRecovery.loaded ||
                                            draftRecovery.resumeAvailable ||
                                            draftRecovery.recoveryBlocked ||
                                            draftRecovery.status === 'saving' ||
                                            form.processing ||
                                            !d.client_id ||
                                            !d.description.trim()
                                        }
                                    >
                                        Save draft
                                    </Button>
                                    <Button
                                        onClick={() => submit('submit')}
                                        disabled={
                                            !draftRecovery.loaded ||
                                            draftRecovery.resumeAvailable ||
                                            draftRecovery.recoveryBlocked ||
                                            form.processing ||
                                            !d.client_id ||
                                            !d.description.trim()
                                        }
                                    >
                                        Submit incident
                                    </Button>
                                </>
                            )}
                        </div>
                    )
                }
                success={success}
            >
                <WizardStepPane>
                    {!draftRecovery.loaded ? (
                        <InfoCard icon={LoaderCircle}>
                            Checking for your saved incident report. This keeps
                            an earlier report from being overwritten.
                        </InfoCard>
                    ) : null}
                    {draftRecovery.resumeAvailable ? (
                        <DraftResumePrompt
                            savedAt={draftRecovery.savedAt}
                            onResume={draftRecovery.resume}
                            onDiscard={() => void discardRecoveredDraft()}
                            title="Resume your incident report?"
                            description="We found your unfinished incident report. Continue it or discard it before starting again."
                            className="[&_button]:min-h-11 [&_button]:min-w-11"
                        />
                    ) : null}
                    {draftRecovery.message && !draftRecovery.resumeAvailable ? (
                        <InfoCard
                            icon={CloudOff}
                            tone={
                                draftRecovery.status === 'session_expired'
                                    ? 'crit'
                                    : 'warn'
                            }
                        >
                            <div className="space-y-3">
                                <p>{draftRecovery.message}</p>
                                {draftRecovery.recoveryBlocked ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="frontline-tap frontline-focus"
                                        onClick={() =>
                                            void discardRecoveredDraft()
                                        }
                                    >
                                        Discard saved report
                                    </Button>
                                ) : null}
                            </div>
                        </InfoCard>
                    ) : null}
                    {draftRecovery.loaded &&
                    !draftRecovery.resumeAvailable &&
                    !draftRecovery.recoveryBlocked ? (
                        <>
                            <ValidationSummary messages={validationMessages} />
                            {stepKey === 'people' ? (
                                <PeopleStep
                                    d={d}
                                    setData={form.setData}
                                    errors={form.errors}
                                    clients={clients}
                                    clientOptions={clientOptions}
                                    siteOptions={siteOptions}
                                    isNearMiss={isNearMiss}
                                />
                            ) : null}
                            {stepKey === 'what' ? (
                                <WhatStep
                                    d={d}
                                    setData={form.setData}
                                    errors={form.errors}
                                    isNearMiss={isNearMiss}
                                />
                            ) : null}
                            {stepKey === 'severity' ? (
                                <SeverityStep d={d} setData={form.setData} />
                            ) : null}
                            {stepKey === 'could' ? (
                                <CouldStep d={d} setData={form.setData} />
                            ) : null}
                            {stepKey === 'notifiable' ? (
                                <NotifiableStep
                                    d={d}
                                    setData={form.setData}
                                    isNearMiss={isNearMiss}
                                />
                            ) : null}
                            {stepKey === 'followups' ? (
                                <FollowupsStep
                                    d={d}
                                    staff={staff}
                                    canManageFollowups={canManageFollowups}
                                    errors={form.errors}
                                    onAdd={addFollowup}
                                    onUpdate={updateFollowup}
                                    onRemove={removeFollowup}
                                />
                            ) : null}
                            {stepKey === 'review' ? (
                                <ReviewStep
                                    d={d}
                                    isNearMiss={isNearMiss}
                                    clients={clients}
                                    staff={staff}
                                    canManageFollowups={canManageFollowups}
                                    goto={setStepIndex}
                                />
                            ) : null}
                        </>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <AlertDialog
                open={closePrompt}
                onOpenChange={(nextOpen) => {
                    if (!closeBusy) setClosePrompt(nextOpen);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Keep this incident report?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            <span className="block">
                                {draftRecovery.savedAt
                                    ? `Your last secure save was at ${formatTime(draftRecovery.savedAt)}.`
                                    : 'Your report has not been saved securely yet.'}{' '}
                                Save and close to continue later, or discard it
                                permanently.
                            </span>
                            {draftRecovery.message ? (
                                <span className="mt-2 block font-medium text-status-critical">
                                    {draftRecovery.message}
                                </span>
                            ) : null}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            className="frontline-tap frontline-focus"
                            disabled={closeBusy}
                        >
                            Keep editing
                        </AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            className="frontline-tap frontline-focus"
                            onClick={() => void discardAndClose()}
                            disabled={closeBusy}
                        >
                            Discard draft
                        </Button>
                        <Button
                            type="button"
                            className="frontline-tap frontline-focus"
                            onClick={() => void saveAndClose()}
                            disabled={
                                closeBusy ||
                                !draftRecovery.loaded ||
                                draftRecovery.resumeAvailable ||
                                draftRecovery.recoveryBlocked
                            }
                        >
                            {closeBusy ? 'Working…' : 'Save and close'}
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function ValidationSummary({ messages }: { messages: string[] }) {
    if (messages.length === 0) {
        return null;
    }

    return (
        <div
            role="alert"
            className="flex gap-2.5 rounded-lg border border-status-critical/35 bg-status-critical-bg p-3 text-sm text-foreground"
        >
            <AlertTriangle
                className="mt-0.5 h-4 w-4 shrink-0 text-status-critical"
                aria-hidden="true"
            />
            <div>
                <p className="font-semibold text-status-critical">
                    Some details need attention
                </p>
                <ul className="mt-1 list-disc space-y-1 pl-4">
                    {messages.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Steps                                                              */
/* ------------------------------------------------------------------ */

const INCIDENT_TYPE_TILES: {
    key: string;
    label: string;
    description?: string;
    icon: LucideIcon;
}[] = [
    { key: 'injury', label: 'Injury', icon: HeartPulse },
    { key: 'fall', label: 'Fall', icon: Activity },
    { key: 'behaviour', label: 'Behaviour', icon: Users },
    { key: 'medication', label: 'Medication', icon: Pill },
    { key: 'safeguarding', label: 'Safeguarding', icon: ShieldAlert },
    { key: 'property_damage', label: 'Property damage', icon: AlertTriangle },
    { key: 'missing_person', label: 'Missing person', icon: Search },
    { key: 'complaint', label: 'Complaint', icon: X },
    { key: 'other', label: 'Other', icon: HelpCircle },
];

function PeopleStep({
    d,
    setData,
    errors,
    clients,
    clientOptions,
    siteOptions,
    isNearMiss,
}: {
    d: { type: string; client_id: string; site_id: string };
    setData: SetData;
    errors: Partial<Record<string, string>>;
    clients: IncidentReportClient[];
    clientOptions: { value: string; label: string }[];
    siteOptions: { value: string; label: string }[];
    isNearMiss: boolean;
}) {
    const selectClient = (clientId: string) => {
        setData('client_id', clientId);
        const clientSiteId = clients.find(
            (client) => String(client.id) === clientId,
        )?.site_id;

        if (clientSiteId) {
            setData('site_id', String(clientSiteId));
        }
    };

    return (
        <div className="flex flex-col gap-5">
            <StepHead
                icon={isNearMiss ? Eye : ClipboardList}
                title={isNearMiss ? 'Report a near miss' : 'Type & people'}
                blurb={
                    isNearMiss
                        ? 'No harm was done — this is blame-free and helps prevent future incidents. Just the essentials.'
                        : 'Choose the kind of incident and who it involves.'
                }
            />
            {!isNearMiss ? (
                <Field label="Incident type" required error={errors.type}>
                    <TilePicker
                        value={d.type}
                        onChange={(v) => setData('type', v)}
                        options={INCIDENT_TYPE_TILES}
                        cols={3}
                    />
                </Field>
            ) : null}
            <Field label="Client" required error={errors.client_id}>
                <SelectInput
                    value={d.client_id}
                    onChange={selectClient}
                    placeholder="Select client"
                    options={clientOptions}
                />
            </Field>
            <Field
                label="Site"
                error={errors.site_id}
                hint="Use the site where the incident happened."
            >
                <SelectInput
                    value={d.site_id}
                    onChange={(value) => setData('site_id', value)}
                    placeholder="Select site"
                    options={siteOptions}
                />
            </Field>
            <FieldErr>{errors.shift_id}</FieldErr>
        </div>
    );
}

function WhatStep({
    d,
    setData,
    errors,
    isNearMiss,
}: {
    d: {
        description: string;
        occurred_date: string;
        occurred_time: string;
    };
    setData: SetData;
    errors: Partial<Record<string, string>>;
    isNearMiss: boolean;
}) {
    return (
        <div className="flex flex-col gap-5">
            <StepHead
                icon={Search}
                title="What happened"
                blurb={
                    isNearMiss
                        ? 'Briefly, what happened (or nearly happened)?'
                        : 'Describe what happened, factually.'
                }
            />
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Incident date" error={errors.occurred_at}>
                    <Input
                        type="date"
                        aria-label="Incident date"
                        value={d.occurred_date}
                        onChange={(event) =>
                            setData('occurred_date', event.target.value)
                        }
                    />
                </Field>
                <Field label="Incident time">
                    <Input
                        type="time"
                        aria-label="Incident time"
                        value={d.occurred_time}
                        onChange={(event) =>
                            setData('occurred_time', event.target.value)
                        }
                    />
                </Field>
            </div>
            <Field label="Description" required error={errors.description}>
                <Textarea
                    aria-label="Description"
                    rows={6}
                    value={d.description}
                    onChange={(e) => setData('description', e.target.value)}
                    placeholder="What happened, where, and who was involved…"
                />
            </Field>
            <InfoCard icon={ListTodo} tone="info">
                Photos &amp; documents can be attached from the incident once
                it&apos;s created (while it&apos;s a draft).
            </InfoCard>
        </div>
    );
}

const SEVERITY_TILES = [
    {
        key: 'low',
        label: 'Low',
        description: 'No / minor harm',
        icon: CheckCircle2,
    },
    {
        key: 'medium',
        label: 'Medium',
        description: 'Some harm or risk',
        icon: Activity,
    },
    {
        key: 'high',
        label: 'High',
        description: 'Serious harm or risk',
        icon: AlertTriangle,
    },
    {
        key: 'critical',
        label: 'Critical',
        description: 'Fatal or life-threatening',
        icon: ShieldAlert,
    },
];

function SeverityStep({
    d,
    setData,
}: {
    d: {
        severity: string;
        immediate_action_taken: string;
        witnesses: string;
        harm_or_injury: string;
        consequence: string;
    };
    setData: SetData;
}) {
    return (
        <div className="flex flex-col gap-5">
            <StepHead
                icon={Activity}
                title="Severity & immediate actions"
                blurb="How serious was it, and what was done straight away?"
            />
            <Field label="Severity" required>
                <TilePicker
                    value={d.severity}
                    onChange={(v) => setData('severity', v)}
                    options={SEVERITY_TILES}
                    cols={2}
                />
            </Field>
            <Field
                label="Harm or injury"
                hint="What harm was observed, including no harm"
            >
                <Textarea
                    aria-label="Harm or injury"
                    rows={2}
                    value={d.harm_or_injury}
                    onChange={(event) =>
                        setData('harm_or_injury', event.target.value)
                    }
                    placeholder="e.g. No visible injury, or bruising to left shoulder"
                />
            </Field>
            <Field label="Consequence" hint="What resulted from the incident">
                <Textarea
                    aria-label="Consequence"
                    rows={2}
                    value={d.consequence}
                    onChange={(event) =>
                        setData('consequence', event.target.value)
                    }
                    placeholder="e.g. Clinical review arranged and mobility plan paused"
                />
            </Field>
            <Field label="Immediate action taken">
                <Textarea
                    rows={3}
                    value={d.immediate_action_taken}
                    onChange={(e) =>
                        setData('immediate_action_taken', e.target.value)
                    }
                    placeholder="First aid given, area made safe, GP called…"
                />
            </Field>
            <Field label="Witnesses">
                <Input
                    value={d.witnesses}
                    onChange={(e) => setData('witnesses', e.target.value)}
                    placeholder="Names of any witnesses"
                />
            </Field>
        </div>
    );
}

const POTENTIAL_TILES = [
    {
        key: 'low',
        label: 'Low',
        description: 'Minor at worst',
        icon: CheckCircle2,
    },
    {
        key: 'medium',
        label: 'Medium',
        description: 'Some harm possible',
        icon: Activity,
    },
    {
        key: 'high',
        label: 'High',
        description: 'Serious harm possible',
        icon: AlertTriangle,
    },
    {
        key: 'critical',
        label: 'Critical',
        description: 'Could have been fatal',
        icon: ShieldAlert,
    },
];

function CouldStep({
    d,
    setData,
}: {
    d: {
        potential_severity: string;
        potential_consequence: string;
        hazard: string;
        immediate_action_taken: string;
    };
    setData: SetData;
}) {
    return (
        <div className="flex flex-col gap-5">
            <StepHead
                icon={AlertTriangle}
                title="What could have happened"
                blurb="Capture the potential — this is what makes near misses so valuable."
            />
            <Field label="Potential severity" required>
                <TilePicker
                    value={d.potential_severity}
                    onChange={(v) => setData('potential_severity', v)}
                    options={POTENTIAL_TILES}
                    cols={2}
                />
            </Field>
            <Field label="What could have happened">
                <Input
                    value={d.potential_consequence}
                    onChange={(e) =>
                        setData('potential_consequence', e.target.value)
                    }
                    placeholder="e.g. A resident could have fallen down the stairs"
                />
            </Field>
            <Field label="Hazard / contributing factor">
                <Input
                    value={d.hazard}
                    onChange={(e) => setData('hazard', e.target.value)}
                    placeholder="e.g. Wet floor, no warning sign"
                />
            </Field>
            <Field label="Immediate control taken">
                <Textarea
                    rows={2}
                    value={d.immediate_action_taken}
                    onChange={(e) =>
                        setData('immediate_action_taken', e.target.value)
                    }
                    placeholder="What did you do to make it safe?"
                />
            </Field>
        </div>
    );
}

const WORKSAFE_STATUS_OPTIONS = [
    { value: 'pending', label: 'Needs H&S assessment' },
    { value: 'notified', label: 'WorkSafe notified' },
    { value: 'acknowledged', label: 'Acknowledged by WorkSafe' },
];

function NotifiableStep({
    d,
    setData,
    isNearMiss,
}: {
    d: {
        is_notifiable: boolean;
        worksafe_reference: string;
        worksafe_notification_status: string;
        site_preserved: boolean;
    };
    setData: SetData;
    isNearMiss: boolean;
}) {
    return (
        <div className="flex flex-col gap-5">
            <StepHead
                icon={isNearMiss ? ShieldQuestion : ShieldAlert}
                title={
                    isNearMiss
                        ? 'Dangerous occurrence check'
                        : 'WorkSafe NZ notifiable check'
                }
                blurb="Under the Health and Safety at Work Act 2015, some events must be notified to WorkSafe NZ."
            />
            <InfoCard icon={ShieldAlert} tone="warn">
                {isNearMiss ? (
                    <>
                        A no-harm event can still be a{' '}
                        <span className="font-semibold">
                            notifiable dangerous occurrence
                        </span>{' '}
                        (e.g. an uncontrolled escape, electric shock, or
                        collapse). If unsure, flag it — a manager will confirm.
                    </>
                ) : (
                    <>
                        Notifiable events include a{' '}
                        <span className="font-semibold">death</span>, a{' '}
                        <span className="font-semibold">
                            notifiable injury/illness
                        </span>{' '}
                        (e.g. hospitalisation), or a{' '}
                        <span className="font-semibold">
                            notifiable incident
                        </span>{' '}
                        (a serious risk to health or safety). If any apply, flag
                        it.
                    </>
                )}
            </InfoCard>
            <label className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-sm">
                <input
                    type="checkbox"
                    aria-label="Potentially notifiable"
                    checked={d.is_notifiable}
                    onChange={(event) => {
                        setData('is_notifiable', event.target.checked);
                        if (
                            event.target.checked &&
                            !d.worksafe_notification_status
                        ) {
                            setData('worksafe_notification_status', 'pending');
                        }
                    }}
                    className="h-4 w-4 rounded border-border"
                />
                <span className="font-medium text-foreground">
                    This may be WorkSafe NZ–notifiable — flag it for H&amp;S to
                    confirm.
                </span>
            </label>
            {d.is_notifiable ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Provisional WorkSafe status">
                        <SelectInput
                            value={d.worksafe_notification_status}
                            onChange={(value) =>
                                setData('worksafe_notification_status', value)
                            }
                            placeholder="Select status"
                            ariaLabel="WorkSafe status"
                            options={WORKSAFE_STATUS_OPTIONS}
                        />
                    </Field>
                    <Field label="WorkSafe reference">
                        <Input
                            aria-label="WorkSafe reference"
                            value={d.worksafe_reference}
                            onChange={(event) =>
                                setData(
                                    'worksafe_reference',
                                    event.target.value,
                                )
                            }
                            placeholder="If already provided"
                        />
                    </Field>
                </div>
            ) : null}
            <label className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-sm">
                <input
                    type="checkbox"
                    aria-label="Site preserved"
                    checked={d.site_preserved}
                    onChange={(event) =>
                        setData('site_preserved', event.target.checked)
                    }
                    className="h-4 w-4 rounded border-border"
                />
                <span className="font-medium text-foreground">
                    The incident site has been preserved pending H&amp;S
                    direction.
                </span>
            </label>
        </div>
    );
}

function FollowupsStep({
    d,
    staff,
    canManageFollowups,
    errors,
    onAdd,
    onUpdate,
    onRemove,
}: {
    d: { followups: FollowupDraft[] };
    staff: StaffOpt[];
    canManageFollowups: boolean;
    errors: Partial<Record<string, string>>;
    onAdd: () => void;
    onUpdate: (i: number, patch: Partial<FollowupDraft>) => void;
    onRemove: (i: number) => void;
}) {
    const staffOptions = staff.map((s) => ({
        value: String(s.id),
        label: s.name,
    }));
    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={ListTodo}
                title="Follow-ups"
                blurb="Optional — add any operational tasks to track (e.g. update the care plan)."
            />
            <FieldErr>{errors.followups}</FieldErr>
            {d.followups.map((f, i) => {
                const notesError = errors[`followups.${i}.notes`];
                const assigneeError =
                    errors[`followups.${i}.assigned_to_user_id`];
                const dueAtError = errors[`followups.${i}.due_at`];

                return (
                    <div
                        key={i}
                        className="flex flex-col gap-2 rounded-xl border border-border p-3"
                    >
                        <Field label={`Task ${i + 1}`} error={notesError}>
                            <Textarea
                                rows={2}
                                value={f.notes}
                                onChange={(e) =>
                                    onUpdate(i, { notes: e.target.value })
                                }
                                placeholder="What needs doing?"
                            />
                        </Field>
                        <div
                            className={
                                canManageFollowups
                                    ? 'grid gap-2 sm:grid-cols-2'
                                    : 'grid gap-2'
                            }
                        >
                            {canManageFollowups ? (
                                <Field label="Assign to" error={assigneeError}>
                                    <SelectInput
                                        value={f.assigned_to_user_id}
                                        onChange={(v) =>
                                            onUpdate(i, {
                                                assigned_to_user_id: v,
                                            })
                                        }
                                        placeholder="Unassigned"
                                        options={staffOptions}
                                    />
                                </Field>
                            ) : (
                                <FieldErr>{assigneeError}</FieldErr>
                            )}
                            <Field label="Due" error={dueAtError}>
                                <Input
                                    type="date"
                                    value={f.due_at}
                                    onChange={(e) =>
                                        onUpdate(i, { due_at: e.target.value })
                                    }
                                />
                            </Field>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="self-end text-status-critical hover:text-status-critical"
                            onClick={() => onRemove(i)}
                        >
                            <Trash2 className="mr-1.5 h-3.5 w-3.5" /> Remove
                        </Button>
                    </div>
                );
            })}
            <Button
                variant="outline"
                size="sm"
                className="self-start"
                onClick={onAdd}
            >
                <Plus className="mr-1.5 h-3.5 w-3.5" /> Add follow-up
            </Button>
        </div>
    );
}

function ReviewStep({
    d,
    isNearMiss,
    clients,
    staff,
    canManageFollowups,
    goto,
}: {
    d: IncidentReportData;
    isNearMiss: boolean;
    clients: IncidentReportClient[];
    staff: StaffOpt[];
    canManageFollowups: boolean;
    goto: (i: number) => void;
}) {
    const client = clients.find((c) => String(c.id) === d.client_id);
    const clientName = client
        ? `${client.first_name} ${client.last_name}`
        : '—';
    const staffName = (id: string) =>
        staff.find((s) => String(s.id) === id)?.name ?? 'Unassigned';
    return (
        <div className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Review & submit"
                blurb="Check the details, then submit."
            />
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard
                    icon={Users}
                    title={isNearMiss ? 'Who & where' : 'Type & people'}
                    onEdit={() => goto(0)}
                >
                    {!isNearMiss ? (
                        <ReviewRow
                            label="Type"
                            value={
                                d.type ? d.type.replace(/_/g, ' ') : undefined
                            }
                        />
                    ) : (
                        <ReviewRow label="Type" value="Near miss" />
                    )}
                    <ReviewRow label="Client" value={clientName} />
                </ReviewCard>
                <ReviewCard
                    icon={Search}
                    title="What happened"
                    onEdit={() => goto(1)}
                >
                    <ReviewRow
                        label="Occurred"
                        value={
                            d.occurred_date
                                ? `${d.occurred_date}${d.occurred_time ? ` ${d.occurred_time}` : ''}`
                                : undefined
                        }
                    />
                    <ReviewRow label="Description" value={d.description} />
                </ReviewCard>
                {isNearMiss ? (
                    <ReviewCard
                        icon={AlertTriangle}
                        title="Potential"
                        onEdit={() => goto(2)}
                    >
                        <ReviewRow
                            label="Could have been"
                            value={d.potential_severity}
                        />
                        <ReviewRow
                            label="Consequence"
                            value={d.potential_consequence}
                        />
                        <ReviewRow label="Hazard" value={d.hazard} />
                    </ReviewCard>
                ) : (
                    <ReviewCard
                        icon={Activity}
                        title="Severity & actions"
                        onEdit={() => goto(2)}
                    >
                        <ReviewRow label="Severity" value={d.severity} />
                        <ReviewRow
                            label="Harm or injury"
                            value={d.harm_or_injury}
                        />
                        <ReviewRow label="Consequence" value={d.consequence} />
                        <ReviewRow
                            label="Immediate action"
                            value={d.immediate_action_taken}
                        />
                    </ReviewCard>
                )}
                <ReviewCard
                    icon={ShieldAlert}
                    title="WorkSafe"
                    onEdit={() => goto(3)}
                >
                    <ReviewRow
                        label="Notifiable"
                        value={
                            d.is_notifiable ? 'Flagged for confirmation' : 'No'
                        }
                    />
                    <ReviewRow
                        label="Status"
                        value={
                            d.is_notifiable
                                ? WORKSAFE_STATUS_OPTIONS.find(
                                      (option) =>
                                          option.value ===
                                          d.worksafe_notification_status,
                                  )?.label || 'Needs H&S assessment'
                                : undefined
                        }
                    />
                    <ReviewRow label="Reference" value={d.worksafe_reference} />
                    <ReviewRow
                        label="Site preserved"
                        value={d.site_preserved ? 'Yes' : 'No'}
                    />
                </ReviewCard>
                {d.followups.filter((f) => f.notes.trim()).length ? (
                    <ReviewCard
                        icon={ListTodo}
                        title="Follow-ups"
                        span
                        onEdit={() => goto(4)}
                    >
                        {d.followups
                            .filter((f) => f.notes.trim())
                            .map((f, i) => (
                                <ReviewRow
                                    key={i}
                                    label={
                                        canManageFollowups
                                            ? staffName(f.assigned_to_user_id)
                                            : 'Unassigned'
                                    }
                                    value={f.notes}
                                />
                            ))}
                    </ReviewCard>
                ) : null}
            </div>
        </div>
    );
}

type IncidentReportData = {
    type: string;
    client_id: string;
    occurred_date: string;
    occurred_time: string;
    description: string;
    severity: string;
    potential_severity: string;
    potential_consequence: string;
    hazard: string;
    immediate_action_taken: string;
    witnesses: string;
    harm_or_injury: string;
    consequence: string;
    is_notifiable: boolean;
    worksafe_reference: string;
    worksafe_notification_status: string;
    site_preserved: boolean;
    followups: FollowupDraft[];
};
