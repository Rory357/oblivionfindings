/* Medication rules › Medicine rules (eMAR P11 v5 / P00 v5 builder): rules
 * that ask for a second person or an observation before a dose is saved.
 * House managers keep rules for their own houses; rules for all houses need
 * all-sites authority (answer 1). Rules are paused, never deleted, and every
 * change is recorded in the change history. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { EntityChip } from '@/components/lists/entity-cells';
import {
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Field,
    InfoCard,
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
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Eye,
    History,
    Home,
    Pause,
    Pencil,
    Pill,
    Play,
    Plus,
    Route as RouteIcon,
    Shield,
    Tag,
} from 'lucide-react';
import { useState } from 'react';
import { useSettings, type Dialog } from './_context';
import { ERROR_BAG, NotFound, whenText } from './_dialogs';
import {
    Note,
    OnOff,
    RecordPicker,
    RowMenu,
    Section,
    type PickItem,
} from './_ui';

export type MatchType = 'medicine_name' | 'route' | 'nzulm_code' | 'controlled';
export type MedicineRule = {
    id: number;
    site_id: number | null;
    site_name: string | null;
    match_type: MatchType;
    match_value: string;
    requires_countersign: boolean;
    required_observations: string[];
    active: boolean;
    what: string;
    needs: string;
    sentence: string;
    last_changed_by: string | null;
    last_changed_at: string | null;
    paused_note: string | null;
    can_change: boolean;
};
export type RuleOptions = {
    names: { name: string; routes: string[] }[];
    routes: string[];
    nzulm: { code: string; name: string }[];
};
export type RuleData = {
    rules: MedicineRule[];
    options: RuleOptions;
    sites: { id: number; name: string }[];
    can: { manage: boolean; manage_global: boolean };
    readOnlyAudit: boolean;
};

export const ALL_HOUSES = 'All houses';

/* ── Wording: the same words as the server's MedicineRuleWording ── */
const OBSERVATIONS: Record<string, string> = {
    pulse: 'pulse',
    blood_glucose: 'blood sugar (BSL)',
    blood_pressure: 'blood pressure',
};
type RuleShape = Pick<
    MedicineRule,
    | 'match_type'
    | 'match_value'
    | 'site_id'
    | 'requires_countersign'
    | 'required_observations'
>;
export function ruleWhat(r: Pick<RuleShape, 'match_type' | 'match_value'>) {
    const v = r.match_value.trim();
    if (r.match_type === 'route')
        return `any medicine given by ${v.toLowerCase()}`;
    if (r.match_type === 'nzulm_code')
        return `the product with NZULM code ${v}`;
    if (r.match_type === 'controlled') return 'any controlled medicine';
    return v;
}
export function ruleNeeds(
    r: Pick<RuleShape, 'requires_countersign' | 'required_observations'>,
) {
    const parts = [
        r.requires_countersign
            ? 'a second person confirms with their witness PIN'
            : '',
        ...r.required_observations.map((o) => `record ${OBSERVATIONS[o] ?? o}`),
    ].filter(Boolean);
    return parts.length ? parts.join(' and ') : 'choose what it requires';
}
export const ruleSentence = (r: RuleShape, where: string) =>
    `Before saving a dose of ${ruleWhat(r)} at ${where}: ${ruleNeeds(r)}.`;

const whereOf = (r: Pick<MedicineRule, 'site_name' | 'site_id'>) =>
    r.site_id === null ? ALL_HOUSES : (r.site_name ?? 'a house');

const match = (q: string, ...s: (string | null | undefined)[]) =>
    !q || s.some((x) => (x ?? '').toLowerCase().includes(q.toLowerCase()));

/* ── The list ── */
export function MedicineRules({
    data,
    q,
    where,
    state,
    clear,
}: {
    data: RuleData;
    q: string;
    where: string;
    state: string;
    clear: () => void;
}) {
    const { open } = useSettings();
    const menu = useEntityContextMenu<MedicineRule>();
    const { rules, can, sites, readOnlyAudit } = data;
    const canAdd = can.manage && !readOnlyAudit;
    const rows = rules.filter(
        (r) =>
            (where === 'all' ||
                (where === 'all-houses'
                    ? r.site_id === null
                    : r.site_id === null || String(r.site_id) === where)) &&
            (state === 'all' || (state === 'active') === r.active) &&
            match(q, r.what, r.needs, whereOf(r)),
    );
    const actions = (r: MedicineRule): MenuItem[] =>
        compactMenu([
            r.can_change
                ? {
                      label: 'Edit rule',
                      icon: Pencil,
                      onClick: () => open({ kind: 'rule', id: r.id }),
                  }
                : {
                      label: 'View rule',
                      icon: Eye,
                      onClick: () => open({ kind: 'ruleview', id: r.id }),
                  },
            r.can_change && {
                label: r.active ? 'Pause rule' : 'Turn rule back on',
                icon: r.active ? Pause : Play,
                onClick: () => open({ kind: 'ruletoggle', id: r.id }),
            },
            { separator: true },
            {
                label: 'View change history',
                icon: History,
                onClick: () => open({ kind: 'rulehistory', id: r.id }),
            },
        ]);
    const right = canAdd ? (
        <Button size="sm" onClick={() => open({ kind: 'rule', id: 'new' })}>
            <Plus />
            Add a rule
        </Button>
    ) : null;
    return (
        <Section
            id="sc-med"
            title="Rules for specific medicines"
            caption={
                rules.length
                    ? `${rows.length} of ${rules.length} shown`
                    : 'None yet'
            }
            right={right}
        >
            <p className="text-subtle">
                Extra checks before a dose is saved: a second person confirms
                with their witness PIN, and/or an observation is recorded.
            </p>
            {!rules.length ? (
                <EmptyState
                    icon={Pill}
                    title="No medicine rules yet"
                    description="Add a rule when a medicine needs a second person or an observation before each dose."
                    action={
                        canAdd ? (
                            <Button
                                size="sm"
                                onClick={() =>
                                    open({ kind: 'rule', id: 'new' })
                                }
                            >
                                <Plus />
                                Add a rule
                            </Button>
                        ) : undefined
                    }
                />
            ) : rows.length ? (
                <EntityTable<MedicineRule>
                    rows={rows}
                    rowKey={(r) => r.id}
                    identityLabel="Rule"
                    identityWidth="2.2fr"
                    minWidth={860}
                    identity={(r) => ({
                        icon: Pill,
                        name: r.what,
                        subline: r.needs,
                    })}
                    columns={[
                        {
                            key: 'where',
                            label: 'Where',
                            width: '1fr',
                            cell: (r) => (
                                <EntityChip
                                    icon={r.site_id === null ? Building2 : Home}
                                >
                                    {whereOf(r)}
                                </EntityChip>
                            ),
                        },
                        {
                            key: 'active',
                            label: 'Active',
                            width: '1.1fr',
                            cell: (r) => (
                                <div onClick={(e) => e.stopPropagation()}>
                                    <OnOff
                                        id={`rs-${r.id}`}
                                        checked={r.active}
                                        disabled={!r.can_change}
                                        label={`Rule active: ${r.what}`}
                                        onChange={() =>
                                            open({
                                                kind: 'ruletoggle',
                                                id: r.id,
                                            })
                                        }
                                    />
                                    {!r.active && r.paused_note ? (
                                        <p className="text-caption mt-1">
                                            {r.paused_note}
                                        </p>
                                    ) : null}
                                </div>
                            ),
                        },
                        {
                            key: 'by',
                            label: 'Last changed',
                            width: '1fr',
                            cell: (r) => (
                                <div>
                                    <div className="text-[13px]">
                                        {r.last_changed_by ?? '—'}
                                    </div>
                                    <div className="text-caption">
                                        {r.last_changed_at
                                            ? whenText(r.last_changed_at)
                                            : ''}
                                    </div>
                                </div>
                            ),
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={(r) =>
                        open({
                            kind: r.can_change ? 'rule' : 'ruleview',
                            id: r.id,
                        })
                    }
                    onRowContextMenu={menu.open}
                    mutedFor={(r) => !r.active}
                />
            ) : (
                <EmptyState
                    icon={Pill}
                    title="No rules match these filters"
                    description="Clear the filters or the search to see every rule."
                    action={
                        <Button variant="outline" size="sm" onClick={clear}>
                            Clear filters
                        </Button>
                    }
                />
            )}
            <RowMenu
                ctx={menu.ctx}
                close={menu.close}
                icon={Pill}
                title={(r) => r.what}
                items={actions}
            />
            <Note>
                {readOnlyAudit
                    ? 'Read-only for audit.'
                    : can.manage_global
                      ? 'Rules for all houses need all-sites authority; a house manager can add and change rules for their own houses.'
                      : can.manage
                        ? `You can add and change rules for ${sites.map((x) => x.name).join(' and ') || 'your houses'}. Rules for all houses need all-sites authority — ask someone who manages medication settings for all houses.`
                        : 'Only people who manage medication settings can change these rules.'}
            </Note>
        </Section>
    );
}

/* ── Add / edit: the P00 v5 builder on WizardShell ── */
const RULE_STEPS = [
    {
        key: 'what',
        label: 'What it applies to',
        blurb: 'Medicines and houses',
        icon: Pill,
    },
    {
        key: 'needs',
        label: 'What it requires',
        blurb: 'Before a dose is saved',
        icon: ClipboardCheck,
    },
    {
        key: 'review',
        label: 'Review & save',
        blurb: 'Check the rule',
        icon: Check,
    },
] as const;
const MATCH_TILES: {
    key: MatchType;
    label: string;
    description: string;
    icon: typeof Pill;
}[] = [
    {
        key: 'medicine_name',
        label: 'Medicine name',
        description: 'For example insulin glargine',
        icon: Pill,
    },
    {
        key: 'route',
        label: 'Route',
        description: 'For example subcutaneous injection',
        icon: RouteIcon,
    },
    {
        key: 'nzulm_code',
        label: 'NZULM code',
        description: 'One exact product',
        icon: Tag,
    },
    {
        key: 'controlled',
        label: 'Controlled status',
        description: 'Any controlled medicine',
        icon: Shield,
    },
];
const NEEDS: [string, string][] = [
    ['cs', 'A second person confirms with their witness PIN'],
    ['pulse', 'Record pulse'],
    ['blood_glucose', 'Record blood sugar (BSL)'],
    ['blood_pressure', 'Record blood pressure'],
];
type Draft = {
    match_type: MatchType;
    match_value: string;
    scope: string; // 'all' or a site id
    requires_countersign: boolean;
    required_observations: string[];
    active: boolean;
};
const Recorded = () => (
    <p className="text-caption">
        Recorded in the change history and the audit log with your name and the
        time.
    </p>
);

export function RuleWizard({
    id,
    data,
}: {
    id: number | 'new';
    data: RuleData;
}) {
    const { close } = useSettings();
    const src = id === 'new' ? undefined : data.rules.find((r) => r.id === id);
    const scopes: { id: string; name: string; sub: string }[] = [
        ...(data.can.manage_global
            ? [
                  {
                      id: 'all',
                      name: ALL_HOUSES,
                      sub: 'Every house, now and in future',
                  },
              ]
            : []),
        ...data.sites.map((s) => ({
            id: String(s.id),
            name: s.name,
            sub: 'This house only',
        })),
    ];
    const [r, setR] = useState<Draft>(() =>
        src
            ? {
                  match_type: src.match_type,
                  match_value: src.match_value,
                  scope: src.site_id === null ? 'all' : String(src.site_id),
                  requires_countersign: src.requires_countersign,
                  required_observations: [...src.required_observations],
                  active: src.active,
              }
            : {
                  match_type: 'medicine_name',
                  match_value: '',
                  scope: scopes[0]?.id ?? '',
                  requires_countersign: false,
                  required_observations: [],
                  active: true,
              },
    );
    const [step, setStep] = useState(0);
    const [err, setErr] = useState<'' | 'value' | 'scope' | 'needs'>('');
    const [problem, setProblem] = useState('');
    const [dirty, setDirty] = useState(false);
    const [saved, setSaved] = useState(false);
    const [saving, setSaving] = useState(false);
    const [guard, setGuard] = useState(false);
    if (id !== 'new' && (!src || !src.can_change))
        return <NotFound what="rule" />;

    const up = (patch: Partial<Draft>) => {
        setR({ ...r, ...patch });
        setDirty(true);
        setErr('');
    };
    const valueOk = r.match_type === 'controlled' || !!r.match_value.trim();
    const scopeOk = scopes.some((s) => s.id === r.scope);
    const needsOk =
        r.requires_countersign || r.required_observations.length > 0;
    const where = scopes.find((s) => s.id === r.scope)?.name ?? '…';
    const shape: RuleShape = {
        match_type: r.match_type,
        match_value: r.match_value,
        site_id: r.scope === 'all' ? null : Number(r.scope),
        requires_countersign: r.requires_countersign,
        required_observations: r.required_observations,
    };
    const sentence = ruleSentence(shape, where);
    const next = () => {
        if (step === 0 && !valueOk) return setErr('value');
        if (step === 0 && !scopeOk) return setErr('scope');
        if (step === 1 && !needsOk) return setErr('needs');
        setErr('');
        setStep(Math.min(2, step + 1));
    };
    const save = () => {
        if (!valueOk || !scopeOk) {
            setStep(0);
            return setErr(valueOk ? 'scope' : 'value');
        }
        if (!needsOk) {
            setStep(1);
            return setErr('needs');
        }
        const payload = {
            site_id: shape.site_id,
            match_type: r.match_type,
            match_value: r.match_type === 'controlled' ? null : r.match_value,
            requires_countersign: r.requires_countersign,
            required_observations: r.required_observations,
            active: r.active,
        };
        const options = {
            preserveScroll: true,
            preserveState: true,
            errorBag: ERROR_BAG,
            onStart: () => setSaving(true),
            onSuccess: () => {
                setSaved(true);
                setDirty(false);
            },
            onError: (errors: Record<string, string>) =>
                setProblem(
                    Object.values(errors)[0] ??
                        'Couldn’t save the rule — nothing was changed.',
                ),
            onFinish: () => setSaving(false),
        };
        if (src) router.put(`/emar/settings/rules/${src.id}`, payload, options);
        else router.post('/emar/settings/rules', payload, options);
    };
    const onClose = () => (dirty && !saved ? setGuard(true) : close());
    const nameItems: PickItem[] = data.options.names.map((n) => ({
        id: n.name,
        name: n.name,
        sub: n.routes.join(', ') || 'Current order',
        ok: true,
    }));
    const pct = Math.round(
        ([valueOk, scopeOk, needsOk, true].filter(Boolean).length / 4) * 100,
    );
    return (
        <>
            <WizardShell
                open
                onClose={onClose}
                title={src ? 'Edit medicine rule' : 'Add a medicine rule'}
                description="A rule that asks for a second person or an observation before a dose is saved."
                railIcon={Pill}
                railTitle={src ? 'Edit medicine rule' : 'Add a medicine rule'}
                railSub="Settings › Medication rules"
                steps={RULE_STEPS}
                stepIndex={step}
                onStepClick={(i) => {
                    setErr('');
                    setStep(i);
                }}
                pct={pct}
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={src ? 'Rule updated' : 'Rule added'}
                            blurb={
                                <>
                                    {sentence}
                                    <br />
                                    {r.active
                                        ? 'Applies from the next dose saved.'
                                        : 'Saved as paused — it doesn’t apply until someone turns it on.'}{' '}
                                    Recorded in the change history.
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
                        <Button variant="outline" onClick={onClose}>
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
                    step < 2 ? (
                        <Button onClick={next}>
                            Continue
                            <ChevronRight />
                        </Button>
                    ) : (
                        <Button onClick={save} disabled={saving}>
                            <Check />
                            {src ? 'Save changes' : 'Save rule'}
                        </Button>
                    )
                }
            >
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <div className="space-y-5">
                            <StepHead
                                icon={Pill}
                                title="What it applies to"
                                blurb="Choose the medicines and where the rule applies."
                            />
                            <Field label="Match medicines by" required>
                                <TilePicker
                                    value={r.match_type}
                                    cols={3}
                                    onChange={(v) =>
                                        up({
                                            match_type: v as MatchType,
                                            match_value: '',
                                        })
                                    }
                                    options={MATCH_TILES}
                                />
                            </Field>
                            {r.match_type === 'medicine_name' ? (
                                <RecordPicker
                                    id="rw-v"
                                    label="Medicine"
                                    required
                                    value={r.match_value}
                                    items={nameItems}
                                    onChange={(v) => up({ match_value: v })}
                                    placeholder="Search and choose a medicine"
                                    search="Search medicines…"
                                    error={
                                        err === 'value'
                                            ? 'Choose which medicines this rule applies to.'
                                            : undefined
                                    }
                                    foot="Matches the medicine’s name on the order."
                                />
                            ) : r.match_type === 'controlled' ? (
                                <InfoCard icon={Shield}>
                                    <b>Any controlled medicine</b> — from the
                                    order’s controlled flag.
                                </InfoCard>
                            ) : (
                                <Field
                                    label={
                                        r.match_type === 'route'
                                            ? 'Route'
                                            : 'Product (NZULM code)'
                                    }
                                    required
                                    error={
                                        err === 'value'
                                            ? 'Choose which medicines this rule applies to.'
                                            : undefined
                                    }
                                >
                                    <SelectInput
                                        value={r.match_value}
                                        onChange={(v) => up({ match_value: v })}
                                        placeholder="Choose"
                                        options={
                                            r.match_type === 'route'
                                                ? data.options.routes.map(
                                                      (x) => ({
                                                          value: x,
                                                          label: x,
                                                      }),
                                                  )
                                                : data.options.nzulm.map(
                                                      (x) => ({
                                                          value: x.code,
                                                          label: x.name
                                                              ? `${x.code} — ${x.name}`
                                                              : x.code,
                                                      }),
                                                  )
                                        }
                                    />
                                </Field>
                            )}
                            <RecordPicker
                                id="rw-scope"
                                label="Where it applies"
                                required
                                value={r.scope}
                                items={scopes.map((s) => ({ ...s, ok: true }))}
                                onChange={(v) => up({ scope: v })}
                                placeholder="Choose where"
                                search="Search houses…"
                                error={
                                    err === 'scope'
                                        ? 'Choose where this rule applies.'
                                        : undefined
                                }
                                foot={
                                    data.can.manage_global
                                        ? 'All houses, or one house.'
                                        : 'You can add rules for your own houses. Rules for all houses need all-sites authority.'
                                }
                            />
                            {valueOk ? (
                                <InfoCard icon={Check}>
                                    <b>{sentence}</b>
                                </InfoCard>
                            ) : null}
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-4">
                            <StepHead
                                icon={ClipboardCheck}
                                title="What it requires"
                                blurb="Before the dose is saved. Observation values and ranges aren’t set here."
                            />
                            <div
                                className="divide-y divide-border rounded-xl border"
                                role="group"
                                aria-label="Before the dose is saved"
                                aria-invalid={err === 'needs' || undefined}
                            >
                                {NEEDS.map(([k, l]) => {
                                    const on =
                                        k === 'cs'
                                            ? r.requires_countersign
                                            : r.required_observations.includes(
                                                  k,
                                              );
                                    return (
                                        <div
                                            key={k}
                                            className="flex items-center justify-between gap-4 p-3"
                                        >
                                            <label
                                                htmlFor={`rw-n-${k}`}
                                                className="text-[13px]"
                                            >
                                                {l}
                                            </label>
                                            <OnOff
                                                id={`rw-n-${k}`}
                                                checked={on}
                                                onChange={(v) =>
                                                    up(
                                                        k === 'cs'
                                                            ? {
                                                                  requires_countersign:
                                                                      v,
                                                              }
                                                            : {
                                                                  required_observations:
                                                                      v
                                                                          ? [
                                                                                ...r.required_observations,
                                                                                k,
                                                                            ]
                                                                          : r.required_observations.filter(
                                                                                (
                                                                                    o,
                                                                                ) =>
                                                                                    o !==
                                                                                    k,
                                                                            ),
                                                              },
                                                    )
                                                }
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                            {err === 'needs' ? (
                                <p
                                    className="flex items-center gap-1 text-xs text-status-critical"
                                    role="alert"
                                >
                                    <AlertTriangle className="size-3" />
                                    Turn on at least one: a second person or an
                                    observation.
                                </p>
                            ) : null}
                            <div className="flex items-center justify-between gap-4 rounded-xl border p-3">
                                <div>
                                    <label
                                        htmlFor="rw-active"
                                        className="text-[13px] font-semibold"
                                    >
                                        Active
                                    </label>
                                    <p className="text-caption">
                                        {r.active
                                            ? 'Applies from the next dose saved.'
                                            : 'Saved, but doesn’t apply until someone turns it on.'}
                                    </p>
                                </div>
                                <OnOff
                                    id="rw-active"
                                    checked={r.active}
                                    onChange={(v) => up({ active: v })}
                                />
                            </div>
                            <InfoCard icon={Check}>
                                <b>{sentence}</b>
                            </InfoCard>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead
                                icon={Check}
                                title="Review & save"
                                blurb="Check the rule."
                            />
                            {problem ? (
                                <SettingsNotice>
                                    <span>
                                        <b>
                                            Couldn’t save — nothing was changed.
                                        </b>{' '}
                                        {problem}
                                    </span>
                                </SettingsNotice>
                            ) : null}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard
                                    icon={Pill}
                                    title="Applies to"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Medicines"
                                        value={ruleWhat(shape)}
                                    />
                                    <ReviewRow label="Where" value={where} />
                                </ReviewCard>
                                <ReviewCard
                                    icon={ClipboardCheck}
                                    title="Requires"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Before a dose is saved"
                                        value={ruleNeeds(shape)}
                                    />
                                    <ReviewRow
                                        label="Status"
                                        value={r.active ? 'Active' : 'Paused'}
                                    />
                                </ReviewCard>
                            </div>
                            <Recorded />
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog
                open={guard}
                mode={src ? 'edit' : 'create'}
                description="Nothing you’ve entered here has been saved. Closing now loses it."
                onKeepEditing={() => setGuard(false)}
                onDiscard={() => {
                    setGuard(false);
                    close();
                }}
            />
        </>
    );
}

export function RuleView({ id, data }: { id: number; data: RuleData }) {
    const { close } = useSettings();
    const r = data.rules.find((x) => x.id === id);
    if (!r) return <NotFound what="rule" />;
    return (
        <SettingsModal
            title="Medicine rule"
            description={r.sentence}
            onClose={close}
        >
            <ReviewCard icon={Pill} title="Rule">
                <ReviewRow label="Medicines" value={r.what} />
                <ReviewRow label="Where" value={whereOf(r)} />
                <ReviewRow label="Requires" value={r.needs} />
                <ReviewRow
                    label="Status"
                    value={r.active ? 'Active' : (r.paused_note ?? 'Paused')}
                />
                <ReviewRow
                    label="Last changed"
                    value={`${r.last_changed_by ?? '—'}${r.last_changed_at ? ` · ${whenText(r.last_changed_at)}` : ''}`}
                />
            </ReviewCard>
            <SettingsNotice role="note">
                <span>
                    {r.site_id === null
                        ? 'Rules for all houses need all-sites authority.'
                        : `House rules can be changed by the managers of ${whereOf(r)}.`}
                </span>
            </SettingsNotice>
        </SettingsModal>
    );
}

export function RuleToggle({ id, data }: { id: number; data: RuleData }) {
    const { close } = useSettings();
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState('');
    const r = data.rules.find((x) => x.id === id);
    if (!r || !r.can_change) return <NotFound what="rule" />;
    return (
        <ConfirmDialog
            open
            onClose={close}
            processing={saving}
            variant={r.active ? 'destructive' : 'default'}
            title={r.active ? 'Pause this rule?' : 'Turn this rule back on?'}
            confirmText={r.active ? 'Pause rule' : 'Turn rule on'}
            description={
                <>
                    <p>
                        {r.active
                            ? 'From the next dose saved, doses no longer need it.'
                            : 'From the next dose saved, doses need it again.'}
                    </p>
                    <p className="mt-2 font-medium text-foreground">
                        {r.sentence}
                    </p>
                    <p className="mt-2">
                        Recorded in the change history with your name and the
                        time.
                    </p>
                    {problem ? (
                        <p className="mt-2 text-status-critical">{problem}</p>
                    ) : null}
                </>
            }
            onConfirm={() =>
                router.post(
                    `/emar/settings/rules/${r.id}/active`,
                    { active: !r.active },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        errorBag: ERROR_BAG,
                        onStart: () => setSaving(true),
                        onSuccess: () => close(),
                        onError: (errors) =>
                            setProblem(
                                Object.values(errors)[0] ??
                                    'Couldn’t change the rule — nothing was changed.',
                            ),
                        onFinish: () => setSaving(false),
                    },
                )
            }
        />
    );
}

export function RuleHistory({ id, data }: { id: number; data: RuleData }) {
    const { s, close } = useSettings();
    const r = data.rules.find((x) => x.id === id);
    if (!r) return <NotFound what="rule" />;
    const rows = s.history.filter(
        (h) => h.group === 'medicine_rules' && h.key === `rule:${r.id}`,
    );
    return (
        <SettingsModal
            title="Rule change history"
            description={r.sentence}
            onClose={close}
        >
            {rows.length ? (
                <ReviewCard icon={History} title="Changes">
                    {rows.map((h) => (
                        <ReviewRow
                            key={h.id}
                            label={whenText(h.at)}
                            value={`${h.who ?? 'Someone'} · ${h.label}`}
                        />
                    ))}
                </ReviewCard>
            ) : (
                <p className="text-subtle">
                    No changes recorded for this rule yet.
                </p>
            )}
        </SettingsModal>
    );
}

/** The medicine-rule dialogs (the page's other dialogs are in DialogHost). */
export function RuleDialogHost({
    dialog,
    data,
}: {
    dialog: Dialog | null;
    data: RuleData;
}) {
    if (!dialog) return null;
    switch (dialog.kind) {
        case 'rule':
            return <RuleWizard id={dialog.id} data={data} />;
        case 'ruleview':
            return <RuleView id={dialog.id} data={data} />;
        case 'ruletoggle':
            return <RuleToggle id={dialog.id} data={data} />;
        case 'rulehistory':
            return <RuleHistory id={dialog.id} data={data} />;
        default:
            return null;
    }
}
