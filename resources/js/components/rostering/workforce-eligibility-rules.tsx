import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

type Mode = 'warn' | 'block';
type HouseApproach = 'per_requirement' | 'all_workers' | 'minimum_staff';
type EligibilityValues = {
    unmapped_mandatory_qualification: Mode;
    house_qualification_approach: HouseApproach;
};
export type EligibilityRules = {
    values: EligibilityValues;
    defaults: EligibilityValues;
    revision: string;
    source: 'deployment_defaults' | 'saved_override';
    can_edit: boolean;
    can_view_history: boolean;
    scope: 'organisation';
    urls: { update: string | null; history: string | null };
};
const fields: {
    key: keyof EligibilityValues;
    id: string;
    label: string;
    hint: string;
    options: Record<string, string>;
}[] = [
    {
        key: 'unmapped_mandatory_qualification',
        id: 'workforce-unmapped-qualification',
        label: 'Unrecognised mandatory qualification',
        hint: 'Choose what happens when a mandatory requirement cannot be matched to a recognised qualification.',
        options: {
            warn: 'Warn — authorised manager may proceed',
            block: 'Block until resolved',
        },
    },
    {
        key: 'house_qualification_approach',
        id: 'workforce-house-qualification-approach',
        label: 'House qualification approach',
        hint: 'Default for new House requirements. Existing requirements keep their own choices. A minimum number must be entered on the requirement.',
        options: {
            per_requirement: 'Choose per requirement',
            all_workers: 'Every worker',
            minimum_staff: 'Minimum number of qualified workers',
        },
    },
];
const validChoice = (key: unknown, value: unknown) =>
    typeof value === 'string' &&
    fields.some(
        (field) => field.key === key && Object.hasOwn(field.options, value),
    );
const choiceLabel = (key: string, value: string) =>
    fields.find((field) => field.key === key)?.options[value] ??
    'Unrecognised choice';
export function parseEligibilityRules(value: unknown): EligibilityRules | null {
    const rules = object(value),
        values = object(rules?.values),
        defaults = object(rules?.defaults),
        urls = object(rules?.urls);
    if (
        !rules ||
        !values ||
        !defaults ||
        !urls ||
        fields.some(
            (field) =>
                !validChoice(field.key, values[field.key]) ||
                !validChoice(field.key, defaults[field.key]),
        ) ||
        Object.keys(values).length !== fields.length ||
        Object.keys(defaults).length !== fields.length ||
        !isRevision(rules.revision) ||
        !['deployment_defaults', 'saved_override'].includes(
            String(rules.source),
        ) ||
        rules.scope !== 'organisation' ||
        typeof rules.can_edit !== 'boolean' ||
        typeof rules.can_view_history !== 'boolean' ||
        !(urls.update === null || typeof urls.update === 'string') ||
        !(urls.history === null || typeof urls.history === 'string')
    )
        return null;
    return rules as EligibilityRules;
}
type HistoryRow = {
    id: number;
    at: string;
    actor: { id: number; name: string } | null;
    reason: string;
    changes: { key: keyof EligibilityValues; before: string; after: string }[];
};
type HistoryPage = {
    data: HistoryRow[];
    current_page: number;
    last_page: number;
    total: number;
};
function parseHistory(value: unknown): HistoryPage | null {
    const page = object(value);
    if (
        !page ||
        !Array.isArray(page.data) ||
        !Number.isSafeInteger(page.current_page) ||
        Number(page.current_page) < 1 ||
        !Number.isSafeInteger(page.last_page) ||
        Number(page.last_page) < Number(page.current_page) ||
        !Number.isSafeInteger(page.total) ||
        Number(page.total) < 0
    )
        return null;
    if (
        page.data.some((item: unknown) => {
            const row = object(item);
            return (
                !row ||
                !Number.isSafeInteger(row.id) ||
                typeof row.at !== 'string' ||
                !Number.isFinite(Date.parse(row.at)) ||
                typeof row.reason !== 'string' ||
                !(
                    row.actor === null ||
                    typeof object(row.actor)?.name === 'string'
                ) ||
                !Array.isArray(row.changes) ||
                row.changes.some((item: unknown) => {
                    const change = object(item);
                    return (
                        !change ||
                        !validChoice(change.key, change.before) ||
                        !validChoice(change.key, change.after)
                    );
                })
            );
        })
    )
        return null;
    return page as HistoryPage;
}
export function WorkforceEligibilityRules({
    actorId,
    rules,
    visible,
    query,
    timezone,
    onDirtyChange,
    onUncertainChange,
    onShow,
}: {
    actorId: number;
    rules: EligibilityRules;
    visible: boolean;
    query: string;
    timezone: string;
    onDirtyChange: (dirty: boolean) => void;
    onUncertainChange: (uncertain: boolean) => void;
    onShow: () => void;
}) {
    const [saved, setSaved] = useState(rules),
        [draft, setDraft] = useState<EligibilityValues>(rules.values);
    const [reason, setReason] = useState(''),
        [review, setReview] = useState(false);
    const [message, setMessage] = useState<string | null>(null),
        [recovery, setRecovery] = useState<string | null>(null);
    const [historyPage, setHistoryPage] = useState<number | null>(null),
        [history, setHistory] = useState<HistoryPage | null>(null);
    const [historyError, setHistoryError] = useState<string | null>(null),
        [historyLoading, setHistoryLoading] = useState(false),
        [historyAttempt, setHistoryAttempt] = useState(0);
    const command = useSettingsCommand(actorId);
    const { processing, uncertain, locked, error, setError } = command;
    const changedFields = fields.filter(
        (field) => draft[field.key] !== saved.values[field.key],
    );
    const dirty = changedFields.length > 0,
        hasDraft = dirty || !!reason.trim() || uncertain || processing;
    const canEdit = rules.can_edit && saved.can_edit && !!rules.urls.update;
    const observedRevision = useRef(rules.revision);
    useEffect(() => onDirtyChange(hasDraft), [hasDraft, onDirtyChange]);
    useEffect(
        () => onUncertainChange(uncertain || processing),
        [uncertain, processing, onUncertainChange],
    );
    useEffect(() => {
        if (observedRevision.current === rules.revision) return;
        observedRevision.current = rules.revision;
        if (!hasDraft && !command.pending.current) {
            setSaved(rules);
            setDraft(rules.values);
        }
    }, [rules, hasDraft, command.pending]);
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
        const historyUrl = new URL(rules.urls.history, window.location.origin);
        historyUrl.searchParams.set('page', String(historyPage));
        fetch(historyUrl.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error('History could not be loaded.');
                const data = parseHistory(await response.json());
                if (!data) throw new Error('History could not be checked.');
                return data;
            })
            .then((data) => {
                if (!controller.signal.aborted) setHistory(data);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setHistoryError(
                        'Change history could not be loaded. Try again.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setHistoryLoading(false);
            });
        return () => controller.abort();
    }, [
        historyPage,
        historyAttempt,
        rules.can_view_history,
        rules.urls.history,
    ]);
    const reloadSaved = () =>
        command.check(
            ['eligibilityRules'],
            (props) => parseEligibilityRules(props.eligibilityRules),
            (latest) => {
                setSaved(latest);
                setReview(false);
                setMessage(null);
                setRecovery(
                    'Current qualification rule loaded. Your choice is retained; compare it before saving. This read does not confirm the earlier save.',
                );
            },
        );
    const discard = () => {
        if (locked) return;
        setDraft(saved.values);
        setReason('');
        setReview(false);
        setError(null);
        setRecovery(null);
        setMessage(null);
    };
    const save = () => {
        if (
            !dirty ||
            !canEdit ||
            locked ||
            !reason.trim() ||
            !rules.urls.update
        )
            return;
        const values = { ...draft };
        command.run(
            {
                action: 'eligibility_rules',
                actor_id: actorId,
                expected_revision: saved.revision,
                values,
            },
            (options) =>
                router.patch(
                    rules.urls.update!,
                    {
                        values,
                        expected_revision: saved.revision,
                        reason: reason.trim(),
                    },
                    options,
                ),
            (receipt) => {
                setSaved((previous) => ({
                    ...previous,
                    values,
                    revision: receipt.revision,
                    source: receipt.changed
                        ? 'saved_override'
                        : previous.source,
                }));
                setDraft(values);
                setReason('');
                setReview(false);
                setRecovery(null);
                setMessage(
                    receipt.changed
                        ? 'Qualification rule saved. Duty rechecks are queued; existing assignments remain in place.'
                        : 'This choice already matches the saved rule. No new recheck was requested.',
                );
            },
        );
    };
    const errorNotice = error ? (
        <SettingsNotice role="alert">
            {error}{' '}
            <Button variant="link" onClick={reloadSaved} disabled={processing}>
                Check current qualification rule
            </Button>
        </SettingsNotice>
    ) : null;
    return (
        <>
            {!visible && hasDraft ? (
                <SettingsNotice>
                    You have an unsaved qualification rule.{' '}
                    <Button variant="link" onClick={onShow}>
                        Review qualification rule
                    </Button>
                </SettingsNotice>
            ) : null}
            <div hidden={!visible} className="space-y-4">
                {message ? (
                    <p role="status" className="text-body text-status-success">
                        {message}
                    </p>
                ) : null}
                {recovery ? (
                    <SettingsNotice role="note">{recovery}</SettingsNotice>
                ) : null}
                {errorNotice}
                <Card className="gap-0 overflow-hidden p-0">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                        <div>
                            <h2 className="text-section-title">
                                Qualification requirements
                            </h2>
                            <p className="text-caption mt-1">
                                {saved.source === 'saved_override'
                                    ? 'Saved organisation rule'
                                    : 'Default organisation rule'}{' '}
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
                            .map((field) => (
                                <div
                                    key={field.key}
                                    data-setting={field.key}
                                    className="flex flex-col gap-4 p-4 lg:flex-row lg:items-start lg:justify-between"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Label htmlFor={field.id}>
                                                {field.label}
                                            </Label>
                                            {changedFields.includes(field) ? (
                                                <StatusBadge
                                                    variant="info"
                                                    size="sm"
                                                >
                                                    Changed — not saved
                                                </StatusBadge>
                                            ) : null}
                                        </div>
                                        <p className="text-caption mt-1">
                                            {field.hint}
                                        </p>
                                        <p className="text-caption mt-1">
                                            Default:{' '}
                                            {
                                                field.options[
                                                    rules.defaults[field.key]
                                                ]
                                            }
                                            .
                                        </p>
                                    </div>
                                    {canEdit ? (
                                        <Select
                                            value={draft[field.key]}
                                            disabled={locked}
                                            onValueChange={(value) => {
                                                setDraft((current) => ({
                                                    ...current,
                                                    [field.key]: value,
                                                }));
                                                setMessage(null);
                                            }}
                                        >
                                            <SelectTrigger
                                                id={field.id}
                                                className="h-auto min-h-9 w-full max-w-sm text-left whitespace-normal"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    field.options,
                                                ).map(([value, text]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {text}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <span className="text-body font-semibold">
                                            {
                                                field.options[
                                                    rules.values[field.key]
                                                ]
                                            }
                                        </span>
                                    )}
                                </div>
                            ))}
                    </div>
                    <p className="text-caption border-t p-4">
                        Warnings require an authorised manager's acknowledgement
                        before proceeding. Missing or expired mapped
                        qualifications keep their existing checks. Changing this
                        rule queues existing assigned duties for review; it does
                        not remove their assignments.
                    </p>
                </Card>
                {canEdit ? (
                    <Card className="sticky bottom-3 z-20 flex-row flex-wrap items-center justify-between gap-4 p-4 shadow-md">
                        <div>
                            <p className="text-body font-semibold">
                                {dirty
                                    ? `${changedFields.length} unsaved qualification ${changedFields.length === 1 ? 'rule' : 'rules'}`
                                    : 'No unsaved qualification changes'}
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
                                Discard qualification changes
                            </Button>
                            <Button
                                disabled={!dirty || locked}
                                onClick={() => setReview(true)}
                            >
                                Review qualification rule
                            </Button>
                        </div>
                    </Card>
                ) : null}
            </div>
            {review ? (
                <SettingsModal
                    title="Review qualification rule"
                    description="Review qualification warnings and the default choices for new House requirements."
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
                                    !reason.trim()
                                }
                            >
                                {processing ? (
                                    <Loader2 className="mr-2 size-4 animate-spin" />
                                ) : null}
                                Save qualification rule
                            </Button>
                        </>
                    }
                >
                    {changedFields.map((field) => (
                        <div key={field.key} className="rounded-lg border p-3">
                            <p className="text-body font-semibold">
                                {field.label}
                            </p>
                            <p className="text-caption">
                                {field.options[saved.values[field.key]]} →{' '}
                                {field.options[draft[field.key]]}
                            </p>
                        </div>
                    ))}
                    <div className="space-y-2">
                        <Label htmlFor="qualification-rule-reason">
                            Reason for this change
                        </Label>
                        <Textarea
                            id="qualification-rule-reason"
                            value={reason}
                            maxLength={2000}
                            disabled={locked}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder="Explain why this qualification rule is changing"
                        />
                        <p className="text-caption">
                            Saved with the before-and-after choices in change
                            history.
                        </p>
                    </div>
                    {errorNotice}
                </SettingsModal>
            ) : null}
            {historyPage !== null &&
            rules.can_view_history &&
            rules.urls.history ? (
                <SettingsModal
                    title="Qualification rule change history"
                    description="Saved changes to qualification warnings and House requirement defaults."
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
                                onClick={() =>
                                    setHistoryAttempt((value) => value + 1)
                                }
                            >
                                Try again
                            </Button>
                        </SettingsNotice>
                    ) : null}
                    {history ? (
                        <>
                            <p className="text-caption">
                                {history.total} recorded changes · Page{' '}
                                {history.current_page} of {history.last_page}
                            </p>
                            {history.data.length === 0 ? (
                                <p>No changes have been recorded.</p>
                            ) : (
                                history.data.map((row) => (
                                    <div
                                        key={row.id}
                                        className="space-y-2 rounded-lg border p-3"
                                    >
                                        <p className="text-body font-semibold">
                                            {row.actor?.name ??
                                                'Former account'}{' '}
                                            ·{' '}
                                            {formatDateTimeInZone(
                                                row.at,
                                                timezone,
                                            )}
                                        </p>
                                        {row.changes.map((change) => (
                                            <p
                                                key={change.key}
                                                className="text-caption"
                                            >
                                                {choiceLabel(
                                                    change.key,
                                                    change.before,
                                                )}{' '}
                                                →{' '}
                                                {choiceLabel(
                                                    change.key,
                                                    change.after,
                                                )}
                                            </p>
                                        ))}
                                        <p className="text-body break-words">
                                            {row.reason}
                                        </p>
                                    </div>
                                ))
                            )}
                            <div className="flex justify-between gap-2">
                                <Button
                                    variant="outline"
                                    disabled={history.current_page <= 1}
                                    onClick={() =>
                                        setHistoryPage(history.current_page - 1)
                                    }
                                >
                                    Previous
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={
                                        history.current_page >=
                                        history.last_page
                                    }
                                    onClick={() =>
                                        setHistoryPage(history.current_page + 1)
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        </>
                    ) : null}
                </SettingsModal>
            ) : null}
        </>
    );
}
