import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Building2 } from 'lucide-react';
import { CompactPeople } from './_compact-people';
import { HubSelect } from './_select';
import { ServiceIcon } from './_service-icon';
import type { HubBlock, HubProps } from './_types';

export function blockPeople(block: HubBlock, data: HubProps) {
    return (data.blockData?.[block.id]?.people ?? data.people ?? []).filter(
        (person) =>
            (!block.team_ids?.length ||
                block.team_ids.includes(person.department_id ?? 0)) &&
            (!block.category || person.department === block.category),
    );
}

export function DynamicBlock({
    block,
    data,
}: {
    block: HubBlock;
    data: HubProps;
}) {
    if (block.type === 'people')
        return (
            <CompactPeople
                people={blockPeople(block, data)}
                finder={block.mode === 'finder'}
                limit={block.limit}
                demo={data.demo}
            />
        );
    const limit = block.limit ?? 4;
    const items =
        block.type === 'teams'
            ? (
                  data.blockData?.[block.id]?.teams ??
                  data.pages.filter((page) => page.kind === 'department')
              )
                  .filter(
                      (page) =>
                          !block.team_ids?.length ||
                          page.team_ids?.some((id) =>
                              block.team_ids!.includes(id),
                          ),
                  )
                  .slice(0, limit)
                  .map((page) => ({
                      id: page.id,
                      name: page.title,
                      description: page.summary,
                      category: page.category,
                      url: data.demo
                          ? `/company-hub/showcase?story=${page.slug}`
                          : `/company-hub/read/${page.slug}`,
                      cover_url: page.cover_url,
                  }))
            : (
                  data.blockData?.[block.id]?.services ??
                  data.searchServices ??
                  []
              )
                  .filter(
                      (service) =>
                          !block.category ||
                          service.category === block.category,
                  )
                  .slice(0, limit);
    return (
        <div className="space-y-3">
            <div
                className={
                    block.type === 'teams' && block.layout !== 'list'
                        ? 'hub-compact-team-grid'
                        : 'hub-compact-services'
                }
            >
                {items.map((item) => (
                    <Link
                        key={item.id}
                        href={item.url ?? '/company-hub?view=services'}
                        className={
                            block.type === 'teams' && block.layout !== 'list'
                                ? 'hub-compact-team'
                                : 'hub-dynamic-link'
                        }
                    >
                        {block.type === 'teams' &&
                        'cover_url' in item &&
                        item.cover_url ? (
                            <img
                                src={String(item.cover_url)}
                                alt=""
                                loading="lazy"
                                className={
                                    block.layout === 'list'
                                        ? 'size-12 shrink-0 rounded-lg object-cover'
                                        : 'hub-compact-team-photo'
                                }
                            />
                        ) : (
                            <span className="hub-dynamic-icon">
                                {block.type === 'teams' ? (
                                    <Building2 className="size-5" />
                                ) : (
                                    <ServiceIcon
                                        name={
                                            'icon' in item
                                                ? item.icon
                                                : undefined
                                        }
                                    />
                                )}
                            </span>
                        )}
                        <span className="min-w-0 flex-1">
                            <strong className="block text-sm font-semibold">
                                {item.name}
                            </strong>
                            <span className="mt-1 block text-xs text-muted-foreground">
                                {item.description || item.category}
                            </span>
                        </span>
                        <ArrowUpRight className="hub-compact-team-arrow size-4 shrink-0 text-muted-foreground" />
                    </Link>
                ))}
            </div>
            {!items.length && (
                <p className="hub-empty-state">
                    {block.type === 'teams'
                        ? 'No published team spaces match this selection.'
                        : 'No staff services match this selection.'}
                </p>
            )}
            <Button asChild variant="link" className="h-auto p-0 text-sm">
                <Link
                    href={`${data.demo ? '/company-hub/showcase' : '/company-hub'}?view=${block.type === 'teams' ? 'teams' : 'services'}`}
                >
                    {block.type === 'teams'
                        ? 'All team spaces'
                        : 'All staff services'}
                    <ArrowUpRight className="size-3.5" />
                </Link>
            </Button>
        </div>
    );
}

export function BlockDisplaySettings({
    block,
    data,
    onChange,
}: {
    block: HubBlock;
    data: HubProps;
    onChange: (update: Partial<HubBlock>) => void;
}) {
    return (
        <div className="space-y-4 border-b pb-4">
            <Label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    className="size-4 accent-primary"
                    checked={!block.hidden}
                    onChange={(event) =>
                        onChange({ hidden: !event.target.checked })
                    }
                />
                Show this block to staff
            </Label>
            <p className="text-xs text-muted-foreground">
                Hidden blocks stay in your design and version history. They are
                left out of the published staff view.
            </p>
            <Label className="grid gap-2">
                Minimum block height
                <div className="flex items-center gap-2">
                    <Input
                        type="number"
                        min={0}
                        max={800}
                        step={20}
                        value={block.min_height ?? 0}
                        onChange={(event) =>
                            onChange({
                                min_height: Math.max(
                                    0,
                                    Math.min(
                                        800,
                                        Math.round(
                                            Number(event.target.value) || 0,
                                        ),
                                    ),
                                ),
                            })
                        }
                    />
                    <span className="text-xs text-muted-foreground">px</span>
                </div>
            </Label>
            <p className="text-xs text-muted-foreground">
                Use 0 for automatic height. Content can always grow to fit.
            </p>
            {block.type === 'people' && (
                <Label className="grid gap-2">
                    Directory display
                    <HubSelect
                        label="Directory display"
                        value={block.mode ?? 'cards'}
                        onChange={(value) =>
                            onChange({
                                mode: value as 'cards' | 'finder',
                            })
                        }
                        options={[
                            { value: 'cards', label: 'People cards' },
                            {
                                value: 'finder',
                                label: 'Searchable people finder',
                            },
                        ]}
                    />
                </Label>
            )}
            {['people', 'teams', 'news', 'resources', 'campaign'].includes(
                block.type,
            ) && (
                <fieldset className="space-y-2">
                    <legend className="mb-2 text-sm font-medium">
                        Related teams
                    </legend>
                    <p className="text-xs text-muted-foreground">
                        Leave empty to show all available teams.
                    </p>
                    {(data.interests?.available_teams ?? []).map((team) => (
                        <Label
                            key={team.id}
                            className="flex items-center gap-2"
                        >
                            <input
                                type="checkbox"
                                className="size-4 accent-primary"
                                checked={
                                    block.team_ids?.includes(team.id) ?? false
                                }
                                onChange={(event) =>
                                    onChange({
                                        team_ids: event.target.checked
                                            ? [
                                                  ...(block.team_ids ?? []),
                                                  team.id,
                                              ]
                                            : (block.team_ids ?? []).filter(
                                                  (id) => id !== team.id,
                                              ),
                                    })
                                }
                            />
                            {team.name}
                        </Label>
                    ))}
                    {!data.interests?.available_teams.length && (
                        <p className="text-xs text-muted-foreground">
                            No teams are currently available.
                        </p>
                    )}
                </fieldset>
            )}
        </div>
    );
}
