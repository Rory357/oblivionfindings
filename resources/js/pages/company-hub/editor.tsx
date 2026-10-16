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
import { arrayMove } from '@dnd-kit/sortable';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    FileClock,
    History,
    Keyboard,
    ListTree,
    Loader2,
    Monitor,
    Plus,
    Redo2,
    Save,
    Send,
    Settings2,
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
import { BlockEditor } from './_block-editor';
import { previewEditorialBlocks, previewSourceData } from './_block-preview';
import { HubBlocks } from './_blocks';
import { CampaignReader, campaignPreview } from './_campaign-reader';
import { CampaignSettings } from './_campaign-settings';
import { ResourceCollectionPicker } from './_collections';
import { HubDateField } from './_date-fields';
import { DepartmentSpace, departmentPreview } from './_department';
import {
    HubDesigner,
    SectionSettings,
    type DesignerSelection,
} from './_designer';
import {
    BlockLibraryPicker,
    DesignerShortcuts,
    PageOutline,
} from './_designer-tools';
import { HubEventDialog } from './_dialogs';
import { DraftTools } from './_draft-tools';
import { DuplicateHubPage } from './_duplicate-page';
import { EditHubTabs } from './_edit-tabs';
import { useEditorLeave } from './_editor-leave';
import { HubArticleHeader, HubSiteHeader } from './_hero';
import {
    HeroWorkspace,
    heroPreview,
    type HeroPreview,
} from './_hero-workspace';
import { ContentInterests } from './_interests';
import {
    ChecklistSettings,
    KnowledgeReader,
    isKnowledgePage,
    knowledgePreview,
    supportsPersonalSteps,
} from './_knowledge';
import { blockSpan, createBlock } from './_layout';
import type { HubMedia } from './_media-library';
import { HubNavigationRail, type NavigationItem } from './_navigation';
import { SectionPresets } from './_presets';
import {
    PublicationReadiness,
    type HubReadiness,
} from './_publication-readiness';
import { PublicationWorkspace } from './_publication-workspace';
import { RevisionHistory, type RevisionSummary } from './_revision-history';
import { HubSelect } from './_select';
import type {
    HubBlock,
    HubContent,
    HubDraftRecovery,
    HubEvent,
    HubProps,
    HubSection,
    HubStarterLayout,
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
    draftRecovery?: HubDraftRecovery;
    starterLayouts?: HubStarterLayout[];
    readiness?: HubReadiness;
};

const resourceTypes = [
    'Information',
    'Guide',
    'Policy',
    'Process',
    'Checklist',
    'Form',
];

export default function HubEditor(props: EditorProps) {
    const reviewIdentity = `${props.page.id}:${props.readiness?.actor_id ?? props.draftRecovery?.actor_id ?? 'demo'}`;
    const [reviewIntent, setReviewIntent] = useState<string | null>(null);
    return (
        <EditorBody
            key={`${props.page.id}-${props.page.draft_version}-${props.readiness?.actor_id ?? props.draftRecovery?.actor_id}-${props.readiness?.editable}-${props.readiness?.blocked_reason}-${props.readiness?.blocking_revision_id}`}
            {...props}
            historyAfterRefresh={reviewIntent === reviewIdentity}
            onHistoryRefreshIntent={(open) =>
                setReviewIntent(open ? reviewIdentity : null)
            }
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
    draftRecovery,
    starterLayouts = [],
    historyAfterRefresh,
    onHistoryRefreshIntent,
    readiness: initialReadiness,
}: EditorProps & {
    historyAfterRefresh: boolean;
    onHistoryRefreshIntent: (open: boolean) => void;
}) {
    const [readiness, setReadiness] = useState(initialReadiness);
    const editLocked = readiness?.editable === false;
    const [content, setContent] = useState(page.draft);
    const [uploadedImages, setUploadedImages] = useState<{
        source: HubProps;
        items: NonNullable<HubProps['featuredMedia']>;
    } | null>(null);
    const previewSources = {
        ...previewData,
        featuredMedia: [
            ...(previewData.featuredMedia ?? []),
            ...(uploadedImages?.source === previewData
                ? uploadedImages.items
                : []),
        ],
    };
    const canvasSources = previewSourceData(
        previewSources,
        page.kind === 'department' ? (content.team_ids ?? []) : undefined,
    );
    const canvasData: HubProps = {
        ...canvasSources,
        blockData: previewEditorialBlocks(
            content.sections,
            canvasSources,
            page.kind === 'department' ? (content.team_ids ?? []) : undefined,
        ),
    };
    const rememberMedia = (media: HubMedia) => {
        if (media.hub_page_id !== page.id || !media.mime.startsWith('image/'))
            return;
        setUploadedImages((previous) => ({
            source: previewData,
            items: [
                ...(previous?.source === previewData
                    ? previous.items.filter((image) => image.id !== media.id)
                    : []),
                { id: media.id, url: media.url, alt: media.alt },
            ],
        }));
    };
    const [selection, setSelection] = useState<DesignerSelection>(null);
    const [inspectorPanel, setInspectorPanel] = useState<
        'settings' | 'outline'
    >('settings');
    const [librarySectionId, setLibrarySectionId] = useState<string | null>(
        null,
    );
    const [pickerDirty, setPickerDirty] = useState(false);
    const [heroDraft, setHeroDraft] = useState<HeroPreview | null>(null);
    const heroTrigger = useRef<HTMLButtonElement>(null);
    const heroSidebarTrigger = useRef<HTMLButtonElement>(null);
    const heroReturnToSidebar = useRef(false);
    const undoTrigger = useRef<HTMLButtonElement>(null);
    const editorFocusFrame = useRef<number | null>(null);
    const libraryReturn = useRef<{ sectionId: string; scrollY: number } | null>(
        null,
    );
    const libraryReturnFrame = useRef<number | null>(null);
    useEffect(
        () => () => {
            if (libraryReturnFrame.current !== null)
                window.cancelAnimationFrame(libraryReturnFrame.current);
            if (editorFocusFrame.current !== null)
                window.cancelAnimationFrame(editorFocusFrame.current);
        },
        [],
    );
    const [shortcutsOpen, setShortcutsOpen] = useState(false);
    const librarySection = content.sections.find(
        (item) => item.id === librarySectionId,
    );
    const select = (next: DesignerSelection) => {
        setSelection(next);
        setInspectorPanel('settings');
    };
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
    const [history, setHistory] = useState(historyAfterRefresh);
    const [publicationOpen, setPublicationOpen] = useState(false);
    const publicationTrigger = useRef<HTMLButtonElement>(null);
    const historyTrigger = useRef<HTMLButtonElement>(null);
    const [event, setEvent] = useState<HubEvent | null>(null);
    const [busy, setBusy] = useState(false);
    const [retry, setRetry] = useState(0);
    const [undo, setUndo] = useState<HubContent[]>([]);
    const [archiveOpen, setArchiveOpen] = useState(false);
    const [draftOptions, setDraftOptions] = useState(false);
    const [copyOpen, setCopyOpen] = useState(false);
    const [tabsOpen, setTabsOpen] = useState(false);
    const [tabRail, setTabRail] = useState<HTMLDivElement | null>(null);
    const [tabInspector, setTabInspector] = useState<HTMLDivElement | null>(
        null,
    );
    const [publishedNavigation, setPublishedNavigation] = useState<
        NavigationItem[] | null
    >(null);
    const [hasSavedDraft, setHasSavedDraft] = useState(
        draftRecovery?.has_saved_draft ?? true,
    );
    useEffect(() => {
        if (
            draftRecovery?.page_id === page.id &&
            draftRecovery.version === version &&
            draftRecovery.actor_id ===
                (initialReadiness?.actor_id ?? draftRecovery.actor_id)
        ) {
            setHasSavedDraft(draftRecovery.has_saved_draft);
        }
    }, [draftRecovery, page.id, version, initialReadiness?.actor_id]);
    const [owner, setOwner] = useState(String(page.owner_id));
    const [demoNotice, setDemoNotice] = useState('');
    const json = JSON.stringify(content);
    const dirty = json !== saved;
    const heroDirty =
        heroDraft !== null &&
        JSON.stringify(heroDraft) !== JSON.stringify(heroPreview(content));
    const saveLock = useRef(false);
    const save = useCallback(
        async (includeHeroPreview = false) => {
            if (editLocked) return false;
            if (pickerDirty) {
                setError(
                    'Add your block to the page, or discard its setup, before saving and leaving.',
                );
                return false;
            }
            if (saveLock.current) return false;
            const target =
                includeHeroPreview && heroDirty && heroDraft
                    ? { ...content, ...heroDraft }
                    : content;
            if (includeHeroPreview && heroDraft) {
                if (heroDirty) {
                    setUndo((items) => [...items.slice(-39), content]);
                    setRedo([]);
                    setContent(target);
                }
                setHeroDraft(null);
            }
            if (JSON.stringify(target) === saved) return true;
            saveLock.current = true;
            setSaving(true);
            setError('');
            try {
                if (demo) {
                    setVersion((current) => current + 1);
                    setSaved(JSON.stringify(target));
                    setHasSavedDraft(true);
                    return true;
                }
                const result = await hubRequest<{
                    version: number;
                    readiness?: HubReadiness;
                }>(`/company-hub/manage/${page.id}`, 'PUT', {
                    version,
                    content: target,
                });
                if (
                    result.readiness &&
                    (result.readiness.page_id !== page.id ||
                        result.readiness.version !== result.version ||
                        (initialReadiness &&
                            result.readiness.actor_id !==
                                initialReadiness.actor_id))
                ) {
                    throw new Error(
                        'The page or your account changed. Reload before continuing.',
                    );
                }
                setVersion(result.version);
                if (result.readiness) setReadiness(result.readiness);
                setSaved(JSON.stringify(target));
                setHasSavedDraft(true);
                return true;
            } catch (failure) {
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Draft could not be saved.',
                );
                return false;
            } finally {
                saveLock.current = false;
                setSaving(false);
            }
        },
        [
            content,
            saved,
            heroDraft,
            heroDirty,
            page.id,
            version,
            demo,
            editLocked,
            initialReadiness,
            pickerDirty,
        ],
    );
    const leave = useEditorLeave({
        dirty: dirty || pickerDirty || heroDirty,
        saving,
        save: () => save(true),
        discardLocal: () => {
            setContent(JSON.parse(saved) as HubContent);
            setUndo([]);
            setRedo([]);
            setError('');
            setLibrarySectionId(null);
            setPickerDirty(false);
            setHeroDraft(null);
        },
        onBlocked: () => setError('Save your changes before continuing.'),
    });
    useEffect(() => {
        if (
            !dirty ||
            editLocked ||
            saving ||
            error ||
            draftOptions ||
            copyOpen ||
            tabsOpen ||
            librarySectionId ||
            heroDraft ||
            leave.pending
        )
            return;
        const timer = window.setTimeout(() => void save(), 900);
        return () => window.clearTimeout(timer);
    }, [
        dirty,
        editLocked,
        saving,
        error,
        save,
        retry,
        draftOptions,
        copyOpen,
        tabsOpen,
        librarySectionId,
        heroDraft,
        leave.pending,
    ]);
    const field = <K extends keyof HubContent>(
        key: K,
        value: HubContent[K],
    ) => {
        if (editLocked) return;
        setError('');
        setRedo([]);
        setUndo((items) => [...items.slice(-39), content]);
        setContent((current) => ({ ...current, [key]: value }));
    };
    const openHero = (fromSidebar = false) => {
        if (editLocked || saving || busy) return;
        heroReturnToSidebar.current = fromSidebar;
        setMode('edit');
        select(null);
        setHeroDraft(heroPreview(content));
    };
    const closeHero = () => {
        setHeroDraft(null);
        if (editorFocusFrame.current !== null)
            window.cancelAnimationFrame(editorFocusFrame.current);
        editorFocusFrame.current = requestAnimationFrame(() => {
            (heroReturnToSidebar.current
                ? heroSidebarTrigger.current
                : heroTrigger.current
            )?.focus();
        });
    };
    const applyHero = () => {
        if (
            !heroDraft ||
            editLocked ||
            saving ||
            busy ||
            !heroDraft.title.trim()
        )
            return;
        if (heroDirty) {
            setUndo((items) => [...items.slice(-39), content]);
            setRedo([]);
            setError('');
            setContent((current) => ({ ...current, ...heroDraft }));
        }
        closeHero();
    };
    const section = (id: string, update: Partial<HubSection>) => {
        if (editLocked) return;
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
    const undoChange = useCallback(() => {
        const previous = undo.at(-1);
        if (
            !previous ||
            saving ||
            busy ||
            editLocked ||
            librarySectionId ||
            heroDraft
        )
            return;
        setRedo((items) => [...items, content]);
        setContent(previous);
        setUndo((items) => items.slice(0, -1));
        setError('');
    }, [undo, content, saving, busy, editLocked, librarySectionId, heroDraft]);
    const redoChange = useCallback(() => {
        const next = redo.at(-1);
        if (
            !next ||
            saving ||
            busy ||
            editLocked ||
            librarySectionId ||
            heroDraft
        )
            return;
        setUndo((items) => [...items.slice(-39), content]);
        setContent(next);
        setRedo((items) => items.slice(0, -1));
        setError('');
    }, [redo, content, saving, busy, editLocked, librarySectionId, heroDraft]);
    useEffect(() => {
        const shortcut = (event: KeyboardEvent) => {
            if (
                mode !== 'edit' ||
                history ||
                publicationOpen ||
                tabsOpen ||
                librarySectionId ||
                heroDraft ||
                event.defaultPrevented ||
                event.altKey ||
                !(event.ctrlKey || event.metaKey) ||
                event.key.toLowerCase() !== 'z'
            )
                return;
            const target =
                event.target instanceof Element ? event.target : null;
            if (
                target?.closest(
                    'input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="dialog"], [data-resizing="true"]',
                )
            )
                return;
            event.preventDefault();
            if (event.shiftKey) redoChange();
            else undoChange();
        };
        window.addEventListener('keydown', shortcut);
        return () => window.removeEventListener('keydown', shortcut);
    }, [
        mode,
        history,
        publicationOpen,
        tabsOpen,
        librarySectionId,
        heroDraft,
        undoChange,
        redoChange,
    ]);
    const addSection = () => {
        if (content.sections.length >= 12) return;
        const next = {
            id: crypto.randomUUID(),
            title: 'New section',
            columns: 1,
            blocks: [createBlock('text')],
        };
        field('sections', [...content.sections, next]);
        select({ section: next.id });
    };
    const openBlockLibrary = (sectionId: string) => {
        if (editLocked || saving || busy) return;
        if (libraryReturnFrame.current !== null)
            window.cancelAnimationFrame(libraryReturnFrame.current);
        libraryReturn.current = { sectionId, scrollY: window.scrollY };
        setLibrarySectionId(sectionId);
        setPickerDirty(false);
        setHistory(false);
    };
    const closeBlockLibrary = () => {
        const origin = libraryReturn.current;
        setLibrarySectionId(null);
        setPickerDirty(false);
        libraryReturnFrame.current = window.requestAnimationFrame(() => {
            libraryReturnFrame.current = null;
            if (!origin) return;
            const target = document.getElementById(
                `hub-designer-section-${origin.sectionId}`,
            );
            const trigger = Array.from(
                target?.querySelectorAll('button') ?? [],
            ).find((button) =>
                button.getAttribute('aria-label')?.startsWith('Add block to '),
            );
            trigger?.focus({ preventScroll: true });
            window.scrollTo({ top: origin.scrollY });
        });
    };
    const addBlock = (block: HubBlock) => {
        if (editLocked || !librarySection || librarySection.blocks.length >= 12)
            return;
        section(librarySection.id, {
            blocks: [...librarySection.blocks, block],
        });
        setLibrarySectionId(null);
        setPickerDirty(false);
        jumpTo({ section: librarySection.id, block: block.id });
    };
    const jumpTo = (next: DesignerSelection) => {
        select(next);
        if (!next) return;
        window.requestAnimationFrame(() => {
            const element = document.getElementById(
                next.block
                    ? `hub-designer-block-${next.block}`
                    : `hub-designer-section-${next.section}`,
            );
            element?.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                    .matches
                    ? 'auto'
                    : 'smooth',
                block: 'center',
            });
            element
                ?.querySelector<HTMLButtonElement>('[data-designer-edit]')
                ?.focus({ preventScroll: true });
        });
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
            <div className="hub-editor space-y-3 pb-10">
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
                    className="hub-editor-topbar flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-3 py-2"
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
                                ) : heroDirty ? (
                                    'Hero preview not yet applied'
                                ) : pickerDirty ? (
                                    'Block setup not yet added'
                                ) : dirty ? (
                                    'Unsaved changes'
                                ) : (
                                    <>
                                        <Check className="size-3.5 text-status-success" />
                                        {hasSavedDraft
                                            ? 'Draft saved'
                                            : 'No saved draft'}
                                    </>
                                )}
                            </p>
                        </div>
                    </div>
                    <div
                        className="flex flex-wrap gap-2"
                        inert={
                            tabsOpen ||
                            !!heroDraft ||
                            !!librarySectionId ||
                            publicationOpen ||
                            history
                        }
                    >
                        <Button
                            variant="outline"
                            disabled={saving || busy || !!heroDraft}
                            onClick={() => setDraftOptions(true)}
                        >
                            <FileClock className="size-4" />
                            Draft options
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => setHistory(!history)}
                            ref={historyTrigger}
                            aria-expanded={history}
                        >
                            <History className="size-4" />
                            Versions
                        </Button>
                        <Button
                            variant="outline"
                            disabled={
                                !dirty || saving || editLocked || !!heroDraft
                            }
                            onClick={() => void save()}
                        >
                            <Save className="size-4" />
                            Save draft
                        </Button>
                        <Button
                            ref={publicationTrigger}
                            disabled={
                                dirty ||
                                saving ||
                                busy ||
                                !hasSavedDraft ||
                                editLocked ||
                                !!heroDraft ||
                                readiness?.content_ready === false
                            }
                            onClick={() => {
                                setPublicationOpen(true);
                                setHistory(false);
                            }}
                        >
                            <Send className="size-4" />
                            Review & publish
                        </Button>
                    </div>
                </Card>
                {readiness &&
                    !heroDraft &&
                    !librarySectionId &&
                    !publicationOpen &&
                    !history && (
                        <PublicationReadiness
                            readiness={readiness}
                            dirty={dirty}
                            saving={saving}
                            onFix={(issue) => {
                                setMode('edit');
                                if (issue.section_id)
                                    jumpTo({
                                        section: issue.section_id,
                                        ...(issue.block_id
                                            ? { block: issue.block_id }
                                            : {}),
                                    });
                                else select(null);
                            }}
                            onReview={() => setHistory(true)}
                        />
                    )}
                {!hasSavedDraft && draftRecovery?.recovery && (
                    <div
                        role="status"
                        className="flex items-center justify-between gap-4 rounded-xl border border-primary/20 bg-primary/5 p-4 text-sm"
                    >
                        <p>
                            <strong>A discarded draft is available.</strong>{' '}
                            Recover it before saving new changes if you want to
                            keep its content.
                        </p>
                        <Button
                            variant="outline"
                            disabled={saving || busy || !!heroDraft}
                            onClick={() => setDraftOptions(true)}
                        >
                            Review recovery options
                        </Button>
                    </div>
                )}
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
                <div
                    className="hub-editor-tools flex flex-wrap items-center gap-1"
                    inert={
                        tabsOpen ||
                        !!heroDraft ||
                        !!librarySectionId ||
                        publicationOpen ||
                        history
                    }
                >
                    <Button
                        variant="ghost"
                        disabled={
                            undo.length === 0 || saving || busy || !!heroDraft
                        }
                        onClick={undoChange}
                        ref={undoTrigger}
                    >
                        <Undo2 className="size-4" />
                        Undo
                    </Button>
                    <Button
                        variant="ghost"
                        disabled={!redo.length || saving || busy || !!heroDraft}
                        onClick={redoChange}
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
                            variant={
                                (editLocked ? 'desktop' : mode) === item.key
                                    ? 'secondary'
                                    : 'ghost'
                            }
                            disabled={editLocked && item.key === 'edit'}
                            onClick={() => setMode(item.key)}
                            aria-pressed={
                                (editLocked ? 'desktop' : mode) === item.key
                            }
                        >
                            <item.icon className="size-4" />
                            {item.label}
                        </Button>
                    ))}
                    <Button
                        variant="ghost"
                        onClick={() => setShortcutsOpen(true)}
                    >
                        <Keyboard className="size-4" />
                        Designer shortcuts
                    </Button>
                    <span className="text-caption ml-auto text-muted-foreground">
                        Changes stay private until published.
                    </span>
                </div>
                <HubSiteHeader
                    compactNavigation={false}
                    editHeroRef={heroTrigger}
                    content={heroDraft ? { ...content, ...heroDraft } : content}
                    summary={
                        page.kind === 'home'
                            ? previewData.heroSummary
                            : undefined
                    }
                    search=""
                    onSearch={() => {}}
                    onSubmit={(e) => e.preventDefault()}
                    onEditHero={
                        tabsOpen ||
                        !!heroDraft ||
                        saving ||
                        busy ||
                        editLocked ||
                        !!librarySectionId ||
                        publicationOpen ||
                        history
                            ? undefined
                            : () => openHero()
                    }
                >
                    {tabsOpen ? (
                        <div
                            ref={setTabRail}
                            className="flex min-h-11 w-full min-w-0 items-center"
                        />
                    ) : (
                        <div className="flex w-full min-w-0 items-center gap-3">
                            <div className="min-w-0 flex-1">
                                {(
                                    publishedNavigation ??
                                    previewData.sharedNavigation
                                )?.length ? (
                                    <HubNavigationRail
                                        items={
                                            publishedNavigation ??
                                            previewData.sharedNavigation!
                                        }
                                        current={
                                            page.kind === 'home'
                                                ? 'view:home'
                                                : `page:${page.id}`
                                        }
                                        onPreview={() => {}}
                                    />
                                ) : (
                                    <nav
                                        className="hub-nav-links"
                                        aria-label="Preview navigation"
                                    >
                                        <a
                                            href="#hub-editor-canvas"
                                            aria-current="page"
                                        >
                                            {page.kind === 'home'
                                                ? 'Home'
                                                : 'Page preview'}
                                        </a>
                                    </nav>
                                )}
                            </div>
                            {capabilities.layout && !demo && (
                                <Button
                                    variant="ghost"
                                    className="hub-glass-button mb-1 shrink-0"
                                    disabled={
                                        saving ||
                                        busy ||
                                        editLocked ||
                                        !!heroDraft ||
                                        !!librarySectionId ||
                                        publicationOpen ||
                                        history
                                    }
                                    onClick={() => {
                                        setMode('edit');
                                        setTabsOpen(true);
                                    }}
                                >
                                    <ListTree className="size-4" />
                                    Edit tabs
                                </Button>
                            )}
                        </div>
                    )}
                </HubSiteHeader>
                {tabsOpen && capabilities.layout && !demo && (
                    <EditHubTabs
                        editorSlots={{ rail: tabRail, inspector: tabInspector }}
                        onClose={() => setTabsOpen(false)}
                        onPublished={(workspace) =>
                            setPublishedNavigation(
                                workspace.published.filter(
                                    (item) =>
                                        item.type !== 'page' || !!item.target,
                                ),
                            )
                        }
                    />
                )}
                {heroDraft && !editLocked ? (
                    <HeroWorkspace
                        pageId={demo ? 0 : page.id}
                        isHome={page.kind === 'home'}
                        value={heroDraft}
                        original={heroPreview(content)}
                        onChange={setHeroDraft}
                        onApply={applyHero}
                        onCancel={closeHero}
                    />
                ) : history ? (
                    <RevisionHistory
                        pageId={page.id}
                        version={version}
                        revisions={revisions}
                        actorId={
                            initialReadiness?.actor_id ??
                            draftRecovery?.actor_id
                        }
                        hasSavedDraft={!demo && hasSavedDraft}
                        cursor={revisionCursor}
                        restoreBlocked={editLocked}
                        disabled={dirty || saving || busy || demo}
                        onClose={() => {
                            setHistory(false);
                            onHistoryRefreshIntent(false);
                            requestAnimationFrame(() =>
                                historyTrigger.current?.focus(),
                            );
                        }}
                        onChanged={() =>
                            router.reload({
                                only: [
                                    'page',
                                    'revisions',
                                    'revisionCursor',
                                    'previewData',
                                    'draftRecovery',
                                    'readiness',
                                ],
                                preserveScroll: true,
                            })
                        }
                    />
                ) : publicationOpen ? (
                    <PublicationWorkspace
                        pageId={page.id}
                        kind={page.kind}
                        version={version}
                        content={content}
                        readiness={readiness}
                        revisions={revisions}
                        canPublish={capabilities.publish}
                        dirty={dirty}
                        saving={saving}
                        busy={busy}
                        sites={sites}
                        departments={departments}
                        owners={owners}
                        owner={owner}
                        currentOwner={page.owner_id}
                        onOwner={setOwner}
                        onAssign={() =>
                            mutate(`/company-hub/manage/${page.id}/owner`, {
                                owner_id: Number(owner),
                            })
                        }
                        onContent={field}
                        onSave={save}
                        demo={demo}
                        onBack={() => {
                            setPublicationOpen(false);
                            requestAnimationFrame(() =>
                                publicationTrigger.current?.focus(),
                            );
                        }}
                        onFix={(issue) => {
                            setPublicationOpen(false);
                            setMode('edit');
                            jumpTo(
                                issue.section_id
                                    ? {
                                          section: issue.section_id,
                                          ...(issue.block_id
                                              ? { block: issue.block_id }
                                              : {}),
                                      }
                                    : null,
                            );
                        }}
                        onReview={() =>
                            new Promise<boolean>((resolve) => {
                                let refreshed = false;
                                onHistoryRefreshIntent(true);
                                router.reload({
                                    only: [
                                        'page',
                                        'revisions',
                                        'revisionCursor',
                                        'previewData',
                                        'draftRecovery',
                                        'readiness',
                                    ],
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        refreshed = true;
                                        setPublicationOpen(false);
                                        setHistory(true);
                                    },
                                    onFinish: () => {
                                        if (!refreshed)
                                            onHistoryRefreshIntent(false);
                                        resolve(refreshed);
                                    },
                                });
                            })
                        }
                        onPublished={() => {
                            setPublicationOpen(false);
                            if (demo) {
                                setMode('desktop');
                                setDemoNotice(
                                    'Publication preview. Your demo changes have not been saved to the company hub.',
                                );
                            } else setHistory(true);
                        }}
                    />
                ) : librarySection && !editLocked ? (
                    <BlockLibraryPicker
                        key={librarySection.id}
                        section={librarySection}
                        data={previewSources}
                        pageId={page.id}
                        sourceTeamIds={
                            page.kind === 'department'
                                ? (content.team_ids ?? [])
                                : undefined
                        }
                        onMedia={rememberMedia}
                        onDirtyChange={setPickerDirty}
                        onAdd={addBlock}
                        onClose={closeBlockLibrary}
                    />
                ) : mode !== 'edit' || editLocked ? (
                    <div
                        className="hub-editor-preview space-y-6"
                        data-device={mode}
                    >
                        {page.kind !== 'home' && (
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
                                    preview
                                    sections={content.sections}
                                    data={{
                                        ...previewData,
                                        blockData: canvasData.blockData,
                                        participation: null,
                                        capabilities: {
                                            ...previewData.capabilities,
                                            recognise: false,
                                        },
                                    }}
                                    onEvent={setEvent}
                                />
                            </KnowledgeReader>
                        ) : page.kind === 'campaign' ? (
                            <CampaignReader
                                content={content}
                                campaign={campaignPreview(
                                    content,
                                    page.owner_id,
                                    previewData,
                                )}
                                data={{
                                    ...previewData,
                                    blockData: canvasData.blockData,
                                    participation: null,
                                    capabilities: {
                                        ...previewData.capabilities,
                                        recognise: false,
                                    },
                                }}
                                onEvent={setEvent}
                                preview
                            />
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
                                    blockData: canvasData.blockData,
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
                                preview
                                sections={content.sections}
                                data={{
                                    ...previewData,
                                    blockData: canvasData.blockData,
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
                            className="hub-editor-settings hub-designer-inspector space-y-3 rounded-lg border border-border bg-card p-3"
                            aria-label="Designer settings"
                        >
                            <div ref={setTabInspector} hidden={!tabsOpen} />
                            <div hidden={tabsOpen} className="space-y-3">
                                <nav
                                    className="hub-inspector-tabs"
                                    aria-label="Designer panel"
                                >
                                    <Button
                                        variant="ghost"
                                        aria-pressed={
                                            inspectorPanel === 'settings'
                                        }
                                        onClick={() =>
                                            setInspectorPanel('settings')
                                        }
                                    >
                                        <Settings2 className="size-3.5" />
                                        Settings
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        aria-pressed={
                                            inspectorPanel === 'outline'
                                        }
                                        onClick={() =>
                                            setInspectorPanel('outline')
                                        }
                                    >
                                        <ListTree className="size-3.5" />
                                        Page outline
                                    </Button>
                                </nav>
                                {inspectorPanel === 'outline' && (
                                    <PageOutline
                                        sections={content.sections}
                                        selection={selection}
                                        onSelect={jumpTo}
                                        onAddBlock={openBlockLibrary}
                                        onAddSection={addSection}
                                    />
                                )}
                                <div
                                    className="space-y-5"
                                    hidden={inspectorPanel !== 'settings'}
                                >
                                    {selectedSection && (
                                        <Button
                                            variant="ghost"
                                            onClick={() => select(null)}
                                        >
                                            <ArrowLeft className="size-4" />
                                            Page & hero settings
                                        </Button>
                                    )}
                                    {selectedSection && !selectedBlock && (
                                        <SectionSettings
                                            section={selectedSection}
                                            onChange={(update) =>
                                                section(
                                                    selectedSection.id,
                                                    update,
                                                )
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
                                                        section(
                                                            selectedSection.id,
                                                            {
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
                                                            },
                                                        )
                                                    }
                                                    options={[
                                                        {
                                                            value: '4',
                                                            label: 'One third',
                                                        },
                                                        {
                                                            value: '6',
                                                            label: 'Half',
                                                        },
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
                                                                (s) =>
                                                                    s.id === id,
                                                            );
                                                        if (
                                                            !destination ||
                                                            destination.blocks
                                                                .length >= 12
                                                        )
                                                            return;
                                                        field(
                                                            'sections',
                                                            content.sections.map(
                                                                (s) =>
                                                                    s.id ===
                                                                    selectedSection.id
                                                                        ? {
                                                                              ...s,
                                                                              blocks: s.blocks.filter(
                                                                                  (
                                                                                      b,
                                                                                  ) =>
                                                                                      b.id !==
                                                                                      selectedBlock.id,
                                                                              ),
                                                                          }
                                                                        : s.id ===
                                                                            id
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
                                                        select({
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
                                                data={canvasData}
                                                onMedia={rememberMedia}
                                                block={selectedBlock}
                                                pageId={page.id}
                                                onChange={(update) =>
                                                    section(
                                                        selectedSection.id,
                                                        {
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
                                                        },
                                                    )
                                                }
                                                onRemove={() => {
                                                    section(
                                                        selectedSection.id,
                                                        {
                                                            blocks: selectedSection.blocks.filter(
                                                                (b) =>
                                                                    b.id !==
                                                                    selectedBlock.id,
                                                            ),
                                                        },
                                                    );
                                                    select({
                                                        section:
                                                            selectedSection.id,
                                                    });
                                                }}
                                                onMove={(direction) =>
                                                    section(
                                                        selectedSection.id,
                                                        {
                                                            blocks: arrayMove(
                                                                selectedSection.blocks,
                                                                selectedSection.blocks.indexOf(
                                                                    selectedBlock,
                                                                ),
                                                                selectedSection.blocks.indexOf(
                                                                    selectedBlock,
                                                                ) + direction,
                                                            ),
                                                        },
                                                    )
                                                }
                                                first={
                                                    selectedSection.blocks[0]
                                                        ?.id ===
                                                    selectedBlock.id
                                                }
                                                last={
                                                    selectedSection.blocks.at(
                                                        -1,
                                                    )?.id === selectedBlock.id
                                                }
                                            />
                                            {selectedBlock.type === 'file' &&
                                                !!selectedBlock.media_id &&
                                                !demo && (
                                                    <div className="space-y-2">
                                                        <Button
                                                            variant="outline"
                                                            disabled={
                                                                dirty ||
                                                                saving ||
                                                                busy ||
                                                                editLocked ||
                                                                !hasSavedDraft
                                                            }
                                                            onClick={() =>
                                                                router.visit(
                                                                    `/company-hub/manage/${page.id}/documents/${selectedBlock.media_id}/edit`,
                                                                )
                                                            }
                                                        >
                                                            Versions &amp;
                                                            replacement
                                                        </Button>
                                                        {dirty && (
                                                            <p className="text-caption text-muted-foreground">
                                                                Save this guide
                                                                first to manage
                                                                the selected
                                                                document’s
                                                                versions.
                                                            </p>
                                                        )}
                                                    </div>
                                                )}
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
                                                Shape the welcome, story and
                                                audience.
                                            </p>
                                        </div>
                                        <Field label="Title">
                                            <Input
                                                value={content.title}
                                                maxLength={160}
                                                onChange={(e) =>
                                                    field(
                                                        'title',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field label="Introduction">
                                            <Textarea
                                                value={content.summary ?? ''}
                                                maxLength={600}
                                                rows={3}
                                                onChange={(e) =>
                                                    field(
                                                        'summary',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field label="Hero label">
                                            <Input
                                                value={content.hero_label}
                                                maxLength={60}
                                                onChange={(e) =>
                                                    field(
                                                        'hero_label',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field label="Category">
                                            <Input
                                                value={content.category}
                                                maxLength={60}
                                                onChange={(e) =>
                                                    field(
                                                        'category',
                                                        e.target.value,
                                                    )
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
                                        {['page', 'knowledge'].includes(
                                            page.kind,
                                        ) && (
                                            <>
                                                <ResourceCollectionPicker
                                                    actorId={
                                                        initialReadiness?.actor_id ??
                                                        draftRecovery?.actor_id ??
                                                        null
                                                    }
                                                    content={content}
                                                    preview={demo}
                                                    onChange={(changes) => {
                                                        if (editLocked) return;
                                                        setError('');
                                                        setRedo([]);
                                                        setUndo((items) => [
                                                            ...items.slice(-39),
                                                            content,
                                                        ]);
                                                        setContent(
                                                            (current) => ({
                                                                ...current,
                                                                ...changes,
                                                            }),
                                                        );
                                                    }}
                                                />
                                                <Field label="Resource type">
                                                    <HubSelect
                                                        label="Resource type"
                                                        value={
                                                            content.resource_type ??
                                                            'Information'
                                                        }
                                                        onChange={(value) =>
                                                            field(
                                                                'resource_type',
                                                                value,
                                                            )
                                                        }
                                                        options={[
                                                            ...(!resourceTypes.includes(
                                                                content.resource_type ??
                                                                    'Information',
                                                            )
                                                                ? [
                                                                      {
                                                                          value:
                                                                              content.resource_type ??
                                                                              '',
                                                                          label:
                                                                              content.resource_type ||
                                                                              'No resource type selected',
                                                                          disabled: true,
                                                                      },
                                                                  ]
                                                                : []),
                                                            ...resourceTypes.map(
                                                                (value) => ({
                                                                    value,
                                                                    label: value,
                                                                }),
                                                            ),
                                                        ]}
                                                    />
                                                </Field>
                                                {supportsPersonalSteps(
                                                    content,
                                                ) && (
                                                    <ChecklistSettings
                                                        value={
                                                            content.checklist
                                                        }
                                                        onChange={(steps) =>
                                                            field(
                                                                'checklist',
                                                                steps,
                                                            )
                                                        }
                                                    />
                                                )}
                                            </>
                                        )}
                                        <div className="rounded-xl border bg-muted/30 p-4">
                                            <h3 className="text-sm font-semibold">
                                                Hero banner
                                            </h3>
                                            <p className="my-2 text-xs leading-5 text-muted-foreground">
                                                Preview your image, colours,
                                                layout and welcome message
                                                together before applying them.
                                            </p>
                                            <Button
                                                variant="outline"
                                                className="w-full"
                                                disabled={saving || busy}
                                                onClick={() => openHero(true)}
                                                ref={heroSidebarTrigger}
                                            >
                                                <Settings2 className="size-4" />
                                                Edit hero banner
                                            </Button>
                                        </div>
                                        {page.kind === 'campaign' && (
                                            <CampaignSettings
                                                data={previewData}
                                                value={content.campaign}
                                                onChange={(campaign) =>
                                                    field('campaign', campaign)
                                                }
                                            />
                                        )}
                                        {page.kind !== 'home' && (
                                            <>
                                                <Field label="Planned publication date">
                                                    <HubDateField
                                                        label="Planned publication date"
                                                        value={
                                                            content.planned_for ??
                                                            ''
                                                        }
                                                        onChange={(value) =>
                                                            field(
                                                                'planned_for',
                                                                value || null,
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
                                                {page.kind === 'news' && (
                                                    <Toggle
                                                        label="Show in company news"
                                                        checked={
                                                            content.show_in_news !==
                                                            false
                                                        }
                                                        onChange={(value) =>
                                                            field(
                                                                'show_in_news',
                                                                value,
                                                            )
                                                        }
                                                    />
                                                )}
                                                <Field label="Who can see this?">
                                                    <Choose
                                                        value={
                                                            content.audience
                                                                .type
                                                        }
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
                                                                    : value ===
                                                                        'sites'
                                                                      ? 'Selected sites'
                                                                      : 'Selected departments',
                                                        }))}
                                                    />
                                                </Field>
                                                {content.audience.type !==
                                                    'all' && (
                                                    <div className="space-y-2">
                                                        {(content.audience
                                                            .type === 'sites'
                                                            ? sites
                                                            : departments
                                                        ).map((option) => (
                                                            <Toggle
                                                                key={option.id}
                                                                label={
                                                                    option.name
                                                                }
                                                                checked={content.audience.ids.includes(
                                                                    option.id,
                                                                )}
                                                                onChange={(v) =>
                                                                    field(
                                                                        'audience',
                                                                        {
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
                                                                        },
                                                                    )
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
                                                    checked={
                                                        content.comments_enabled
                                                    }
                                                    onChange={(v) =>
                                                        field(
                                                            'comments_enabled',
                                                            v,
                                                        )
                                                    }
                                                />
                                                <Toggle
                                                    label="Allow reactions"
                                                    checked={
                                                        content.reactions_enabled
                                                    }
                                                    onChange={(v) =>
                                                        field(
                                                            'reactions_enabled',
                                                            v,
                                                        )
                                                    }
                                                />
                                                <Toggle
                                                    label="Require another publisher’s approval"
                                                    checked={
                                                        content.requires_approval
                                                    }
                                                    onChange={(v) =>
                                                        field(
                                                            'requires_approval',
                                                            v,
                                                        )
                                                    }
                                                />
                                                {capabilities.layout && (
                                                    <p className="text-sm text-muted-foreground">
                                                        After publishing, add
                                                        this page to the shared
                                                        menu in{' '}
                                                        <Link
                                                            className="text-primary underline"
                                                            href="/company-hub?view=publishing"
                                                        >
                                                            Publishing →
                                                            Navigation
                                                        </Link>
                                                        . Menu changes have
                                                        their own draft and
                                                        publish step.
                                                    </p>
                                                )}
                                                <Field label="Review date">
                                                    <HubDateField
                                                        label="Review date"
                                                        value={
                                                            content.review_on ??
                                                            ''
                                                        }
                                                        onChange={(value) =>
                                                            field(
                                                                'review_on',
                                                                value || null,
                                                            )
                                                        }
                                                    />
                                                </Field>
                                                {owners.length > 0 && (
                                                    <div className="space-y-2">
                                                        <Field label="Page owner">
                                                            <Choose
                                                                value={owner}
                                                                onChange={
                                                                    setOwner
                                                                }
                                                                options={owners.map(
                                                                    (
                                                                        person,
                                                                    ) => ({
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
                                                    <div>
                                                        <p className="text-sm text-muted-foreground">
                                                            Publication times
                                                            use New Zealand
                                                            time.
                                                        </p>
                                                        <Button
                                                            variant="outline"
                                                            disabled={
                                                                dirty ||
                                                                saving ||
                                                                busy
                                                            }
                                                            onClick={() => {
                                                                setPublicationOpen(
                                                                    true,
                                                                );
                                                                setHistory(
                                                                    false,
                                                                );
                                                            }}
                                                        >
                                                            Review audience &
                                                            timing
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        </details>
                                        {capabilities.publish &&
                                            page.kind !== 'home' &&
                                            !page.archived_at && (
                                                <Button
                                                    variant="outline"
                                                    disabled={
                                                        dirty || saving || busy
                                                    }
                                                    onClick={() =>
                                                        setArchiveOpen(true)
                                                    }
                                                >
                                                    Archive page
                                                </Button>
                                            )}
                                    </div>
                                </div>
                            </div>
                        </aside>
                        <div
                            id="hub-editor-canvas"
                            className="hub-editor-canvas min-w-0"
                            inert={tabsOpen}
                        >
                            <div className="hub-designer-topline">
                                <span>
                                    Drag a grip to move · Resize a corner
                                </span>
                                <Button
                                    variant="ghost"
                                    onClick={() => select(null)}
                                >
                                    Page settings
                                </Button>
                            </div>
                            <HubDesigner
                                onSectionRemoved={() => {
                                    if (editorFocusFrame.current !== null)
                                        window.cancelAnimationFrame(
                                            editorFocusFrame.current,
                                        );
                                    editorFocusFrame.current =
                                        requestAnimationFrame(() =>
                                            undoTrigger.current?.focus(),
                                        );
                                }}
                                sections={content.sections}
                                data={canvasData}
                                selection={selection}
                                onSelect={select}
                                onAddBlock={openBlockLibrary}
                                onAddSection={addSection}
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
            {leave.dialog}
            {draftOptions && (
                <DraftTools
                    editable={!editLocked}
                    pageId={page.id}
                    version={version}
                    actorId={draftRecovery?.actor_id ?? null}
                    dirty={dirty}
                    demo={demo}
                    onDuplicate={
                        page.kind !== 'home' && capabilities.write && !demo
                            ? () => {
                                  setDraftOptions(false);
                                  setCopyOpen(true);
                              }
                            : undefined
                    }
                    starters={starterLayouts}
                    onClose={() => setDraftOptions(false)}
                    onReset={(sections) => {
                        field('sections', sections);
                        select(null);
                    }}
                    onChanged={() =>
                        router.reload({
                            only: [
                                'page',
                                'draftRecovery',
                                'readiness',
                                'revisions',
                                'revisionCursor',
                                'previewData',
                            ],
                        })
                    }
                />
            )}
            {copyOpen &&
                !demo &&
                capabilities.write &&
                page.kind !== 'home' && (
                    <DuplicateHubPage
                        pageId={page.id}
                        version={version}
                        actorId={draftRecovery?.actor_id ?? null}
                        title={content.title}
                        dirty={dirty}
                        onSave={save}
                        onClose={() => setCopyOpen(false)}
                    />
                )}
            <DesignerShortcuts
                open={shortcutsOpen}
                onClose={() => setShortcutsOpen(false)}
            />
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
                    <SelectItem
                        key={option.value}
                        value={option.value}
                        className="focus:text-primary"
                    >
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
