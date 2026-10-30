import { Input } from '@/components/ui/input';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Search, UsersRound } from 'lucide-react';
import { useState } from 'react';
import { HubPersonAvatar } from './_person-avatar';
import type { HubPerson } from './_types';

function Initials({ person }: { person: HubPerson }) {
    return (
        <HubPersonAvatar
            name={person.name}
            url={person.avatar_url}
            className="hub-compact-avatar"
        />
    );
}

export function CompactPeople({
    people,
    finder = false,
    limit = 4,
    demo = false,
}: {
    people: HubPerson[];
    finder?: boolean;
    limit?: number;
    demo?: boolean;
}) {
    const [query, setQuery] = useState('');
    const needle = query.trim().toLocaleLowerCase();
    const count = Math.max(1, Math.min(12, limit));
    const matches = people.filter(
        (person) =>
            !needle ||
            [
                person.name,
                person.position,
                person.department,
                person.site,
                person.shared_profile?.about,
                ...(person.shared_profile?.help_with ?? []),
            ]
                .join(' ')
                .toLocaleLowerCase()
                .includes(needle),
    );
    const visible = matches.slice(0, count);
    const directory = demo
        ? '/company-hub/showcase?view=people'
        : '/company-hub?view=people';
    return (
        <div className="hub-compact-people">
            {finder && (
                <>
                    <div className="hub-finder-faces" aria-hidden="true">
                        {people.slice(0, Math.min(count, 5)).map((person) => (
                            <Initials key={person.id} person={person} />
                        ))}
                        {!people.length && (
                            <UsersRound className="size-7 text-primary" />
                        )}
                    </div>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        A name, a role or someone who can help. Find the right
                        colleague.
                    </p>
                    <label className="hub-compact-search">
                        <Search
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <Input
                            aria-label="Search colleagues"
                            placeholder="Name, role or expertise…"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </label>
                </>
            )}
            {(!finder || !!needle) && (
                <>
                    <div className="hub-compact-people-results">
                        {visible.map((person) => (
                            <Link
                                key={person.id}
                                className="hub-compact-person"
                                href={
                                    demo
                                        ? directory
                                        : `/company-hub/people/${person.id}`
                                }
                            >
                                <Initials person={person} />
                                <span className="min-w-0 flex-1">
                                    <strong>{person.name}</strong>
                                    <small>
                                        {[person.position, person.department]
                                            .filter(Boolean)
                                            .join(' · ') || 'Colleague'}
                                    </small>
                                </span>
                                <ArrowUpRight
                                    className="size-3.5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </Link>
                        ))}
                    </div>
                    <p className="text-xs text-muted-foreground" role="status">
                        {!matches.length
                            ? 'No colleagues match this selection.'
                            : matches.length > count
                              ? `Showing ${count} of ${matches.length} colleagues`
                              : `${matches.length} ${matches.length === 1 ? 'colleague' : 'colleagues'}`}
                    </p>
                </>
            )}
            <Link className="hub-compact-more" href={directory}>
                Explore the directory
                <ArrowUpRight className="size-3.5" />
            </Link>
        </div>
    );
}
