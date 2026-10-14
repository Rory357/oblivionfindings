import { PageHeaderRail } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Building2, Check, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import { HubBlocks, NewsCards } from './_blocks';
import { CreateHubPageDialog } from './_dialogs';
import { PeopleDirectory } from './_people';
import { ResourceLibrary } from './_resource-library';
import type {
    HubContent,
    HubDepartment,
    HubEvent,
    HubInterests,
    HubProps,
} from './_types';

export function departmentPreview(
    content: HubContent,
    page: { id: number; owner_id: number; draft_version: number },
    data: HubProps,
): HubDepartment {
    const teams = (data.interests?.available_teams ?? []).filter((team) =>
        content.team_ids?.includes(team.id),
    );
    const ids = teams.map((team) => team.id);
    const related = data.pages.filter(
        (item) =>
            item.id !== page.id &&
            item.team_ids?.some((id) => ids.includes(id)),
    );
    return {
        actor_id: 0,
        page_id: page.id,
        revision_id: 0,
        version: page.draft_version,
        teams,
        owner:
            data.people?.find((person) => person.user_id === page.owner_id) ??
            null,
        updates: related.filter((item) => item.kind === 'news'),
        resources: related.filter((item) =>
            ['knowledge', 'page'].includes(item.kind),
        ),
        people: (data.people ?? []).filter(
            (person) =>
                person.department_id && ids.includes(person.department_id),
        ),
        can_create_update: false,
    };
}

function FollowTeams({
    space,
    initial,
}: {
    space: HubDepartment;
    initial: HubInterests;
}) {
    const [saved, setSaved] = useState<{
        source: HubInterests;
        value: HubInterests;
    } | null>(null);
    const interests = saved?.source === initial ? saved.value : initial;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const following = space.teams.every((team) =>
        interests.teams.includes(team.id),
    );
    const change = async () => {
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<{
                actor_id: number;
                page_id: number;
                revision_id: number;
                interests: HubInterests;
            }>(`/company-hub/pages/${space.page_id}/follow-teams`, 'PUT', {
                actor_id: space.actor_id,
                revision_id: space.revision_id,
                version: interests.version,
                following: !following,
            });
            if (!alive.current) return;
            if (
                result.actor_id !== space.actor_id ||
                result.page_id !== space.page_id ||
                result.revision_id !== space.revision_id
            )
                throw new Error(
                    'This space or your signed-in account changed. Reload before continuing.',
                );
            setSaved({ source: initial, value: result.interests });
        } catch (err) {
            if (alive.current) setError((err as Error).message);
        } finally {
            if (alive.current) setBusy(false);
        }
    };
    return (
        <div className="space-y-2">
            <Button
                variant="outline"
                disabled={busy}
                aria-pressed={following}
                onClick={change}
            >
                {following ? (
                    <Check className="size-4" />
                ) : (
                    <Plus className="size-4" />
                )}
                {busy
                    ? 'Saving…'
                    : following
                      ? space.teams.length === 1
                          ? 'Following team'
                          : 'Following teams'
                      : space.teams.length === 1
                        ? 'Follow team'
                        : 'Follow teams'}
            </Button>
            {error && (
                <div role="alert" className="max-w-sm text-sm text-destructive">
                    <p>{error}</p>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.reload()}
                    >
                        Reload space
                    </Button>
                </div>
            )}
        </div>
    );
}

export function DepartmentSpace({
    content,
    space,
    data,
    preview = false,
    onEvent,
    onRecognise,
}: {
    content: HubContent;
    space: HubDepartment;
    data: HubProps;
    preview?: boolean;
    onEvent: (event: HubEvent) => void;
    onRecognise?: (userId?: number) => void;
}) {
    const [tab, setTab] = useState('overview');
    const [create, setCreate] = useState(false);
    const ids = space.teams.map((team) => team.id);
    const scoped = {
        ...data,
        pages: [...space.updates, ...space.resources],
        resourceItems: [],
        people: space.people,
        kudos: data.kudos.filter((post) =>
            post.team_ids?.some((id) => ids.includes(id)),
        ),
        events: data.events.filter((event) =>
            ids.includes(event.extendedProps.departmentId ?? 0),
        ),
    };
    const names = space.teams.map((team) => team.name).join(' and ');
    return (
        <section className="hub-department-space" aria-label="Department space">
            <div className="hub-department-owner">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="hub-person-avatar" aria-hidden>
                        <Building2 className="size-5" />
                    </span>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Space owner
                        </p>
                        {space.owner ? (
                            <Link
                                className="font-medium text-primary hover:underline"
                                href={`/company-hub/people/${space.owner.id}`}
                            >
                                {space.owner.name}
                            </Link>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No current owner contact is available.
                            </p>
                        )}
                        {names && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                {names}
                            </p>
                        )}
                    </div>
                </div>
                {!preview && space.teams.length > 0 && data.interests && (
                    <FollowTeams
                        key={`${space.actor_id}:${space.page_id}:${space.revision_id}`}
                        space={space}
                        initial={data.interests}
                    />
                )}
            </div>
            <div className="hub-department-tabs">
                <PageHeaderRail
                    ariaLabel="Department space views"
                    value={tab}
                    onSelect={setTab}
                    showFind={false}
                    items={[
                        { key: 'overview', label: 'Overview' },
                        {
                            key: 'updates',
                            label: 'Updates',
                            count: space.updates.length,
                        },
                        {
                            key: 'resources',
                            label: 'Resources',
                            count: space.resources.length,
                        },
                        {
                            key: 'people',
                            label: 'Our team',
                            count: space.people.length,
                        },
                    ]}
                />
                {!preview && space.can_create_update && (
                    <Button variant="ghost" onClick={() => setCreate(true)}>
                        <Plus className="size-4" />
                        New update
                    </Button>
                )}
            </div>
            {!space.teams.length && (
                <p className="hub-empty-note">
                    {preview || data.selectedPage?.can_edit
                        ? 'Choose related teams in Page settings to connect this space to its updates, resources and colleagues.'
                        : 'This space’s team details are not available yet.'}
                </p>
            )}
            <div
                className="hub-department-content"
                role="region"
                aria-label={`${tab === 'people' ? 'Our team' : tab[0].toUpperCase() + tab.slice(1)} content`}
            >
                {tab === 'overview' && (
                    <HubBlocks
                        sections={content.sections}
                        data={scoped}
                        onEvent={onEvent}
                        onRecognise={onRecognise}
                    />
                )}
                {tab === 'updates' && (
                    <NewsCards
                        pages={space.updates}
                        emptyMessage="Published stories from this team will appear here."
                    />
                )}
                {tab === 'resources' && (
                    <ResourceLibrary data={scoped} preview={preview} />
                )}
                {tab === 'people' && (
                    <PeopleDirectory
                        people={space.people}
                        canViewOrg={data.canViewOrg}
                    />
                )}
            </div>
            <footer className="hub-department-footer">
                <Link href="/company-hub?view=teams">
                    <ArrowLeft className="size-4" />
                    All team spaces
                </Link>
                <span>
                    {preview
                        ? 'Draft preview'
                        : `Published version ${space.version}`}
                </span>
            </footer>
            {!preview && (
                <CreateHubPageDialog
                    open={create}
                    close={() => setCreate(false)}
                    initial={{
                        title: `An update from ${names}`,
                        kind: 'news',
                        template: 'story',
                        source_page_id: space.page_id,
                        source_revision_id: space.revision_id,
                    }}
                    context={`Related to ${names}. This private draft starts with the space’s current audience. Review its content and audience before publishing.`}
                />
            )}
        </section>
    );
}
