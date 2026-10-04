/* Medication › Settings › Alerts & access › On-call contacts (eMAR P11 v5,
 * B2 chunk 4). Stephan, 29 Sep 2026: each house's on-call contact is an
 * employed staff member and follows the roster — whoever is on an on-call
 * shift, then (if chosen) the team lead on shift, then a backup person; or
 * always the same person. Phone numbers come from staff records (the work
 * phone, Q8). A contact saves straight away, not through the page draft, and
 * is recorded in the change history. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { InfoCard } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDate } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CalendarDays,
    ListChecks,
    Pencil,
    Phone,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { useSettings, type Dialog } from './_context';
import { NotFound } from './_dialogs';
import {
    KV,
    NotConfigured,
    OnOff,
    RecordPicker,
    Section,
    type PickItem,
} from './_ui';

export type OnCallPerson = { id: number; name: string; phone: string | null };

export type OnCallRule = {
    mode: 'roster' | 'fixed';
    team_lead: boolean;
    backup: OnCallPerson | null;
    describe: string;
    changed_by: string | null;
    changed_at: string | null;
};

export type OnCallNight = {
    label: string;
    hours: string;
    on_call: OnCallPerson | null;
    team_lead: OnCallPerson | null;
};

export type OnCallHouse = {
    site_id: number;
    name: string;
    house_leads: string[];
    rule: OnCallRule | null;
    roster: OnCallNight[];
    can_manage: boolean;
};

export type OnCallStaff = {
    id: number;
    name: string;
    role: string;
    phone: string | null;
    ok: boolean;
    why: string | null;
    /** Nights (by index into the roster) they're on approved leave. */
    away: number[];
    leave: string | null;
};

export type OnCallData = {
    houses: OnCallHouse[];
    /** Who can be the backup, per house this person can change. */
    staff: Record<string, OnCallStaff[]>;
};

type Draft = { mode: 'roster' | 'fixed'; team_lead: boolean; backup: string };

const match = (q: string, ...s: (string | null | undefined)[]) =>
    !q || s.some((x) => (x ?? '').toLowerCase().includes(q.toLowerCase()));

const phoneText = (p: OnCallPerson | OnCallStaff | null | undefined) =>
    p?.phone ?? 'no work phone';

/** Who staff will see on one night under a rule (the server works it out the same way). */
export function resolveNight(
    rule: { mode: 'roster' | 'fixed'; team_lead: boolean },
    backup: (OnCallPerson & { away?: number[] }) | null,
    night: OnCallNight,
    index: number,
): { who: OnCallPerson | null; how: string; warning: string | null } {
    if (rule.mode === 'roster') {
        if (night.on_call)
            return {
                who: night.on_call,
                how: 'On an on-call shift',
                warning: null,
            };
        if (rule.team_lead && night.team_lead)
            return {
                who: night.team_lead,
                how: 'Team lead on shift',
                warning: null,
            };
    }
    if (!backup) return { who: null, how: 'Nobody chosen', warning: null };
    if (backup.away?.includes(index))
        return {
            who: null,
            how: 'Backup on leave',
            warning: `Nobody — ${backup.name} is on leave`,
        };
    return {
        who: backup,
        how:
            rule.mode === 'roster'
                ? 'Backup — nobody rostered'
                : 'Always this person',
        warning: null,
    };
}

const decided = (rule: { mode: string; team_lead: boolean }) =>
    rule.mode === 'roster'
        ? `The roster${rule.team_lead ? ', then the team lead on shift' : ''}`
        : 'Always the same person';

const changed = (rule: OnCallRule) =>
    rule.changed_by
        ? `${rule.changed_by}, ${formatDate(rule.changed_at)}`
        : formatDate(rule.changed_at);

/** The backup with the nights they're away, from the house's staff list when it's there. */
const backupOf = (data: OnCallData, h: OnCallHouse) => {
    const b = h.rule?.backup;
    if (!b) return null;
    const staff = data.staff[String(h.site_id)]?.find((x) => x.id === b.id);
    return { ...b, away: staff?.away ?? [] };
};

/* ── The tab: one card per house. ── */
export function OnCallContacts({
    q,
    clear,
    data,
    readOnlyAudit,
}: {
    q: string;
    clear: () => void;
    data: OnCallData;
    readOnlyAudit: boolean;
}) {
    const { open } = useSettings();
    const houses = data.houses.filter((h) =>
        match(q, h.name, h.rule?.backup?.name),
    );
    return (
        <Section
            id="sc-oncall"
            title="On-call contact"
            caption="One per house · follows the roster"
        >
            <p className="text-subtle">
                Shown on escalations and follow-ups, and can get alerts. After
                hours it’s whoever is on an on-call shift, then the team lead on
                shift, then a backup person. Until a house sets one, screens say
                “On-call contact: Not configured”.
            </p>
            {houses.length ? (
                <div className="grid gap-5 lg:grid-cols-2">
                    {houses.map((h) => {
                        const r = h.rule;
                        const backup = backupOf(data, h);
                        const nights = r
                            ? h.roster.map((n, i) =>
                                  resolveNight(r, backup, n, i),
                              )
                            : [];
                        const tonight = nights[0];
                        const gaps = h.roster
                            .map((n, i) => [n, nights[i]] as const)
                            .filter(([, x]) => x?.warning);
                        const can = h.can_manage && !readOnlyAudit;
                        return (
                            <Card
                                key={h.site_id}
                                className="gap-3 p-4"
                                data-setting={`oncall-${h.site_id}`}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-sm font-semibold">
                                            {h.name}
                                        </p>
                                        <p className="text-caption">
                                            House lead:{' '}
                                            {h.house_leads.join(', ') ||
                                                'none recorded'}
                                        </p>
                                    </div>
                                    {r ? (
                                        <StatusBadge
                                            variant="success"
                                            size="sm"
                                        >
                                            {r.mode === 'roster'
                                                ? 'Follows the roster'
                                                : 'Set'}
                                        </StatusBadge>
                                    ) : (
                                        <NotConfigured />
                                    )}
                                </div>
                                {r && tonight ? (
                                    <>
                                        <div className="flex items-center gap-3 rounded-lg border p-3 text-[13px]">
                                            <Phone
                                                className="size-4 shrink-0 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <div>
                                                <p className="text-caption">
                                                    {h.roster[0]?.label} ·{' '}
                                                    {h.roster[0]?.hours}
                                                </p>
                                                <p className="font-semibold">
                                                    {tonight.who
                                                        ? `${tonight.who.name} · ${phoneText(tonight.who)}`
                                                        : (tonight.warning ??
                                                          'Nobody')}
                                                </p>
                                                <p className="text-caption">
                                                    {tonight.how}
                                                </p>
                                            </div>
                                        </div>
                                        <KV
                                            rows={[
                                                [
                                                    'How it’s decided',
                                                    decided(r),
                                                ],
                                                [
                                                    r.mode === 'roster'
                                                        ? 'Backup'
                                                        : 'Person',
                                                    r.backup
                                                        ? `${r.backup.name} · ${phoneText(r.backup)}`
                                                        : '—',
                                                ],
                                                ['Last changed', changed(r)],
                                            ]}
                                        />
                                        {gaps.length ? (
                                            <InfoCard
                                                icon={AlertTriangle}
                                                tone="warn"
                                            >
                                                {gaps
                                                    .map(
                                                        ([n, x]) =>
                                                            `${n.label}: ${x!.warning}`,
                                                    )
                                                    .join('; ')}
                                                . Roster an on-call shift, or
                                                change the backup.
                                            </InfoCard>
                                        ) : null}
                                    </>
                                ) : (
                                    <p className="text-subtle">
                                        Screens at this house give no number.
                                        {h.roster.some((n) => n.on_call)
                                            ? ' Rostering has on-call shifts here — set up a contact to use them.'
                                            : ''}
                                    </p>
                                )}
                                {can ? (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant={r ? 'outline' : 'default'}
                                            size="sm"
                                            onClick={() =>
                                                open({
                                                    kind: 'oncall',
                                                    siteId: h.site_id,
                                                })
                                            }
                                        >
                                            {r ? (
                                                <>
                                                    <Pencil />
                                                    Change contact
                                                </>
                                            ) : (
                                                <>
                                                    <Plus />
                                                    Set up contact
                                                </>
                                            )}
                                        </Button>
                                        {r ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    open({
                                                        kind: 'oncallremove',
                                                        siteId: h.site_id,
                                                    })
                                                }
                                            >
                                                <Trash2 />
                                                Remove
                                            </Button>
                                        ) : null}
                                    </div>
                                ) : (
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-caption">
                                            {readOnlyAudit
                                                ? 'Read-only for audit.'
                                                : `Only someone who manages settings for ${h.name} can change it.`}
                                        </p>
                                        {/* The read-only view (Main, B2 C4): linked from the card. */}
                                        <Button
                                            variant="link"
                                            size="sm"
                                            onClick={() =>
                                                open({
                                                    kind: 'oncallview',
                                                    siteId: h.site_id,
                                                })
                                            }
                                        >
                                            View details
                                            <ArrowUpRight className="size-4" />
                                        </Button>
                                    </div>
                                )}
                            </Card>
                        );
                    })}
                </div>
            ) : (
                <EmptyState
                    icon={ListChecks}
                    title={
                        q
                            ? `No houses in this tab match “${q}”`
                            : 'No houses to show'
                    }
                    description="Clear the search to see every house you can see."
                    action={
                        <Button variant="outline" size="sm" onClick={clear}>
                            Clear search
                        </Button>
                    }
                />
            )}
        </Section>
    );
}

function RosterPreview({
    h,
    rule,
    backup,
}: {
    h: OnCallHouse;
    rule: { mode: 'roster' | 'fixed'; team_lead: boolean };
    backup: (OnCallPerson & { away?: number[] }) | null;
}) {
    return (
        <ReviewCard icon={CalendarDays} title="Who staff will see after hours">
            {h.roster.map((n, i) => {
                const x = resolveNight(rule, backup, n, i);
                return (
                    <ReviewRow
                        key={n.label}
                        label={`${n.label} · ${n.hours}`}
                        value={
                            x.who ? (
                                <span className="text-right">
                                    <b>{x.who.name}</b> ·{' '}
                                    <span className="whitespace-nowrap">
                                        {phoneText(x.who)}
                                    </span>
                                    <span className="text-caption block">
                                        {x.how}
                                    </span>
                                </span>
                            ) : (
                                <span className="text-right text-status-critical">
                                    {x.warning ? (
                                        <>
                                            {x.warning}
                                            <span className="text-caption block">
                                                Add someone to the roster, or
                                                choose a backup who isn’t away
                                            </span>
                                        </>
                                    ) : rule.mode === 'roster' ? (
                                        'Nobody rostered — choose a backup'
                                    ) : (
                                        'Choose the on-call person'
                                    )}
                                </span>
                            )
                        }
                    />
                );
            })}
        </ReviewCard>
    );
}

function SwitchRow({
    id,
    label,
    hint,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    hint: string;
    checked: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 p-3">
            <div>
                <label htmlFor={id} className="text-[13px] font-semibold">
                    {label}
                </label>
                <p className="text-caption">{hint}</p>
            </div>
            <OnOff
                id={id}
                checked={checked}
                onChange={onChange}
                label={label}
            />
        </div>
    );
}

/* ── Set up or change a house's contact: saves straight away. ── */
export function OnCallDialog({
    siteId,
    data,
}: {
    siteId: number;
    data: OnCallData;
}) {
    const { close } = useSettings();
    const h = data.houses.find((x) => x.site_id === siteId);
    const [d, setD] = useState<Draft>(() => ({
        mode: h?.rule?.mode ?? 'roster',
        team_lead: h?.rule?.team_lead ?? true,
        backup: h?.rule?.backup ? String(h.rule.backup.id) : '',
    }));
    const [err, setErr] = useState('');
    const [fail, setFail] = useState('');
    const [saving, setSaving] = useState(false);
    if (!h) return <NotFound what="house" />;
    if (!h.can_manage) return <OnCallView siteId={siteId} data={data} />;
    const roster = d.mode === 'roster';
    const staff = data.staff[String(h.site_id)] ?? [];
    const pick = staff.find((x) => String(x.id) === d.backup) ?? null;
    const items: PickItem[] = staff.map((x) => ({
        id: String(x.id),
        name: x.name,
        sub: x.ok
            ? `${x.role} · ${phoneText(x)}${x.leave ? ` · ${x.leave}` : ''}`
            : x.role,
        ok: x.ok,
        why: x.why ?? undefined,
    }));
    const missing = roster
        ? 'Choose who staff call when nobody is rostered on call.'
        : 'Choose the on-call person.';
    const save = () => {
        if (!pick) {
            setErr(missing);
            setTimeout(() => document.getElementById('ow-person')?.focus(), 0);
            return;
        }
        router.put(
            `/emar/settings/oncall/${h.site_id}`,
            {
                mode: d.mode,
                team_lead: roster && d.team_lead,
                backup_user_id: pick.id,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSaving(true),
                onSuccess: () => close(),
                onError: (errors: Record<string, string>) => {
                    if (errors.backup_user_id) setErr(errors.backup_user_id);
                    else setFail(Object.values(errors)[0] ?? '');
                },
                onFinish: () => setSaving(false),
            },
        );
    };
    return (
        <SettingsModal
            frontline
            title={`On-call contact — ${h.name}`}
            description="Who staff at this house call when they need help. Shown on escalations and follow-ups."
            onClose={close}
            footer={
                <>
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button onClick={save} disabled={saving}>
                        {fail ? 'Try again' : 'Save contact'}
                    </Button>
                </>
            }
        >
            {fail ? (
                <SettingsNotice>
                    <span>
                        <b>Couldn’t save — nothing was changed.</b> {fail} Your
                        choices are kept.
                    </span>
                </SettingsNotice>
            ) : null}
            <div className="divide-y divide-border rounded-xl border">
                <SwitchRow
                    id="ow-roster"
                    label="Follow the roster"
                    hint={`Whoever is on an on-call shift at ${h.name}. On-call shifts are set in Rostering.`}
                    checked={roster}
                    onChange={(v) =>
                        setD({ ...d, mode: v ? 'roster' : 'fixed' })
                    }
                />
                {roster ? (
                    <SwitchRow
                        id="ow-lead"
                        label="Then the team lead on shift"
                        hint="If nobody is on an on-call shift, the team lead working at the house."
                        checked={d.team_lead}
                        onChange={(v) => setD({ ...d, team_lead: v })}
                    />
                ) : null}
            </div>
            <RecordPicker
                id="ow-person"
                label={roster ? 'If nobody is rostered' : 'On-call person'}
                required
                value={d.backup}
                items={items}
                error={err}
                onChange={(v) => {
                    setD({ ...d, backup: v });
                    setErr('');
                }}
                placeholder="Search and choose a staff member"
                search="Search staff…"
                foot={`Employed staff with access to ${h.name}. Their phone number comes from their staff record.`}
            />
            {pick ? (
                <InfoCard
                    icon={Phone}
                    tone={pick.away.length ? 'warn' : 'info'}
                >
                    <b>{pick.name}</b> · {pick.phone} — work phone, from their
                    staff record.
                    {pick.leave
                        ? ` ${pick.leave} — nights they’re away show nobody.`
                        : ''}
                </InfoCard>
            ) : null}
            <RosterPreview
                h={h}
                rule={d}
                backup={pick ? { ...pick, away: pick.away } : null}
            />
            <p className="text-caption">
                Recorded in the change history. Other houses are unchanged.
            </p>
        </SettingsModal>
    );
}

/* ── Read-only: linked from the card for people who can't change it. ── */
export function OnCallView({
    siteId,
    data,
}: {
    siteId: number;
    data: OnCallData;
}) {
    const { close } = useSettings();
    const h = data.houses.find((x) => x.site_id === siteId);
    if (!h) return <NotFound what="house" />;
    const r = h.rule;
    return (
        <SettingsModal
            frontline
            title={`On-call contact — ${h.name}`}
            description="Only someone who manages settings for this house can change it."
            onClose={close}
            footer={
                <Button variant="outline" onClick={close}>
                    Close
                </Button>
            }
        >
            {r ? (
                <>
                    <ReviewCard icon={Phone} title="How it’s decided">
                        <ReviewRow
                            label="Rule"
                            value={
                                r.mode === 'roster'
                                    ? `Follows the roster${r.team_lead ? ', then the team lead on shift' : ''}`
                                    : 'Always the same person'
                            }
                        />
                        <ReviewRow
                            label={r.mode === 'roster' ? 'Backup' : 'Person'}
                            value={
                                r.backup
                                    ? `${r.backup.name} · ${phoneText(r.backup)}`
                                    : '—'
                            }
                        />
                        <ReviewRow label="Last changed" value={changed(r)} />
                    </ReviewCard>
                    <RosterPreview h={h} rule={r} backup={backupOf(data, h)} />
                </>
            ) : (
                <ReviewCard icon={Phone} title="On-call contact">
                    <p className="text-[13px]">
                        Not configured — screens at this house give no number.
                    </p>
                </ReviewCard>
            )}
        </SettingsModal>
    );
}

/* ── Remove: a red confirm. ── */
export function OnCallRemove({
    siteId,
    data,
}: {
    siteId: number;
    data: OnCallData;
}) {
    const { close } = useSettings();
    const [saving, setSaving] = useState(false);
    const h = data.houses.find((x) => x.site_id === siteId);
    if (!h || !h.can_manage) return <NotFound what="house" />;
    return (
        <ConfirmDialog
            frontline
            open
            onClose={close}
            variant="destructive"
            processing={saving}
            title={`Remove the on-call contact for ${h.name}?`}
            confirmText="Remove contact"
            description={`Screens at ${h.name} say “On-call contact: Not configured” again and give no number — even when someone is rostered on call.${h.rule ? ` Now: ${h.rule.describe}.` : ''}`}
            onConfirm={() =>
                router.delete(`/emar/settings/oncall/${h.site_id}`, {
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => setSaving(true),
                    onFinish: () => {
                        setSaving(false);
                        close();
                    },
                })
            }
        />
    );
}

export function OnCallDialogHost({
    dialog,
    data,
}: {
    dialog: Dialog | null;
    data: OnCallData;
}) {
    if (dialog?.kind === 'oncall')
        return <OnCallDialog siteId={dialog.siteId} data={data} />;
    if (dialog?.kind === 'oncallview')
        return <OnCallView siteId={dialog.siteId} data={data} />;
    if (dialog?.kind === 'oncallremove')
        return <OnCallRemove siteId={dialog.siteId} data={data} />;
    return null;
}
