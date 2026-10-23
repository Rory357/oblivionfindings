import { PageHeaderSearch } from '@/components/page';
import { PlaceCards, SectionHead, type SgTone } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { type SafetyView } from '@/lib/health-safety/workspace-navigation';

export function SafetyDirectorySearch({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <PageHeaderSearch
            value={value}
            onChange={onChange}
            placeholder="Find a safety destination"
            ariaLabel="Find a safety destination"
        />
    );
}

export function filterSafetyDirectory(views: SafetyView[], query: string) {
    const terms = query
        .toLocaleLowerCase('en-NZ')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    return views.filter((view) =>
        terms.every((term) =>
            [view.label, view.description, ...(view.keywords ?? [])]
                .join(' ')
                .toLocaleLowerCase('en-NZ')
                .includes(term),
        ),
    );
}

/**
 * What a register shows on its card (approved H&S drawings, 23 Oct): one flag and up to two facts, each from the
 * register's own source. Optional — a card without status shows its description only, never invented counts.
 */
export type SafetyDirectoryStatus = {
    flag?: { tone: 'critical' | 'warning' | 'success' | 'info' | 'neutral'; label: string } | null;
    facts?: Array<{ tone: SgTone; text: string }>;
};

/** Each register (or report) as a card: its name, what it holds, its flag and facts; the whole card opens it. */
export function SafetyDirectory({
    views,
    label,
    status = {},
    cols = 2,
}: {
    views: SafetyView[];
    label: string;
    status?: Record<string, SafetyDirectoryStatus | undefined>;
    cols?: number;
}) {
    return views.length ? (
        <section aria-label={label}>
            <SectionHead title={label} />
            <PlaceCards
                cols={cols}
                items={views.map((view) => {
                    const s = status[view.key];
                    return {
                        name: view.label,
                        sub: view.description,
                        href: view.href,
                        flag: s?.flag ? (
                            <StatusBadge variant={s.flag.tone} size="sm">
                                {s.flag.label}
                            </StatusBadge>
                        ) : undefined,
                        facts: s?.facts,
                    };
                })}
            />
        </section>
    ) : (
        <p role="status" className="py-8 text-sm text-muted-foreground">
            No matching destinations. Try another search.
        </p>
    );
}
