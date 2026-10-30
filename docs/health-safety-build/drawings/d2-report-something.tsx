/**
 * DRAWING D2 — "Report something": ONE chooser in plain words, on the pop-up kit (PopupForm, 720). Each choice says
 * what happened in everyday words and which form it opens; only the choices this person may use show (Part 1). The
 * main button names what happens next. Shown over the support worker's Overview start.
 */
import { PopupForm, PopupSection } from '@/components/popup';
import { Activity, AlertOctagon, Clipboard, HeartPulse, PersonStanding, ShieldAlert, ShieldQuestion, TestTubeDiagonal, type LucideIcon } from 'lucide-react';
import { useState } from 'react';
import DrawingOverview from './d1-overview-start';

type Choice = { key: string; icon: LucideIcon; what: string; opens: string; asks: string };

const GROUPS: { title: string; choices: Choice[] }[] = [
    {
        title: 'Someone was hurt, or nearly',
        choices: [
            { key: 'incident', icon: ShieldAlert, what: 'A person was hurt, or nearly hurt', opens: 'Report an incident', asks: 'Who, where, what happened and what you did straight away.' },
            { key: 'injury', icon: Activity, what: 'A staff member was hurt at work', opens: 'Report a workplace injury', asks: 'Who was hurt, the injury, and any time off or ACC claim.' },
            { key: 'first_aid', icon: HeartPulse, what: 'You gave first aid', opens: 'Record first aid', asks: 'Who you treated, what you did and what happened next.' },
            { key: 'restraint', icon: Clipboard, what: 'A person’s movement or freedom was limited', opens: 'Record a restraint', asks: 'What led to it, what you tried first, and how the person is now.' },
        ],
    },
    {
        title: 'Something could hurt someone',
        choices: [
            { key: 'hazard', icon: AlertOctagon, what: 'Something could cause harm', opens: 'Report a hazard', asks: 'Where it is, how bad it could be, and what you did to make it safe.' },
            { key: 'exposure', icon: TestTubeDiagonal, what: 'A chemical got on or into someone', opens: 'Record a chemical exposure', asks: 'Which chemical, how it happened and any treatment.' },
        ],
    },
    {
        title: 'Before or during work',
        choices: [
            { key: 'lone', icon: PersonStanding, what: 'You’re about to work alone', opens: 'Start working alone', asks: 'Where you’ll be and when to check in.' },
            { key: 'safeguarding', icon: ShieldQuestion, what: 'You’re worried a person is being harmed', opens: 'Raise a safeguarding concern', asks: 'What you saw or were told. It goes only to the safeguarding lead.' },
        ],
    },
];

export default function DrawingReportSomething() {
    const [picked, setPicked] = useState('incident');
    const choice = GROUPS.flatMap((g) => g.choices).find((c) => c.key === picked)!;
    return (
        <>
            <DrawingOverview role="support_worker" />
            <PopupForm
                open
                onClose={() => {}}
                size="md"
                icon={ShieldAlert}
                title="Report something"
                description="Choose what happened. We’ll open the right form."
                submitLabel={choice.opens}
                onSubmit={() => {}}
                footerStart={<span className="text-subtle">Not sure? Choose “A person was hurt, or nearly hurt”.</span>}
            >
                {GROUPS.map((group) => (
                    <PopupSection key={group.title} title={group.title}>
                        <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label={group.title}>
                            {group.choices.map((c) => {
                                const on = c.key === picked;
                                return (
                                    // eslint-disable-next-line no-restricted-syntax -- drawing: tile picker, as RadioTiles
                                    <button
                                        key={c.key}
                                        type="button"
                                        role="radio"
                                        aria-checked={on}
                                        onClick={() => setPicked(c.key)}
                                        className={`flex items-start gap-3 rounded-xl border p-3 text-left transition-colors ${on ? 'border-primary bg-primary/5' : 'border-border bg-card hover:bg-accent'}`}
                                    >
                                        <span className="popup-lens size-9 shrink-0"><c.icon className="size-4" aria-hidden="true" /></span>
                                        <span className="min-w-0">
                                            <span className="block text-sm font-semibold text-foreground">{c.what}</span>
                                            <span className="block text-xs text-muted-foreground">{on ? c.asks : c.opens}</span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </PopupSection>
                ))}
            </PopupForm>
        </>
    );
}
