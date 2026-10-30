import { EntityChip } from '@/components/lists/entity-cells';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import {
    ArrowUpRight,
    Building2,
    ClipboardCheck,
    Clock,
    Eye,
    KeyRound,
    LockKeyhole,
    Pill,
    Timer,
} from 'lucide-react';
import { useSettings } from './_context';
import { fmtT } from './_model';
import { NoMatches, useRow } from './_sections';
import {
    Choice,
    GroupGrid,
    GroupRow,
    NumberInput,
    OnOff,
    Section,
    SettingGroup,
} from './_ui';

export const minutesText = (n: number) =>
    n >= 60 && n % 60 === 0
        ? `${n / 60} ${n === 60 ? 'hour' : 'hours'}`
        : `${n} minutes`;
export const exampleTime = (minutes: number) =>
    fmtT(
        `${String(Math.floor(((540 + minutes) % 1440) / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`,
    ) + (540 + minutes >= 1440 ? ' next day' : '');

export function EmergencyAccess({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    const { s, value, edit, state, disabled, shown } = useRow('ea');
    const { errors, clearError, go, open } = useSettings();
    const n = (key: string) => Number(value(key)) || 0;
    const def = n('default_minutes'),
        max = n('max_minutes'),
        ext = n('extend_minutes');
    const valid = def >= 5 && max >= def && max <= 1440 && ext >= 5;
    const extensions = valid && max > def ? Math.ceil((max - def) / ext) : 0;
    const steps = [
        {
            icon: LockKeyhole,
            title: 'Start',
            text: `Someone who is not rostered for a person, but must record a dose for them now, chooses Emergency access on that person's record${value('reason_required') === 'yes' ? ' and says why' : ''}.`,
        },
        {
            icon: Pill,
            title: 'Record',
            text: `For ${valid ? minutesText(def) : 'the grant length'}, they can record for that one person only — never a whole house or round.`,
        },
        {
            icon: Clock,
            title: 'Extend or end',
            text: valid
                ? `They can add ${minutesText(ext)} at a time, but never past ${minutesText(max)} in all. Then access ends by itself.`
                : 'They can extend it up to the longest time. Then access ends by itself.',
        },
        {
            icon: Eye,
            title: 'Review',
            text: `Reviewers get a daily report of every grant. Someone using it ${n('repeat_threshold_count') || '…'} times within ${n('repeat_window_days') || '…'} days is flagged.`,
        },
    ];
    const input = (key: string, label?: string, unit = 'minutes') => (
        <NumberInput
            id={`ea-${key}`}
            label={label}
            value={value(key)}
            unit={unit}
            min={s.definitions.ea[key].range?.[0]}
            max={s.definitions.ea[key].range?.[1]}
            disabled={disabled}
            error={errors[`ea.${key}`]}
            errorId={`ea-${key}-error`}
            onChange={(v) => {
                edit(key, v);
                clearError(`ea.${key}`);
            }}
        />
    );
    const row = (key: string, hint: string) => (
        <GroupRow
            id={`ea-${key}`}
            label={s.definitions.ea[key].label}
            hint={hint}
            state={state(key)}
            error={errors[`ea.${key}`]}
            errorId={`ea-${key}-error`}
            hidden={!shown(show, q, key, s.definitions.ea[key].label, hint)}
            control={input(key)}
        />
    );
    return (
        <Section
            id="sc-ea"
            title="Emergency access"
            caption="When someone must record for a person they are not rostered for"
            right={<EntityChip icon={Building2}>Every house</EntityChip>}
        >
            {disabled && (
                <SettingsNotice>
                    You can read this policy. Changing it needs emergency-access
                    policy permission and all-sites authority.
                </SettingsNotice>
            )}
            <ol
                className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4"
                aria-label="How emergency access works"
            >
                {steps.map((step, i) => (
                    <li key={step.title}>
                        <Card className="h-full gap-2 p-4">
                            <div className="flex items-center gap-2">
                                <span
                                    className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-fill/10 text-caption font-semibold text-primary"
                                    aria-hidden="true"
                                >
                                    {i + 1}
                                </span>
                                <step.icon
                                    className="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <p className="text-sm font-semibold">
                                    {step.title}
                                </p>
                            </div>
                            <p className="text-subtle">{step.text}</p>
                        </Card>
                    </li>
                ))}
            </ol>
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                <SettingGroup
                    id="length"
                    icon={Clock}
                    title="How long a grant lasts"
                    caption="Steps 2 and 3 — every limit is in minutes"
                >
                    {row(
                        'default_minutes',
                        'They can choose a shorter time when they start.',
                    )}
                    {row(
                        'extend_minutes',
                        extensions
                            ? `Up to ${extensions} ${extensions === 1 ? 'extension' : 'extensions'} before the longest time.`
                            : 'The grant already uses the longest time.',
                    )}
                    {row(
                        'max_minutes',
                        'From the start, including every extension. It cannot be shorter than a grant.',
                    )}
                </SettingGroup>
                <SettingGroup
                    id="review"
                    icon={Eye}
                    title="What reviewers see"
                    caption="Steps 1 and 4"
                >
                    <GroupRow
                        id="ea-reason_required"
                        label="Ask for a reason"
                        hint="They say why when they start. Reviewers and the audit trail see it."
                        state={state('reason_required')}
                        hidden={
                            !shown(
                                show,
                                q,
                                'reason_required',
                                'Ask for a reason',
                            )
                        }
                        control={
                            <OnOff
                                id="ea-reason_required"
                                checked={value('reason_required') === 'yes'}
                                disabled={disabled}
                                onChange={(v) =>
                                    edit('reason_required', v ? 'yes' : 'no')
                                }
                            />
                        }
                    />
                    <GroupRow
                        id="ea-repeat_threshold_count"
                        label="Flag repeat use"
                        hint="When one person uses emergency access this often, reviewers are told."
                        state={state('repeat_threshold_count')}
                        error={
                            errors['ea.repeat_threshold_count'] ||
                            errors['ea.repeat_window_days']
                        }
                        hidden={
                            !shown(
                                show,
                                q,
                                'repeat_threshold_count',
                                'Flag repeat use',
                            )
                        }
                    >
                        <span className="inline-flex flex-wrap items-center gap-2">
                            {input(
                                'repeat_threshold_count',
                                'Number of grants',
                                'grants within',
                            )}
                            {input(
                                'repeat_window_days',
                                'Number of days',
                                'days',
                            )}
                        </span>
                    </GroupRow>
                    <GroupRow
                        id="ea-reviewers"
                        label="Who gets the daily report"
                        hint="Set on the Emergency access used alert."
                        hidden={
                            !shown(
                                show,
                                q,
                                'reason_required',
                                'Who gets the daily report',
                                'reviewers',
                            )
                        }
                        control={
                            s.definitions.alerts?.breakglass ? (
                                <Button
                                    variant="link"
                                    onClick={() => {
                                        go('alerts', 'alerts');
                                        open({
                                            kind: 'alertwho',
                                            key: 'breakglass',
                                        });
                                    }}
                                >
                                    Change <ArrowUpRight className="size-4" />
                                </Button>
                            ) : (
                                <span className="text-caption">
                                    Not configured
                                </span>
                            )
                        }
                    />
                </SettingGroup>
                {s.definitions.ea.second_person &&
                    s.definitions.ea.review_days && (
                        <SettingGroup
                            id="second-reviews"
                            icon={ClipboardCheck}
                            title="A second person, and reviews"
                            caption="From the next grant — grants already running keep what they started with"
                            wide
                        >
                            <GroupRow
                                id="ea-second_person"
                                label="A second person confirms a grant"
                                hint="They type their own witness PIN on the screen of the person starting it."
                                state={state('second_person')}
                                hidden={
                                    !shown(
                                        show,
                                        q,
                                        'second_person',
                                        'A second person confirms a grant',
                                    )
                                }
                            >
                                <Choice
                                    value={value('second_person')}
                                    disabled={disabled}
                                    onChange={(v) => edit('second_person', v)}
                                    options={s.definitions.ea.second_person.options.map(
                                        (o) => [
                                            o.value,
                                            o.value === 'off'
                                                ? 'Not asked'
                                                : o.value === 'optional'
                                                  ? 'Optional'
                                                  : 'Required',
                                        ],
                                    )}
                                />
                                <p className="text-caption">
                                    {value('second_person') === 'required'
                                        ? 'If nobody who can confirm is there, it cannot start — the screen shows the house’s on-call contact. There is no way around it.'
                                        : value('second_person') === 'optional'
                                          ? 'The person starting it chooses. Reviewers see when no one confirmed it.'
                                          : 'Nobody is asked to confirm.'}
                                </p>
                            </GroupRow>
                            <GroupRow
                                id="ea-review_days"
                                label="A review is due within"
                                hint="Counted from when the grant ends — however it ends. Overdue reviews go to Follow-ups and the daily report."
                                state={state('review_days')}
                                hidden={
                                    !shown(
                                        show,
                                        q,
                                        'review_days',
                                        'A review is due within',
                                    )
                                }
                            >
                                <Choice
                                    value={value('review_days')}
                                    disabled={disabled}
                                    onChange={(v) => edit('review_days', v)}
                                    options={s.definitions.ea.review_days.options.map(
                                        (o) => [o.value, o.label],
                                    )}
                                />
                            </GroupRow>
                            <GroupRow
                                id="ea-reviewer"
                                label="Who reviews a grant"
                                hint="Anyone who reviews emergency access at the house — never the person who used it or the one who confirmed it."
                                hidden={
                                    !shown(
                                        show,
                                        q,
                                        'review_days',
                                        'Who reviews a grant',
                                    )
                                }
                                control={
                                    <span className="inline-flex items-center gap-2 text-caption">
                                        <KeyRound
                                            className="size-4"
                                            aria-hidden="true"
                                        />{' '}
                                        Always
                                    </span>
                                }
                            />
                            <GroupRow
                                id="ea-review-where"
                                label="Where grants are reviewed"
                                hint="Safety & oversight › Emergency access › To review."
                                hidden={
                                    !shown(
                                        show,
                                        q,
                                        'review_days',
                                        'Where grants are reviewed',
                                    )
                                }
                                control={
                                    <Button variant="outline" size="sm" asChild>
                                        <a href="/emar/emergency-access?view=review">
                                            Emergency access{' '}
                                            <ArrowUpRight className="size-4" />
                                        </a>
                                    </Button>
                                }
                            />
                        </SettingGroup>
                    )}
                <ReviewCard
                    icon={Timer}
                    title="Example: one grant with these settings"
                    span
                >
                    <p className="text-subtle mb-2">
                        Worked out from your draft. Synthetic — nothing is
                        recorded.
                    </p>
                    {valid ? (
                        <>
                            <ReviewRow
                                label="9:00 am"
                                value={
                                    <span className="text-right">
                                        <b>
                                            Mere Kahu starts emergency access
                                            for Aroha N.
                                        </b>
                                        <span className="text-caption block">
                                            {value('reason_required') === 'yes'
                                                ? 'Reason: Aroha’s rostered support worker went home unwell.'
                                                : 'No reason is asked for.'}
                                        </span>
                                    </span>
                                }
                            />
                            <ReviewRow
                                label={exampleTime(def)}
                                value={
                                    <span className="text-right">
                                        <b>Access ends</b>
                                        <span className="text-caption block">
                                            {extensions
                                                ? 'Unless Mere extends it before then.'
                                                : 'It cannot be extended — the grant already uses the longest time.'}
                                        </span>
                                    </span>
                                }
                            />
                            {!!extensions && (
                                <ReviewRow
                                    label={exampleTime(max)}
                                    value={
                                        <span className="text-right">
                                            <b>Latest possible end</b>
                                            <span className="text-caption block">
                                                After up to {extensions}{' '}
                                                extensions of {minutesText(ext)}
                                                , capped at {minutesText(max)}{' '}
                                                in all.
                                            </span>
                                        </span>
                                    }
                                />
                            )}
                            {s.definitions.ea.review_days && (
                                <ReviewRow
                                    label={`Within ${value('review_days')} ${value('review_days') === '1' ? 'day' : 'days'}`}
                                    value="Someone other than Mere or the person who confirmed it reviews the grant."
                                />
                            )}
                            <ReviewRow
                                label="Next day"
                                value="Reviewers get the daily report"
                            />
                            <ReviewRow
                                label="If it happens again"
                                value={`Repeat use is flagged at ${n('repeat_threshold_count')} grants within ${n('repeat_window_days')} days.`}
                            />
                        </>
                    ) : (
                        <p className="text-caption">
                            Fix the grant lengths above to see the example.
                        </p>
                    )}
                </ReviewCard>
            </GroupGrid>
        </Section>
    );
}
