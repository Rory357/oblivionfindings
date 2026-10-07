import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTimeInZone } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { History, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    isRevision,
    object,
    useSettingsCommand,
} from './workforce-settings-outcome';

export type StaffingValues = {
    max_hours_per_day: number;
    max_hours_per_week: number;
    warning_threshold_weekly: number;
    min_rest_between_shifts_hours: number;
    max_consecutive_days: number;
};
export type StaffingRules = {
    values: StaffingValues;
    defaults: StaffingValues;
    revision: string;
    source: 'deployment_defaults' | 'saved_override';
    limits: { max_rest_hours: number; max_consecutive_days: number };
    can_edit: boolean;
    can_view_history: boolean;
    scope: 'organisation';
    urls: { update: string | null; history: string | null };
};
const fields: {
    key: keyof StaffingValues;
    label: string;
    hint: string;
    unit: string;
}[] = [
    {
        key: 'max_hours_per_day',
        label: 'Daily hours threshold',
        hint: 'The existing daily workload check uses this threshold.',
        unit: 'hours',
    },
    {
        key: 'max_hours_per_week',
        label: 'Weekly hours threshold',
        hint: 'The existing weekly workload check uses this threshold.',
        unit: 'hours',
    },
    {
        key: 'warning_threshold_weekly',
        label: 'Weekly warning threshold',
        hint: 'Highlights scheduled hours approaching the weekly threshold.',
        unit: 'hours',
    },
    {
        key: 'min_rest_between_shifts_hours',
        label: 'Minimum rest between shifts',
        hint: 'The required interval from the end of one duty to the next.',
        unit: 'hours',
    },
    {
        key: 'max_consecutive_days',
        label: 'Consecutive working days',
        hint: 'The threshold for consecutive days with scheduled work.',
        unit: 'days',
    },
];
export function parseStaffingRules(value: unknown): StaffingRules | null {
    const rules = object(value);
    const values = object(rules?.values);
    const defaults = object(rules?.defaults);
    const limits = object(rules?.limits);
    const urls = object(rules?.urls);
    if (
        !rules ||
        !values ||
        !defaults ||
        !limits ||
        !urls ||
        !isRevision(rules.revision) ||
        !['deployment_defaults', 'saved_override'].includes(
            String(rules.source),
        ) ||
        rules.scope !== 'organisation' ||
        typeof rules.can_edit !== 'boolean' ||
        typeof rules.can_view_history !== 'boolean'
    )
        return null;
    if (
        fields.some(
            ({ key }) =>
                typeof values[key] !== 'number' ||
                !Number.isFinite(values[key]) ||
                typeof defaults[key] !== 'number' ||
                !Number.isFinite(defaults[key]),
        )
    )
        return null;
    if (
        typeof limits.max_rest_hours !== 'number' ||
        !Number.isFinite(limits.max_rest_hours) ||
        typeof limits.max_consecutive_days !== 'number' ||
        !Number.isFinite(limits.max_consecutive_days)
    )
        return null;
    if (
        urls.update !== null &&
        urls.update !== '/operations/workforce-settings/staffing-rules' &&
        !(
            typeof urls.update === 'string' &&
            typeof window !== 'undefined' &&
            urls.update ===
                window.location.origin +
                    '/operations/workforce-settings/staffing-rules'
        )
    )
        return null;
    if (
        urls.history !== null &&
        urls.history !== '/operations/workforce-settings/history' &&
        !(
            typeof urls.history === 'string' &&
            typeof window !== 'undefined' &&
            urls.history ===
                window.location.origin +
                    '/operations/workforce-settings/history'
        )
    )
        return null;
    return rules as StaffingRules;
}

type Draft = Record<keyof StaffingValues, string>;
const toDraft = (values: StaffingValues): Draft =>
    Object.fromEntries(
        fields.map(({ key }) => [key, String(values[key])]),
    ) as Draft;
type HistoryPage = {
    data: {
        id: number;
        at: string;
        actor: { id: number; name: string } | null;
        reason: string;
        changes: { key: string; before: number; after: number }[];
    }[];
    current_page: number;
    last_page: number;
    total: number;
};

export function WorkforceStaffingRules({
    actorId,
    rules,
    visible,
    query,
    onDirtyChange,
    onUncertainChange,
    onShow,
}: {
    actorId: number;
    rules: StaffingRules;
    visible: boolean;
    query: string;
    onDirtyChange: (dirty: boolean) => void;
    onUncertainChange?: (uncertain: boolean) => void;
    onShow: () => void;
}) {
    const [saved, setSaved] = useState(rules);
    const [draft, setDraft] = useState(() => toDraft(rules.values));
    const [reason, setReason] = useState('');
    const [review, setReview] = useState(false);
    const command = useSettingsCommand(actorId);
    const { processing, uncertain, locked, error, setError } = command;
    const [recovery, setRecovery] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [historyPage, setHistoryPage] = useState<number | null>(null);
    const [history, setHistory] = useState<HistoryPage | null>(null);
    const [historyError, setHistoryError] = useState<string | null>(null);
    const [historyLoading, setHistoryLoading] = useState(false);
    const changed = fields.filter(
        ({ key }) =>
            draft[key].trim() === '' ||
            Number(draft[key]) !== saved.values[key],
    );
    const observedRevision = useRef(rules.revision);
    const dirty = changed.length > 0;
    const canEdit = rules.can_edit && saved.can_edit && !!rules.urls.update;
    const hasDraft = dirty || !!reason.trim() || uncertain || processing;
    useEffect(() => onDirtyChange(hasDraft), [hasDraft, onDirtyChange]);
    useEffect(
        () => onUncertainChange?.(uncertain || processing),
        [uncertain, processing, onUncertainChange],
    );
    useEffect(() => {
        if (observedRevision.current === rules.revision) return;
        observedRevision.current = rules.revision;
        if (!hasDraft && !command.pending.current) {
            setSaved(rules);
            setDraft(toDraft(rules.values));
        }
    }, [rules, hasDraft, command.pending]);
    const values = Object.fromEntries(
        fields.map(({ key }) => [key, Number(draft[key])]),
    ) as StaffingValues;
    const invalid = fields.filter(({ key }) => {
        const n = values[key];
        if (draft[key].trim() === '' || !Number.isFinite(n)) return true;
        if (key === 'max_consecutive_days')
            return (
                !Number.isInteger(n) ||
                n < 1 ||
                n > rules.limits.max_consecutive_days
            );
        if (key === 'min_rest_between_shifts_hours')
            return n < 0 || n > rules.limits.max_rest_hours;
        if (key === 'warning_threshold_weekly')
            return n < 0 || n > values.max_hours_per_week;
        return n <= 0;
    });
    useEffect(() => {
        if (
            historyPage === null ||
            !rules.can_view_history ||
            !rules.urls.history
        )
            return;
        const controller = new AbortController();
        setHistoryLoading(true);
        setHistoryError(null);
        setHistory(null);
        fetch(`${rules.urls.history}?page=${historyPage}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        response.status === 403
                            ? 'You no longer have access to staffing rule history.'
                            : 'Could not load the change history.',
                    );
                return response.json() as Promise<HistoryPage>;
            })
            .then(setHistory)
            .catch((failure: Error) => {
                if (!controller.signal.aborted)
                    setHistoryError(failure.message);
            })
            .finally(() => {
                if (!controller.signal.aborted) setHistoryLoading(false);
            });
        return () => controller.abort();
    }, [historyPage, rules.can_view_history, rules.urls.history]);
    const discard = () => {
        if (command.pending.current || uncertain) return;
        setDraft(toDraft(saved.values));
        setReason('');
        setError(null);
        setReview(false);
    };
    const reloadSaved = () =>
        command.check(
            ['staffingRules', 'workforceSettings'],
            (props) => parseStaffingRules(props.staffingRules),
            (latest) => {
                observedRevision.current = latest.revision;
                setSaved(latest);
                setReview(false);
                setMessage(null);
                setRecovery(
                    'Current rules loaded. Your entries and reason are retained; compare them before saving. This read does not confirm the earlier save.',
                );
            },
        );
    const save = () => {
        if (
            !dirty ||
            !canEdit ||
            locked ||
            invalid.length ||
            !reason.trim() ||
            !rules.urls.update
        )
            return;
        const submitted = { ...values };
        const submittedReason = reason.trim();
        command.run(
            {
                action: 'staffing_rules',
                actor_id: actorId,
                expected_revision: saved.revision,
                values: submitted,
            },
            (options) =>
                router.patch(
                    rules.urls.update!,
                    {
                        expected_revision: saved.revision,
                        values: submitted,
                        reason: submittedReason,
                    },
                    options,
                ),
            (receipt) => {
                setSaved((previous) => ({
                    ...previous,
                    values: submitted,
                    revision: receipt.revision,
                    source: receipt.changed
                        ? 'saved_override'
                        : previous.source,
                }));
                setDraft(toDraft(submitted));
                setReason('');
                setReview(false);
                setRecovery(null);
                setMessage(
                    receipt.changed
                        ? 'Staffing rules saved. Duty rechecks are queued; existing assignments remain in place.'
                        : 'The submitted rules already match the saved values. No new recheck was requested.',
                );
            },
        );
    };
    return (
        <>
            {!visible && hasDraft ? (
                <SettingsNotice>
                    You have unsaved staffing rules.{' '}
                    <Button variant="link" onClick={onShow}>
                        Review staffing rules
                    </Button>
                </SettingsNotice>
            ) : null}
            <div hidden={!visible} className="space-y-4">
                <SettingsNotice role="note">
                    These organisation-wide thresholds are shared with HR
                    workload and roster eligibility checks. Saving queues
                    assigned duties for a fresh check and preserves their
                    assignments. Queued checks may still be pending or need
                    attention.
                </SettingsNotice>
                {message ? (
                    <p role="status" className="text-body text-status-success">
                        {message}
                    </p>
                ) : null}
                {recovery && (
                    <div role="status">
                        <SettingsNotice role="note">{recovery}</SettingsNotice>
                    </div>
                )}
                {error ? (
                    <SettingsNotice role="alert">
                        {error}{' '}
                        <Button
                            variant="link"
                            onClick={reloadSaved}
                            disabled={processing}
                        >
                            Check current rules
                        </Button>
                    </SettingsNotice>
                ) : null}
                <Card className="gap-0 overflow-hidden p-0">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                        <div>
                            <h2 className="text-section-title">Hours & rest</h2>
                            <p className="text-caption mt-1">
                                {saved.source === 'saved_override'
                                    ? 'Saved organisation rules'
                                    : 'Current deployment defaults'}{' '}
                                ·{' '}
                                {canEdit
                                    ? 'Changes require a reason and review'
                                    : 'View only — managed by authorised HR settings staff'}
                            </p>
                        </div>
                        {rules.can_view_history && rules.urls.history ? (
                            <Button
                                variant="outline"
                                onClick={() => setHistoryPage(1)}
                            >
                                <History className="mr-2 size-4" />
                                Change history
                            </Button>
                        ) : null}
                    </div>
                    <div className="divide-y divide-border">
                        {fields
                            .filter((field) =>
                                `${field.label} ${field.hint}`
                                    .toLowerCase()
                                    .includes(query.toLowerCase()),
                            )
                            .map(({ key, label, hint, unit }) => (
                                <div
                                    key={key}
                                    data-setting={key}
                                    className="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Label htmlFor={`staffing-${key}`}>
                                                {label}
                                            </Label>
                                            {changed.some(
                                                (field) => field.key === key,
                                            ) ? (
                                                <StatusBadge
                                                    variant="info"
                                                    size="sm"
                                                >
                                                    Changed — not saved
                                                </StatusBadge>
                                            ) : null}
                                        </div>
                                        <p className="text-caption mt-1">
                                            {hint}
                                        </p>
                                        <p className="text-caption mt-1">
                                            Deployment default:{' '}
                                            {rules.defaults[key]} {unit}
                                        </p>
                                    </div>
                                    {canEdit ? (
                                        <div className="flex items-center gap-2">
                                            <Input
                                                id={`staffing-${key}`}
                                                type="number"
                                                inputMode="decimal"
                                                step={
                                                    key ===
                                                    'max_consecutive_days'
                                                        ? 1
                                                        : 'any'
                                                }
                                                className="w-28"
                                                value={draft[key]}
                                                disabled={locked}
                                                aria-invalid={invalid.some(
                                                    (field) =>
                                                        field.key === key,
                                                )}
                                                onChange={(event) => {
                                                    setDraft((current) => ({
                                                        ...current,
                                                        [key]: event.target
                                                            .value,
                                                    }));
                                                    setMessage(null);
                                                }}
                                            />
                                            <span className="text-caption">
                                                {unit}
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="text-body font-semibold">
                                            {rules.values[key]} {unit}
                                        </span>
                                    )}
                                </div>
                            ))}
                    </div>
                </Card>
                {invalid.length ? (
                    <SettingsNotice role="alert">
                        Check{' '}
                        {invalid
                            .map((field) => field.label.toLowerCase())
                            .join(', ')}
                        . Daily and weekly hours must be above zero. The warning
                        must be between zero and weekly hours; rest must be
                        between zero and {rules.limits.max_rest_hours} hours;
                        consecutive days must be a whole number from 1 to{' '}
                        {rules.limits.max_consecutive_days}.
                    </SettingsNotice>
                ) : null}
                {canEdit ? (
                    <Card className="sticky bottom-3 z-20 flex-row flex-wrap items-center justify-between gap-4 p-4 shadow-md">
                        <div>
                            <p className="text-body font-semibold">
                                {changed.length} unsaved staffing{' '}
                                {changed.length === 1 ? 'rule' : 'rules'}
                            </p>
                            <p className="text-caption">
                                Applies across the organisation after saving.
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                            <Button
                                variant="outline"
                                disabled={!hasDraft || locked}
                                onClick={discard}
                            >
                                Discard rule changes
                            </Button>
                            <Button
                                disabled={!dirty || locked || !!invalid.length}
                                onClick={() => setReview(true)}
                            >
                                Review staffing rules
                            </Button>
                        </div>
                    </Card>
                ) : null}
            </div>
            {review ? (
                <SettingsModal
                    title="Review staffing rules"
                    description="These values change shared workload checks. Existing permission, warning and override rules still apply."
                    onClose={() => {
                        if (!command.pending.current) setReview(false);
                    }}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                disabled={processing}
                                onClick={() => setReview(false)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                onClick={save}
                                disabled={
                                    locked ||
                                    !canEdit ||
                                    !dirty ||
                                    !!invalid.length ||
                                    !reason.trim()
                                }
                            >
                                {processing ? (
                                    <Loader2 className="mr-2 size-4 animate-spin" />
                                ) : null}
                                Save staffing rules
                            </Button>
                        </>
                    }
                >
                    {changed.map(({ key, label, unit }) => (
                        <div key={key} className="rounded-lg border p-3">
                            <p className="text-body font-semibold">{label}</p>
                            <p className="text-caption">
                                {saved.values[key]} → {draft[key]} {unit}
                            </p>
                        </div>
                    ))}
                    <div className="space-y-2">
                        <Label htmlFor="staffing-reason">
                            Reason for this change
                        </Label>
                        <Textarea
                            id="staffing-reason"
                            value={reason}
                            maxLength={2000}
                            disabled={locked}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder="Explain why these thresholds are changing"
                        />
                        <p className="text-caption">
                            Saved with the before-and-after values in change
                            history.
                        </p>
                    </div>
                    {error ? (
                        <SettingsNotice role="alert">
                            {error}{' '}
                            <Button
                                variant="link"
                                disabled={processing}
                                onClick={reloadSaved}
                            >
                                Check current rules
                            </Button>
                        </SettingsNotice>
                    ) : null}
                </SettingsModal>
            ) : null}
            {historyPage !== null &&
            rules.can_view_history &&
            rules.urls.history ? (
                <SettingsModal
                    title="Staffing rule change history"
                    description="Saved changes to organisation-wide hours and rest thresholds."
                    onClose={() => setHistoryPage(null)}
                >
                    {historyLoading ? (
                        <p role="status">Loading change history…</p>
                    ) : null}
                    {historyError ? (
                        <SettingsNotice role="alert">
                            {historyError}{' '}
                            <Button
                                variant="link"
                                onClick={() => {
                                    setHistoryPage(null);
                                }}
                            >
                                Close and try again
                            </Button>
                        </SettingsNotice>
                    ) : null}
                    {history && history.data.length === 0 ? (
                        <p className="text-body">
                            No staffing rule changes recorded.
                        </p>
                    ) : null}
                    {history?.data.map((entry) => (
                        <article
                            key={entry.id}
                            className="space-y-2 rounded-lg border p-3"
                        >
                            <p className="text-body font-semibold">
                                {formatDateTimeInZone(entry.at)}
                            </p>
                            <p className="text-caption">
                                {entry.actor?.name ??
                                    'Recorded actor unavailable'}
                            </p>
                            <p className="text-body whitespace-pre-wrap">
                                {entry.reason}
                            </p>
                            <ul className="text-caption space-y-1">
                                {entry.changes.map((change) => (
                                    <li key={change.key}>
                                        {fields.find(
                                            (field) => field.key === change.key,
                                        )?.label ?? change.key}
                                        : {change.before} → {change.after}
                                    </li>
                                ))}
                            </ul>
                        </article>
                    ))}
                    {history && history.last_page > 1 ? (
                        <nav
                            aria-label="Staffing history pages"
                            className="flex items-center justify-between gap-3"
                        >
                            <Button
                                variant="outline"
                                disabled={history.current_page <= 1}
                                onClick={() =>
                                    setHistoryPage(history.current_page - 1)
                                }
                            >
                                Previous
                            </Button>
                            <span className="text-caption">
                                Page {history.current_page} of{' '}
                                {history.last_page}
                            </span>
                            <Button
                                variant="outline"
                                disabled={
                                    history.current_page >= history.last_page
                                }
                                onClick={() =>
                                    setHistoryPage(history.current_page + 1)
                                }
                            >
                                Next
                            </Button>
                        </nav>
                    ) : null}
                </SettingsModal>
            ) : null}
        </>
    );
}
