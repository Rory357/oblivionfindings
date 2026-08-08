/* eslint-disable no-restricted-syntax -- The Shout-outs tab uses a bespoke
 * segmented Received/Given toggle and a "give a shout-out" CTA sized to the
 * design handoff; the spotlight band itself is the shared component. Colours map
 * to semantic tokens throughout. */
import { Megaphone, Send } from 'lucide-react';
import { useState } from 'react';

import {
    MyHrShell,
    MyHrShoutoutSpotlight,
    useSendKudos,
    type MyHrShellData,
    type MyHrShoutout,
} from '@/components/hr';
import { cn } from '@/lib/utils';

interface Props {
    myHr: MyHrShellData;
    received: MyHrShoutout[];
    given: MyHrShoutout[];
}

export default function MyHrShoutouts({ myHr, received, given }: Props) {
    const openKudos = useSendKudos();
    const [box, setBox] = useState<'received' | 'given'>('received');

    const me = {
        initials: myHr.profile.initials,
        firstName: myHr.profile.first_name,
    };
    const items = box === 'received' ? received : given;

    return (
        <MyHrShell active="shoutouts" myHr={myHr} title="Shout-outs">
            <div className="flex flex-col gap-5">
                {/* Header: intro + give CTA */}
                <div
                    className="flex flex-wrap items-center gap-4 rounded-[18px] border px-5 py-4"
                    style={{
                        background:
                            'linear-gradient(120deg, color-mix(in oklch, var(--category-hr) 12%, var(--card)), color-mix(in oklch, var(--category-hr) 4%, var(--card)))',
                        borderColor:
                            'color-mix(in oklch, var(--category-hr) 22%, var(--border))',
                    }}
                >
                    <span
                        className="grid h-12 w-12 shrink-0 place-items-center rounded-[15px] text-2xl"
                        style={{
                            background:
                                'color-mix(in oklch, var(--category-hr) 22%, var(--card))',
                            color: 'var(--category-hr)',
                        }}
                    >
                        <Megaphone className="h-6 w-6" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="text-[15.5px] font-bold">
                            Shout-outs
                        </div>
                        <p className="mt-0.5 text-[12.5px] leading-relaxed text-muted-foreground">
                            Recognition from your team — and the ones you’ve
                            given. React, reply and close the loop. 💛
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={openKudos}
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-[10px] bg-primary px-4 py-2.5 text-[13px] font-bold text-primary-foreground transition-colors hover:opacity-90"
                    >
                        <Send className="h-4 w-4" /> Give a shout-out
                    </button>
                </div>

                {/* Received / Given toggle */}
                <div className="flex items-center gap-1.5">
                    {(
                        [
                            ['received', 'Received', received.length],
                            ['given', 'Given', given.length],
                        ] as const
                    ).map(([key, label, count]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setBox(key)}
                            className={cn(
                                'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors',
                                box === key
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border bg-card text-muted-foreground hover:bg-muted',
                            )}
                        >
                            {label}
                            <span
                                className={cn(
                                    'inline-grid h-[18px] min-w-[18px] place-items-center rounded-full px-1 text-[10px] font-bold',
                                    box === key
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {count}
                            </span>
                        </button>
                    ))}
                </div>

                <MyHrShoutoutSpotlight
                    key={box}
                    shoutouts={items}
                    perspective={box}
                    me={me}
                    onGiveShoutout={openKudos}
                />
            </div>
        </MyHrShell>
    );
}
