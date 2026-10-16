import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Link } from '@inertiajs/react';
import { ChevronRight, Network, Search, UsersRound } from 'lucide-react';
import { HubBrowseSelect } from './_browse-select';
import { HubPersonAvatar } from './_person-avatar';
import { useHubListControls } from './_reading-navigation';
import type { HubPerson } from './_types';

export function PeopleDirectory({
    people,
    canViewOrg,
    readOnly = false,
}: {
    people: HubPerson[];
    canViewOrg?: boolean;
    readOnly?: boolean;
}) {
    const [{ query, department, site, mode }, setControls] = useHubListControls(
        'people',
        { query: '', department: 'all', site: 'all', mode: 'cards' },
    );
    const setQuery = (query: string) =>
        setControls((previous) => ({ ...previous, query }));
    const setDepartment = (department: string) =>
        setControls((previous) => ({ ...previous, department }));
    const setSite = (site: string) =>
        setControls((previous) => ({ ...previous, site }));
    const setMode = (mode: string) =>
        setControls((previous) => ({ ...previous, mode }));
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
    const departments = [
        ...new Set(people.flatMap((p) => (p.department ? [p.department] : []))),
    ];
    const sites = [...new Set(people.flatMap((p) => (p.site ? [p.site] : [])))];
    const clearFilters = () =>
        setControls((previous) => ({
            ...previous,
            query: '',
            department: 'all',
            site: 'all',
        }));
    const card = (p: HubPerson) => {
        const content = (
            <>
                <HubPersonAvatar
                    name={p.name}
                    url={p.avatar_url}
                    className="size-11 self-start text-xs"
                />
                <span className="flex min-w-0 flex-1 flex-col items-start gap-1 [overflow-wrap:anywhere]">
                    <strong className="font-semibold">{p.name}</strong>
                    <span className="text-sm font-normal">
                        {p.position || 'Colleague'}
                    </span>
                    <small className="font-normal text-muted-foreground">
                        {[p.department, p.site].filter(Boolean).join(' · ')}
                    </small>
                    {!!p.shared_profile?.help_with.length && (
                        <span className="mt-1 text-xs leading-relaxed font-normal text-muted-foreground">
                            {p.shared_profile.help_with.slice(0, 3).join(' · ')}
                        </span>
                    )}
                </span>
                {!readOnly && (
                    <ChevronRight
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                )}
                {(p.first_aider || p.fire_warden) && (
                    <span className="hub-person-badges">
                        {p.first_aider && <span>First aider</span>}
                        {p.fire_warden && <span>Fire warden</span>}
                    </span>
                )}
            </>
        );
        if (readOnly)
            return (
                <article
                    key={p.id}
                    className="flex min-h-28 items-center gap-4 border-b px-2 py-5"
                >
                    {content}
                </article>
            );
        return (
            <Button
                key={p.id}
                variant="ghost"
                className="h-auto min-h-28 w-full justify-start gap-4 rounded-none border-b px-2 py-5 text-left whitespace-normal hover:bg-accent/50"
                asChild
            >
                <Link
                    href={`/company-hub/people/${p.id}`}
                    data-hub-return-key={`person:${p.id}`}
                >
                    {content}
                </Link>
            </Button>
        );
    };
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
                {own && !readOnly && (
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
            <div className="flex items-start justify-between gap-4">
                <div
                    className="flex flex-wrap gap-2"
                    role="group"
                    aria-label="Filter by department"
                >
                    {['all', ...departments].map((item) => (
                        <Button
                            key={item}
                            size="sm"
                            variant="ghost"
                            aria-pressed={department === item}
                            className={
                                department === item
                                    ? 'border border-primary/30 bg-primary/10 text-primary hover:bg-primary/15'
                                    : 'border border-transparent bg-muted/50 font-normal'
                            }
                            onClick={() => setDepartment(item)}
                        >
                            {item === 'all' ? 'All departments' : item}
                        </Button>
                    ))}
                </div>
                <HubBrowseSelect
                    label="Site"
                    value={site}
                    onChange={setSite}
                    options={[
                        { value: 'all', label: 'All sites' },
                        ...sites.map((name) => ({ value: name, label: name })),
                    ]}
                />
            </div>
            <div className="flex items-center justify-between gap-4">
                <p role="status" className="text-sm text-muted-foreground">
                    {filtered.length}{' '}
                    {filtered.length === 1 ? 'colleague' : 'colleagues'} · Work
                    contact details
                </p>
                {(query || department !== 'all' || site !== 'all') && (
                    <Button variant="ghost" size="sm" onClick={clearFilters}>
                        Clear filters
                    </Button>
                )}
            </div>
            {mode === 'org' && canViewOrg ? (
                groups.map((id) => (
                    <section className="hub-org-group" key={id}>
                        <h3>
                            <UsersRound className="size-4" />
                            {people.find((p) => p.id === id)?.name ??
                                'No visible reporting line'}
                        </h3>
                        <div className="grid grid-cols-2 gap-x-7">
                            {filtered
                                .filter((p) => (p.manager_id ?? 0) === id)
                                .map(card)}
                        </div>
                    </section>
                ))
            ) : (
                <div className="grid grid-cols-2 gap-x-7">
                    {filtered.map(card)}
                </div>
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
