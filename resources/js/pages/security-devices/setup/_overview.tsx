/** Setup › Overview (pg-sdr-15): what's still to set up, then a review card for each section. */
import { QuietLine, Ring } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard } from '@/components/wizard/shell';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, History, KeyRound, ListChecks, Plug, Router, Users, type LucideIcon } from 'lucide-react';
import { LineText, ToneLine, matches } from './_parts';
import type { ReviewCardData, SetupProps, SetupSection, TodoItem } from './_types';

const ICONS: Record<ReviewCardData['icon'], LucideIcon> = {
    router: Router,
    plug: Plug,
    key: KeyRound,
    'list-checks': ListChecks,
    users: Users,
    history: History,
};

const RING = { success: 'success', warning: 'warning', critical: 'critical', info: 'info', neutral: 'none' } as const;

export function SetupOverview({
    data,
    query,
    now,
    onSection,
    onAddHub,
    onOpenHub,
}: {
    data: NonNullable<SetupProps['overview']>;
    query: string;
    now: Date;
    onSection: (section: SetupSection, tab: string | null) => void;
    onAddHub: (siteId: number) => void;
    onOpenHub: (hubId: number) => void;
}) {
    const todo = data.todo.filter((item) => matches(query, item.text));
    const cards = data.cards.filter((card) => matches(query, card.title, ...card.lines.map((line) => line.text)));

    const act = (item: TodoItem) => {
        const action = item.action;
        if (!action) return;
        if (action.kind === 'section') onSection(action.section, action.tab);
        else if (action.kind === 'add_hub') onAddHub(action.site_id);
        else onOpenHub(action.hub_id);
    };

    return (
        <div className="space-y-5">
            {data.todo.length === 0 ? (
                <QuietLine title="Still to set up" text="Nothing waiting. Everything Oblivion can check here is set up." />
            ) : (
                <Card className="gap-0 overflow-hidden p-0" aria-label="Still to set up">
                    <div className="flex items-center gap-2 border-b border-border px-4 py-3">
                        <h2 className="text-sm font-semibold">Still to set up</h2>
                        <StatusBadge variant="warning" size="sm">
                            {data.todo.length}
                        </StatusBadge>
                    </div>
                    <ul className="divide-y divide-border">
                        {todo.map((item) => (
                            <li key={item.key} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <Ring tone={RING[item.tone]} />
                                <span className="min-w-0 flex-1 text-sm">
                                    <LineText line={item} now={now} />
                                </span>
                                {item.action ? (
                                    <Button variant="outline" size="sm" onClick={() => act(item)}>
                                        {item.action.label}
                                    </Button>
                                ) : null}
                            </li>
                        ))}
                        {todo.length === 0 ? <li className="text-subtle px-4 py-3">Nothing here matches your search.</li> : null}
                    </ul>
                </Card>
            )}

            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                {cards.map((card) => (
                    <ReviewCard key={card.key} icon={ICONS[card.icon]} title={card.title}>
                        <div className="grid gap-2">
                            {card.lines.map((line, index) => (
                                <ToneLine key={index} line={line} now={now} />
                            ))}
                        </div>
                        {card.link ? (
                            card.link.href ? (
                                <Button variant="link" className="mt-2 px-0" asChild>
                                    <Link href={card.link.href}>
                                        {card.link.label} <ArrowUpRight className="size-4" />
                                    </Link>
                                </Button>
                            ) : (
                                <Button variant="link" className="mt-2 px-0" onClick={() => onSection(card.link!.section!, card.link!.tab ?? null)}>
                                    {card.link.label} <ArrowUpRight className="size-4" />
                                </Button>
                            )
                        ) : null}
                    </ReviewCard>
                ))}
            </div>
            {cards.length === 0 && query ? <QuietLine text="No section matches your search." /> : null}
        </div>
    );
}
