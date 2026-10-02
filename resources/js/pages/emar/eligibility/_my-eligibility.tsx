/* My eligibility (eMAR P11 v5 `dialogs-elig.tsx` MyEligibility, `today.tsx`
 * meter): the worker's own medication competency, from the same decision as
 * the register, opened from the "My eligibility" meter on Meds today. When a
 * new assessment waits for them, it says so and opens Acknowledge.
 *
 * v5's witness lines are left out — who can witness is enforced with P07b —
 * and so is the shift row (no "on shift now" until then). */
import {
    AcknowledgeAssessment,
    type PendingAssessment,
} from '@/components/meds/pending-assessment';
import { PageHeaderStatusChip } from '@/components/page/page-header';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { InfoCard } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatTime } from '@/lib/datetime';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    KeyRound,
    UserCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { abilities } from './_assessment';
import {
    day,
    givenAbility,
    PIN_LABEL,
    type AreaMeta,
    type EligPerson,
    type EligPolicy,
} from './_model';
import { AreaTable, CanList } from './_parts';

export type MyEligibilityData = {
    person: EligPerson;
    policy: EligPolicy;
    areas: AreaMeta[];
    /** A new assessment waiting for their acknowledgement. */
    pending: PendingAssessment | null;
    checked_at: string;
};

type Tone = 'success' | 'warning' | 'critical' | 'neutral';

/** The meter on Meds today: one word and one line. */
export function myMeter(d: MyEligibilityData): {
    big: string;
    cap: string;
    tone: Tone;
} {
    const x = d.person;
    if (!x.records_doses)
        return {
            big: 'Not needed',
            cap: 'You don’t record doses in this role',
            tone: 'neutral',
        };
    if (d.pending)
        return x.prev_valid
            ? {
                  big: 'Acknowledge',
                  cap: 'New assessment waiting for you',
                  tone: 'warning',
              }
            : {
                  big: 'Acknowledge',
                  cap: 'New assessment waiting for you',
                  tone: 'critical',
              };
    switch (x.status) {
        case 'due':
            return {
                big: 'Renewal due',
                cap: `Ends ${day(x.until)} · in ${x.days} ${x.days === 1 ? 'day' : 'days'}`,
                tone: 'warning',
            };
        case 'expired':
            return {
                big: 'Expired',
                cap: x.until
                    ? `Competency ended ${day(x.until)}`
                    : 'No end date recorded',
                tone: 'critical',
            };
        case 'restricted':
            return d.policy.restricted_mode === 'cosigner'
                ? {
                      big: 'Restricted',
                      cap: 'Co-signer needed for given',
                      tone: 'warning',
                  }
                : d.policy.restricted_mode === 'block'
                  ? {
                        big: 'Restricted',
                        cap: 'Can’t sign doses as given',
                        tone: 'critical',
                    }
                  : {
                        big: 'Restricted',
                        cap: `To ${day(x.until)}`,
                        tone: 'warning',
                    };
        case 'exempt':
            return {
                big: 'Exemption',
                cap: `Until ${day(x.exemption?.until)}`,
                tone: 'warning',
            };
        case 'failed':
            return {
                big: 'Not passed',
                cap: 'Can’t record given doses yet',
                tone: 'critical',
            };
        case 'none':
            return {
                big: 'Not assessed',
                cap: 'Can’t record given doses yet',
                tone: 'critical',
            };
        default:
            return {
                big: 'Current',
                cap: `To ${day(x.until)}`,
                tone: 'success',
            };
    }
}

/**
 * The meter's value on the Meds today hero, as a status chip. Its own
 * background keeps the verified status pairs (≥ 5.7:1 in both modes); tinted
 * text straight on the PageHero gradient can't reach 4.5:1 (P11 chunk 7).
 */
export function MyEligibilityChip({ data }: { data: MyEligibilityData }) {
    const m = myMeter(data);
    return (
        <PageHeaderStatusChip variant={m.tone} className="text-sm">
            {m.big}
        </PageHeaderStatusChip>
    );
}

/** The summary at the top of My eligibility. */
function meHead(
    d: MyEligibilityData,
): ['info' | 'warn' | 'crit', string, string] {
    const x = d.person;
    if (d.pending)
        return [
            'warn',
            'Your new assessment is waiting for you',
            `${d.pending.assessor ?? 'Your assessor'} recorded it on ${day(d.pending.assessed_on)}. It counts once you acknowledge it${x.prev_valid ? ` — until then your previous assessment counts (until ${day(x.prev_valid)}).` : ' — until then you can’t record doses as given.'}`,
        ];
    if (x.st === 'none')
        return [
            'crit',
            'You haven’t been assessed yet',
            'You can record refused, withheld and away, but not given. Your house lead books your first assessment.',
        ];
    if (x.status === 'exempt' && x.exemption)
        return [
            'warn',
            `You can record doses as given until ${day(x.exemption.until)} — exemption`,
            `${x.exemption.by ?? 'A lead'} approved it for ${x.exemption.house ?? 'your house'}: “${x.exemption.reason}”.`,
        ];
    if (x.st === 'failed')
        return [
            'crit',
            'You can’t record doses as given',
            'Your last assessment wasn’t passed. You can still record refused, withheld and away. Talk to your house lead about a remedial assessment.',
        ];
    if (x.st === 'expired')
        return [
            'crit',
            'You can’t record doses as given',
            `${x.until ? `Your competency ended on ${day(x.until)}.` : 'Your assessment has no end date.'} You can still record refused, withheld and away. Talk to your house lead about reassessment.`,
        ];
    if (x.st === 'restricted') {
        const notes = x.assessment?.restriction_notes || 'restricted';
        return d.policy.restricted_mode === 'cosigner'
            ? [
                  'warn',
                  'A colleague confirms each dose you give',
                  `Your competency is restricted: ${notes}. A colleague on shift confirms with their witness PIN.`,
              ]
            : d.policy.restricted_mode === 'block'
              ? [
                    'crit',
                    'You can’t sign doses as given on your own',
                    `Your competency is restricted: ${notes}. A colleague on shift gives the dose; you can record refused, withheld and away.`,
                ]
              : [
                    'info',
                    'You can record doses as given',
                    `Your competency is restricted (${notes}), but the organisation doesn’t enforce it.`,
                ];
    }
    if (x.status === 'due')
        return [
            'warn',
            `You can record doses as given — renewal due in ${x.days} ${x.days === 1 ? 'day' : 'days'}`,
            `Your competency ends on ${day(x.until)}. Ask your house lead to book your renewal.`,
        ];
    return [
        'info',
        'You can record doses as given',
        `Your competency is current until ${day(x.until)}.`,
    ];
}

const ME_SECS = [
    {
        key: 'sum',
        label: 'Summary',
        blurb: 'Can I give doses now?',
        icon: UserCheck,
    },
    {
        key: 'can',
        label: 'What I can do',
        blurb: 'Given, controlled, covert',
        icon: CheckCircle2,
    },
    {
        key: 'areas',
        label: 'My areas',
        blurb: '12 areas',
        icon: ClipboardCheck,
    },
    {
        key: 'details',
        label: 'Details',
        blurb: 'Dates and PIN',
        icon: FileText,
    },
];
const NOT_GIVEN = {
    v: 'yes' as const,
    t: 'Refused, withheld and away — always recordable',
    head: 'Not given',
};

export function MyEligibility({
    data,
    name,
    onClose,
}: {
    data: MyEligibilityData;
    name: string;
    onClose: () => void;
}) {
    const [sec, setSec] = useState(0);
    const [ack, setAck] = useState(false);
    const x = data.person;
    const checked = `checked ${formatTime(data.checked_at)}`;
    if (!x.records_doses) {
        return (
            <SettingsModal
                title="My medication eligibility"
                description={`${name} · ${x.role ?? 'Staff'} · ${checked}`}
                onClose={onClose}
            >
                <InfoCard icon={UserCheck}>
                    <b>Your role doesn’t record doses.</b> No medication
                    competency assessment is needed for your work.
                </InfoCard>
            </SettingsModal>
        );
    }
    if (ack && data.pending)
        return (
            <AcknowledgeAssessment
                assessment={data.pending}
                onClose={() => {
                    setAck(false);
                    onClose();
                }}
            />
        );
    const [tone, title, text] = meHead(data);
    const Icon =
        tone === 'info'
            ? CheckCircle2
            : tone === 'warn'
              ? AlertTriangle
              : XCircle;
    const g = givenAbility(x, data.policy);
    const a = x.assessment;
    return (
        <WizardShell
            open
            onClose={onClose}
            title="My medication eligibility"
            description="What you can do right now, from the same rules as the register."
            railIcon={UserCheck}
            railTitle="My eligibility"
            railSub={`${name} · ${checked}`}
            steps={ME_SECS}
            stepIndex={sec}
            onStepClick={setSec}
            sequential={false}
            headerLabel={`My medication eligibility — ${ME_SECS[sec].label}`}
            footerStart={
                <Button
                    variant="outline"
                    className="frontline-tap"
                    onClick={onClose}
                >
                    Close
                </Button>
            }
            footerEnd={
                data.pending ? (
                    <Button
                        className="frontline-tap"
                        onClick={() => setAck(true)}
                    >
                        <Check />
                        Read and acknowledge
                    </Button>
                ) : null
            }
        >
            <WizardStepPane key={sec}>
                {sec === 0 ? (
                    <div className="space-y-4">
                        <InfoCard icon={Icon} tone={tone}>
                            <b>{title}</b>
                            <br />
                            {text}
                        </InfoCard>
                        <CanList
                            items={[{ ...g, head: 'Give doses' }, NOT_GIVEN]}
                        />
                    </div>
                ) : sec === 1 ? (
                    <CanList
                        items={[
                            ...abilities(x, data.policy, data.areas).map(
                                (y, i) =>
                                    i === 0 ? { ...y, head: 'Give doses' } : y,
                            ),
                            NOT_GIVEN,
                        ]}
                    />
                ) : sec === 2 ? (
                    !a ? (
                        <InfoCard icon={ClipboardCheck}>
                            No assessment yet, so no areas are recorded.
                        </InfoCard>
                    ) : (
                        <AreaTable
                            x={x}
                            areas={data.areas}
                            policy={data.policy}
                            corePasses={data.policy.core_must_pass}
                        />
                    )
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={FileText} title="My assessment">
                            {a ? (
                                <>
                                    <ReviewRow
                                        label="Assessed"
                                        value={`${day(a.assessed)} by ${a.assessor ?? '—'} · ${a.type_label}`}
                                    />
                                    <ReviewRow
                                        label="Ends"
                                        value={`${day(a.until)} · reminders start ${data.policy.renewal_days} days before`}
                                    />
                                    <ReviewRow
                                        label="Restriction"
                                        value={
                                            a.restricted
                                                ? a.restriction_notes ||
                                                  'Restricted'
                                                : 'None'
                                        }
                                    />
                                    <ReviewRow
                                        label="My acknowledgement"
                                        value={
                                            a.acknowledged_at ? (
                                                day(a.acknowledged_at)
                                            ) : (
                                                <b>Not yet</b>
                                            )
                                        }
                                    />
                                </>
                            ) : (
                                <ReviewRow
                                    label="Assessment"
                                    value="None yet"
                                />
                            )}
                        </ReviewCard>
                        <ReviewCard
                            icon={KeyRound}
                            title="Witness PIN and house"
                        >
                            <ReviewRow
                                label="Witness PIN"
                                value={
                                    x.pin === 'set'
                                        ? 'Set'
                                        : x.pin === 'locked'
                                          ? 'Locked after wrong attempts — it unlocks by itself, or choose a new one in Settings › Witness PIN'
                                          : `${PIN_LABEL[x.pin]} — you can’t co-sign or witness until you set one in Settings › Witness PIN`
                                }
                            />
                            <ReviewRow label="House" value={x.house ?? '—'} />
                        </ReviewCard>
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}
