import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Building2,
    GitBranch,
    Info,
    LayoutGrid,
    Loader2,
    RefreshCw,
    ShieldCheck,
    Tag,
    Wrench,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    AutoRuleBuilder,
    autoRuleFieldLabel,
    autoRuleOperatorLabel,
    normaliseAutoRules,
    type AutoRules,
} from './auto-rule-builder';
import { autoRuleValueLabel } from './auto-rule-values';

export type WizardGroup = {
    id: number;
    name: string;
    type: string;
    description: string | null;
    auto_rules: AutoRules | null;
};
type Draft = {
    name: string;
    type: string;
    description: string;
    auto_rules: AutoRules | null;
};
const groupTypes = [
    {
        value: 'location',
        label: 'Location',
        description: 'Equipment for a Site or area',
        icon: Building2,
    },
    {
        value: 'functional',
        label: 'Functional',
        description: 'Devices with a shared purpose',
        icon: LayoutGrid,
    },
    {
        value: 'vendor',
        label: 'Vendor',
        description: 'Equipment from one supplier',
        icon: Tag,
    },
    {
        value: 'maintenance',
        label: 'Maintenance',
        description: 'Equipment managed together',
        icon: Wrench,
    },
    {
        value: 'custom',
        label: 'Custom',
        description: 'A group for another purpose',
        icon: GitBranch,
    },
];
export const groupTypeLabel = (value: string) =>
    groupTypes.find((type) => type.value === value)?.label ??
    value.replaceAll('_', ' ');
const steps = [
    {
        key: 'type',
        label: 'Group type',
        blurb: 'Choose how to organise the group',
        icon: GitBranch,
    },
    {
        key: 'details',
        label: 'Group details',
        blurb: 'Name and purpose',
        icon: Info,
    },
    {
        key: 'rules',
        label: 'Automatic membership',
        blurb: 'Optional saved rule',
        icon: RefreshCw,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: ShieldCheck,
    },
];
const initialDraft = (group?: WizardGroup): Draft => ({
    name: group?.name ?? '',
    type: group?.type ?? 'custom',
    description: group?.description ?? '',
    auto_rules: structuredClone(group?.auto_rules ?? null),
});
function validRules(rules: AutoRules | null) {
    return (
        !rules ||
        (['all', 'any'].includes(rules.match) &&
            rules.conditions.length >= 1 &&
            rules.conditions.length <= 8 &&
            rules.conditions.every(
                (rule) =>
                    [
                        'domain',
                        'category',
                        'subcategory',
                        'provider',
                        'status',
                        'health_status',
                    ].includes(rule.field) &&
                    ['equals', 'not_equals', 'in'].includes(rule.op) &&
                    rule.value.length > 0,
            ))
    );
}
const rulesIdentity = (rules: AutoRules | null) =>
    rules
        ? JSON.stringify([
              rules.match,
              rules.conditions.map((rule) => [rule.field, rule.op, rule.value]),
          ])
        : 'null';
function confirmedGroup(
    value: unknown,
    draft: Draft,
    actor: number,
    expectedId?: number,
): WizardGroup {
    if (!value || typeof value !== 'object')
        throw Error('Save was not confirmed');
    const reply = value as {
        saved?: boolean;
        saved_by?: number;
        group?: WizardGroup;
    };
    const group = reply.group;
    if (
        reply.saved !== true ||
        reply.saved_by !== actor ||
        !group ||
        !Number.isSafeInteger(group.id) ||
        group.id <= 0 ||
        (expectedId !== undefined && group.id !== expectedId) ||
        group.name !== draft.name ||
        group.type !== draft.type ||
        (group.description ?? '') !== draft.description ||
        rulesIdentity(group.auto_rules) !== rulesIdentity(draft.auto_rules)
    )
        throw Error('Save identity did not match');
    return group;
}
export function GroupWizard({
    open,
    group,
    onClose,
    onSaved,
}: {
    open: boolean;
    group?: WizardGroup;
    onClose: () => void;
    onSaved?: (group: WizardGroup) => void;
}) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, unknown> };
    }>().props;
    return open ? (
        <GroupWizardBody
            key={JSON.stringify([auth?.user?.id, auth?.can, group])}
            group={group}
            actorId={auth?.user?.id ?? 0}
            onClose={onClose}
            onSaved={onSaved}
        />
    ) : null;
}
function GroupWizardBody({
    group,
    actorId,
    onClose,
    onSaved,
}: {
    group?: WizardGroup;
    actorId: number;
    onClose: () => void;
    onSaved?: (group: WizardGroup) => void;
}) {
    const [draft, setDraft] = useState(() => initialDraft(group));
    const [step, setStep] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [denied, setDenied] = useState(false);
    const [notice, setNotice] = useState('');
    const [saved, setSaved] = useState<WizardGroup | null>(null);
    const request = useRef<AbortController | null>(null);
    const mounted = useRef(true);
    const dirty =
        !saved && JSON.stringify(draft) !== JSON.stringify(initialDraft(group));
    const leave = useSettingsLeaveConfirmation(
        dirty,
        'Discard this group draft?',
        'Your unsaved group details and rule will be discarded. A save already sent may still finish; check the saved group before retrying. Cancel to keep editing.',
    );
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            request.current?.abort();
        };
    }, []);
    const change = <K extends keyof Draft>(key: K, value: Draft[K]) => {
        if (busy) return;
        setDraft((before) => ({ ...before, [key]: value }));
        setErrors((before) => {
            const next = { ...before };
            delete next[key];
            return next;
        });
    };
    const check = (through: number) => {
        const next: Record<string, string> = {};
        if (!groupTypes.some((type) => type.value === draft.type))
            next.type = 'Choose a supported group type.';
        if (through >= 1) {
            if (!draft.name.trim()) next.name = 'Enter a group name.';
            else if (draft.name.trim().length > 255)
                next.name = 'Use 255 characters or fewer.';
            if (draft.description.trim().length > 2000)
                next.description = 'Use 2,000 characters or fewer.';
        }
        if (through >= 2 && !validRules(normaliseAutoRules(draft.auto_rules)))
            next.auto_rules = 'Complete every rule condition before saving.';
        setErrors(next);
        if (Object.keys(next).length) {
            setStep(next.type ? 0 : next.name || next.description ? 1 : 2);
            return false;
        }
        return true;
    };
    async function save() {
        if (busy || uncertain || denied || actorId <= 0 || !check(2)) return;
        const payload: Draft = {
            ...draft,
            name: draft.name.trim(),
            description: draft.description.trim(),
            auto_rules: normaliseAutoRules(draft.auto_rules),
        };
        const call = new AbortController();
        request.current = call;
        setBusy(true);
        setNotice('');
        try {
            const reply = group
                ? await axios.put<unknown>(
                      `/security-devices/device-groups/${group.id}`,
                      payload,
                      {
                          headers: { Accept: 'application/json' },
                          signal: call.signal,
                          timeout: 30000,
                      },
                  )
                : await axios.post<unknown>(
                      '/security-devices/device-groups',
                      payload,
                      {
                          headers: { Accept: 'application/json' },
                          signal: call.signal,
                          timeout: 30000,
                      },
                  );
            const current = confirmedGroup(
                reply.data,
                payload,
                actorId,
                group?.id,
            );
            if (!mounted.current || call.signal.aborted) return;
            setSaved(current);
            onSaved?.(current);
        } catch (error) {
            if (!mounted.current || call.signal.aborted) return;
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                const issues = error.response.data?.errors;
                const next: Record<string, string> = {};
                if (issues && typeof issues === 'object')
                    for (const [key, value] of Object.entries(issues)) {
                        const text = Array.isArray(value) ? value[0] : value;
                        if (typeof text === 'string') next[key] = text;
                    }
                setErrors(next);
                setNotice(
                    'The group was not saved. Check the highlighted entries.',
                );
                setStep(
                    Object.keys(next).some((key) =>
                        key.startsWith('auto_rules'),
                    )
                        ? 2
                        : next.type
                          ? 0
                          : 1,
                );
            } else if (
                axios.isAxiosError(error) &&
                [401, 403, 404].includes(error.response?.status ?? 0)
            ) {
                setDenied(true);
                setNotice(
                    'You cannot save this group with your current access. Your draft is still here.',
                );
            } else {
                setUncertain(true);
                setNotice(
                    'The save could not be confirmed. It may have finished. Check the saved groups before trying again.',
                );
            }
        } finally {
            if (mounted.current && !call.signal.aborted) setBusy(false);
        }
    }
    const close = () => {
        if (!busy) leave.request(onClose);
    };
    const errorFor = (key: string) =>
        errors[key] ? (
            <p role="alert" className="mt-1 text-sm text-destructive">
                {errors[key]}
            </p>
        ) : null;
    const ruleError = Object.entries(errors).find(([key]) =>
        key.startsWith('auto_rules'),
    )?.[1];
    const pct =
        [
            Boolean(draft.type),
            Boolean(draft.name.trim()),
            Boolean(draft.description.trim()),
            validRules(normaliseAutoRules(draft.auto_rules)),
        ].filter(Boolean).length * 25;
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={group ? 'Edit device group' : 'Add device group'}
                description="Group details and optional automatic membership rule."
                railIcon={GitBranch}
                railTitle={group ? 'Edit device group' : 'Add device group'}
                railSub={group?.name ?? 'New device group'}
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy) setStep(index);
                }}
                pct={pct}
                pctLabel="Details completed"
                maxWidth="min(94vw, 1100px)"
                footerStart={
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step < 3 ? (
                        <Button
                            disabled={busy}
                            onClick={() => {
                                if (check(step)) setStep(step + 1);
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                busy || uncertain || denied || actorId <= 0
                            }
                            onClick={save}
                        >
                            {busy && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {busy
                                ? 'Saving…'
                                : group
                                  ? 'Save changes'
                                  : 'Add device group'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={group ? 'Group updated' : 'Group added'}
                            blurb="The details and rule were saved. Saving a rule does not change membership; review and apply it from the group profile."
                            actions={
                                <div className="flex max-w-full min-w-0 flex-wrap justify-center gap-2">
                                    <Button
                                        onClick={() =>
                                            router.visit(
                                                `/security-devices/device-groups/${saved.id}`,
                                            )
                                        }
                                    >
                                        View group
                                    </Button>
                                    {!group && (
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                setSaved(null);
                                                setDraft(initialDraft());
                                                setErrors({});
                                                setNotice('');
                                                setStep(0);
                                            }}
                                        >
                                            Add another
                                        </Button>
                                    )}
                                    <Button variant="outline" onClick={onClose}>
                                        Close
                                    </Button>
                                </div>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    {dirty && !leave.historyProtectionAvailable && (
                        <p
                            role="note"
                            className="mb-4 rounded-lg border p-3 text-sm text-muted-foreground"
                        >
                            Use Cancel to leave this draft safely. This browser
                            cannot ask before you use Back or Forward.
                        </p>
                    )}
                    {notice && (
                        <div
                            role="alert"
                            className="mb-4 space-y-3 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm"
                        >
                            <p>{notice}</p>
                            {uncertain || denied ? (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        leave.request(() =>
                                            router.visit(
                                                group
                                                    ? `/security-devices/device-groups/${group.id}`
                                                    : '/security-devices/device-groups',
                                            ),
                                        )
                                    }
                                >
                                    Check saved {group ? 'group' : 'groups'}
                                </Button>
                            ) : null}
                        </div>
                    )}
                    {step === 0 && (
                        <>
                            <h3 className="mb-3 text-lg font-semibold">
                                Choose a group type
                            </h3>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                {groupTypes.map((type) => (
                                    // eslint-disable-next-line no-restricted-syntax -- Approved type tile needs a card layout and pressed state.
                                    <button
                                        key={type.value}
                                        type="button"
                                        disabled={busy}
                                        aria-pressed={draft.type === type.value}
                                        onClick={() =>
                                            change('type', type.value)
                                        }
                                        className={`flex items-start gap-2 rounded-xl border p-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring ${draft.type === type.value ? 'border-primary bg-primary/10 ring-1 ring-primary/40' : 'border-border bg-card/40 hover:border-primary/50'}`}
                                    >
                                        <type.icon className="mt-0.5 size-4 shrink-0 text-primary" />
                                        <span className="min-w-0">
                                            <span className="block text-sm font-semibold">
                                                {type.label}
                                            </span>
                                            <span className="mt-1 block text-xs text-muted-foreground">
                                                {type.description}
                                            </span>
                                        </span>
                                    </button>
                                ))}
                            </div>
                            {errorFor('type')}
                        </>
                    )}
                    {step === 1 && (
                        <div className="space-y-5">
                            <div>
                                <label
                                    htmlFor="group-wizard-name"
                                    className="mb-1.5 block text-sm font-medium"
                                >
                                    Group name{' '}
                                    <span className="text-destructive">*</span>
                                </label>
                                <Input
                                    id="group-wizard-name"
                                    value={draft.name}
                                    onChange={(event) =>
                                        change('name', event.target.value)
                                    }
                                    disabled={busy}
                                    aria-invalid={Boolean(errors.name)}
                                    maxLength={255}
                                    placeholder="For example, Kauri House network"
                                />
                                {errorFor('name')}
                            </div>
                            <div>
                                <label
                                    htmlFor="group-wizard-description"
                                    className="mb-1.5 block text-sm font-medium"
                                >
                                    Purpose or description
                                </label>
                                <Textarea
                                    id="group-wizard-description"
                                    value={draft.description}
                                    onChange={(event) =>
                                        change(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    disabled={busy}
                                    aria-invalid={Boolean(errors.description)}
                                    maxLength={2000}
                                    rows={4}
                                    placeholder="What this group is used for…"
                                />
                                {errorFor('description')}
                            </div>
                        </div>
                    )}
                    {step === 2 && (
                        <>
                            {ruleError && (
                                <p
                                    role="alert"
                                    className="mb-3 text-sm text-destructive"
                                >
                                    {ruleError}
                                </p>
                            )}
                            <AutoRuleBuilder
                                value={draft.auto_rules}
                                onChange={(value) =>
                                    change('auto_rules', value)
                                }
                                disabled={busy}
                            />
                        </>
                    )}
                    {step === 3 && (
                        <div className="space-y-4">
                            <ReviewCard
                                title="Group details"
                                icon={Info}
                                onEdit={() => setStep(1)}
                            >
                                <ReviewRow
                                    label="Name"
                                    value={draft.name.trim()}
                                />
                                <ReviewRow
                                    label="Type"
                                    value={groupTypeLabel(draft.type)}
                                />
                                <ReviewRow
                                    label="Description"
                                    value={
                                        draft.description.trim() ||
                                        'No description'
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard
                                title="Automatic membership"
                                icon={RefreshCw}
                                onEdit={() => setStep(2)}
                            >
                                {draft.auto_rules ? (
                                    <>
                                        <p className="mb-2 text-sm">
                                            Match{' '}
                                            {draft.auto_rules.match === 'all'
                                                ? 'all'
                                                : 'any'}{' '}
                                            conditions:
                                        </p>
                                        <ul className="space-y-2 text-sm">
                                            {normaliseAutoRules(
                                                draft.auto_rules,
                                            )?.conditions.map((rule, index) => (
                                                <li
                                                    key={index}
                                                    className="break-words"
                                                >
                                                    {autoRuleFieldLabel(
                                                        rule.field,
                                                    )}{' '}
                                                    ·{' '}
                                                    {autoRuleOperatorLabel(
                                                        rule.op,
                                                    )}{' '}
                                                    ·{' '}
                                                    {(Array.isArray(rule.value)
                                                        ? rule.value
                                                        : [rule.value]
                                                    )
                                                        .filter(Boolean)
                                                        .map((value) =>
                                                            autoRuleValueLabel(
                                                                rule.field,
                                                                value,
                                                            ),
                                                        )
                                                        .join(', ') ||
                                                        'Value needed'}
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                ) : (
                                    <p className="text-sm">
                                        Manual membership. No automatic rule.
                                    </p>
                                )}
                                <p className="mt-3 text-sm text-muted-foreground">
                                    Saving does not add or remove devices.
                                    Review and apply membership separately from
                                    the group profile.
                                </p>
                            </ReviewCard>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}
