/* Medication › Settings dialogs (eMAR P11 v5 `dialogs-settings.tsx` and
 * `dialogs-v4.tsx`). Simple dialogs use the settings Modal layout;
 * consequential single actions use ConfirmDialog; the defaults walkthrough is
 * a WizardShell with a DiscardDraftDialog guard. Saves go to the server; a
 * change that loosens a check is saved with the destructive variant. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { InfoCard, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly, formatTime, toDateInput } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    Bell,
    Building2,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Clock,
    History,
    Info,
    Layers,
    Pill,
    Repeat,
    RotateCcw,
    Shield,
    Users,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { useSettings, type Dialog } from './_context';
import {
    canRestore,
    changes,
    definitionOf,
    fallbackHouses,
    fallbackWarnings,
    format,
    keptTogether,
    loosens,
    reviewerOf,
    savedValue,
    stillToDecide,
    VIEW_LABEL,
    withDraft,
    withoutDrafts,
    type HistoryEntry,
    type SettingsPayload,
    type ViewKey,
} from './_model';
import { sectionLabel } from './_nav';
import { OnOff } from './_ui';

/** Settings saves and "Keep today's value" report errors in their own bag, shown in the dialog, not as a toast. */
export const ERROR_BAG = 'medicationSettings';

const Recorded = () => (
    <p className="text-caption">
        Recorded in the change history and the audit log with your name and the
        time.
    </p>
);

/** "29 Sep 2026" in New Zealand time (P11 v5 wording). */
export function dayText(at: string | null) {
    return at ? formatDateOnly(toDateInput(at)) : '';
}

/** "29 Sep 2026 8:05 am" in New Zealand time (P11 v5 wording). */
export function whenText(at: string | null) {
    return at ? `${dayText(at)} ${formatTime(at)}` : '';
}

const GROUP_ICON: Record<string, typeof Layers> = {
    timing: Clock,
    elig: ClipboardCheck,
    pin: Shield,
    cdw: Shield,
    alerts: Bell,
    alertExtra: Bell,
    ea: Shield,
    review_cadence: Repeat,
};

/** Review → save (Fleet "Review … changes"), with failure and conflict states. */
export function ReviewChanges({ view }: { view: ViewKey }) {
    const { s, draft, setDraft, close, flash } = useSettings();
    const [state, setState] = useState<'' | 'fail' | 'conflict'>('');
    const [problem, setProblem] = useState('');
    const [saving, setSaving] = useState(false);
    // After a save, focus goes to the page's status message, not back to the button.
    const saved = useRef(false);
    const ch = changes(s, draft, view);
    const byGroup = ch.reduce<Record<string, typeof ch>>((acc, c) => {
        (acc[c.group] ??= []).push(c);
        return acc;
    }, {});
    const looser = ch.filter((c) => c.loosens);
    // P11 B2 safety net: not a loosening, but shown before saving.
    const fallbacks = [
        ...new Set(
            ch
                .filter((c) => c.group === 'alerts' || c.group === 'alertExtra')
                .map((c) => c.key),
        ),
    ].flatMap((k) =>
        fallbackWarnings(fallbackHouses(s, draft, k)).map(
            (w) => `${s.definitions.alerts?.[k]?.alert?.label ?? k}: ${w}.`,
        ),
    );

    const save = () => {
        let outcome: 'none' | 'ok' | 'invalid' = 'none';
        router.put(
            '/emar/settings/changes',
            {
                view,
                changes: ch.map((c) => ({
                    group: c.group,
                    key: c.key,
                    site_id: c.site_id,
                    value: c.to,
                    from: c.from,
                })),
                confirm_loosening: looser.length > 0,
            },
            {
                preserveScroll: true,
                preserveState: true,
                errorBag: ERROR_BAG,
                onStart: () => setSaving(true),
                onSuccess: () => {
                    outcome = 'ok';
                },
                onError: (errors) => {
                    outcome = 'invalid';
                    if (errors.conflict) {
                        setProblem(errors.conflict);
                        setState('conflict');
                    } else {
                        setProblem(Object.values(errors)[0] ?? '');
                        setState('fail');
                    }
                },
                onFinish: () => {
                    setSaving(false);
                    if (outcome === 'none') {
                        setProblem('');
                        setState('fail');
                    }
                    if (outcome === 'ok') {
                        saved.current = true;
                        setDraft((d) => withoutDrafts(s, d, view));
                        close();
                    }
                },
            },
        );
    };
    const refresh = () =>
        router.reload({
            only: ['settings'],
            onSuccess: (page) => {
                const latest = (
                    page.props.settings as SettingsPayload
                ).history.find((h) => h.view === view);
                close();
                flash(
                    `Refreshed. ${latest?.who ? `${latest.who}’s` : 'The latest'} change is shown; your changes are kept — review and save again.`,
                );
            },
        });

    return (
        <SettingsModal
            title={`Review ${VIEW_LABEL[view].toLowerCase()} changes`}
            description={`${ch.length} ${ch.length === 1 ? 'change' : 'changes'} · nothing applies until you save.`}
            onClose={close}
            onCloseAutoFocus={(e) => {
                if (saved.current) e.preventDefault();
            }}
            footer={
                state === 'conflict' ? (
                    <>
                        <Button variant="outline" onClick={close}>
                            Keep editing
                        </Button>
                        <Button onClick={refresh}>Refresh and review</Button>
                    </>
                ) : (
                    <>
                        <Button
                            variant="outline"
                            onClick={close}
                            disabled={saving}
                        >
                            Keep editing
                        </Button>
                        <Button
                            variant={looser.length ? 'destructive' : 'default'}
                            onClick={save}
                            disabled={saving || !ch.length}
                        >
                            {state === 'fail' ? 'Try again' : 'Save changes'}
                        </Button>
                    </>
                )
            }
        >
            {state === 'fail' ? (
                <SettingsNotice>
                    <span>
                        <b>Couldn’t save — nothing was changed.</b>{' '}
                        {problem ||
                            'Check your connection and try again. Your changes are kept.'}
                    </span>
                </SettingsNotice>
            ) : null}
            {state === 'conflict' ? (
                <SettingsNotice>
                    <span>
                        <b>Settings changed elsewhere.</b> {problem} Your
                        changes are kept — refresh to see theirs, then save
                        again.
                    </span>
                </SettingsNotice>
            ) : null}
            {Object.entries(byGroup).map(([g, list]) => (
                <ReviewCard
                    key={g}
                    icon={GROUP_ICON[g] ?? Layers}
                    title={s.groups[g]?.effect ?? ''}
                >
                    {list.map((c) => (
                        <ReviewRow
                            key={`${c.key}@${c.site_id ?? 'org'}`}
                            label={c.label}
                            value={
                                <>
                                    <span className="text-muted-foreground line-through decoration-1">
                                        {c.fromText}
                                    </span>{' '}
                                    → <b>{c.toText}</b>
                                    {c.loosens ? (
                                        <span className="text-caption block text-status-critical">
                                            Loosens this check
                                        </span>
                                    ) : null}
                                </>
                            }
                        />
                    ))}
                </ReviewCard>
            ))}
            {fallbacks.length ? (
                <InfoCard icon={AlertTriangle} tone="warn">
                    <b>
                        After saving, nobody in the chosen groups would be told
                        at some houses.
                    </b>{' '}
                    Their alerts go to the people who manage medication settings
                    there instead.
                    <ul className="mt-1 list-disc pl-5">
                        {fallbacks.map((f) => (
                            <li key={f}>{f}</li>
                        ))}
                    </ul>
                </InfoCard>
            ) : null}
            {looser.length ? (
                <InfoCard icon={AlertTriangle} tone="warn">
                    <b>
                        {looser.length === 1
                            ? 'This change loosens a check'
                            : `${looser.length} of these changes loosen a check`}
                        .
                    </b>{' '}
                    Staff will be checked less than they are now. Save only if
                    that’s intended.
                </InfoCard>
            ) : null}
            <Recorded />
        </SettingsModal>
    );
}

export function DiscardView({ view }: { view: ViewKey }) {
    const { s, draft, setDraft, close, flash } = useSettings();
    const ch = changes(s, draft, view);
    return (
        <ConfirmDialog
            open
            onClose={close}
            title={`Discard ${VIEW_LABEL[view].toLowerCase()} changes?`}
            confirmText="Discard changes"
            cancelText="Keep editing"
            description={
                <>
                    <p>
                        {ch.length} unsaved{' '}
                        {ch.length === 1 ? 'change' : 'changes'} will be lost.
                        Saved settings stay as they are.
                    </p>
                    <ul className="mt-2 list-disc pl-5">
                        {ch.map((c) => (
                            <li
                                key={`${c.group}.${c.key}@${c.site_id ?? 'org'}`}
                            >
                                <b>{c.label}:</b> {c.toText}
                            </li>
                        ))}
                    </ul>
                </>
            }
            onConfirm={() => {
                setDraft((d) => withoutDrafts(s, d, view));
                flash(
                    'Unsaved changes discarded. Saved settings are unchanged.',
                );
            }}
        />
    );
}

export function UnsavedList() {
    const { s, draft, close, go } = useSettings();
    const ch = changes(s, draft);
    const views = [...new Set(ch.map((c) => c.view))];
    return (
        <SettingsModal
            title="Unsaved changes"
            description="Nothing applies until you review and save each view."
            onClose={close}
        >
            {views.map((v) => (
                <ReviewCard key={v} icon={Layers} title={VIEW_LABEL[v]}>
                    {ch
                        .filter((c) => c.view === v)
                        .map((c) => (
                            <ReviewRow
                                key={`${c.group}.${c.key}@${c.site_id ?? 'org'}`}
                                label={c.label}
                                value={c.toText}
                            />
                        ))}
                    <Button
                        variant="link"
                        onClick={() => {
                            close();
                            go(v);
                        }}
                    >
                        Go to {VIEW_LABEL[v]}{' '}
                        <ArrowUpRight className="size-4" />
                    </Button>
                </ReviewCard>
            ))}
        </SettingsModal>
    );
}

/** Fleet's guard: leaving Settings with an unsaved draft asks first. */
export function LeaveGuard({ url }: { url: string }) {
    const { s, draft, close, leave } = useSettings();
    return (
        <SettingsModal
            title="Leave with an unsaved draft?"
            description="Your saved settings will stay unchanged."
            onClose={close}
            footer={
                <>
                    <Button variant="outline" onClick={close} autoFocus>
                        Keep editing
                    </Button>
                    <Button onClick={() => leave(url)}>Leave page</Button>
                </>
            }
        >
            <p className="text-subtle">
                Review and save your changes before leaving if you want them
                applied.
            </p>
            <ul className="list-disc space-y-1 pl-5 text-[13px]">
                {changes(s, draft).map((c) => (
                    <li key={`${c.group}.${c.key}@${c.site_id ?? 'org'}`}>
                        <b>{VIEW_LABEL[c.view]}</b> — {c.label}: {c.toText}
                    </li>
                ))}
            </ul>
        </SettingsModal>
    );
}

export const historyWhat = (h: HistoryEntry) =>
    h.action === 'kept' ? `Reviewed — ${h.label}` : h.label;
export const historyScope = (h: HistoryEntry) => h.site_name ?? 'All houses';

export function HistDetail({ id }: { id: number }) {
    const { s, draft, close, go, open, canEdit } = useSettings();
    const h = s.history.find((x) => x.id === id);
    if (!h) return <NotFound what="change" />;
    return (
        <SettingsModal
            title={historyWhat(h)}
            description={`${whenText(h.at)} NZ time · ${h.who ?? 'Someone'}${h.note ? ` · ${h.note}` : ''}`}
            onClose={close}
            footer={
                <>
                    <Button variant="outline" onClick={close}>
                        Close
                    </Button>
                    {canRestore(s, draft, h, canEdit) ? (
                        <Button
                            variant="outline"
                            onClick={() => open({ kind: 'restore', id: h.id })}
                        >
                            <RotateCcw />
                            Put the earlier value back
                        </Button>
                    ) : null}
                    <Button
                        onClick={() => {
                            close();
                            go(h.view, h.section);
                        }}
                    >
                        Go to the setting
                        <ArrowUpRight />
                    </Button>
                </>
            }
        >
            <ReviewCard icon={History} title="The change">
                <ReviewRow label="Before" value={h.before_text || '—'} />
                <ReviewRow label="After" value={<b>{h.after_text}</b>} />
            </ReviewCard>
            <ReviewCard icon={Building2} title="Where and when">
                <ReviewRow
                    label="Area"
                    value={`${VIEW_LABEL[h.view]} › ${sectionLabel(h.view, h.section)}`}
                />
                <ReviewRow label="Where" value={historyScope(h)} />
                <ReviewRow
                    label="Recorded as"
                    value={<code className="text-[12px]">{h.event}</code>}
                />
            </ReviewCard>
        </SettingsModal>
    );
}

export function NotFound({ what = 'record' }: { what?: string }) {
    const { close } = useSettings();
    return (
        <SettingsModal
            title="We can’t show this record"
            description="It may not exist, or it may not be available to you."
            onClose={close}
        >
            <p className="text-subtle">
                Check the link, or choose the {what} from the list.
            </p>
        </SettingsModal>
    );
}

/** Put an earlier value back (Change history). It goes into the draft; nothing changes until it's reviewed and saved. */
export function RestoreValue({ id }: { id: number }) {
    const { s, draft, setDraft, close, go, flash, canEdit } = useSettings();
    const h = s.history.find((x) => x.id === id);
    const def = h ? definitionOf(s, h.group, h.key) : undefined;
    if (
        !h ||
        !def ||
        h.before_value === null ||
        !canRestore(s, draft, h, canEdit)
    )
        return <NotFound what="change" />;
    const earlier = h.before_value;
    const current = savedValue(s, h.group, h.key);
    const weaker = loosens(def, current, earlier);
    const where = VIEW_LABEL[h.view].toLowerCase();
    return (
        <ConfirmDialog
            open
            onClose={close}
            variant={weaker ? 'destructive' : 'default'}
            title="Put the earlier value back in your draft?"
            confirmText="Put back in draft"
            description={
                <>
                    <p>
                        <b className="text-foreground">{def.label}</b>
                    </p>
                    <p className="mt-1">Now: {format(def, current)}</p>
                    <p>
                        Earlier:{' '}
                        <b className="text-foreground">
                            {format(def, earlier)}
                        </b>{' '}
                        — before {h.who ?? 'someone'}’s change on{' '}
                        {whenText(h.at)}.
                    </p>
                    {weaker ? (
                        <p className="mt-2 text-status-critical">
                            <b>This loosens a check.</b> Staff will be checked
                            less than they are now.
                        </p>
                    ) : null}
                    {h.note ? (
                        <p className="mt-2 text-status-critical">
                            This undoes a recorded decision: {h.note}.
                        </p>
                    ) : null}
                    <p className="mt-2">
                        Nothing changes until you review and save {where}.
                        Saving records it in the change history.
                    </p>
                </>
            }
            onConfirm={() => {
                setDraft((d) => withDraft(d, h.group, h.key, earlier));
                go(h.view, h.section);
                flash(
                    `Earlier value put back in your draft. Review and save ${where} to apply it.`,
                );
            }}
        />
    );
}

/** A setting and its pair, where the pair is still a default nobody reviewed. */
const keepItems = (s: SettingsPayload, group: string, key: string) =>
    keptTogether(s, group, key)
        .filter((k) => k === key || !reviewerOf(s, group, k))
        .map((k) => ({ group, key: k, site_id: null }));

/** Keep today's value: confirms a default as reviewed without changing it (records who and when). */
export function KeepDefault({
    group,
    keyName,
}: {
    group: string;
    keyName: string;
}) {
    const { s, close, canEdit } = useSettings();
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState('');
    const def = definitionOf(s, group, keyName);
    if (!def || !canEdit(group) || s.reviewed[group]?.[keyName])
        return <NotFound what="setting" />;
    const pair = def.paired_with
        ? definitionOf(s, group, def.paired_with)
        : undefined;
    const value = format(def, savedValue(s, group, keyName));
    const pairValue = pair
        ? format(pair, savedValue(s, group, pair.key))
        : null;
    return (
        <ConfirmDialog
            open
            onClose={close}
            variant="default"
            title="Keep today’s value?"
            confirmText="Keep this value"
            processing={saving}
            description={
                <>
                    <p>
                        <b className="text-foreground">{def.label}</b>: {value}
                        {pairValue ? ` within ${pairValue}` : ''}.
                    </p>
                    <p className="mt-2">
                        Nothing changes in how doses are recorded. The setting
                        shows as reviewed by you, and this is recorded in the
                        change history and the audit log.
                    </p>
                    {problem ? (
                        <p className="mt-2 text-status-critical">{problem}</p>
                    ) : null}
                </>
            }
            onConfirm={() =>
                router.post(
                    '/emar/settings/keep',
                    { items: keepItems(s, group, keyName) },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        errorBag: ERROR_BAG,
                        onStart: () => setSaving(true),
                        onSuccess: () => close(),
                        onError: (errors) =>
                            setProblem(
                                errors.conflict
                                    ? `Someone reviewed it first. ${errors.conflict}`
                                    : (Object.values(errors)[0] ??
                                          'Couldn’t keep it — nothing was changed.'),
                            ),
                        onFinish: () => setSaving(false),
                    },
                )
            }
        />
    );
}

/* ── Review the defaults in one go: every "Default — not yet reviewed" the person can change, one view per step. ── */
const STEP_ICON: Record<string, typeof Bell> = {
    rules: Pill,
    rounds: Repeat,
    staff: Users,
    alerts: Bell,
};
type ReviewItem = {
    id: string;
    group: string;
    key: string;
    view: ViewKey;
    section: string;
    label: string;
    today: string;
    keepable: boolean;
};
export function reviewItems(
    s: SettingsPayload,
    canEdit: (group: string) => boolean,
): ReviewItem[] {
    return stillToDecide(s).map((p) => ({
        id: `${p.group}.${p.key}`,
        group: p.group,
        key: p.key,
        view: p.view,
        section: p.section,
        label: p.label,
        today: p.until,
        keepable: p.state === 'default' && canEdit(p.group),
    }));
}

export function ReviewDefaults() {
    const { s, close, go, canEdit } = useSettings();
    const [items] = useState(() => reviewItems(s, canEdit));
    const views = (['rules', 'rounds', 'staff', 'alerts'] as ViewKey[]).filter(
        (v) => items.some((i) => i.view === v),
    );
    const steps = [
        ...views.map((v) => ({
            key: v,
            label: VIEW_LABEL[v],
            blurb: `${items.filter((i) => i.view === v).length} to decide`,
            icon: STEP_ICON[v],
        })),
        {
            key: 'review',
            label: 'Review',
            blurb: 'Keep the ones you chose',
            icon: Check,
        },
    ];
    const [step, setStep] = useState(0);
    const [keep, setKeep] = useState<Record<string, boolean>>({});
    const [guard, setGuard] = useState<
        null | { view: ViewKey; section: string } | 'close'
    >(null);
    const [done, setDone] = useState<null | { kept: number; left: number }>(
        null,
    );
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState('');
    const kept = items.filter((i) => keep[i.id]);
    const total = items.length;
    const left = total - kept.length;
    if (!items.some((i) => i.keepable)) return <NotFound what="setting" />;

    const leave = (to: { view: ViewKey; section: string } | null) => {
        if (kept.length && !done) {
            setGuard(to ?? 'close');
            return;
        }
        if (to) {
            close();
            go(to.view, to.section);
        } else close();
    };
    const apply = () =>
        router.post(
            '/emar/settings/keep',
            {
                items: kept.flatMap((i) => keepItems(s, i.group, i.key)),
            },
            {
                preserveScroll: true,
                preserveState: true,
                errorBag: ERROR_BAG,
                onStart: () => setSaving(true),
                onSuccess: () => setDone({ kept: kept.length, left }),
                onError: (errors) =>
                    setProblem(
                        errors.conflict
                            ? `Someone reviewed one of these first — ${errors.conflict} Close and open the walkthrough again.`
                            : (Object.values(errors)[0] ??
                                  'Couldn’t keep these — nothing was changed.'),
                    ),
                onFinish: () => setSaving(false),
            },
        );
    const last = steps.length - 1;
    const v = views[step];
    const list = items.filter((i) => i.view === v);
    return (
        <>
            <WizardShell
                open
                onClose={() => leave(null)}
                title="Review the defaults"
                description="Keep today’s value for each setting you’re happy with. The rest stay “not yet reviewed”."
                railIcon={Check}
                railTitle="Review the defaults"
                railSub={`${total} still to decide`}
                steps={steps}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    (kept.length /
                        Math.max(1, items.filter((i) => i.keepable).length)) *
                        100,
                )}
                success={
                    done ? (
                        <WizardSuccessPane
                            title={`${done.kept} ${done.kept === 1 ? 'value' : 'values'} kept`}
                            blurb={
                                <>
                                    They now show as reviewed by you, and each
                                    is in the change history. {done.left} still
                                    to decide — they carry on behaving as today
                                    until someone chooses.
                                </>
                            }
                            actions={
                                <Button onClick={close} autoFocus>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => leave(null)}>
                            Cancel
                        </Button>
                        {step > 0 ? (
                            <Button
                                variant="outline"
                                onClick={() => setStep(step - 1)}
                            >
                                <ChevronLeft />
                                Back
                            </Button>
                        ) : null}
                    </div>
                }
                footerEnd={
                    step < last ? (
                        <Button onClick={() => setStep(step + 1)}>
                            Continue
                            <ChevronRight />
                        </Button>
                    ) : (
                        <Button
                            onClick={apply}
                            disabled={!kept.length || saving}
                        >
                            <Check />
                            Keep {kept.length}{' '}
                            {kept.length === 1 ? 'value' : 'values'}
                        </Button>
                    )
                }
            >
                <WizardStepPane key={step}>
                    {step < last ? (
                        <div className="space-y-4">
                            <StepHead
                                icon={STEP_ICON[v]}
                                title={VIEW_LABEL[v]}
                                blurb="Turn on Keep for each value you’re happy with. To change one instead, open its tab."
                            />
                            {list.some((i) => i.keepable) ? (
                                <div className="flex justify-end">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setKeep({
                                                ...keep,
                                                ...Object.fromEntries(
                                                    list
                                                        .filter(
                                                            (i) => i.keepable,
                                                        )
                                                        .map((i) => [
                                                            i.id,
                                                            true,
                                                        ]),
                                                ),
                                            })
                                        }
                                    >
                                        Keep all on this step
                                    </Button>
                                </div>
                            ) : null}
                            <div className="divide-y divide-border rounded-xl border">
                                {list.map((i) => (
                                    <div
                                        key={i.id}
                                        className="flex items-center justify-between gap-4 p-3"
                                    >
                                        <div className="min-w-0">
                                            <label
                                                htmlFor={`rd-${i.id}`}
                                                className="text-[13px] font-semibold"
                                            >
                                                {i.label}
                                            </label>
                                            <p className="text-caption">
                                                {i.today}
                                            </p>
                                        </div>
                                        {i.keepable ? (
                                            <span className="inline-flex items-center gap-3">
                                                <span className="text-caption">
                                                    Keep
                                                </span>
                                                <OnOff
                                                    id={`rd-${i.id}`}
                                                    checked={!!keep[i.id]}
                                                    label={`Keep today’s value: ${i.label}`}
                                                    onChange={(x) =>
                                                        setKeep({
                                                            ...keep,
                                                            [i.id]: x,
                                                        })
                                                    }
                                                />
                                            </span>
                                        ) : (
                                            <Button
                                                variant="link"
                                                onClick={() =>
                                                    leave({
                                                        view: i.view,
                                                        section: i.section,
                                                    })
                                                }
                                            >
                                                Open its tab{' '}
                                                <ArrowUpRight className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead
                                icon={Check}
                                title="Review"
                                blurb="Kept values show as reviewed by you. Each is recorded in the change history and the audit log."
                            />
                            {problem ? (
                                <SettingsNotice>
                                    <span>{problem}</span>
                                </SettingsNotice>
                            ) : null}
                            <div className="grid gap-4 sm:grid-cols-2">
                                {views.map((x, n) => {
                                    const kv = kept.filter((i) => i.view === x);
                                    return (
                                        <ReviewCard
                                            key={x}
                                            icon={STEP_ICON[x]}
                                            title={VIEW_LABEL[x]}
                                            onEdit={() => setStep(n)}
                                        >
                                            {kv.length ? (
                                                kv.map((i) => (
                                                    <ReviewRow
                                                        key={i.id}
                                                        label={i.label}
                                                        value="Keep"
                                                    />
                                                ))
                                            ) : (
                                                <p className="text-caption">
                                                    Nothing kept — these stay to
                                                    decide.
                                                </p>
                                            )}
                                        </ReviewCard>
                                    );
                                })}
                            </div>
                            <InfoCard icon={Info}>
                                <b>
                                    {kept.length} kept · {left} still to decide
                                    after this.
                                </b>{' '}
                                Nothing about how doses are recorded changes.
                            </InfoCard>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog
                open={guard !== null}
                mode="edit"
                description="The values you chose to keep haven’t been applied. Leaving now loses them."
                onKeepEditing={() => setGuard(null)}
                onDiscard={() => {
                    const to = guard;
                    setGuard(null);
                    close();
                    if (to && to !== 'close') go(to.view, to.section);
                }}
            />
        </>
    );
}

/** The one place dialogs are chosen. */
export function DialogHost({ dialog }: { dialog: Dialog | null }) {
    if (!dialog) return null;
    switch (dialog.kind) {
        case 'review':
            return <ReviewChanges view={dialog.view} />;
        case 'discard':
            return <DiscardView view={dialog.view} />;
        case 'unsaved':
            return <UnsavedList />;
        case 'guard':
            return <LeaveGuard url={dialog.url} />;
        case 'hist':
            return <HistDetail id={dialog.id} />;
        case 'restore':
            return <RestoreValue id={dialog.id} />;
        case 'keep':
            return <KeepDefault group={dialog.group} keyName={dialog.key} />;
        case 'reviewdefaults':
            return <ReviewDefaults />;
        default:
            // Medicine-rule dialogs are hosted by RuleDialogHost.
            return null;
    }
}
