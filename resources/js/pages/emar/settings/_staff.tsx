/* Medication › Settings › Staff & PINs (eMAR P11 v5 `settings.tsx`
 * StaffOverview, Competency and Exemptions). The values are saved through the
 * save bar like every other setting; the server reads them through
 * CompetencyPolicySettings. Witness PINs and PIN status are in _sections.tsx.
 *
 * v5 links to Staff eligibility, which arrives in P11 chunk 6; until then
 * these tabs link to the Competency register that exists today. The "How it's
 * used" cards list only what the app does today (no witnessing rows: the
 * "can witness" flag isn't checked when a dose is witnessed yet). */
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard } from '@/components/wizard/shell';
import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarDays,
    ClipboardCheck,
    Info,
    KeyRound,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useSettings } from './_context';
import { decisionReviewer, savedValue } from './_model';
import { NoMatches, useRow, type WitnessPinProps } from './_sections';
import {
    GroupGrid,
    GroupRow,
    KV,
    NumberInput,
    OnOff,
    Overview,
    Section,
    SettingGroup,
} from './_ui';

const G = 'elig';

/** The short words for the two Safety checks competency rules. */
const SAFETY_WORDS: Record<string, Record<string, string>> = {
    restricted_competency: {
        off: 'Off',
        block: 'Block',
        cosigner: 'Co-signer with witness PIN',
    },
    competency_areas: {
        off: 'Off',
        failed: 'When the area was failed',
        failed_or_not_seen: 'When failed or not seen',
    },
};

const RegisterLink = () => (
    <Button variant="link" asChild>
        <Link href="/emar/competency">
            Open the competency register
            <ArrowUpRight className="size-4" />
        </Link>
    </Button>
);

function useNumber(prefix: string) {
    const row = useRow(G);
    const { errors, clearError } = useSettings();
    const def = (key: string) => row.s.definitions[G]?.[key];
    const input = (key: string, unit?: string, label?: string) => (
        <NumberInput
            id={`${prefix}-${key}`}
            label={label}
            value={row.value(key)}
            unit={unit ?? def(key)?.unit ?? ''}
            min={def(key)?.range?.[0]}
            max={def(key)?.range?.[1]}
            disabled={row.disabled}
            error={errors[`${G}.${key}`]}
            onChange={(v) => {
                row.edit(key, v);
                clearError(`${G}.${key}`);
            }}
        />
    );
    return { ...row, def, input, errors, clearError };
}

/* ── Staff & PINs › Competency ── */
export function Competency({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    const {
        s,
        value,
        edit,
        state,
        disabled,
        shown,
        def,
        input,
        errors,
        clearError,
    } = useNumber('el');
    const number = (key: string, label: string, hint: string) =>
        def(key) ? (
            <GroupRow
                key={key}
                id={`el-${key}`}
                label={label}
                hint={hint}
                state={state(key)}
                error={errors[`${G}.${key}`]}
                errorId={`el-${key}-error`}
                hidden={!shown(show, q, key, label, hint, def(key)!.label)}
                control={input(key)}
            />
        ) : null;
    const off = def('observed_minimum')?.numeric?.off ?? 'off';
    const obsOn = value('observed_minimum') !== off;
    const savedObs = s.values[G]?.observed_minimum;
    const safety = (key: string) =>
        `${SAFETY_WORDS[key]?.[savedValue(s, 'safety', key)] ?? savedValue(s, 'safety', key)} (Safety checks)`;
    return (
        <Section
            id="sc-comp"
            title="Medication competency"
            caption="Used by the assessment form and the register"
            right={<RegisterLink />}
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                <SettingGroup
                    id="assessment"
                    icon={ClipboardCheck}
                    title="Assessment"
                    caption="How long it lasts and what passes"
                >
                    {number(
                        'validity',
                        'Stays current for',
                        'The assessor can choose an earlier end date.',
                    )}
                    {number(
                        'pass_mark',
                        'Pass mark',
                        'An area not assessed counts as not passed.',
                    )}
                    {def('core_must_pass') ? (
                        <GroupRow
                            id="el-core"
                            label="Every core area must pass"
                            hint="Knowledge, the five rights, safety checks, documentation, errors, allergies."
                            state={state('core_must_pass')}
                            hidden={
                                !shown(
                                    show,
                                    q,
                                    'core_must_pass',
                                    'Every core area must pass',
                                    def('core_must_pass')!.label,
                                )
                            }
                            control={
                                <OnOff
                                    id="el-core"
                                    checked={value('core_must_pass') === 'yes'}
                                    disabled={disabled}
                                    onChange={(v) =>
                                        edit('core_must_pass', v ? 'yes' : 'no')
                                    }
                                />
                            }
                        />
                    ) : null}
                </SettingGroup>
                <SettingGroup
                    id="evidence"
                    icon={CalendarDays}
                    title="Evidence and renewal"
                    caption="What the assessor logs, and when renewal is due"
                >
                    {def('observed_minimum') ? (
                        <GroupRow
                            id="el-obs"
                            label="Minimum observed administrations"
                            hint="Off until the organisation chooses a number. When on, an assessment logging fewer isn’t saved."
                            state={state('observed_minimum')}
                            error={errors[`${G}.observed_minimum`]}
                            errorId="el-observed_minimum-error"
                            hidden={
                                !shown(
                                    show,
                                    q,
                                    'observed_minimum',
                                    'Minimum observed administrations',
                                )
                            }
                            control={
                                <OnOff
                                    id="el-obs"
                                    checked={obsOn}
                                    disabled={disabled}
                                    onChange={(v) => {
                                        // On asks for a number (v5): the saved one, or an empty box.
                                        edit(
                                            'observed_minimum',
                                            v
                                                ? savedObs && savedObs !== off
                                                    ? savedObs
                                                    : ''
                                                : off,
                                        );
                                        clearError(`${G}.observed_minimum`);
                                    }}
                                />
                            }
                        >
                            {obsOn
                                ? input(
                                      'observed_minimum',
                                      'observed administrations',
                                      'Minimum observed administrations',
                                  )
                                : null}
                        </GroupRow>
                    ) : null}
                    {number(
                        'reminder',
                        'Renewal reminder',
                        'Starts “Due for renewal”, the rostering warning and the reminder alert.',
                    )}
                </SettingGroup>
                <ReviewCard icon={Info} title="How competency is used" span>
                    <KV
                        rows={[
                            [
                                'Restricted competency',
                                safety('restricted_competency'),
                            ],
                            [
                                'Controlled-drug and covert areas',
                                safety('competency_areas'),
                            ],
                            [
                                'Rostering',
                                'No current assessment blocks shifts that need medication cover; ending soon is a warning the rosterer can override',
                            ],
                            [
                                'Acknowledgement',
                                'Only from the worker’s own login (Meds today)',
                            ],
                        ]}
                    />
                </ReviewCard>
            </GroupGrid>
        </Section>
    );
}

/* ── Staff & PINs › Exemption limit ── */
export function ExemptionLimit({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    const { state, shown, def, input, errors } = useNumber('el');
    return (
        <Section
            id="sc-ex"
            title="Exemption limit"
            caption="Applies to every exemption"
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                <SettingGroup
                    id="limit"
                    icon={ShieldCheck}
                    title="Limit"
                    caption="Every exemption needs an end date within this"
                >
                    {def('longest_exemption') ? (
                        <GroupRow
                            id="el-longest_exemption"
                            label="Longest exemption"
                            hint="For the clinical lead to confirm."
                            state={state('longest_exemption')}
                            error={errors[`${G}.longest_exemption`]}
                            errorId="el-longest_exemption-error"
                            hidden={
                                !shown(
                                    show,
                                    q,
                                    'longest_exemption',
                                    'Longest exemption',
                                )
                            }
                            control={input('longest_exemption', 'days')}
                        />
                    ) : null}
                </SettingGroup>
                <ReviewCard icon={Info} title="How exemptions work">
                    <KV
                        rows={[
                            [
                                'What it does',
                                'Lets someone record doses as given at one house without a current assessment, until a fixed end date',
                            ],
                            [
                                'Who can grant',
                                'People who can approve competency exemptions (Settings › Roles), for someone else at their houses',
                            ],
                            [
                                'Ending',
                                'By itself on its end date, or early with a reason. Granting and ending are in the audit log',
                            ],
                        ]}
                    />
                </ReviewCard>
            </GroupGrid>
        </Section>
    );
}

/* ── Staff & PINs › Overview: four cards, each with its state and a way in ── */
export function StaffOverview({
    q,
    witnessPin,
}: {
    q: string;
    witnessPin: WitnessPinProps;
}) {
    const { s, go } = useSettings();
    const e = (key: string) => savedValue(s, G, key);
    const pin = (key: string) => savedValue(s, 'pin', key);
    const renewalOff =
        s.definitions.pin?.renewal_months?.numeric?.off ?? 'none';
    const notReviewed = (keys: string[]) => {
        const open = keys.filter(
            (k) => s.definitions[G]?.[k] && !decisionReviewer(s, G, k),
        ).length;
        return open ? (
            <StatusBadge variant="warning" size="sm">
                {open} not yet reviewed
            </StatusBadge>
        ) : (
            <StatusBadge variant="success" size="sm">
                All reviewed
            </StatusBadge>
        );
    };
    const staff = witnessPin.staff;
    const n = (...states: string[]) =>
        staff.filter((x) => states.includes(x.status)).length;
    const locked = n('locked');
    return (
        <Overview
            q={q}
            title="Staff & PINs"
            caption="Who can give, co-sign and witness doses"
            cards={[
                {
                    icon: ClipboardCheck,
                    title: 'Competency',
                    lines: [
                        `An assessment lasts ${e('validity')} months. Pass mark ${e('pass_mark')} of 12${e('core_must_pass') === 'yes' ? ', every core area passed' : ''}.`,
                        `Renewal reminder ${e('reminder')} days before it ends.`,
                    ],
                    badge: notReviewed([
                        'validity',
                        'pass_mark',
                        'core_must_pass',
                        'reminder',
                    ]),
                    cta: 'Review competency',
                    onClick: () => go('staff', 'competency'),
                },
                {
                    icon: ShieldCheck,
                    title: 'Exemption limit',
                    lines: [
                        `An exemption lasts up to ${e('longest_exemption')} days, at one house.`,
                    ],
                    badge: notReviewed(['longest_exemption']),
                    cta: 'Review exemption limit',
                    onClick: () => go('staff', 'exemptions'),
                },
                {
                    icon: KeyRound,
                    title: 'Witness PINs',
                    lines: [
                        `Locks after ${pin('max_attempts')} wrong attempts, for ${pin('lockout_minutes')} minutes. ${pin('renewal_months') !== renewalOff ? `Renewed every ${pin('renewal_months')} months.` : 'No renewal.'}`,
                    ],
                    cta: 'Review PIN rules',
                    onClick: () => go('staff', 'pins'),
                },
                {
                    icon: Users,
                    title: 'PIN status',
                    lines: [
                        `${n('set')} of ${staff.length} people have set a PIN · ${locked} locked · ${n('not_set', 'reset', 'expired')} still to set.`,
                    ],
                    badge: locked ? (
                        <StatusBadge variant="warning" size="sm">
                            {locked} locked
                        </StatusBadge>
                    ) : undefined,
                    cta: 'Review PIN status',
                    onClick: () => go('staff', 'status'),
                },
            ]}
        />
    );
}
