import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { ArrowLeft, Eye, Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { blockDetails } from './_block-catalog';
import { BlockEditor } from './_block-editor';
import { previewEditorialBlocks, previewSourceData } from './_block-preview';
import { HubBlocks } from './_blocks';
import { blockLibrary, createBlock } from './_layout';
import type { HubMedia } from './_media-library';
import type { HubBlock, HubProps, HubSection } from './_types';

const groups = [
    'All blocks',
    'Stories & media',
    'People & community',
    'Everyday essentials',
];
function safeDestination(value = '') {
    if (
        !value ||
        /[\s\\]/.test(value) ||
        Array.from(value).some((character) => {
            const code = character.charCodeAt(0);
            return code < 32 || code === 127;
        }) ||
        value.length > 2000
    )
        return false;
    if (value.startsWith('/') && !value.startsWith('//')) return true;
    try {
        const url = new URL(value);
        return (
            value.startsWith('https://') &&
            url.protocol === 'https:' &&
            !!url.hostname &&
            !url.username &&
            !url.password
        );
    } catch {
        return false;
    }
}

/** Completeness guidance for insertion; the canonical save/publish checks still apply. */
export function blockSetupIssue(
    block: HubBlock,
    data: HubProps,
    sourceTeamIds?: number[],
    allowIncomplete = false,
): string | null {
    if (block.title.length > 160)
        return 'Keep the heading to 160 characters or fewer.';
    const bodyLimit = block.type === 'featured' ? 600 : 20000;
    if ((block.body?.length ?? 0) > bodyLimit)
        return `Keep the text to ${bodyLimit.toLocaleString()} characters or fewer.`;
    if ((block.alt?.length ?? 0) > 300)
        return 'Keep the image description to 300 characters or fewer.';
    if ((block.category?.length ?? 0) > 60)
        return 'Keep the category to 60 characters or fewer.';
    if ((block.action_label?.length ?? 0) > 60)
        return 'Keep the button label to 60 characters or fewer.';
    if (block.url && !safeDestination(block.url))
        return 'Use a valid HTTPS or internal destination.';
    if ((block.links?.length ?? 0) > 24)
        return 'Use no more than 24 links in one block.';
    if (
        block.links?.some(
            (link) =>
                link.title.length > 100 ||
                (link.description?.length ?? 0) > 200,
        )
    )
        return 'Keep link titles to 100 characters and descriptions to 200 characters or fewer.';
    if (block.links?.some((link) => link.url && !safeDestination(link.url)))
        return 'Use a valid HTTPS or internal destination for each link.';
    const answers = (block.options ?? []).map((answer) =>
        answer.trim().toLocaleLowerCase(),
    );
    const filledAnswers = answers.filter(Boolean);
    if (
        (block.question?.length ?? 0) > 300 ||
        answers.length > 8 ||
        block.options?.some((answer) => answer.length > 120) ||
        new Set(filledAnswers).size !== filledAnswers.length
    )
        return 'Use a question up to 300 characters and at most 8 different answers, each up to 120 characters.';
    if (
        block.limit !== undefined &&
        (!Number.isInteger(block.limit) || block.limit < 1 || block.limit > 12)
    )
        return 'Choose between 1 and 12 items.';
    if (
        block.type === 'video' &&
        block.url &&
        ![
            'www.youtube.com',
            'www.youtube-nocookie.com',
            'player.vimeo.com',
        ].includes(block.url.slice('https://'.length).split(/[/:?#]/)[0])
    )
        return 'Use a video link from www.youtube.com, www.youtube-nocookie.com or player.vimeo.com.';
    if (
        block.type === 'featured' &&
        block.story_id &&
        !previewSourceData(data, sourceTeamIds).pages.some(
            (story) =>
                story.id === block.story_id &&
                story.kind === 'news' &&
                story.show_in_news !== false,
        )
    )
        return 'Choose a currently available published story, or use the latest available story.';
    if (allowIncomplete) return null;
    if (block.type !== 'featured' && !block.title.trim())
        return 'Give this block a heading.';
    if (['text', 'notice'].includes(block.type) && !block.body?.trim())
        return 'Add the words you want staff to read.';
    if (['image', 'file'].includes(block.type) && !block.media_id)
        return `Choose ${block.type === 'image' ? 'an image' : 'a file'} for this block.`;
    if (
        block.type === 'button' &&
        (!block.action_label?.trim() || !safeDestination(block.url))
    )
        return 'Add a button label and a valid HTTPS or internal destination.';
    if (
        block.type === 'links' &&
        (!block.links?.length ||
            block.links.some(
                (link) => !link.title.trim() || !safeDestination(link.url),
            ))
    )
        return 'Add at least one link, with a title and valid HTTPS or internal destination for every row.';
    if (block.type === 'video' && !block.url)
        return 'Add an approved YouTube or Vimeo video link.';
    if (block.type === 'poll') {
        if (
            !block.question?.trim() ||
            answers.length < 2 ||
            answers.length > 8 ||
            answers.some((answer) => !answer || answer.length > 120) ||
            new Set(answers).size !== answers.length
        )
            return 'Add a question and 2–8 different answers, each up to 120 characters.';
    }
    return null;
}

export function BlockLibraryPicker({
    section,
    data,
    pageId,
    sourceTeamIds,
    onMedia,
    onAdd,
    onClose,
    onDirtyChange,
}: {
    section: HubSection;
    data: HubProps;
    pageId: number;
    sourceTeamIds?: number[];
    onMedia: (media: HubMedia) => void;
    onAdd: (block: HubBlock) => void;
    onClose: () => void;
    onDirtyChange: (dirty: boolean) => void;
}) {
    const [group, setGroup] = useState('All blocks');
    const [query, setQuery] = useState('');
    const [setup, setSetup] = useState<{
        original: string;
        block: HubBlock;
    } | null>(null);
    const [discard, setDiscard] = useState<'library' | 'close' | null>(null);
    const [error, setError] = useState('');
    const heading = useRef<HTMLHeadingElement>(null),
        added = useRef(false);
    const dirty = !!setup && JSON.stringify(setup.block) !== setup.original;
    const full = section.blocks.length >= 12;
    const choices = blockLibrary.filter(
        ([type, label]) =>
            (group === 'All blocks' || blockDetails[type].group === group) &&
            `${label} ${blockDetails[type].description}`
                .toLocaleLowerCase()
                .includes(query.trim().toLocaleLowerCase()),
    );
    useEffect(() => {
        heading.current?.focus();
    }, [setup?.block.id]);
    const change = (update: Partial<HubBlock>) => {
        if (!setup || discard) return;
        const block = { ...setup.block, ...update };
        setSetup({ ...setup, block });
        setError('');
        onDirtyChange(JSON.stringify(block) !== setup.original);
    };
    const leave = (destination: 'library' | 'close') => {
        if (dirty) {
            setDiscard(destination);
            return;
        }
        finishLeave(destination);
    };
    const finishLeave = (destination: 'library' | 'close') => {
        setSetup(null);
        setError('');
        setDiscard(null);
        onDirtyChange(false);
        if (destination === 'close') onClose();
    };
    const insert = (incomplete: boolean) => {
        if (!setup || added.current || full || discard) return;
        const issue = blockSetupIssue(
            setup.block,
            data,
            sourceTeamIds,
            incomplete,
        );
        if (issue) {
            setError(issue);
            return;
        }
        added.current = true;
        onDirtyChange(false);
        onAdd(setup.block);
    };
    const previewSections: HubSection[] = setup
        ? [
              {
                  ...section,
                  id: 'block-setup-preview',
                  columns: 1,
                  blocks: [{ ...setup.block, width: 12, hidden: false }],
              },
          ]
        : [];
    const sourceData = previewSourceData(data, sourceTeamIds);
    const previewData: HubProps = {
        ...sourceData,
        participation: null,
        capabilities: { ...data.capabilities, recognise: false },
        blockData: previewEditorialBlocks(
            previewSections,
            sourceData,
            sourceTeamIds,
        ),
    };
    const settingsData = setup?.block.type === 'featured' ? sourceData : data;
    return (
        <section
            aria-label="Block library"
            className="space-y-6 rounded-2xl border bg-card p-6"
        >
            <header className="flex items-start justify-between gap-6">
                <div>
                    <p className="mb-2 text-xs font-semibold tracking-wide text-primary uppercase">
                        Page designer · {section.title || 'This section'}
                    </p>
                    <h2
                        ref={heading}
                        tabIndex={-1}
                        className="text-2xl font-semibold outline-none"
                    >
                        {setup
                            ? `Configure ${blockLibrary.find(([type]) => type === setup.block.type)?.[1] ?? 'block'}`
                            : 'Find the right block'}
                    </h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {setup
                            ? 'Make it your own and see how it will read before adding it to the page.'
                            : 'Bring stories, people and everyday essentials together.'}
                    </p>
                </div>
                <Button
                    variant="outline"
                    disabled={!!discard}
                    onClick={() => leave('close')}
                >
                    Close library
                </Button>
            </header>
            {full && (
                <p role="status" className="rounded-lg bg-muted/40 p-4 text-sm">
                    This section has 12 blocks. Remove a block or add another
                    section.
                </p>
            )}
            {discard && (
                <div
                    role="alert"
                    className="flex items-center justify-between gap-5 rounded-xl border p-4"
                >
                    <div>
                        <h3 className="font-semibold">
                            Discard this block setup?
                        </h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            This block has not been added. The rest of your page
                            stays as it is.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setDiscard(null)}
                        >
                            Keep configuring
                        </Button>
                        <Button onClick={() => finishLeave(discard)}>
                            Discard setup
                        </Button>
                    </div>
                </div>
            )}
            {setup ? (
                <>
                    <Button
                        variant="ghost"
                        disabled={!!discard}
                        onClick={() => leave('library')}
                    >
                        <ArrowLeft className="size-4" />
                        All blocks
                    </Button>
                    <div className="grid grid-cols-[minmax(300px,380px)_minmax(0,1fr)] items-start gap-6">
                        <fieldset disabled={!!discard} className="min-w-0">
                            <legend className="sr-only">Block settings</legend>
                            <BlockEditor
                                configuring
                                data={settingsData}
                                block={setup.block}
                                pageId={pageId}
                                onChange={change}
                                onMedia={onMedia}
                                onRemove={() => {}}
                                onMove={() => {}}
                                first
                                last
                            />
                        </fieldset>
                        <Card className="sticky top-6 min-w-0 gap-0 overflow-hidden p-0">
                            <div className="flex items-center gap-2 border-b px-5 py-4 text-sm font-semibold">
                                <Eye className="size-4 text-primary" />
                                Live preview
                            </div>
                            <div
                                aria-label="Block preview"
                                className="min-h-64 p-5"
                                onClickCapture={(event) => {
                                    event.preventDefault();
                                    event.stopPropagation();
                                }}
                                onKeyDownCapture={(event) => {
                                    if (
                                        event.key === 'Enter' ||
                                        event.key === ' '
                                    ) {
                                        event.preventDefault();
                                        event.stopPropagation();
                                    }
                                }}
                            >
                                <div inert>
                                    <HubBlocks
                                        preview
                                        sections={previewSections}
                                        data={previewData}
                                        onEvent={() => {}}
                                    />
                                </div>
                            </div>
                            <p className="border-t px-5 py-4 text-xs leading-5 text-muted-foreground">
                                Preview uses content you can access. Links and
                                participation are inactive here. The block will
                                fit its destination section after insertion.
                            </p>
                        </Card>
                    </div>
                    {error && (
                        <p
                            role="alert"
                            className="rounded-lg border border-destructive/30 p-4 text-sm text-destructive"
                        >
                            {error}
                        </p>
                    )}
                    <footer className="flex items-center justify-between gap-5 border-t pt-5">
                        <p className="max-w-md text-sm text-muted-foreground">
                            Adding a block changes your draft. It becomes
                            visible to staff when the page is published.
                        </p>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                disabled={full || !!discard}
                                onClick={() => insert(true)}
                            >
                                Add unconfigured block
                            </Button>
                            <Button
                                disabled={full || !!discard}
                                onClick={() => insert(false)}
                            >
                                <Plus className="size-4" />
                                Add to page
                            </Button>
                        </div>
                    </footer>
                </>
            ) : (
                <>
                    <div className="relative max-w-lg">
                        <Search className="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground" />
                        <Input
                            aria-label="Search blocks"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search blocks by name or purpose…"
                            className="pl-9"
                        />
                    </div>
                    <nav
                        className="hub-library-filters"
                        aria-label="Block categories"
                    >
                        {groups.map((name) => (
                            <Button
                                key={name}
                                size="sm"
                                variant={name === group ? 'secondary' : 'ghost'}
                                aria-pressed={name === group}
                                onClick={() => setGroup(name)}
                            >
                                {name}
                            </Button>
                        ))}
                    </nav>
                    <div className="hub-block-library-grid">
                        {choices.map(([type, label]) => {
                            const item = blockDetails[type],
                                Icon = item.icon;
                            return (
                                <Button
                                    key={type}
                                    variant="outline"
                                    className="hub-library-choice h-auto gap-0 p-0 whitespace-normal"
                                    disabled={full}
                                    aria-label={`Configure ${label}`}
                                    onClick={() => {
                                        const block = createBlock(type);
                                        setSetup({
                                            original: JSON.stringify(block),
                                            block,
                                        });
                                        setError('');
                                    }}
                                >
                                    <span
                                        className="hub-library-preview"
                                        data-preview={item.preview}
                                        aria-hidden="true"
                                    >
                                        <Icon className="size-5" />
                                        <i />
                                        <i />
                                        <i />
                                    </span>
                                    <span className="hub-library-choice-copy">
                                        <strong>{label}</strong>
                                        <small>{item.description}</small>
                                    </span>
                                    <Plus
                                        className="hub-library-choice-plus size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                            );
                        })}
                    </div>
                    {!choices.length && (
                        <div className="py-10 text-center">
                            <h3 className="font-semibold">No blocks match</h3>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Try another word or clear the filters.
                            </p>
                            <Button
                                variant="outline"
                                className="mt-4"
                                onClick={() => {
                                    setQuery('');
                                    setGroup('All blocks');
                                }}
                            >
                                Clear filters
                            </Button>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
