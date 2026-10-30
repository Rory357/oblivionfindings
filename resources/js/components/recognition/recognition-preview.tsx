import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Users } from 'lucide-react';
import { AppreciationIcon } from './appreciation-icon';

export const RECOGNITION_HEADINGS: Record<string, string> = {
    thank_you: 'Thank you',
    good_job: 'You made a difference',
    impressive: 'You inspired us',
    exceptional: 'Above and beyond',
};

export function recognitionHeading(
    impact?: string | null,
    label?: string | null,
) {
    return (
        label ||
        RECOGNITION_HEADINGS[impact || 'good_job'] ||
        RECOGNITION_HEADINGS.good_job
    );
}

export function RecognitionPreview({
    names,
    team,
    message,
    category,
    impact,
    sent = false,
}: {
    names: string;
    team: boolean;
    message: string;
    category?: string;
    impact: string;
    sent?: boolean;
}) {
    return (
        <article
            aria-label={sent ? 'Sent shout-out' : 'Your card preview'}
            className="overflow-hidden rounded-2xl border border-border bg-card text-card-foreground"
        >
            <header className="flex items-center justify-between gap-3 bg-gradient-to-br from-primary/10 to-primary/20 px-5 py-5">
                <div>
                    <p className="text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                        A shout-out for
                    </p>
                    <h3 className="mt-2 font-semibold text-primary">
                        {recognitionHeading(impact)}
                    </h3>
                </div>
                <AppreciationIcon className="size-9 shrink-0 text-primary" />
            </header>
            <div className="space-y-5 p-5">
                <Avatar className="size-9">
                    <AvatarFallback className="bg-primary/10 text-xs text-primary">
                        {team ? (
                            <Users className="size-4" />
                        ) : (
                            names
                                .split(/\s+/)
                                .filter(Boolean)
                                .slice(0, 2)
                                .map((name) => name[0])
                                .join('')
                        )}
                    </AvatarFallback>
                </Avatar>
                <h4 className="text-lg leading-snug font-semibold break-words">
                    {names || 'Your colleagues'}
                </h4>
                <p className="text-sm leading-7 break-words whitespace-pre-wrap">
                    {message.trim() ? (
                        message
                    ) : (
                        <span className="text-muted-foreground">
                            Your words will appear here as you write.
                        </span>
                    )}
                </p>
                {category && (
                    <span className="inline-flex rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
                        {category}
                    </span>
                )}
                <p className="text-xs leading-5 text-muted-foreground">
                    From you
                    <br />
                    {sent
                        ? 'Shared with colleagues in the Hub'
                        : 'Ready to share after your review'}
                </p>
            </div>
        </article>
    );
}
