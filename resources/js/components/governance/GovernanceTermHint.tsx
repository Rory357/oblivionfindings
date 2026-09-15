import { HelpCircle, Info, type LucideIcon } from 'lucide-react';
import { useId, type ReactNode } from 'react';

import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    GOVERNANCE_GLOSSARY,
    type GovernanceTermKey,
} from '@/lib/governance-glossary';
import { cn } from '@/lib/utils';

/** "Quorum" → "quorum", but "CEO report" keeps its acronym. */
function inlineTerm(term: string): string {
    const second = term.charAt(1);
    if (second && second === second.toUpperCase() && /\p{L}/u.test(second)) {
        return term;
    }
    return term.charAt(0).toLowerCase() + term.slice(1);
}

export interface GovernanceTermHintProps {
    /** Glossary key from `lib/governance-glossary.ts`. */
    term: GovernanceTermKey;
    /**
     * Optional visible text. When given, the text itself becomes the trigger
     * (dotted underline) instead of the small "What's this?" icon.
     */
    children?: ReactNode;
    side?: 'top' | 'right' | 'bottom' | 'left';
    align?: 'start' | 'center' | 'end';
    className?: string;
}

/**
 * "What's this?" hint for a Governance term: a small help icon (or the term
 * text itself, dotted-underlined) that opens a popover with the plain
 * definition. The trigger is a real button, so it's reachable with Tab,
 * opens with Enter/Space, closes with Escape and returns focus.
 *
 * <GovernanceTermHint term="quorum" />
 * <GovernanceTermHint term="quorum">quorum</GovernanceTermHint>
 */
export function GovernanceTermHint({
    term,
    children,
    side = 'top',
    align = 'center',
    className,
}: GovernanceTermHintProps) {
    const entry = GOVERNANCE_GLOSSARY[term];
    const titleId = useId();
    const bodyId = useId();

    return (
        <Popover>
            <PopoverTrigger asChild>
                {children ? (
                    <button
                        type="button"
                        data-slot="governance-term-hint"
                        className={cn(
                            'cursor-help rounded-sm text-left underline decoration-muted-foreground decoration-dotted underline-offset-4 transition-colors hover:decoration-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            className,
                        )}
                    >
                        {children}
                        <span className="sr-only">, what does this mean?</span>
                    </button>
                ) : (
                    <button
                        type="button"
                        data-slot="governance-term-hint"
                        aria-label={`What does ${inlineTerm(entry.term)} mean?`}
                        className={cn(
                            'inline-flex size-5 shrink-0 items-center justify-center rounded-full align-middle text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none data-[state=open]:text-primary',
                            className,
                        )}
                    >
                        <HelpCircle className="size-3.5" aria-hidden="true" />
                    </button>
                )}
            </PopoverTrigger>
            <PopoverContent
                side={side}
                align={align}
                aria-labelledby={titleId}
                aria-describedby={bodyId}
                className="w-72 space-y-1.5"
            >
                <p id={titleId} className="text-section-title">
                    {entry.term}
                </p>
                <p id={bodyId} className="text-subtle">
                    {entry.definition}
                </p>
            </PopoverContent>
        </Popover>
    );
}

export interface GovernanceExplainerProps {
    /** Short heading, sentence case — e.g. "How board voting works". */
    title: ReactNode;
    /** One or two plain sentences (or a short list). */
    body: ReactNode;
    icon?: LucideIcon;
    /** Heading level for the page outline; defaults to h3. */
    headingLevel?: 2 | 3 | 4;
    /** Optional extra content below the body, e.g. a link or term hints. */
    children?: ReactNode;
    className?: string;
}

/**
 * A short inline "How this works" callout card. Static guidance only — use
 * a status/blocked banner for anything the member must act on.
 */
export function GovernanceExplainer({
    title,
    body,
    icon: Icon = Info,
    headingLevel = 3,
    children,
    className,
}: GovernanceExplainerProps) {
    const headingId = useId();
    const Heading = `h${headingLevel}` as 'h2' | 'h3' | 'h4';

    return (
        <div
            role="note"
            aria-labelledby={headingId}
            data-slot="governance-explainer"
            className={cn(
                'flex items-start gap-3 rounded-lg border border-border bg-card p-4 text-card-foreground',
                className,
            )}
        >
            <span
                aria-hidden="true"
                className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
            >
                <Icon className="size-4" />
            </span>
            <div className="min-w-0 space-y-1">
                <Heading id={headingId} className="text-section-title">
                    {title}
                </Heading>
                <div className="text-subtle">{body}</div>
                {children ? <div className="pt-1">{children}</div> : null}
            </div>
        </div>
    );
}
