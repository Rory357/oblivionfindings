import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Link } from '@inertiajs/react';
import { Network, Search, UsersRound } from 'lucide-react';
import { useState } from 'react';
import type { HubPerson } from './_types';

export function PeopleDirectory({
    people,
    canViewOrg,
}: {
    people: HubPerson[];
    canViewOrg?: boolean;
}) {
    const [query, setQuery] = useState('');
    const [department, setDepartment] = useState('all');
    const [site, setSite] = useState('all');
    const [mode, setMode] = useState('cards');
    const own = people.find((p) => p.can_edit_profile);
    const filtered = people.filter(
        (p) =>
            (department === 'all' || p.department === department) &&
            (site === 'all' || p.site === site) &&
            [
                p.name,
                p.position,
                p.department,
                p.site,
                p.shared_profile?.about,
                ...(p.shared_profile?.help_with ?? []),
            ]
                .join(' ')
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const groups = [...new Set(filtered.map((p) => p.manager_id ?? 0))];
    const card = (p: HubPerson) => (
        <Button
            key={p.id}
            variant="ghost"
            className="hub-person-card h-auto whitespace-normal"
            asChild
        >
            <Link href={`/company-hub/people/${p.id}`}>
                <span className="hub-person-avatar" aria-hidden>
                    {p.name
                        .split(' ')
                        .slice(0, 2)
                        .map((n) => n[0])
                        .join('')}
                </span>
                <strong>{p.name}</strong>
                <span>{p.position || 'Colleague'}</span>
                <small>
                    {[p.department, p.site].filter(Boolean).join(' · ')}
                </small>
                {!!p.shared_profile?.help_with.length && (
                    <span className="hub-person-badges">
                        {p.shared_profile.help_with.slice(0, 3).map((skill) => (
                            <span key={skill}>{skill}</span>
                        ))}
                    </span>
                )}
                {(p.first_aider || p.fire_warden) && (
                    <span className="hub-person-badges">
                        {p.first_aider && <span>First aider</span>}
                        {p.fire_warden && <span>Fire warden</span>}
                    </span>
                )}
            </Link>
        </Button>
    );
    return (
        <section className="space-y-5" aria-label="People directory">
            <div className="hub-library-tools">
                <label className="hub-library-search">
                    <Search className="size-4" />
                    <Input
                        aria-label="Search colleagues"
                        placeholder="Search by name, team, role or skill…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </label>
                <select
                    className="hub-native-select"
                    aria-label="Department"
                    value={department}
                    onChange={(e) => setDepartment(e.target.value)}
                >
                    <option value="all">All departments</option>
                    {[
                        ...new Set(
                            people.map((p) => p.department).filter(Boolean),
                        ),
                    ].map((d) => (
                        <option key={d} value={d!}>
                            {d}
                        </option>
                    ))}
                </select>
                <select
                    className="hub-native-select"
                    aria-label="Site"
                    value={site}
                    onChange={(e) => setSite(e.target.value)}
                >
                    <option value="all">All sites</option>
                    {[
                        ...new Set(people.map((p) => p.site).filter(Boolean)),
                    ].map((s) => (
                        <option key={s} value={s!}>
                            {s}
                        </option>
                    ))}
                </select>
                {own && (
                    <Button variant="outline" asChild>
                        <Link href={`/company-hub/people/${own.id}`}>
                            My shared profile
                        </Link>
                    </Button>
                )}
                {canViewOrg && (
                    <Button
                        variant="outline"
                        aria-pressed={mode === 'org'}
                        onClick={() =>
                            setMode(mode === 'org' ? 'cards' : 'org')
                        }
                    >
                        <Network className="size-4" />
                        Reporting lines
                    </Button>
                )}
            </div>
            <p className="text-sm text-muted-foreground">
                {filtered.length}{' '}
                {filtered.length === 1 ? 'colleague' : 'colleagues'} · Work
                contact details
            </p>
            {mode === 'org' && canViewOrg ? (
                groups.map((id) => (
                    <section className="hub-org-group" key={id}>
                        <h3>
                            <UsersRound className="size-4" />
                            {people.find((p) => p.id === id)?.name ??
                                'No visible reporting line'}
                        </h3>
                        <div className="hub-people-grid">
                            {filtered
                                .filter((p) => (p.manager_id ?? 0) === id)
                                .map(card)}
                        </div>
                    </section>
                ))
            ) : (
                <div className="hub-people-grid">{filtered.map(card)}</div>
            )}
            {filtered.length === 0 && (
                <div className="hub-empty-note">
                    No colleagues match these filters. Try another name or
                    department.
                </div>
            )}
        </section>
    );
}
