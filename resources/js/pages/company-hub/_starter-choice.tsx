import {
    File,
    FileText,
    Hand,
    Image,
    Images,
    Library,
    MessageSquare,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { heroIcons } from './_hero-icons';
import { blockLibrary } from './_layout';
import type { HubStarterLayout, HubStarterTemplate } from './_types';

const icons: Record<string, ComponentType<{ className?: string }>> = {
    ...heroIcons,
    file: File,
    'file-text': FileText,
    hand: Hand,
    images: Images,
    library: Library,
    'message-square': MessageSquare,
};

export function StarterDescription({
    starter,
    illustrated = false,
}: {
    starter: HubStarterTemplate | HubStarterLayout;
    illustrated?: boolean;
}) {
    const Icon = icons[starter.icon ?? 'file'] ?? File;
    const blocks =
        starter.block_types ??
        ('sections' in starter
            ? starter.sections.flatMap((section) =>
                  section.blocks.map((block) => block.type),
              )
            : []);
    return (
        <span className="block min-w-0 flex-1 space-y-2">
            {illustrated && <StarterLayoutPreview blocks={blocks} />}
            <span className="flex items-center gap-2">
                <Icon className="size-5 shrink-0 text-primary" />
                <span className="font-semibold">{starter.label}</span>
            </span>
            {starter.purpose && (
                <span className="block text-xs leading-relaxed text-muted-foreground">
                    {starter.purpose}
                </span>
            )}
            <span
                className={
                    illustrated
                        ? 'sr-only'
                        : 'flex flex-wrap items-center gap-1 text-[11px] text-muted-foreground'
                }
                aria-label="Starting content"
            >
                {blocks.length
                    ? blocks.map((type, index) => (
                          <span
                              key={`${type}-${index}`}
                              className="rounded border bg-background px-1.5 py-0.5"
                          >
                              {blockLibrary.find(
                                  (item) => item[0] === type,
                              )?.[1] ?? type}
                          </span>
                      ))
                    : 'An empty canvas, ready for your ideas'}
            </span>
        </span>
    );
}

/** A small schematic of the server-defined composition, never fictional page content. */
function StarterLayoutPreview({ blocks }: { blocks: string[] }) {
    return (
        <span
            aria-hidden="true"
            className="mb-3 block h-20 overflow-hidden rounded-lg border border-primary/10 bg-background p-2.5 text-primary/60"
        >
            <span className="mb-2 flex h-4 items-center gap-1.5 rounded-sm bg-primary/10 px-2">
                <span className="size-1.5 rounded-full bg-primary/50" />
                <span className="h-1 w-12 rounded-full bg-primary/30" />
            </span>
            {!blocks.length ? (
                <span className="flex h-10 items-center justify-center rounded border border-dashed border-primary/20">
                    <span className="text-lg font-light">+</span>
                </span>
            ) : (
                <span className="flex h-10 gap-1.5">
                    {blocks.slice(0, 4).map((type, index) => (
                        <span
                            key={index}
                            className="flex min-w-0 flex-1 flex-col justify-center gap-1 rounded-sm bg-primary/5 p-1.5"
                        >
                            {type === 'image' ? (
                                <Image className="mx-auto size-6" />
                            ) : type === 'people' || type === 'shoutouts' ? (
                                <span className="flex justify-center gap-1">
                                    {[0, 1, 2].map((n) => (
                                        <span
                                            key={n}
                                            className="size-3 rounded-full bg-primary/25"
                                        />
                                    ))}
                                </span>
                            ) : type === 'campaign' ? (
                                <>
                                    <span className="h-1 w-4/5 rounded bg-primary/20" />
                                    <span className="h-1.5 rounded-full bg-primary/10">
                                        <span className="block h-full w-2/3 rounded-full bg-primary/40" />
                                    </span>
                                </>
                            ) : type === 'links' ||
                              type === 'resources' ||
                              type === 'services' ? (
                                [0, 1, 2].map((n) => (
                                    <span
                                        key={n}
                                        className="flex items-center gap-1"
                                    >
                                        <span className="size-1.5 rounded-sm bg-primary/30" />
                                        <span className="h-1 flex-1 rounded bg-primary/15" />
                                    </span>
                                ))
                            ) : (
                                <>
                                    <span className="h-1 w-3/4 rounded bg-primary/30" />
                                    <span className="h-1 w-full rounded bg-primary/15" />
                                    <span className="h-1 w-2/3 rounded bg-primary/15" />
                                </>
                            )}
                        </span>
                    ))}
                </span>
            )}
        </span>
    );
}
