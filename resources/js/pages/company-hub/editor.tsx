import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { shiftInputInstant } from '@/lib/workforce-time-input';
import { arrayMove } from '@dnd-kit/sortable';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Check,
    History,
    Loader2,
    Monitor,
    Plus,
    Redo2,
    Save,
    Send,
    Trash2,
    Undo2,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { hubRequest } from './_api';
import { HubBlocks } from './_blocks';
import { CampaignSettings } from './_campaign-settings';
import { DepartmentSpace, departmentPreview } from './_department';
import {
    HubDesigner,
    SectionSettings,
    type DesignerSelection,
} from './_designer';
import { HubEventDialog } from './_dialogs';
import { HubArticleHeader, HubHero, HubSiteHeader } from './_hero';
import { HeroSettings, defaultHero } from './_hero-settings';
import { ContentInterests } from './_interests';
import {
    ChecklistSettings,
    KnowledgeReader,
    isKnowledgePage,
    knowledgePreview,
} from './_knowledge';
import { blockLibrary, blockSpan } from './_layout';
import { MediaUpload } from './_media-picker';
import { SectionPresets } from './_presets';
import { RevisionHistory, type RevisionSummary } from './_revision-history';
import type {
    HubBlock,
    HubContent,
    HubEvent,
    HubProps,
    HubSection,
} from './_types';
import './designer.css';
import './hub.css';

type EditorProps = {
    page: {
        id: number;
        kind: string;
        slug: string;
        draft: HubContent;
        draft_version: number;
        owner_id: number;
        archived_at: string | null;
    };
    capabilities: Record<string, boolean>;
    revisions: RevisionSummary[];
    revisionCursor?: number | null;
    sites: Array<{ id: number; name: string }>;
    departments: Array<{ id: number; name: string }>;
    previewData: HubProps;
    owners: Array<{ id: number; name: string }>;
    demo?: boolean;
};
const library = blockLibrary;

export default function HubEditor(props: EditorProps) {
    return (
        <EditorBody
            key={`${props.page.id}-${props.page.draft_version}`}
            {...props}
        />
    );
}
function EditorBody({
    page,
    capabilities,
    revisions,
    revisionCursor = null,
    sites,
    departments,
    previewData,
    owners,
    demo = false,
}: EditorProps) {
    const [content, setContent] = useState(page.draft);
    const [selection, setSelection] = useState<DesignerSelection>(null);
    const [redo, setRedo] = useState<HubContent[]>([]);
    const selectedSection = content.sections.find(
        (s) => s.id === selection?.section,
    );
    const selectedBlock = selectedSection?.blocks.find(
        (b) => b.id === selection?.block,
    );
    const [version, setVersion] = useState(page.draft_version);
    const [saved, setSaved] = useState(JSON.stringify(page.draft));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [mode, setMode] = useState('edit');
    const [history, setHistory] = useState(false);
    const [publishAt, setPublishAt] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [event, setEvent] = useState<HubEvent | null>(null);
    const [busy, setBusy] = useState(false);
    const [retry, setRetry] = useState(0);
    const [undo, setUndo] = useState<HubContent[]>([]);
    const [archiveOpen, setArchiveOpen] = useState(false);
    const [owner, setOwner] = useState(String(page.owner_id));
    const [demoNotice, setDemoNotice] = useState('');
    const json = JSON.stringify(content);
    const dirty = json !== saved;
    const dirtyRef = useRef(dirty);
    useEffect(() => {
        dirtyRef.current = dirty;
    }, [dirty]);
    const save = useCallback(async () => {
        if (saving || !dirty) return;
        setSaving(true);
        setError('');
        try {
            if (demo) {
                setVersion((current) => current + 1);
                setSaved(JSON.stringify(content));
                return;
            }
            const result = await hubRequest<{ version: number }>(
                `/company-hub/manage/${page.id}`,
                'PUT',
                { version, content },
            );
            setVersion(result.version);
            setSaved(JSON.stringify(content));
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Draft could not be saved.',
            );
        } finally {
            setSaving(false);
        }
    }, [content, dirty, page.id, saving, version, demo]);
    useEffect(() => {
        if (!dirty || saving || error) return;
        const timer = window.setTimeout(() => void save(), 900);
        return () => window.clearTimeout(timer);
    }, [dirty, saving, error, save, retry]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (dirtyRef.current) event.preventDefault();
        };
        window.addEventListener('beforeunload', warn);
        const unsubscribe = router.on('before', (event) => {
            if (dirtyRef.current) {
                event.preventDefault();
                setError('Save your changes before leaving this page.');
            }
        });
        return () => {
            window.removeEventListener('beforeunload', warn);
            unsubscribe();
        };
    }, []);
    const field = <K extends keyof HubContent>(
        key: K,
        value: HubContent[K],
    ) => {
        setError('');
        setRedo([]);
        setUndo((items) => [...items.slice(-39), content]);
        setContent((current) => ({ ...current, [key]: value }));
    };
    const section = (id: string, update: Partial<HubSection>) => {
        setError('');
        setRedo([]);
        setUndo((items) => [...items.slice(-39), content]);
        setContent((current) => ({
            ...current,
            sections: current.sections.map((item) =>
                item.id === id ? { ...item, ...update } : item,
            ),
        }));
    };
    const mutate = (url: string, extra: Record<string, unknown> = {}) => {
        if (dirty || saving) {
            void save();
            return;
        }
        if (demo) {
            setMode('desktop');
            setDemoNotice(
                'Publication preview. Your demo changes have not been saved to the company hub.',
            );
            return;
        }
        setBusy(true);
        router.post(
            url,
            { version, ...extra },
            {
                preserveScroll: true,
                onError: (errors) => setError(Object.values(errors).join(' ')),
                onFinish: () => setBusy(false),
            },
        );
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Company Hub', href: '/company-hub' },
                {
                    title: 'Edit page',
                    href: `/company-hub/manage/${page.id}/edit`,
                },
            ]}
        >
            <Head title={`Edit ${content.title} · Company Hub`} />
            <div className="hub-editor space-y-5 pb-10">
                {demo && (
                    <Card
                        unstyled
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-4 text-sm"
                    >
                        <p>
                            <strong className="text-primary">
                                Editor showcase
                            </strong>
                            <span className="ml-2 text-muted-foreground">
                                Try the layout tools. Changes last until you
                                leave this page.
                            </span>
                        </p>
                        <Link
                            href="/company-hub/showcase"
                            className="font-medium text-primary"
                        >
                            Back to showcase
                        </Link>
                    </Card>
                )}
                {demoNotice && (
                    <p
                        role="status"
                        className="rounded-lg bg-primary/10 p-4 text-sm text-primary"
                    >
                        {demoNotice}
                    </p>
                )}
                <Card
                    unstyled
                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4"
                >
                    <div className="flex min-w-0 items-center gap-3">
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Back to Company Hub"
                            asChild
                        >
                            <Link href="/company-hub">
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <div>
                            <h1 className="text-section-title">
                                Make it your company’s space
                            </h1>
                            <p
                                aria-live="polite"
                                className="text-caption mt-1 flex items-center gap-1.5 text-muted-foreground"
                            >
                                {saving ? (
                                    <>
                                        <Loader2 className="size-3.5 animate-spin" />
                                        Saving draft…
                                    </>
                                ) : dirty ? (
                                    'Unsaved changes'
                                ) : (
                                    <>
                                        <Check className="size-3.5 text-status-success" />
                                        Draft saved
                                    </>
                                )}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setHistory(!history)}
                            aria-expanded={history}
                        >
                            <History className="size-4" />
                            Versions
                        </Button>
                        <Button
                            variant="outline"
                            disabled={!dirty || saving}
                            onClick={() => void save()}
                        >
                            <Save className="size-4" />
                            Save draft
                        </Button>
                        <Button
                            disabled={dirty || saving || busy}
                            onClick={() => {
                                try {
                                    mutate(
                                        `/company-hub/manage/${page.id}/${capabilities.publish && !content.requires_approval ? 'publish' : 'submit'}`,
                                        {
                                            publish_at: publishAt
                                                ? shiftInputInstant(publishAt)
                                                : null,
                                            expires_at: expiresAt
                                                ? shiftInputInstant(expiresAt)
                                                : null,
                                        },
                                    );
                                } catch (failure) {
                                    setError(
                                        failure instanceof Error
                                            ? failure.message
                                            : 'Choose a valid publication time.',
                                    );
                                }
                            }}
                        >
                            <Send className="size-4" />
                            {capabilities.publish && !content.requires_approval
                                ? publishAt
                                    ? 'Schedule'
                                    : 'Publish'
                                : 'Submit for review'}
                        </Button>
                    </div>
                </Card>
                {error && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-status-warning bg-status-warning-bg p-4 text-status-warning-foreground"
                    >
                        <p className="flex-1 text-sm">{error}</p>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setError('');
                                setRetry((v) => v + 1);
                            }}
                        >
                            Try saving again
                        </Button>
                    </div>
                )}
                {revisions[0]?.state === 'changes_requested' &&
                    revisions[0].review_note && (
                        <section
                            className="hub-review-feedback"
                            aria-label="Reviewer feedback"
                        >
                            <h2 className="font-semibold">Changes requested</h2>
                            <p className="mt-2 text-sm whitespace-pre-wrap">
                                {revisions[0].review_note}
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {revisions[0].reviewed_by_name} · Version{' '}
                                {revisions[0].draft_version}
                                {revisions[0].reviewed_at
                                    ? ` · ${formatDateTime(revisions[0].reviewed_at)}`
                                    : ''}
                            </p>
                        </section>
                    )}
                {history && (
                    <RevisionHistory
                        pageId={page.id}
                        version={version}
                        revisions={revisions}
                        cursor={revisionCursor}
                        disabled={dirty || saving || busy || demo}
                        onChanged={() =>
                            router.reload({
                                only: [
                                    'page',
                                    'revisions',
                                    'revisionCursor',
                                    'previewData',
                                ],
                                preserveScroll: true,
                            })
                        }
                    />
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant="ghost"
                        disabled={undo.length === 0 || saving}
                        onClick={() => {
                            const previous = undo.at(-1);
                            if (previous) {
                                setRedo((items) => [...items, content]);
                                setContent(previous);
                                setUndo((items) => items.slice(0, -1));
                                setError('');
                            }
                        }}
                    >
                        <Undo2 className="size-4" />
                        Undo
                    </Button>
                    <Button
                        variant="ghost"
                        disabled={!redo.length}
                        onClick={() => {
                            setUndo((items) => [...items, content]);
                            setContent(redo[redo.length - 1]);
                            setRedo((items) => items.slice(0, -1));
                            setError('');
                        }}
                    >
                        <Redo2 className="size-4" />
                        Redo
                    </Button>
                    {[
                        { key: 'edit', label: 'Design', icon: Plus },
                        {
                            key: 'desktop',
                            label: 'Desktop preview',
                            icon: Monitor,
                        },
                    ].map((item) => (
                        <Button
                            key={item.key}
                            variant={mode === item.key ? 'secondary' : 'ghost'}
                            onClick={() => setMode(item.key)}
                            aria-pressed={mode === item.key}
                        >
                            <item.icon className="size-4" />
                            {item.label}
                        </Button>
                    ))}
                    <span className="text-caption ml-auto text-muted-foreground">
                        Changes stay private until published.
                    </span>
                </div>
                <HubSiteHeader
                    compactNavigation={false}
                    content={content}
                    summary={
                        page.kind === 'home'
                            ? previewData.heroSummary
                            : undefined
                    }
                    search=""
                    onSearch={() => {}}
                    onSubmit={(e) => e.preventDefault()}
                    onEditHero={() => {
                        setMode('edit');
                        setSelection(null);
                    }}
                >
                    <nav
                        className="hub-nav-links"
                        aria-label="Preview navigation"
                    >
                        <a href="#hub-editor-canvas" aria-current="page">
                            {page.kind === 'home' ? 'Home' : 'Page preview'}
                        </a>
                    </nav>
                </HubSiteHeader>
                {mode !== 'edit' ? (
                    <div
                        className="hub-editor-preview space-y-6"
                        data-device={mode}
                    >
                        {page.kind === 'home' ? (
                            <HubHero
                                content={content}
                                pages={previewData.pages}
                                demo={demo}
                                baseUrl={
                                    demo
                                        ? '/company-hub/showcase'
                                        : '/company-hub'
                                }
                                featured={
                                    page.kind === 'home'
                                        ? (previewData.pages.find(
                                              (item) =>
                                                  item.kind === 'news' &&
                                                  item.featured,
                                          ) ??
                                          previewData.pages.find(
                                              (item) => item.kind === 'news',
                                          ))
                                        : undefined
                                }
                            />
                        ) : (
                            <HubArticleHeader content={content} />
                        )}
                        {isKnowledgePage(page.kind, content) ? (
                            <KnowledgeReader
                                content={content}
                                knowledge={knowledgePreview(
                                    content,
                                    page,
                                    previewData,
                                )}
                                preview
                            >
                                <HubBlocks
                                    sections={content.sections}
                                    data={{
                                        ...previewData,
                                        participation: null,
                                        capabilities: {
                                            ...previewData.capabilities,
                                            recognise: false,
                                        },
                                    }}
                                    onEvent={setEvent}
                                />
                            </KnowledgeReader>
                        ) : page.kind === 'department' ? (
                            <DepartmentSpace
                                key={page.id}
                                content={content}
                                space={departmentPreview(
                                    content,
                                    page,
                                    previewData,
                                )}
                                data={{
                                    ...previewData,
                                    participation: null,
                                    capabilities: {
                                        ...previewData.capabilities,
                                        recognise: false,
                                    },
                                }}
                                onEvent={setEvent}
                                preview
                            />
                        ) : (
                            <HubBlocks
                                sections={content.sections}
                                data={{
                                    ...previewData,
                                    participation: null,
                                    capabilities: {
                                        ...previewData.capabilities,
                                        recognise: false,
                                    },
                                }}
                                onEvent={setEvent}
                            />
                        )}
                    </div>
                ) : (
                    <div className="hub-editor-workspace">
                        <aside
                            className="hub-editor-settings hub-designer-inspector space-y-5 rounded-lg border border-border bg-card p-5"
                            aria-label="Designer settings"
                        >
                            {selectedSection && (
                                <Button
                                    variant="ghost"
                                    onClick={() => setSelection(null)}
                                >
                                    <ArrowLeft className="size-4" />
                                    Page & hero settings
                                </Button>
                            )}
                            {selectedSection && !selectedBlock && (
                                <SectionSettings
                                    section={selectedSection}
                                    onChange={(update) =>
                                        section(selectedSection.id, update)
                                    }
                                />
                            )}
                            {selectedSection && selectedBlock && (
                                <>
                                    <Field label="Block width">
                                        <Choose
                                            value={String(
                                                selectedBlock.width ??
                                                    blockSpan(
                                                        selectedSection,
                                                        selectedBlock,
                                                        selectedSection.blocks.indexOf(
                                                            selectedBlock,
                                                        ),
                                                    ),
                                            )}
                                            onChange={(value) =>
                                                section(selectedSection.id, {
                                                    blocks: selectedSection.blocks.map(
                                                        (b) =>
                                                            b.id ===
                                                            selectedBlock.id
                                                                ? {
                                                                      ...b,
                                                                      width: Number(
                                                                          value,
                                                                      ),
                                                                  }
                                                                : b,
                                                    ),
                                                })
                                            }
                                            options={[
                                                {
                                                    value: '4',
                                                    label: 'One third',
                                                },
                                                { value: '6', label: 'Half' },
                                                {
                                                    value: '8',
                                                    label: 'Two thirds',
                                                },
                                                {
                                                    value: '12',
                                                    label: 'Full width',
                                                },
                                            ]}
                                        />
                                    </Field>
                                    <Field label="Move to section">
                                        <Choose
                                            value={selectedSection.id}
                                            onChange={(id) => {
                                                const destination =
                                                    content.sections.find(
                                                        (s) => s.id === id,
                                                    );
                                                if (
                                                    !destination ||
                                                    destination.blocks.length >=
                                                        12
                                                )
                                                    return;
                                                field(
                                                    'sections',
                                                    content.sections.map((s) =>
                                                        s.id ===
                                                        selectedSection.id
                                                            ? {
                                                                  ...s,
                                                                  blocks: s.blocks.filter(
                                                                      (b) =>
                                                                          b.id !==
                                                                          selectedBlock.id,
                                                                  ),
                                                              }
                                                            : s.id === id
                                                              ? {
                                                                    ...s,
                                                                    blocks: [
                                                                        ...s.blocks,
                                                                        selectedBlock,
                                                                    ],
                                                                }
                                                              : s,
                                                    ),
                                                );
                                                setSelection({
                                                    section: id,
                                                    block: selectedBlock.id,
                                                });
                                            }}
                                            options={content.sections.map(
                                                (s, i) => ({
                                                    value: s.id,
                                                    label:
                                                        s.title ||
                                                        `Section ${i + 1}`,
                                                }),
                                            )}
                                        />
                                    </Field>
                                    <BlockEditor
                                        block={selectedBlock}
                                        pageId={page.id}
                                        onChange={(update) =>
                                            section(selectedSection.id, {
                                                blocks: selectedSection.blocks.map(
                                                    (b) =>
                                                        b.id ===
                                                        selectedBlock.id
                                                            ? {
                                                                  ...b,
                                                                  ...update,
                                                              }
                                                            : b,
                                                ),
                                            })
                                        }
                                        onRemove={() => {
                                            section(selectedSection.id, {
                                                blocks: selectedSection.blocks.filter(
                                                    (b) =>
                                                        b.id !==
                                                        selectedBlock.id,
                                                ),
                                            });
                                            setSelection({
                                                section: selectedSection.id,
                                            });
                                        }}
                                        onMove={(direction) =>
                                            section(selectedSection.id, {
                                                blocks: arrayMove(
                                                    selectedSection.blocks,
                                                    selectedSection.blocks.indexOf(
                                                        selectedBlock,
                                                    ),
                                                    selectedSection.blocks.indexOf(
                                                        selectedBlock,
                                                    ) + direction,
                                                ),
                                            })
                                        }
                                        first={
                                            selectedSection.blocks[0]?.id ===
                                            selectedBlock.id
                                        }
                                        last={
                                            selectedSection.blocks.at(-1)
                                                ?.id === selectedBlock.id
                                        }
                                    />
                                </>
                            )}
                            <div
                                className="space-y-5"
                                hidden={!!selectedSection}
                            >
                                <div>
                                    <h2 className="text-section-title">
                                        Page settings
                                    </h2>
                                    <p className="text-caption mt-1 text-muted-foreground">
                                        Shape the welcome, story and audience.
                                    </p>
                                </div>
                                <Field label="Title">
                                    <Input
                                        value={content.title}
                                        maxLength={160}
                                        onChange={(e) =>
                                            field('title', e.target.value)
                                        }
                                    />
                                </Field>
                                <Field label="Introduction">
                                    <Textarea
                                        value={content.summary ?? ''}
                                        maxLength={600}
                                        rows={3}
                                        onChange={(e) =>
                                            field('summary', e.target.value)
                                        }
                                    />
                                </Field>
                                <Field label="Hero label">
                                    <Input
                                        value={content.hero_label}
                                        maxLength={60}
                                        onChange={(e) =>
                                            field('hero_label', e.target.value)
                                        }
                                    />
                                </Field>
                                <Field label="Category">
                                    <Input
                                        value={content.category}
                                        maxLength={60}
                                        onChange={(e) =>
                                            field('category', e.target.value)
                                        }
                                    />
                                </Field>
                                {page.kind !== 'home' && (
                                    <ContentInterests
                                        content={content}
                                        teams={departments}
                                        onChange={(changes) => {
                                            setError('');
                                            setRedo([]);
                                            setUndo((items) => [
                                                ...items.slice(-39),
                                                content,
                                            ]);
                                            setContent((current) => ({
                                                ...current,
                                                ...changes,
                                            }));
                                        }}
                                    />
                                )}
                                {['page', 'knowledge'].includes(page.kind) && (
                                    <>
                                        <Field label="Resource collection">
                                            <Input
                                                maxLength={60}
                                                value={content.collection ?? ''}
                                                onChange={(e) =>
                                                    field(
                                                        'collection',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="For example, Getting started"
                                            />
                                        </Field>
                                        <Field label="Resource type">
                                            <select
                                                className="hub-native-select w-full"
                                                value={
                                                    content.resource_type ??
                                                    'Information'
                                                }
                                                onChange={(e) =>
                                                    field(
                                                        'resource_type',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                {[
                                                    'Information',
                                                    'Guide',
                                                    'Policy',
                                                    'Checklist',
                                                    'Form',
                                                ].map((value) => (
                                                    <option key={value}>
                                                        {value}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        {content.resource_type ===
                                            'Checklist' && (
                                            <ChecklistSettings
                                                value={content.checklist}
                                                onChange={(steps) =>
                                                    field('checklist', steps)
                                                }
                                            />
                                        )}
                                    </>
                                )}
                                <MediaUpload
                                    pageId={page.id}
                                    mediaId={content.cover_id}
                                    onChange={(id) => {
                                        if (
                                            id === content.cover_id &&
                                            content.hero?.style ===
                                                (id ? 'image' : 'brand')
                                        )
                                            return;
                                        setUndo((items) => [
                                            ...items.slice(-39),
                                            content,
                                        ]);
                                        setRedo([]);
                                        setContent((current) => ({
                                            ...current,
                                            cover_id: id,
                                            hero: {
                                                ...defaultHero,
                                                ...current.hero,
                                                style: id ? 'image' : 'brand',
                                            },
                                        }));
                                    }}
                                    label="Hero image"
                                />
                                <HeroSettings
                                    isHome={page.kind === 'home'}
                                    value={content.hero}
                                    onChange={(hero) => field('hero', hero)}
                                />
                                {page.kind === 'campaign' && (
                                    <CampaignSettings
                                        value={content.campaign}
                                        onChange={(campaign) =>
                                            field('campaign', campaign)
                                        }
                                    />
                                )}
                                {page.kind !== 'home' && (
                                    <>
                                        <Field label="Planned publication date">
                                            <Input
                                                type="date"
                                                value={
                                                    content.planned_for ?? ''
                                                }
                                                onChange={(e) =>
                                                    field(
                                                        'planned_for',
                                                        e.target.value || null,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Toggle
                                            label="Feature in the spotlight"
                                            checked={content.featured}
                                            onChange={(v) =>
                                                field('featured', v)
                                            }
                                        />
                                        <Field label="Who can see this?">
                                            <Choose
                                                value={content.audience.type}
                                                onChange={(v) =>
                                                    field('audience', {
                                                        type: v as HubContent['audience']['type'],
                                                        ids: [],
                                                    })
                                                }
                                                options={[
                                                    'all',
                                                    'sites',
                                                    'departments',
                                                ].map((value) => ({
                                                    value,
                                                    label:
                                                        value === 'all'
                                                            ? 'All staff'
                                                            : value === 'sites'
                                                              ? 'Selected sites'
                                                              : 'Selected departments',
                                                }))}
                                            />
                                        </Field>
                                        {content.audience.type !== 'all' && (
                                            <div className="space-y-2">
                                                {(content.audience.type ===
                                                'sites'
                                                    ? sites
                                                    : departments
                                                ).map((option) => (
                                                    <Toggle
                                                        key={option.id}
                                                        label={option.name}
                                                        checked={content.audience.ids.includes(
                                                            option.id,
                                                        )}
                                                        onChange={(v) =>
                                                            field('audience', {
                                                                ...content.audience,
                                                                ids: v
                                                                    ? [
                                                                          ...content
                                                                              .audience
                                                                              .ids,
                                                                          option.id,
                                                                      ]
                                                                    : content.audience.ids.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              option.id,
                                                                      ),
                                                            })
                                                        }
                                                    />
                                                ))}
                                            </div>
                                        )}
                                    </>
                                )}
                                <details className="border-t border-border pt-4">
                                    <summary className="frontline-tap cursor-pointer font-medium">
                                        Publishing & participation
                                    </summary>
                                    <div className="mt-4 space-y-4">
                                        <Toggle
                                            label="Allow comments"
                                            checked={content.comments_enabled}
                                            onChange={(v) =>
                                                field('comments_enabled', v)
                                            }
                                        />
                                        <Toggle
                                            label="Allow reactions"
                                            checked={content.reactions_enabled}
                                            onChange={(v) =>
                                                field('reactions_enabled', v)
                                            }
                                        />
                                        <Toggle
                                            label="Require another publisher’s approval"
                                            checked={content.requires_approval}
                                            onChange={(v) =>
                                                field('requires_approval', v)
                                            }
                                        />
                                        {capabilities.layout && (
                                            <p className="text-sm text-muted-foreground">
                                                After publishing, add this page
                                                to the shared menu in{' '}
                                                <Link
                                                    className="text-primary underline"
                                                    href="/company-hub?view=publishing"
                                                >
                                                    Publishing → Navigation
                                                </Link>
                                                . Menu changes have their own
                                                draft and publish step.
                                            </p>
                                        )}
                                        <Field label="Review date">
                                            <Input
                                                type="date"
                                                value={content.review_on ?? ''}
                                                onChange={(e) =>
                                                    field(
                                                        'review_on',
                                                        e.target.value || null,
                                                    )
                                                }
                                            />
                                        </Field>
                                        {owners.length > 0 && (
                                            <div className="space-y-2">
                                                <Field label="Page owner">
                                                    <Choose
                                                        value={owner}
                                                        onChange={setOwner}
                                                        options={owners.map(
                                                            (person) => ({
                                                                value: String(
                                                                    person.id,
                                                                ),
                                                                label: person.name,
                                                            }),
                                                        )}
                                                    />
                                                </Field>
                                                <Button
                                                    variant="outline"
                                                    disabled={
                                                        dirty ||
                                                        saving ||
                                                        busy ||
                                                        owner ===
                                                            String(
                                                                page.owner_id,
                                                            )
                                                    }
                                                    onClick={() =>
                                                        mutate(
                                                            `/company-hub/manage/${page.id}/owner`,
                                                            {
                                                                owner_id:
                                                                    Number(
                                                                        owner,
                                                                    ),
                                                            },
                                                        )
                                                    }
                                                >
                                                    Update owner
                                                </Button>
                                            </div>
                                        )}
                                        {capabilities.publish && (
                                            <>
                                                <p className="text-sm text-muted-foreground">
                                                    Publication times use New
                                                    Zealand time.
                                                </p>
                                                <Field label="Publish later (optional)">
                                                    <Input
                                                        type="datetime-local"
                                                        value={publishAt}
                                                        onChange={(e) =>
                                                            setPublishAt(
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </Field>
                                                <Field label="Expire on (optional)">
                                                    <Input
                                                        type="datetime-local"
                                                        value={expiresAt}
                                                        onChange={(e) =>
                                                            setExpiresAt(
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </Field>
                                            </>
                                        )}
                                    </div>
                                </details>
                                {capabilities.publish &&
                                    page.kind !== 'home' &&
                                    !page.archived_at && (
                                        <Button
                                            variant="outline"
                                            disabled={dirty || saving || busy}
                                            onClick={() => setArchiveOpen(true)}
                                        >
                                            Archive page
                                        </Button>
                                    )}
                            </div>
                        </aside>
                        <div
                            id="hub-editor-canvas"
                            className="hub-editor-canvas min-w-0"
                        >
                            <div className="hub-designer-topline">
                                <span>
                                    Select a block to edit it. Drag its handle
                                    to move it.
                                </span>
                                <Button
                                    variant="ghost"
                                    onClick={() => setSelection(null)}
                                >
                                    Page settings
                                </Button>
                            </div>
                            <HubDesigner
                                sections={content.sections}
                                data={previewData}
                                selection={selection}
                                onSelect={setSelection}
                                onChange={(sections) =>
                                    field('sections', sections)
                                }
                            />
                            {!demo && (
                                <SectionPresets
                                    pageId={page.id}
                                    selected={selectedSection}
                                    full={content.sections.length >= 12}
                                    onInsert={(section) =>
                                        field('sections', [
                                            ...content.sections,
                                            section,
                                        ])
                                    }
                                />
                            )}
                        </div>
                    </div>
                )}
            </div>
            <HubEventDialog
                events={previewData.events}
                onSelect={setEvent}
                event={event}
                close={() => setEvent(null)}
                preview
            />
            <Dialog open={archiveOpen} onOpenChange={setArchiveOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Archive this page?</DialogTitle>
                        <DialogDescription>
                            It will leave the staff hub and search results. Your
                            draft and version history remain available, and a
                            publisher can publish it again.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setArchiveOpen(false)}
                        >
                            Keep page
                        </Button>
                        <Button
                            disabled={busy}
                            onClick={() =>
                                mutate(`/company-hub/manage/${page.id}/archive`)
                            }
                        >
                            Archive page
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block space-y-2">
            <span className="text-sm font-medium">{label}</span>
            {children}
        </label>
    );
}
function Toggle({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <Label className="flex items-center justify-between gap-4 text-sm leading-relaxed">
            <span>{label}</span>
            <Switch checked={checked} onCheckedChange={onChange} />
        </Label>
    );
}
function Choose({
    value,
    onChange,
    options,
    placeholder = 'Choose…',
}: {
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string }>;
    placeholder?: string;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger aria-label={placeholder}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
function BlockEditor({
    block,
    pageId,
    onChange,
    onRemove,
    onMove,
    first,
    last,
}: {
    block: HubBlock;
    pageId: number;
    onChange: (update: Partial<HubBlock>) => void;
    onRemove: () => void;
    onMove: (direction: number) => void;
    first: boolean;
    last: boolean;
}) {
    return (
        <Card
            unstyled
            className="space-y-4 rounded-lg border border-border bg-background p-4"
        >
            <div className="flex items-center gap-2">
                <span className="text-caption mr-auto font-semibold text-primary">
                    {library.find((item) => item[0] === block.type)?.[1] ??
                        block.type}
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
            <Field label="Heading">
                <Input
                    value={block.title}
                    onChange={(e) => onChange({ title: e.target.value })}
                />
            </Field>
            {block.type === 'text' && (
                <Field label="Story">
                    <Textarea
                        rows={6}
                        value={block.body ?? ''}
                        onChange={(e) => onChange({ body: e.target.value })}
                        placeholder="Share your story. Separate paragraphs with a blank line."
                    />
                </Field>
            )}
            {block.type === 'image' && (
                <>
                    <MediaUpload
                        key={block.id}
                        pageId={pageId}
                        mediaId={block.media_id}
                        onChange={(id, media) =>
                            onChange({ media_id: id, alt: media?.alt ?? '' })
                        }
                        label="Image"
                    />
                    <Field label="Describe the image for screen readers">
                        <Input
                            value={block.alt ?? ''}
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
                    <Field label="Closes on (optional)">
                        <Input
                            type="date"
                            value={block.closes_at?.slice(0, 10) ?? ''}
                            onChange={(e) =>
                                onChange({
                                    closes_at: e.target.value || undefined,
                                })
                            }
                        />
                    </Field>
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
            ].includes(block.type) && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Items to show">
                        <Input
                            type="number"
                            min={1}
                            max={12}
                            value={block.limit ?? 4}
                            onChange={(e) =>
                                onChange({ limit: Number(e.target.value) })
                            }
                        />
                    </Field>
                    {['news', 'resources', 'people'].includes(block.type) && (
                        <Field
                            label={
                                block.type === 'people'
                                    ? 'Department filter (optional)'
                                    : 'Category filter (optional)'
                            }
                        >
                            <Input
                                value={block.category ?? ''}
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
                                    value={link.title}
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
                                value={link.url}
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
                        </div>
                    ))}
                    <Button
                        variant="outline"
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
