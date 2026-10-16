import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/datetime';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { HubSelect } from './_select';
import type { HubContent, HubProps } from './_types';

type Campaign = NonNullable<HubContent['campaign']>;

function move<T>(items: T[], from: number, to: number): T[] {
    const next = [...items];
    next.splice(to, 0, next.splice(from, 1)[0]);
    return next;
}

function OrderButtons({
    index,
    count,
    label,
    reorder,
    remove,
}: {
    index: number;
    count: number;
    label: string;
    reorder: (to: number) => void;
    remove: () => void;
}) {
    return (
        <div className="flex shrink-0 gap-1">
            <Button
                variant="ghost"
                size="icon"
                aria-label={`Move ${label} up`}
                disabled={index === 0}
                onClick={() => reorder(index - 1)}
            >
                <ArrowUp className="size-3.5" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                aria-label={`Move ${label} down`}
                disabled={index === count - 1}
                onClick={() => reorder(index + 1)}
            >
                <ArrowDown className="size-3.5" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                aria-label={`Remove ${label}`}
                onClick={remove}
            >
                <Trash2 className="size-3.5" />
            </Button>
        </div>
    );
}

function RelatedPicker({
    label,
    selected,
    options,
    onChange,
}: {
    label: string;
    selected: string[];
    options: Array<{ id: string; title: string }>;
    onChange: (ids: string[]) => void;
}) {
    return (
        <fieldset className="space-y-2">
            <legend className="mb-2 text-sm font-medium">{label}</legend>
            <ol className="space-y-2">
                {selected.map((id, index) => {
                    const title = options.find(
                        (option) => option.id === id,
                    )?.title;
                    return (
                        <li
                            key={id}
                            className="flex items-center gap-2 rounded-md border p-2"
                        >
                            <span className="min-w-0 flex-1 text-xs">
                                {title ??
                                    'No longer available — remove this selection'}
                            </span>
                            <OrderButtons
                                index={index}
                                count={selected.length}
                                label={`${label.toLowerCase()} ${index + 1}`}
                                reorder={(to) =>
                                    onChange(move(selected, index, to))
                                }
                                remove={() =>
                                    onChange(
                                        selected.filter(
                                            (value) => value !== id,
                                        ),
                                    )
                                }
                            />
                        </li>
                    );
                })}
            </ol>
            <HubSelect
                label={`Add ${label.toLowerCase()}`}
                value=""
                disabled={selected.length >= 12}
                onChange={(value) => {
                    if (value) onChange([...selected, value]);
                }}
                options={[
                    {
                        value: '',
                        label:
                            selected.length >= 12
                                ? '12 selected'
                                : `Choose ${label.toLowerCase()}…`,
                    },
                    ...options
                        .filter((option) => !selected.includes(option.id))
                        .map((option) => ({
                            value: option.id,
                            label: option.title,
                        })),
                ]}
            />
            {!options.length && (
                <p className="text-xs text-muted-foreground">
                    Nothing currently available to select.
                </p>
            )}
        </fieldset>
    );
}

export function CampaignCuration({
    value,
    data,
    onChange,
}: {
    value: HubContent['campaign'];
    data: HubProps;
    onChange: (value: Campaign) => void;
}) {
    const milestones = value?.milestones ?? [];
    const update = (patch: Partial<Campaign>) =>
        onChange({ ends_on: null, steps: [], ...value, ...patch });
    return (
        <>
            <section
                className="space-y-4 border-t pt-4"
                aria-label="Shared campaign milestones"
            >
                <h3 className="font-semibold">Shared milestones</h3>
                <p className="text-xs text-muted-foreground">
                    Show everyone what’s complete, what’s happening and what
                    comes next. These milestones describe the company’s
                    progress.
                </p>
                {milestones.map((milestone, index) => (
                    <div
                        key={milestone.id}
                        className="space-y-2 rounded-lg border p-3"
                    >
                        <Label className="grid gap-2">
                            Milestone {index + 1}
                            <Input
                                maxLength={200}
                                value={milestone.title}
                                onChange={(event) =>
                                    update({
                                        milestones: milestones.map((item) =>
                                            item.id === milestone.id
                                                ? {
                                                      ...item,
                                                      title: event.target.value,
                                                  }
                                                : item,
                                        ),
                                    })
                                }
                            />
                        </Label>
                        <div className="flex items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <HubSelect
                                    label={`Milestone ${index + 1} status`}
                                    value={milestone.status}
                                    onChange={(value) =>
                                        update({
                                            milestones: milestones.map(
                                                (item) =>
                                                    item.id === milestone.id
                                                        ? {
                                                              ...item,
                                                              status: value as
                                                                  | 'done'
                                                                  | 'active'
                                                                  | 'next',
                                                          }
                                                        : item,
                                            ),
                                        })
                                    }
                                    options={[
                                        { value: 'done', label: 'Complete' },
                                        {
                                            value: 'active',
                                            label: 'In progress',
                                        },
                                        { value: 'next', label: 'Up next' },
                                    ]}
                                />
                            </div>
                            <OrderButtons
                                index={index}
                                count={milestones.length}
                                label={`milestone ${index + 1}`}
                                reorder={(to) =>
                                    update({
                                        milestones: move(milestones, index, to),
                                    })
                                }
                                remove={() =>
                                    update({
                                        milestones: milestones.filter(
                                            (item) => item.id !== milestone.id,
                                        ),
                                    })
                                }
                            />
                        </div>
                    </div>
                ))}
                <Button
                    variant="outline"
                    disabled={milestones.length >= 12}
                    onClick={() =>
                        update({
                            milestones: [
                                ...milestones,
                                {
                                    id: crypto.randomUUID(),
                                    title: 'New milestone',
                                    status: 'next',
                                },
                            ],
                        })
                    }
                >
                    <Plus className="size-4" />
                    Add milestone
                </Button>
            </section>
            <section
                className="space-y-5 border-t pt-4"
                aria-label="Campaign related content"
            >
                <h3 className="font-semibold">Bring the initiative together</h3>
                <p className="text-xs text-muted-foreground">
                    Choose published content and upcoming events. Staff see only
                    items available to them. Use the arrows to set the order.
                </p>
                <RelatedPicker
                    label="Stories"
                    selected={(value?.story_ids ?? []).map(String)}
                    options={data.pages
                        .filter((page) => page.kind === 'news')
                        .map((page) => ({
                            id: String(page.id),
                            title: page.title,
                        }))}
                    onChange={(ids) => update({ story_ids: ids.map(Number) })}
                />
                <RelatedPicker
                    label="Resources"
                    selected={(value?.resource_ids ?? []).map(String)}
                    options={data.pages
                        .filter((page) =>
                            ['page', 'knowledge'].includes(page.kind),
                        )
                        .map((page) => ({
                            id: String(page.id),
                            title: page.title,
                        }))}
                    onChange={(ids) =>
                        update({ resource_ids: ids.map(Number) })
                    }
                />
                <RelatedPicker
                    label="Events"
                    selected={value?.event_keys ?? []}
                    options={data.events
                        .filter((event) => Date.parse(event.end) > Date.now())
                        .map((event) => ({
                            id: event.id,
                            title: `${event.title} · ${formatDateTime(event.start)}`,
                        }))}
                    onChange={(event_keys) => update({ event_keys })}
                />
                <p className="text-xs text-muted-foreground">
                    Event choices cover the next 90 days. Recurring events are
                    selected by occurrence.
                </p>
            </section>
        </>
    );
}
