import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { HubMedia } from './_media-library';
import { MediaUpload } from './_media-picker';
import { HubSelect } from './_select';
import type { HubBlock, HubProps } from './_types';

export function BlockContentSettings({
    block,
    data,
    pageId,
    onChange,
    onMedia,
}: {
    block: HubBlock;
    data: HubProps;
    pageId: number;
    onChange: (value: Partial<HubBlock>) => void;
    onMedia: (media: HubMedia) => void;
}) {
    const stories = data.pages.filter(
        (page) => page.kind === 'news' && page.show_in_news !== false,
    );
    const chosen = stories.find((story) => story.id === block.story_id);
    return (
        <div className="space-y-4">
            <Label className="grid gap-2">
                Block background
                <HubSelect
                    label="Block background"
                    value={block.tone ?? 'plain'}
                    onChange={(value) =>
                        onChange({
                            tone: value as 'plain' | 'soft',
                        })
                    }
                    options={[
                        { value: 'plain', label: 'Plain' },
                        { value: 'soft', label: 'Soft tint' },
                    ]}
                />
            </Label>
            {['featured', 'news', 'links', 'resources', 'teams'].includes(
                block.type,
            ) && (
                <Label className="grid gap-2">
                    Presentation
                    <HubSelect
                        label="Presentation"
                        value={
                            block.layout ??
                            (block.type === 'featured'
                                ? 'mosaic'
                                : block.type === 'resources'
                                  ? 'list'
                                  : 'cards')
                        }
                        onChange={(value) =>
                            onChange({
                                layout: value as HubBlock['layout'],
                            })
                        }
                        options={
                            block.type === 'featured'
                                ? [
                                      {
                                          value: 'mosaic',
                                          label: 'Large story with two supporting tiles',
                                      },
                                      {
                                          value: 'equal',
                                          label: 'Equal columns',
                                      },
                                      {
                                          value: 'single',
                                          label: 'One featured story',
                                      },
                                  ]
                                : [
                                      { value: 'cards', label: 'Cards' },
                                      { value: 'list', label: 'List' },
                                  ]
                        }
                    />
                </Label>
            )}
            {block.type === 'featured' && (
                <>
                    <Label className="grid gap-2">
                        Featured story
                        <HubSelect
                            label="Featured story"
                            value={String(block.story_id ?? '')}
                            onChange={(value) => {
                                const story = stories.find(
                                    (page) => String(page.id) === value,
                                );
                                onChange({
                                    story_id: story?.id ?? null,
                                    title: story?.title ?? '',
                                    body: story?.summary ?? '',
                                    media_id: null,
                                    alt: '',
                                });
                            }}
                            options={[
                                { value: '', label: 'Latest available story' },
                                ...(block.story_id && !chosen
                                    ? [
                                          {
                                              value: String(block.story_id),
                                              label: 'Selected story is unavailable',
                                              disabled: true,
                                          },
                                      ]
                                    : []),
                                ...stories.map((story) => ({
                                    value: String(story.id),
                                    label: story.title,
                                })),
                            ]}
                        />
                    </Label>
                    <p className="text-xs text-muted-foreground">
                        Choose a story to tailor its heading, message and photo.
                        If it becomes unavailable to a reader, they see the
                        latest story they can access.
                    </p>
                    <Label className="grid gap-2">
                        Featured message
                        <Textarea
                            rows={3}
                            disabled={!chosen}
                            value={block.body ?? ''}
                            maxLength={600}
                            onChange={(event) =>
                                onChange({ body: event.target.value })
                            }
                            placeholder="Use the story summary"
                        />
                    </Label>
                    {chosen && (
                        <MediaUpload
                            key={block.id}
                            pageId={pageId}
                            mediaId={block.media_id}
                            label="Featured photo"
                            onChange={(id, media) => {
                                if (media) onMedia(media);
                                onChange({
                                    media_id: id,
                                    alt: media?.alt ?? '',
                                });
                            }}
                        />
                    )}
                </>
            )}
            {['notice', 'button', 'video', 'file'].includes(block.type) && (
                <Label className="grid gap-2">
                    {['video', 'file'].includes(block.type)
                        ? 'Description'
                        : 'Message'}
                    <Textarea
                        rows={4}
                        maxLength={20000}
                        value={block.body ?? ''}
                        onChange={(event) =>
                            onChange({ body: event.target.value })
                        }
                    />
                </Label>
            )}
            {block.type === 'notice' && (
                <p className="text-xs text-muted-foreground">
                    A message within this page. Use Important notices for
                    company announcements and read confirmations.
                </p>
            )}
            {block.type === 'button' && (
                <>
                    <Label className="grid gap-2">
                        Button label
                        <Input
                            value={block.action_label ?? ''}
                            maxLength={60}
                            onChange={(event) =>
                                onChange({ action_label: event.target.value })
                            }
                        />
                    </Label>
                    <Label className="grid gap-2">
                        Button destination
                        <Input
                            value={block.url ?? ''}
                            maxLength={2000}
                            placeholder="https://… or /internal-page"
                            onChange={(event) =>
                                onChange({ url: event.target.value })
                            }
                        />
                    </Label>
                </>
            )}
            {block.type === 'image' && (
                <Label className="grid gap-2">
                    Image caption
                    <Textarea
                        rows={2}
                        maxLength={20000}
                        value={block.body ?? ''}
                        onChange={(event) =>
                            onChange({ body: event.target.value })
                        }
                        placeholder="Optional caption below the photo"
                    />
                </Label>
            )}
        </div>
    );
}
