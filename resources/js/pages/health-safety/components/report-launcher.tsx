/* "Report something" — the one chooser opened from the H&S Overview header (approved drawing D2, MASTER for Stephan,
 * 30 Oct). Plain-words choices in three groups, on the pop-up kit (PopupForm, 720). The chosen tile says what its form
 * will ask; the main button names the form it opens. Each choice shows only to people allowed to use it (the same
 * permission its route checks), so nobody picks something that then refuses them. Tokens only. */
import { PopupForm, PopupSection } from '@/components/popup';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import {
    Activity,
    AlertOctagon,
    Clipboard,
    HeartPulse,
    type LucideIcon,
    PersonStanding,
    ShieldAlert,
    ShieldQuestion,
    TestTubeDiagonal,
} from 'lucide-react';
import { useEffect, useState } from 'react';

/** The shared permission flags this chooser reads (module → flag → allowed). */
type Can = Partial<Record<string, Partial<Record<string, boolean>>>> | undefined;

type Workflow = {
    key: string;
    icon: LucideIcon;
    /** What happened, in everyday words. */
    label: string;
    /** The form it opens. */
    opens: string;
    /** What that form will ask (shown on the chosen tile). */
    asks: string;
    group: 'hurt' | 'harm' | 'work';
    /** Mirrors the permission the destination route checks. */
    allowed: (can: Can) => boolean;
};

const hazardCapture = (can: Can) =>
    Boolean(can?.hazards?.create || can?.hazards?.manage);

const WORKFLOWS: Workflow[] = [
    {
        key: 'incident',
        icon: ShieldAlert,
        label: 'A person was hurt, or nearly hurt',
        opens: 'Report an incident',
        asks: 'Who, where, what happened and what you did straight away.',
        group: 'hurt',
        allowed: (can) => Boolean(can?.incidents?.create),
    },
    {
        key: 'rtw',
        icon: Activity,
        label: 'A staff member was hurt at work',
        opens: 'Report a workplace injury',
        asks: 'Who was hurt, the injury, and any time off or ACC claim.',
        group: 'hurt',
        allowed: hazardCapture,
    },
    {
        key: 'first_aid',
        icon: HeartPulse,
        label: 'You gave first aid',
        opens: 'Record first aid',
        asks: 'Who you treated, what you did and what happened next.',
        group: 'hurt',
        allowed: hazardCapture,
    },
    {
        key: 'restraint',
        icon: Clipboard,
        label: 'A person’s movement or freedom was limited',
        opens: 'Record a restraint',
        asks: 'What led to it, what you tried first, and how the person is now.',
        group: 'hurt',
        allowed: (can) =>
            Boolean(can?.restraints?.create || can?.restraints?.manage),
    },
    {
        key: 'hazard',
        icon: AlertOctagon,
        label: 'Something could cause harm',
        opens: 'Report a hazard',
        asks: 'Where it is, how bad it could be, and what you did to make it safe.',
        group: 'harm',
        allowed: (can) => Boolean(can?.hazards?.create),
    },
    {
        key: 'exposure',
        icon: TestTubeDiagonal,
        label: 'A chemical got on or into someone',
        opens: 'Record a chemical exposure',
        asks: 'Pick the chemical, then how it happened and any treatment.',
        group: 'harm',
        allowed: hazardCapture,
    },
    {
        key: 'lone',
        icon: PersonStanding,
        label: 'You’re about to work alone',
        opens: 'Start working alone',
        asks: 'Where you’ll be and when to check in.',
        group: 'work',
        allowed: (can) => Boolean(can?.hazards?.view && can?.hazards?.manage),
    },
    {
        key: 'safeguarding',
        icon: ShieldQuestion,
        label: 'You’re worried a person is being harmed',
        opens: 'Raise a safeguarding concern',
        asks: 'What you saw or were told. It goes only to the safeguarding lead.',
        group: 'work',
        allowed: (can) => Boolean(can?.safeguarding?.create),
    },
];

const GROUPS: { key: Workflow['group']; title: string }[] = [
    { key: 'hurt', title: 'Someone was hurt, or nearly' },
    { key: 'harm', title: 'Something could hurt someone' },
    { key: 'work', title: 'Before or during work' },
];

/** The choices this person may use (exported for tests). */
export function launcherWorkflows(can: Can): Workflow[] {
    return WORKFLOWS.filter((workflow) => workflow.allowed(can));
}

export function ReportLauncher({
    open,
    onClose,
    onWorkflow,
}: {
    open: boolean;
    onClose: () => void;
    onWorkflow: (key: string) => void;
}) {
    const can = usePage<SharedData>().props.auth?.can as unknown as Can;
    const workflows = launcherWorkflows(can);
    const [picked, setPicked] = useState<string | null>(workflows[0]?.key ?? null);
    // A fresh chooser starts on the first choice each time it opens.
    useEffect(() => {
        if (open) setPicked(workflows[0]?.key ?? null);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when it opens
    }, [open]);
    const chosen = workflows.find((w) => w.key === picked) ?? null;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={ShieldAlert}
            title="Report something"
            description={
                workflows.length
                    ? 'Choose what happened. We’ll open the right form.'
                    : 'You can’t record health and safety work.'
            }
            submitLabel={chosen?.opens ?? 'Continue'}
            submitDisabled={!chosen}
            onSubmit={() => chosen && onWorkflow(chosen.key)}
            footerStart={
                workflows.some((w) => w.key === 'incident') ? (
                    <span className="text-subtle">
                        Not sure? Choose “A person was hurt, or nearly hurt”.
                    </span>
                ) : undefined
            }
        >
            {workflows.length === 0 ? (
                <p className="text-subtle">
                    Ask your manager or a health and safety lead to give you
                    access.
                </p>
            ) : (
                GROUPS.map((group) => {
                    const items = workflows.filter((w) => w.group === group.key);
                    if (!items.length) return null;
                    return (
                        <PopupSection key={group.key} title={group.title}>
                            <div
                                className="grid gap-2 sm:grid-cols-2"
                                role="radiogroup"
                                aria-label={group.title}
                            >
                                {items.map((w) => {
                                    const on = w.key === picked;
                                    return (
                                        // eslint-disable-next-line no-restricted-syntax -- tile picker (radio), as RadioTiles
                                        <button
                                            key={w.key}
                                            type="button"
                                            role="radio"
                                            aria-checked={on}
                                            onClick={() => setPicked(w.key)}
                                            onDoubleClick={() => onWorkflow(w.key)}
                                            className={`flex items-start gap-3 rounded-xl border p-3 text-left transition-colors ${on ? 'border-primary bg-primary/5' : 'border-border bg-card hover:bg-accent'}`}
                                        >
                                            <span className="popup-lens size-9 shrink-0">
                                                <w.icon
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-sm font-semibold text-foreground">
                                                    {w.label}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {on ? w.asks : w.opens}
                                                </span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </PopupSection>
                    );
                })
            )}
        </PopupForm>
    );
}

export default ReportLauncher;
