import { Button } from '@/components/ui/button';
import { Plus, Search } from 'lucide-react';

export type HubSearchInsightData = {
    since: string;
    until: string;
    days: number;
    total: number;
    unanswered: number;
    topics: Array<{
        key: string;
        label: string;
        total: number;
        unanswered: number;
    }>;
};

export function SearchInsights({
    data,
    onDraftAnswer,
}: {
    data: HubSearchInsightData;
    onDraftAnswer?: (title: string) => void;
}) {
    return (
        <section
            className="hub-insight-panel"
            aria-label="Unanswered search topics"
        >
            <div className="flex items-start justify-between gap-6">
                <div>
                    <h3 className="flex items-center gap-2 font-semibold">
                        <Search className="size-4 text-primary" />
                        What are people looking for?
                    </h3>
                    <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Submitted Hub searches from staff at your approved
                        sites, grouped into broad topics. Use gaps to improve
                        company guides and links.
                    </p>
                </div>
                <div className="shrink-0 text-right">
                    <p className="text-2xl font-semibold tabular-nums">
                        {data.unanswered}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        without results · {data.total} searches
                    </p>
                </div>
            </div>
            {data.total === 0 ? (
                <p className="hub-empty-note mt-5">
                    No searches recorded in this period yet. This panel fills as
                    colleagues use Company Hub search.
                </p>
            ) : data.unanswered === 0 ? (
                <p className="mt-5 rounded-lg bg-muted p-4 text-sm">
                    Every recorded search returned at least one result in this
                    period.
                </p>
            ) : (
                <div className="mt-6 space-y-4">
                    {data.topics.map((topic) => (
                        <div key={topic.key}>
                            <div className="mb-2 flex items-center justify-between gap-4 text-sm">
                                <span className="font-medium">
                                    {topic.label}
                                </span>
                                <span className="text-muted-foreground tabular-nums">
                                    {topic.unanswered} without results ·{' '}
                                    {topic.total} searches
                                </span>
                                {onDraftAnswer && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-label={`Draft an answer for ${topic.label}`}
                                        onClick={() =>
                                            onDraftAnswer(
                                                topic.key === 'other'
                                                    ? 'Company questions and answers'
                                                    : `${topic.label}: useful answers`,
                                            )
                                        }
                                    >
                                        <Plus className="size-3.5" />
                                        Draft an answer
                                    </Button>
                                )}
                            </div>
                            <div
                                className="h-1.5 overflow-hidden rounded-full bg-muted"
                                aria-hidden
                            >
                                <div
                                    className="h-full rounded-full bg-primary/70"
                                    style={{
                                        width: `${Math.min(100, (topic.unanswered / Math.max(1, data.unanswered)) * 100)}%`,
                                    }}
                                />
                            </div>
                        </div>
                    ))}
                </div>
            )}
            <p className="mt-5 text-xs leading-5 text-muted-foreground">
                Last {data.days} calendar days in New Zealand time. Repeating
                the same search on the same day counts once per colleague;
                keyboard suggestions are excluded. This report contains topic
                totals, without search phrases or staff names. Totals are
                retained for 90 days.
            </p>
        </section>
    );
}
