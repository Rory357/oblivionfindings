import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    JOURNEY_TERM_DEFINITIONS,
    type JourneyTerm,
} from '@/lib/journey-labels';
import { CircleHelp } from 'lucide-react';

export function JourneyTermHelp({
    terms,
    label,
}: {
    terms: JourneyTerm[];
    label: string;
}) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <CircleHelp className="h-4 w-4" aria-hidden="true" />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-72">
                <p className="mb-2 text-sm font-semibold">
                    What these terms mean
                </p>
                <dl className="space-y-2">
                    {terms.map((term) => {
                        const item = JOURNEY_TERM_DEFINITIONS[term];

                        return (
                            <div key={term}>
                                <dt className="text-xs font-semibold">
                                    {item.label}
                                </dt>
                                <dd className="text-xs text-muted-foreground">
                                    {item.definition}
                                </dd>
                            </div>
                        );
                    })}
                </dl>
            </PopoverContent>
        </Popover>
    );
}
