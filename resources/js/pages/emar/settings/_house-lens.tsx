/* What applies at a house (eMAR P11 v5 `dialogs-v5.tsx` HouseLens): every
 * medication setting in force at one house — the organisation's and the
 * house's own — read-only, opened from Settings' "At a house".
 *
 * Only what the app has today: v5's controlled-drug witness card (P07a), its
 * phone-instructions rule and its Alerts & on-call section (P11 B2) aren't
 * built, so they aren't shown. Every value here is an organisation value
 * today, apart from medicine rules set for the house itself.
 *
 * v5 switches between a house lead's houses in the footer; past a handful
 * of houses that becomes a searchable picker above the cards instead. */
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import {
    ArrowUpRight,
    Clock,
    Home,
    KeyRound,
    Pill,
    Repeat,
    Shield,
    Users,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    format,
    savedValue,
    type SettingsPayload,
    type ViewKey,
} from './_model';
import type { MedicineRule } from './_rules';
import type { WitnessPinStaffRow } from './_sections';
import type { RoundTemplate } from './_templates';
import { Choice, RecordPicker } from './_ui';

const Src = ({ house }: { house?: boolean }) =>
    house ? (
        <StatusBadge variant="info" size="sm">
            This house
        </StatusBadge>
    ) : (
        <StatusBadge variant="neutral" size="sm">
            Organisation
        </StatusBadge>
    );
const V = ({ v, house }: { v: ReactNode; house?: boolean }) => (
    <span className="inline-flex flex-wrap items-center justify-end gap-2 text-right">
        {v}
        <Src house={house} />
    </span>
);

/** Up to this many houses switch in the footer, as in v5. */
const FOOTER_HOUSES = 4;

const STEPS: {
    key: ViewKey;
    label: string;
    blurb: string;
    icon: typeof Pill;
}[] = [
    {
        key: 'rules',
        label: 'Medication rules',
        blurb: 'Checks when a dose is signed',
        icon: Pill,
    },
    {
        key: 'rounds',
        label: 'Rounds & timing',
        blurb: 'Rounds and when doses are late',
        icon: Repeat,
    },
    {
        key: 'staff',
        label: 'Staff & PINs',
        blurb: 'Competency and PINs',
        icon: Users,
    },
];

/** "07:30" → "7:30 am". */
const time12 = (t: string) => {
    const [h, m] = t.split(':').map(Number);
    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'am' : 'pm'}`;
};
const DAY = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const daysText = (d: number[]) =>
    !d.length || d.length === 7
        ? 'Every day'
        : d.length === 5 && [1, 2, 3, 4, 5].every((x) => d.includes(x))
          ? 'Monday to Friday'
          : d
                .slice()
                .sort()
                .map((x) => DAY[x])
                .join(', ');

export function HouseLens({
    s,
    houses,
    rules,
    templates,
    pins,
    onOpen,
    onClose,
}: {
    s: SettingsPayload;
    houses: { id: number; name: string }[];
    rules: MedicineRule[];
    templates: RoundTemplate[];
    pins: WitnessPinStaffRow[];
    /** Close the lens and open that view of Settings. */
    onOpen: (view: ViewKey) => void;
    onClose: () => void;
}) {
    const [houseId, setHouseId] = useState(houses[0]?.id ?? 0);
    const [step, setStep] = useState(0);
    const house = houses.find((h) => h.id === houseId);
    const name = house?.name ?? 'this house';
    const word = (group: string, key: string) => {
        const def = s.definitions[group]?.[key];
        return def
            ? format(def, savedValue(s, group, key)).split(' — ')[0]
            : '—';
    };
    const here = rules.filter(
        (r) => r.active && (r.site_id === null || r.site_id === houseId),
    );
    const rounds = templates
        .filter((t) => t.site_id === houseId && t.status === 'active')
        .sort((a, b) => a.scheduled_time.localeCompare(b.scheduled_time));
    const housePins = pins.filter((p) => p.house_id === houseId);
    const n = (...st: string[]) =>
        housePins.filter((p) => st.includes(p.status)).length;
    const v = (group: string, key: string) => savedValue(s, group, key);
    const renewal = v('pin', 'renewal_months');

    return (
        <WizardShell
            open
            onClose={onClose}
            title={`What applies at ${name}`}
            description="Every rule in force at this house: the organisation’s, and the house’s own. Read-only."
            railIcon={Home}
            railTitle={name}
            railSub="Read-only summary"
            steps={STEPS}
            stepIndex={step}
            onStepClick={setStep}
            sequential={false}
            pct={null}
            headerLabel={STEPS[step].label}
            footerStart={
                houses.length > 1 && houses.length <= FOOTER_HOUSES ? (
                    <Choice
                        value={String(houseId)}
                        onChange={(id) => setHouseId(Number(id))}
                        options={houses.map(
                            (h) => [String(h.id), h.name] as [string, string],
                        )}
                    />
                ) : undefined
            }
            footerEnd={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <WizardStepPane key={`${houseId}-${step}`}>
                {houses.length > FOOTER_HOUSES ? (
                    <div className="mb-4 max-w-sm">
                        <RecordPicker
                            id="lens-house"
                            label="House"
                            value={String(houseId)}
                            items={houses.map((h) => ({
                                id: String(h.id),
                                name: h.name,
                                sub: 'House',
                                ok: true,
                            }))}
                            onChange={(id) => setHouseId(Number(id))}
                            placeholder="Choose a house"
                            search="Search houses…"
                        />
                    </div>
                ) : null}
                {step === 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={Shield} title="Safety checks" span>
                            <ReviewRow
                                label="Allergy match"
                                value={
                                    <V
                                        v={word(
                                            'safety',
                                            'profile_allergy_match',
                                        )}
                                    />
                                }
                            />
                            <ReviewRow
                                label="Competency restricted"
                                value={
                                    <V
                                        v={word(
                                            'safety',
                                            'restricted_competency',
                                        )}
                                    />
                                }
                            />
                            <ReviewRow
                                label="Area not passed"
                                value={
                                    <V v={word('safety', 'competency_areas')} />
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Pill}
                            title={`Medicine rules that apply here (${here.length})`}
                            span
                        >
                            {here.length ? (
                                here.map((r) => (
                                    <ReviewRow
                                        key={r.id}
                                        label={
                                            r.concealed
                                                ? 'A controlled medicine (details withheld)'
                                                : r.what
                                        }
                                        value={
                                            <V
                                                v={r.needs || 'Active'}
                                                house={r.site_id !== null}
                                            />
                                        }
                                    />
                                ))
                            ) : (
                                <p className="text-caption">
                                    No active medicine rules apply here.
                                </p>
                            )}
                        </ReviewCard>
                    </div>
                ) : step === 1 ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard
                            icon={Repeat}
                            title={`Rounds (${rounds.length} active)`}
                        >
                            {rounds.length ? (
                                rounds.map((t) => (
                                    <ReviewRow
                                        key={t.id}
                                        label={`${t.name} · ${time12(t.scheduled_time)}`}
                                        value={
                                            <V
                                                v={`${daysText(t.days_of_week)} · ±${t.window_minutes} min`}
                                                house
                                            />
                                        }
                                    />
                                ))
                            ) : (
                                <p className="text-caption">
                                    No active round templates.
                                </p>
                            )}
                        </ReviewCard>
                        <ReviewCard icon={Clock} title="Dose timing">
                            <ReviewRow
                                label="Can be given from"
                                value={
                                    <V
                                        v={`${v('timing', 'early')} minutes before`}
                                    />
                                }
                            />
                            {s.definitions.timing?.due_soon ? (
                                <ReviewRow
                                    label="Shows as due soon"
                                    value={
                                        <V
                                            v={`${v('timing', 'due_soon')} minutes before`}
                                        />
                                    }
                                />
                            ) : null}
                            <ReviewRow
                                label="Late after"
                                value={
                                    <V v={`${v('timing', 'late')} minutes`} />
                                }
                            />
                            <ReviewRow
                                label="Late dose raises an incident"
                                value={
                                    <V
                                        v={`After ${v('timing', 'late_incident')} minutes`}
                                    />
                                }
                            />
                        </ReviewCard>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={Users} title="Competency">
                            <ReviewRow
                                label="An assessment lasts"
                                value={
                                    <V v={`${v('elig', 'validity')} months`} />
                                }
                            />
                            <ReviewRow
                                label="Pass mark"
                                value={
                                    <V v={`${v('elig', 'pass_mark')} of 12`} />
                                }
                            />
                            <ReviewRow
                                label="Longest exemption"
                                value={
                                    <V
                                        v={`${v('elig', 'longest_exemption')} days`}
                                    />
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={KeyRound}
                            title={`Witness PINs at ${name}`}
                        >
                            <ReviewRow
                                label="Set"
                                value={`${n('set')} of ${housePins.length}`}
                            />
                            <ReviewRow
                                label="Not set yet"
                                value={String(n('not_set', 'reset', 'expired'))}
                            />
                            <ReviewRow
                                label="Locked"
                                value={String(n('locked'))}
                            />
                            <ReviewRow
                                label="Locks after"
                                value={
                                    <V
                                        v={`${v('pin', 'max_attempts')} wrong attempts, for ${v('pin', 'lockout_minutes')} minutes`}
                                    />
                                }
                            />
                            <ReviewRow
                                label="Renewal"
                                value={
                                    <V
                                        v={
                                            renewal === 'none'
                                                ? 'No renewal'
                                                : `Every ${renewal} months`
                                        }
                                    />
                                }
                            />
                        </ReviewCard>
                    </div>
                )}
                <p className="text-caption mt-4">
                    Saved settings only — unsaved changes aren’t shown.{' '}
                    <Button
                        variant="link"
                        onClick={() => onOpen(STEPS[step].key)}
                    >
                        Open {STEPS[step].label}
                        <ArrowUpRight className="size-4" />
                    </Button>
                </p>
            </WizardStepPane>
        </WizardShell>
    );
}
