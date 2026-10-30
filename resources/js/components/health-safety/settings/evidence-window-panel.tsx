import { Button } from '@/components/ui/button';
import { EvidenceWindows, Note, Surface } from '@/components/signature/blocks';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { useSafetyDraftState } from '@/hooks/use-safety-workspace-drafts';
import { formatDateTimeLong } from '@/lib/datetime';
import axios from 'axios';
import { AlertCircle, Camera, CheckCircle2, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    blankRule, confirmsEvidenceWindowReceipt, EVIDENCE_POLICY_KEY, policyFromDraft, ruleDraft,
    type EvidenceWindowCommand, type EvidenceWindowHistory, type EvidenceWindowOptions,
    type EvidenceWindowRule, type EvidenceWindowRuleDraft, type EvidenceWindowSnapshot,
} from './evidence-window-policy';

const ENDPOINT = '/health-safety/settings/evidence-windows';
type Props = {
    policy: EvidenceWindowSnapshot; history: EvidenceWindowHistory; options: EvidenceWindowOptions;
    canManage: boolean; actorId: number; discard: (action: () => void) => void;
};
const stateLabels = { unconfigured: 'Not configured', invalid: 'Needs review', disabled: 'Disabled', configured: 'Configured' };
type EditorResponse = {
    policy: EvidenceWindowSnapshot; history: EvidenceWindowHistory; options: EvidenceWindowOptions;
    can_manage: boolean; evidence_window_viewer_id: number;
};

export function EvidenceWindowPanel(props: Props) {
    return <ActorEvidenceWindowPanel key={props.actorId} {...props} />;
}
function ActorEvidenceWindowPanel(props: Props) {
    const [remote, setRemote] = useState<{ source: EvidenceWindowSnapshot; data: EditorResponse } | null>(null);
    const [editing, setEditing] = useState(false);
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState('');
    const [refused, setRefused] = useState(false);
    const latest = useRef(props);
    latest.current = props;
    const request = useRef<AbortController | null>(null);
    useEffect(() => () => request.current?.abort(), []);
    const current = remote?.source === props.policy ? remote.data : {
        policy: props.policy, history: props.history, options: props.options, can_manage: props.canManage,
        evidence_window_viewer_id: props.actorId,
    };
    const { policy, history, options } = current;
    const canManage = !refused && props.canManage && current.can_manage && policy.scope_complete;
    const refresh = async (page = 1, successMessage = '') => {
        if (request.current) return;
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        const source = props.policy;
        try {
            const response = await axios.get<EditorResponse>(ENDPOINT, {
                params: { window_policy_page: page }, headers: { Accept: 'application/json' },
                signal: controller.signal, timeout: 15000,
            });
            if (controller.signal.aborted || latest.current.actorId !== props.actorId || latest.current.policy !== source) return;
            const data = response.data;
            if (data.evidence_window_viewer_id !== props.actorId || data.policy?.setting_key !== EVIDENCE_POLICY_KEY ||
                data.policy.schema_version !== 1 || !Array.isArray(data.history?.data) || !Array.isArray(data.options?.sites)) {
                throw new Error('Current settings could not be verified.');
            }
            setRemote({ source, data });
            setRefused(false);
            setMessage(successMessage);
        } catch (error) {
            if (!controller.signal.aborted) {
                if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) setRefused(true);
                setMessage(`${successMessage ? `${successMessage} ` : ''}Current settings could not be refreshed. Check your connection and access, then try again.`);
            }
        } finally {
            if (request.current === controller) { request.current = null; setLoading(false); }
        }
    };
    const sub = <>
        Set the time before and after an incident to review. A Site rule takes priority over the organisation rule for the same incident type.
        {!refused ? <> · <StatusBadge variant={policy.configuration_valid ? 'info' : 'warning'} size="sm">
            {policy.configuration_valid ? <Camera aria-hidden className="size-3" /> : <AlertCircle aria-hidden className="size-3" />}
            {stateLabels[policy.configuration_state]}
        </StatusBadge></> : null}
    </>;
    const buttons = <>
        {!editing || refused ? <Button variant="outline" size="sm" className="frontline-hit" disabled={loading} onClick={() => void refresh()}>{refused ? 'Check current access' : 'Refresh settings'}</Button> : null}
        {!editing && canManage && policy.policy_revision !== null ?
            <Button size="sm" variant="outline" className="frontline-hit" onClick={() => setEditing(true)}>Review evidence windows</Button> : null}
    </>;
    const rules = policy.policy_snapshot?.rules ?? [];
    const min = (seconds: number) => Math.round((seconds / 60) * 10) / 10;
    const label = (rule: EvidenceWindowRule) => options.triggers.find((item) => item.value === rule.trigger)?.label ?? rule.trigger;
    const where = (rule: EvidenceWindowRule) => rule.site_id === null ? 'Organisation default' : options.sites.find((site) => site.id === rule.site_id)?.name ?? 'Site unavailable';
    const sources = (rule: EvidenceWindowRule) => rule.source_types.map((type) => options.source_types.find((item) => item.value === type)?.label ?? type);
    // How long evidence is reviewed, drawn as time bars around the moment of the incident (approved drawing 24e).
    return <section aria-label="Evidence window policy" className="flex flex-col gap-5" aria-busy={loading}>
        {editing ? <Surface title="Review evidence windows" sub={sub}>
            <div className="p-[18px]"><EvidenceWindowEditor policy={policy} options={options} actorId={props.actorId}
                onRefused={() => setRefused(true)}
                canManage={canManage} discard={props.discard} onClose={(saved) => {
                    setEditing(false);
                    if (saved) { setMessage('Evidence-window policy saved.'); void refresh(1, 'Evidence-window policy saved.'); }
                }} /></div>
            {/* A refused save keeps the retained command here; checking access is the way on. */}
            {refused ? <div className="sg-actions">{buttons}</div> : null}
        </Surface> : refused || !rules.length ? <Surface title="How long evidence is reviewed" sub={sub}>
            {!refused ? <p className="sg-quiet">No evidence windows are recorded.</p> : null}
            <div className="sg-actions">{buttons}</div>
        </Surface> : <EvidenceWindows
            aria-label="Current evidence rules"
            title="How long evidence is reviewed"
            sub={sub}
            before={Math.max(1, ...rules.map((rule) => min(rule.max_before_seconds)))}
            after={Math.max(1, ...rules.map((rule) => min(rule.max_after_seconds)))}
            rows={rules.map((rule) => ({
                name: label(rule), where: where(rule),
                review: [min(rule.before_seconds), min(rule.after_seconds)],
                max: [min(rule.max_before_seconds), min(rule.max_after_seconds)],
                sources: sources(rule).map((text) => ({ label: text })),
            }))}
            actions={buttons}
        />}
        <Note>Source selections do not grant access or consent, confirm recordings, or preserve footage. Each recorded incident window keeps its original policy and history.</Note>
        {refused ? <p role="alert" className="text-subtle text-status-warning-foreground">Current access could not be confirmed. Scoped settings and history are hidden. Check current access before continuing.</p> : null}
        {!refused && !policy.scope_complete ? <p role="alert" className="text-subtle text-status-warning-foreground">
            Some rules are outside your current Site access. You can review the visible rules, but an authorised reviewer with access to all rules must change this policy.
        </p> : null}
        {message ? <p role="status" className="text-subtle">{message}</p> : null}
        {!refused ? <div className="flex flex-col gap-3">
            <Surface title="Evidence-window policy history" sub="NZ time">
                {history.data.length ? <ul className="sg-list mt-2">{history.data.map((entry) => <li key={entry.id} className="grid gap-1 px-[18px] py-3">
                    <p className="text-subtle font-semibold">Revision {entry.revision} · {entry.after_value?.enabled ? 'Enabled' : entry.after_value ? 'Disabled' : 'Unavailable'}</p>
                    <p className="text-caption text-muted-foreground">{entry.actor?.name ?? 'Reviewer unavailable'} · {formatDateTimeLong(entry.recorded_at)}</p>
                    <p className="whitespace-pre-wrap break-words text-subtle">{entry.reason}</p>
                    {entry.after_value?.rules.length ? <details className="mt-1">
                        <summary className="frontline-tap frontline-focus cursor-pointer text-subtle text-primary">Review recorded rules</summary>
                        <ul className="mt-2 grid gap-2">{entry.after_value.rules.map((rule) => <li key={rule.identifier} className="text-subtle">
                            <p className="font-medium">{label(rule)} · {where(rule)}</p>
                            <p>{rule.before_seconds} seconds before · {rule.after_seconds} seconds after</p>
                            <p className="text-muted-foreground">Maximum: {rule.max_before_seconds} seconds before · {rule.max_after_seconds} seconds after</p>
                            <p>{sources(rule).join(', ')}</p>
                        </li>)}</ul>
                    </details> : null}
                    {!entry.scope_complete ? <p className="text-caption text-muted-foreground">Some Site rules are restricted.</p> : null}
                </li>)}</ul> : <p className="sg-quiet">No evidence-window policy decisions have been recorded.</p>}
            </Surface>
            <LaravelPagination ariaLabel="Evidence policy history pages" navigation={{
                currentPage: history.current_page, previousPage: history.current_page > 1 ? history.current_page - 1 : null,
                nextPage: history.current_page < history.last_page ? history.current_page + 1 : null,
                pending: loading, onPageChange: (page) => props.discard(() => { setEditing(false); void refresh(page); }),
            }} />
        </div> : null}
    </section>;
}

function EvidenceWindowEditor({ policy, options, actorId, canManage, discard, onClose, onRefused }: Omit<Props, 'history'> & { onClose: (saved: boolean) => void; onRefused: () => void }) {
    const [revision] = useState(policy.policy_revision);
    const [enabled, setEnabled] = useState(policy.policy_snapshot ? policy.policy_snapshot.enabled ? 'enabled' : 'disabled' : '');
    const [rules, setRules] = useState(() => (policy.policy_snapshot?.rules ?? []).map(ruleDraft));
    const [reason, setReason] = useState('');
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [submitted, setSubmitted] = useState<EvidenceWindowCommand | null>(null);
    const [message, setMessage] = useState('');
    const request = useRef<AbortController | null>(null);
    const current = useRef({ actorId, canManage });
    current.current = { actorId, canManage };
    useEffect(() => () => request.current?.abort(), []);
    useSafetyDraftState({ dirty, busy, uncertain: submitted !== null });
    const stale = revision !== policy.policy_revision;
    const blocked = busy || !canManage || revision === null;
    const updateRule = (index: number, next: Partial<EvidenceWindowRuleDraft>) => {
        setDirty(true); setRules((previous) => previous.map((rule, i) => i === index ? { ...rule, ...next } : rule));
    };
    const send = async (recover: boolean) => {
        if (blocked || request.current || (stale && !submitted)) return;
        let command = submitted;
        if (!command) {
            try {
                if (reason.trim().length < 10 || reason.trim().length > 2000) throw new Error('Explain this decision in 10 to 2,000 characters.');
                command = { expected_actor_id: actorId, expected_policy_revision: revision!,
                    idempotency_key: crypto.randomUUID(), reason: reason.trim(), policy: policyFromDraft(enabled, rules, options) };
            } catch (error) { setMessage(error instanceof Error ? error.message : 'Review the rule entries.'); return; }
        }
        const original = command;
        setSubmitted(original); setBusy(true); setMessage('');
        const controller = new AbortController();
        request.current = controller;
        const accepts = () => !controller.signal.aborted && current.current.actorId === actorId && current.current.canManage;
        try {
            const config = { headers: { Accept: 'application/json' }, signal: controller.signal, timeout: 15000 };
            const response = recover
                ? await axios.get(`${ENDPOINT}/requests/${original.idempotency_key}`, { ...config, params: { expected_actor_id: actorId } })
                : await axios.put(ENDPOINT, original, config);
            if (!accepts()) return;
            if (!confirmsEvidenceWindowReceipt(response.data, original, actorId)) throw new Error('Unconfirmed receipt');
            setDirty(false); setSubmitted(null); onClose(true);
        } catch (error) {
            if (!accepts()) return;
            if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) {
                onRefused();
                setMessage('Current access was refused. Your original submission remains in memory; check current access before retrying.');
            } else if (!recover && axios.isAxiosError(error) && error.response?.status === 422) {
                setSubmitted(null);
                const errors = error.response.data?.errors;
                setMessage(errors && typeof errors === 'object' ? Object.values(errors).flat().join(' ') : 'Review the rule entries. The policy was not saved.');
            } else {
                setMessage('This save is not confirmed. Your original submission is retained. Check its result or retry the same submission before changing it.');
            }
        } finally {
            if (request.current === controller) { request.current = null; setBusy(false); }
        }
    };
    const durationFields = [
        ['before_seconds', 'Seconds before the incident'], ['after_seconds', 'Seconds after the incident'],
        ['max_before_seconds', 'Maximum seconds before'], ['max_after_seconds', 'Maximum seconds after'],
    ] as const;
    return <form className="space-y-4 border-t border-border pt-4" onSubmit={(event) => { event.preventDefault(); void send(false); }}>
        {!canManage ? <p role="alert" className="text-subtle text-status-warning-foreground">Your settings access changed. Editing and saving are unavailable; your draft remains in this tab.</p> : null}
        {stale ? <p role="alert" className="text-subtle text-status-warning-foreground">The policy changed while you were editing. Check any pending save, then cancel and review the current policy before starting again.</p> : null}
        {canManage ? <fieldset disabled={blocked || stale || submitted !== null} className="space-y-4">
            <legend className="sr-only">Evidence-window rules</legend>
            <label className="block space-y-2 text-subtle">Evidence-window policy
                <select value={enabled} className="frontline-tap frontline-focus w-full rounded-md border border-input bg-background px-3"
                    onChange={(event) => { setEnabled(event.target.value); setDirty(true); }}>
                    <option value="" disabled>Choose a policy</option><option value="enabled">Enable with explicit rules</option><option value="disabled">Disable new evidence windows</option>
                </select>
            </label>
            {enabled === 'disabled' ? <p className="text-subtle">Saving this decision removes active rules from the new policy. Historical incident windows remain recorded. Rules in this draft are retained until you save or cancel.</p> : null}
            {enabled === 'enabled' ? <div className="space-y-4">
                <p className="text-caption text-muted-foreground">Enter whole seconds (60 seconds = 1 minute). Maximum durations set the limit for an authorised, explained expansion; they do not grant that authority.</p>
                {rules.map((rule, index) => <fieldset key={rule.identifier} className="space-y-3 rounded-lg border border-border p-4">
                    <legend className="px-1 text-subtle font-medium">Rule {index + 1}</legend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="space-y-2 text-subtle">Incident type
                            <select className="frontline-tap frontline-focus w-full rounded-md border border-input bg-background px-3" value={rule.trigger} onChange={(event) => updateRule(index, { trigger: event.target.value })}>
                                <option value="" disabled>Choose an incident type</option>{options.triggers.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                            </select>
                        </label>
                        <label className="space-y-2 text-subtle">Applies to
                            <select className="frontline-tap frontline-focus w-full rounded-md border border-input bg-background px-3" value={rule.site_id} onChange={(event) => updateRule(index, { site_id: event.target.value })}>
                                <option value="">Organisation default</option>{options.sites.map((site) => <option key={site.id} value={site.id} disabled={!site.is_active}>{site.name}{site.is_active ? '' : ' (inactive — remove or replace)'}</option>)}
                            </select>
                        </label>
                        {durationFields.map(([key, label]) => <label key={key} className="space-y-2 text-subtle">{label}<Input className="frontline-tap" type="number" inputMode="numeric" min={0} max={2147483647} step={1} value={rule[key]} onChange={(event) => updateRule(index, { [key]: event.target.value })} /></label>)}
                    </div>
                    <fieldset className="space-y-2"><legend className="text-subtle font-medium">Eligible source types</legend>
                        <div className="flex flex-wrap gap-x-4 gap-y-2">{options.source_types.map((item) => <label key={item.value} className="frontline-tap flex items-center gap-2 text-subtle">
                            <input type="checkbox" className="frontline-focus size-4" checked={rule.source_types.includes(item.value)} onChange={(event) => updateRule(index, { source_types: event.target.checked ? [...rule.source_types, item.value] : rule.source_types.filter((type) => type !== item.value) })} />{item.label}
                        </label>)}</div>
                    </fieldset>
                    <Button type="button" variant="outline" size="sm" className="frontline-hit" onClick={() => { setRules((previous) => previous.filter((_, i) => i !== index)); setDirty(true); }}><Trash2 aria-hidden className="size-4" />Remove rule {index + 1}</Button>
                </fieldset>)}
                <Button type="button" variant="outline" size="sm" className="frontline-hit" onClick={() => { setRules((previous) => [...previous, blankRule()]); setDirty(true); }}><Plus aria-hidden className="size-4" />Add incident rule</Button>
            </div> : null}
            <label className="block space-y-2 text-subtle">Reason for this decision<Textarea value={reason} minLength={10} maxLength={2000} onChange={(event) => { setReason(event.target.value); setDirty(true); }} /></label>
        </fieldset> : null}
        {message ? <p role="alert" className="text-subtle text-status-warning-foreground">{message}</p> : null}
        <div className="flex flex-wrap gap-3">
            <Button type="submit" size="sm" className="frontline-hit" disabled={blocked || (stale && !submitted)}><CheckCircle2 aria-hidden className="size-4" />{busy ? 'Checking…' : submitted ? 'Retry same submission' : 'Save evidence policy'}</Button>
            {submitted ? <Button type="button" variant="outline" size="sm" className="frontline-hit" disabled={blocked} onClick={() => void send(true)}>Check save result</Button> : null}
            <Button type="button" variant="outline" size="sm" className="frontline-hit" onClick={() => discard(() => onClose(false))}>Cancel</Button>
        </div>
    </form>;
}

