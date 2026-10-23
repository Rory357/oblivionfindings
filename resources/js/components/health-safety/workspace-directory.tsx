import { PageHeaderSearch } from '@/components/page';
import { type SafetyView } from '@/lib/health-safety/workspace-navigation';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

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

export function SafetyDirectory({
    views,
    label,
}: {
    views: SafetyView[];
    label: string;
}) {
    return views.length ? (
        <ul
            aria-label={label}
            className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
        >
            {views.map(({ key, icon: Icon, href, label, description }) => (
                <li key={key}>
                    <Link
                        href={href}
                        className="frontline-focus flex h-full min-h-28 items-start gap-3 rounded-xl border border-border bg-card p-5 transition-colors hover:bg-accent"
                    >
                        <Icon
                            aria-hidden
                            className="mt-0.5 size-5 shrink-0 text-primary"
                        />
                        <span className="min-w-0 flex-1">
                            <span className="block font-semibold">{label}</span>
                            <span className="mt-1 block text-sm text-muted-foreground">
                                {description}
                            </span>
                        </span>
                        <ArrowRight
                            aria-hidden
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </li>
            ))}
        </ul>
    ) : (
        <p role="status" className="py-8 text-sm text-muted-foreground">
            No matching destinations. Try another search.
        </p>
    );
}
