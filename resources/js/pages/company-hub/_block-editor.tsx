import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { BlockContentSettings } from './_block-content-settings';
import { BlockDisplaySettings } from './_dynamic-blocks';
import { blockLibrary } from './_layout';
import type { HubMedia } from './_media-library';
import { MediaUpload } from './_media-picker';
import { PollDeadline } from './_poll-time';
import { HubRichTextEditor } from './_rich-text';
import { HubSelect } from './_select';
import type { HubBlock, HubProps } from './_types';

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block space-y-2">
            <span className="text-sm font-medium">{label}</span>
            {children}
        </label>
    );
}

export function BlockEditor({
    data,
    block,
    pageId,
    onMedia,
    onChange,
    onRemove,
    onMove,
    first,
    last,
    configuring = false,
}: {
    data: HubProps;
    block: HubBlock;
    pageId: number;
    onMedia: (media: HubMedia) => void;
    onChange: (update: Partial<HubBlock>) => void;
    onRemove: () => void;
    onMove: (direction: number) => void;
    first: boolean;
    last: boolean;
    configuring?: boolean;
}) {
    return (
        <Card
            unstyled
            className="space-y-4 rounded-lg border border-border bg-background p-4"
        >
            {!configuring && (
                <div className="flex items-center gap-2">
                    <span className="text-caption mr-auto font-semibold text-primary">
                        {blockLibrary.find(
                            (item) => item[0] === block.type,
                        )?.[1] ?? block.type}
                    </span>
                    <Button
                        size="icon"
                        variant="ghost"
                        aria-label="Move block up"
                        disabled={first}
                        onClick={() => onMove(-1)}
                    >
                        <ArrowUp className="size-4" />
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
                        aria-label="Move block down"
                        disabled={last}
                        onClick={() => onMove(1)}
                    >
                        <ArrowDown className="size-4" />
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
                        aria-label="Remove block"
                        onClick={onRemove}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            )}
            <Field label="Heading">
                <Input
                    maxLength={160}
                    value={block.title ?? ''}
                    disabled={
                        block.type === 'featured' &&
                        !data.pages.some(
                            (page) =>
                                page.id === block.story_id &&
                                page.kind === 'news',
                        )
                    }
                    placeholder={
                        block.type === 'featured'
                            ? 'Use the story heading'
                            : undefined
                    }
                    onChange={(e) => onChange({ title: e.target.value })}
                />
            </Field>
            <BlockDisplaySettings
                block={block}
                data={data}
                onChange={onChange}
            />
            <BlockContentSettings
                block={block}
                data={data}
                pageId={pageId}
                onChange={onChange}
                onMedia={onMedia}
            />
            {block.type === 'text' && (
                <HubRichTextEditor
                    body={block.body ?? ''}
                    value={block.rich_text}
                    onChange={(rich_text, body) =>
                        onChange({ rich_text, body })
                    }
                />
            )}
            {block.type === 'image' && (
                <>
                    <MediaUpload
                        key={block.id}
                        pageId={pageId}
                        mediaId={block.media_id}
                        onChange={(id, media) => {
                            if (media) onMedia(media);
                            onChange({
                                media_id: id,
                                alt: media?.alt ?? '',
                                ...(media &&
                                id !== block.media_id &&
                                !block.body?.trim()
                                    ? { body: media.caption ?? '' }
                                    : {}),
                            });
                        }}
                        label="Image"
                    />
                    <Field label="Describe the image for screen readers">
                        <Input
                            value={block.alt ?? ''}
                            maxLength={300}
                            onChange={(e) => onChange({ alt: e.target.value })}
                        />
                    </Field>
                </>
            )}
            {block.type === 'file' && (
                <MediaUpload
                    key={block.id}
                    pageId={pageId}
                    mediaId={block.media_id}
                    onChange={(id) => onChange({ media_id: id })}
                    label="Downloadable file"
                    file
                />
            )}
            {block.type === 'video' && (
                <Field label="YouTube or Vimeo link">
                    <Input
                        type="url"
                        maxLength={2000}
                        value={block.url ?? ''}
                        onChange={(e) => onChange({ url: e.target.value })}
                    />
                </Field>
            )}
            {block.type === 'poll' && (
                <div className="space-y-3">
                    <Field label="Question">
                        <Input
                            maxLength={300}
                            value={block.question ?? ''}
                            onChange={(e) =>
                                onChange({ question: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Answers (one per line, 2–8)">
                        <Textarea
                            rows={4}
                            value={(block.options ?? []).join('\n')}
                            onChange={(e) =>
                                onChange({
                                    options: e.target.value.split('\n'),
                                })
                            }
                        />
                    </Field>
                    <PollDeadline
                        value={block.closes_at}
                        onChange={(closes_at) => onChange({ closes_at })}
                    />
                    <p className="text-sm text-muted-foreground">
                        Changing the question or answers starts a new poll.
                        Existing votes stay with the original question.
                    </p>
                </div>
            )}
            {[
                'news',
                'events',
                'shoutouts',
                'celebrations',
                'resources',
                'people',
                'teams',
                'services',
                'campaign',
                'notices',
            ].includes(block.type) && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Items to show">
                        <Input
                            type="number"
                            min={1}
                            max={12}
                            value={
                                block.limit ??
                                (block.type === 'campaign'
                                    ? 1
                                    : block.type === 'notices'
                                      ? 6
                                      : 4)
                            }
                            onChange={(e) =>
                                onChange({ limit: Number(e.target.value) })
                            }
                        />
                    </Field>
                    {['news', 'resources', 'people', 'services'].includes(
                        block.type,
                    ) && (
                        <Field
                            label={
                                block.type === 'people'
                                    ? 'Department filter (optional)'
                                    : 'Category filter (optional)'
                            }
                        >
                            <Input
                                value={block.category ?? ''}
                                maxLength={60}
                                onChange={(e) =>
                                    onChange({ category: e.target.value })
                                }
                            />
                        </Field>
                    )}
                </div>
            )}
            {block.type === 'links' && (
                <div className="space-y-4">
                    {(block.links ?? []).map((link, i) => (
                        <div
                            key={link.id}
                            className="space-y-2 border-t border-border pt-3"
                        >
                            <div className="flex items-center gap-2">
                                <Input
                                    aria-label={`Link ${i + 1} title`}
                                    maxLength={100}
                                    value={link.title ?? ''}
                                    placeholder="Link title"
                                    onChange={(e) =>
                                        onChange({
                                            links: block.links?.map((item) =>
                                                item.id === link.id
                                                    ? {
                                                          ...item,
                                                          title: e.target.value,
                                                      }
                                                    : item,
                                            ),
                                        })
                                    }
                                />
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove ${link.title || 'link'}`}
                                    onClick={() =>
                                        onChange({
                                            links: block.links?.filter(
                                                (item) => item.id !== link.id,
                                            ),
                                        })
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                            <Input
                                aria-label={`Link ${i + 1} address`}
                                maxLength={2000}
                                value={link.url ?? ''}
                                placeholder="https://… or /internal-page"
                                onChange={(e) =>
                                    onChange({
                                        links: block.links?.map((item) =>
                                            item.id === link.id
                                                ? {
                                                      ...item,
                                                      url: e.target.value,
                                                  }
                                                : item,
                                        ),
                                    })
                                }
                            />
                            <Input
                                aria-label={`Link ${i + 1} description`}
                                value={link.description ?? ''}
                                maxLength={200}
                                placeholder="What will people find here?"
                                onChange={(event) =>
                                    onChange({
                                        links: block.links?.map((item) =>
                                            item.id === link.id
                                                ? {
                                                      ...item,
                                                      description:
                                                          event.target.value,
                                                  }
                                                : item,
                                        ),
                                    })
                                }
                            />
                            <HubSelect
                                label={`Link ${i + 1} company page`}
                                value=""
                                onChange={(value) => {
                                    const page = data.pages.find(
                                        (item) => String(item.id) === value,
                                    );
                                    if (page)
                                        onChange({
                                            links: block.links?.map((item) =>
                                                item.id === link.id
                                                    ? {
                                                          ...item,
                                                          title: page.title.slice(
                                                              0,
                                                              100,
                                                          ),
                                                          description:
                                                              page.summary.slice(
                                                                  0,
                                                                  200,
                                                              ),
                                                          url: data.demo
                                                              ? `/company-hub/showcase?story=${page.slug}`
                                                              : `/company-hub/read/${page.slug}`,
                                                      }
                                                    : item,
                                            ),
                                        });
                                }}
                                options={[
                                    {
                                        value: '',
                                        label: 'Choose a company page…',
                                    },
                                    ...data.pages.map((page) => ({
                                        value: String(page.id),
                                        label: page.title,
                                    })),
                                ]}
                            />
                        </div>
                    ))}
                    <Button
                        variant="outline"
                        disabled={(block.links?.length ?? 0) >= 24}
                        onClick={() =>
                            onChange({
                                links: [
                                    ...(block.links ?? []),
                                    {
                                        id: crypto.randomUUID(),
                                        title: '',
                                        url: '',
                                    },
                                ],
                            })
                        }
                    >
                        <Plus className="size-4" />
                        Add link
                    </Button>
                </div>
            )}
        </Card>
    );
}
