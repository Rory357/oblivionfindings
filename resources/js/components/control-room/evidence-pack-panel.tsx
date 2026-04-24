import { useState, useRef } from 'react';
import { router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Camera,
    ChevronDown,
    Download,
    FileCode,
    FileText,
    CheckCircle,
    Mic,
    Plus,
    Upload,
    Video,
    X,
    StickyNote,
    PackagePlus,
} from 'lucide-react';

/* ------------------------------------------------------------------
 * Types
 * ---------------------------------------------------------------- */

interface EvidenceItem {
    id: number;
    type: string;
    title: string | null;
    description: string | null;
    file_path: string | null;
    file_size: number | null;
    mime_type: string | null;
    external_system: string | null;
    external_ref: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
}

interface EvidencePack {
    id: number;
    title: string;
    status: string;
    item_count: number;
    items: EvidenceItem[];
    created_at?: string;
}

interface EvidencePackPanelProps {
    alertId: number;
    packs: EvidencePack[];
    canManage: boolean;
}

/* ------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------- */

function typeIcon(type: string) {
    switch (type) {
        case 'photo':
            return <Camera className="h-4 w-4 text-blue-500" />;
        case 'document':
            return <FileText className="h-4 w-4 text-amber-500" />;
        case 'cctv_bookmark':
            return <Video className="h-4 w-4 text-primary" />;
        case 'note':
            return <StickyNote className="h-4 w-4 text-green-500" />;
        case 'audio':
            return <Mic className="h-4 w-4 text-rose-500" />;
        case 'door_log':
            return <FileCode className="h-4 w-4 text-muted-foreground" />;
        default:
            return <FileText className="h-4 w-4 text-muted-foreground" />;
    }
}

function statusBadge(status: string) {
    switch (status) {
        case 'collecting':
            return <Badge variant="outline">Collecting</Badge>;
        case 'complete':
            return <Badge variant="secondary">Complete</Badge>;
        case 'exported':
            return <Badge variant="default">Exported</Badge>;
        default:
            return <Badge variant="outline">{status}</Badge>;
    }
}

function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/* ------------------------------------------------------------------
 * Component
 * ---------------------------------------------------------------- */

export default function EvidencePackPanel({ alertId, packs, canManage }: EvidencePackPanelProps) {
    const [showCreatePack, setShowCreatePack] = useState(false);
    const [packTitle, setPackTitle] = useState('');
    const [creatingPack, setCreatingPack] = useState(false);

    const [showNoteDialog, setShowNoteDialog] = useState(false);
    const [notePackId, setNotePackId] = useState<number | null>(null);
    const [noteContent, setNoteContent] = useState('');
    const [savingNote, setSavingNote] = useState(false);

    const [showCctvDialog, setShowCctvDialog] = useState(false);
    const [cctvPackId, setCctvPackId] = useState<number | null>(null);
    const [cctvCameraId, setCctvCameraId] = useState('');
    const [cctvTimestamp, setCctvTimestamp] = useState('');
    const [savingCctv, setSavingCctv] = useState(false);

    const [deletingItemId, setDeletingItemId] = useState<number | null>(null);

    const fileInputRefs = useRef<Record<number, HTMLInputElement | null>>({});

    /* --- Actions --- */

    function handleCreatePack() {
        setCreatingPack(true);
        router.post(
            `/control-room/alerts/${alertId}/evidence`,
            { title: packTitle },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowCreatePack(false);
                    setPackTitle('');
                },
                onFinish: () => setCreatingPack(false),
            },
        );
    }

    function handleFileUpload(packId: number, file: File) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('item_type', 'file');

        router.post(`/control-room/evidence/${packId}/items`, formData as any, {
            preserveScroll: true,
            forceFormData: true,
        });
    }

    function handleAddNote() {
        if (!notePackId) return;
        setSavingNote(true);
        router.post(
            `/control-room/evidence/${notePackId}/items`,
            { item_type: 'note', content: noteContent },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowNoteDialog(false);
                    setNoteContent('');
                    setNotePackId(null);
                },
                onFinish: () => setSavingNote(false),
            },
        );
    }

    function handleAddCctvBookmark() {
        if (!cctvPackId) return;
        setSavingCctv(true);
        router.post(
            `/control-room/evidence/${cctvPackId}/items`,
            { item_type: 'cctv_bookmark', camera_id: cctvCameraId, timestamp: cctvTimestamp },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowCctvDialog(false);
                    setCctvCameraId('');
                    setCctvTimestamp('');
                    setCctvPackId(null);
                },
                onFinish: () => setSavingCctv(false),
            },
        );
    }

    function handleDeleteItem(itemId: number) {
        setDeletingItemId(null);
        router.delete(`/control-room/evidence/items/${itemId}`, {
            preserveScroll: true,
        });
    }

    function handleCompletePack(packId: number) {
        router.post(`/control-room/evidence/${packId}/complete`, {}, {
            preserveScroll: true,
        });
    }

    /* --- Render --- */

    return (
        <>
            <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                    <CardTitle className="text-base font-semibold">Evidence</CardTitle>
                    {canManage && (
                        <Button size="sm" variant="outline" onClick={() => setShowCreatePack(true)}>
                            <PackagePlus className="mr-1.5 h-4 w-4" />
                            New Pack
                        </Button>
                    )}
                </CardHeader>
                <CardContent className="space-y-3">
                    {packs.length === 0 && (
                        <p className="text-sm text-muted-foreground">No evidence packs yet.</p>
                    )}
                    {packs.map((pack) => (
                        <PackSection
                            key={pack.id}
                            pack={pack}
                            canManage={canManage}
                            fileInputRefs={fileInputRefs}
                            onFileUpload={handleFileUpload}
                            onOpenNoteDialog={(packId) => {
                                setNotePackId(packId);
                                setShowNoteDialog(true);
                            }}
                            onOpenCctvDialog={(packId) => {
                                setCctvPackId(packId);
                                setShowCctvDialog(true);
                            }}
                            onDeleteItem={setDeletingItemId}
                            onCompletePack={handleCompletePack}
                        />
                    ))}
                </CardContent>
            </Card>

            {/* Create Pack Dialog */}
            <Dialog open={showCreatePack} onOpenChange={setShowCreatePack}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New Evidence Pack</DialogTitle>
                        <DialogDescription>Create a new pack to collect evidence for this alert.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="pack-title">Title</Label>
                        <Input
                            id="pack-title"
                            value={packTitle}
                            onChange={(e) => setPackTitle(e.target.value)}
                            placeholder="e.g. Initial Response Evidence"
                            maxLength={200}
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowCreatePack(false)}>
                            Cancel
                        </Button>
                        <Button onClick={handleCreatePack} disabled={!packTitle.trim() || creatingPack}>
                            {creatingPack ? 'Creating...' : 'Create Pack'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Add Note Dialog */}
            <Dialog open={showNoteDialog} onOpenChange={setShowNoteDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Note</DialogTitle>
                        <DialogDescription>Add a text note to the evidence pack.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="note-content">Note</Label>
                        <Textarea
                            id="note-content"
                            value={noteContent}
                            onChange={(e) => setNoteContent(e.target.value)}
                            placeholder="Enter evidence note..."
                            rows={4}
                            maxLength={5000}
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowNoteDialog(false)}>
                            Cancel
                        </Button>
                        <Button onClick={handleAddNote} disabled={!noteContent.trim() || savingNote}>
                            {savingNote ? 'Saving...' : 'Add Note'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Add CCTV Bookmark Dialog */}
            <Dialog open={showCctvDialog} onOpenChange={setShowCctvDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add CCTV Bookmark</DialogTitle>
                        <DialogDescription>Bookmark a CCTV camera timestamp for review.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="cctv-camera">Camera ID</Label>
                            <Input
                                id="cctv-camera"
                                value={cctvCameraId}
                                onChange={(e) => setCctvCameraId(e.target.value)}
                                placeholder="e.g. CAM-LOBBY-01"
                                maxLength={100}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="cctv-timestamp">Timestamp</Label>
                            <Input
                                id="cctv-timestamp"
                                type="datetime-local"
                                value={cctvTimestamp}
                                onChange={(e) => setCctvTimestamp(e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowCctvDialog(false)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={handleAddCctvBookmark}
                            disabled={!cctvCameraId.trim() || !cctvTimestamp || savingCctv}
                        >
                            {savingCctv ? 'Saving...' : 'Add Bookmark'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation Dialog */}
            <Dialog open={deletingItemId !== null} onOpenChange={() => setDeletingItemId(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Remove Evidence Item</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to remove this evidence item? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingItemId(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => deletingItemId && handleDeleteItem(deletingItemId)}
                        >
                            Remove
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/* ------------------------------------------------------------------
 * Pack Section (collapsible)
 * ---------------------------------------------------------------- */

interface PackSectionProps {
    pack: EvidencePack;
    canManage: boolean;
    fileInputRefs: React.MutableRefObject<Record<number, HTMLInputElement | null>>;
    onFileUpload: (packId: number, file: File) => void;
    onOpenNoteDialog: (packId: number) => void;
    onOpenCctvDialog: (packId: number) => void;
    onDeleteItem: (itemId: number) => void;
    onCompletePack: (packId: number) => void;
}

function PackSection({
    pack,
    canManage,
    fileInputRefs,
    onFileUpload,
    onOpenNoteDialog,
    onOpenCctvDialog,
    onDeleteItem,
    onCompletePack,
}: PackSectionProps) {
    const [open, setOpen] = useState(true);

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="rounded-md border">
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm font-medium hover:bg-muted/50"
                >
                    <div className="flex items-center gap-2">
                        <ChevronDown
                            className={`h-4 w-4 shrink-0 transition-transform ${open ? '' : '-rotate-90'}`}
                        />
                        <span className="truncate">{pack.title}</span>
                        {statusBadge(pack.status)}
                        <span className="text-xs text-muted-foreground">
                            {pack.item_count} {pack.item_count === 1 ? 'item' : 'items'}
                        </span>
                    </div>
                </button>
            </CollapsibleTrigger>

            <CollapsibleContent>
                <div className="border-t px-3 py-2 space-y-2">
                    {/* Items list */}
                    {pack.items.length === 0 && (
                        <p className="text-xs text-muted-foreground py-1">No items in this pack.</p>
                    )}
                    {pack.items.map((item) => (
                        <div
                            key={item.id}
                            className="flex items-start justify-between gap-2 rounded-md bg-muted/30 px-2.5 py-1.5 text-sm"
                        >
                            <div className="flex items-start gap-2 min-w-0">
                                <span className="mt-0.5 shrink-0">{typeIcon(item.type)}</span>
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-sm">
                                        {item.title || item.description || item.type}
                                    </p>
                                    {item.description && item.type === 'note' && (
                                        <p className="text-xs text-muted-foreground line-clamp-2 mt-0.5">
                                            {item.description}
                                        </p>
                                    )}
                                    <div className="flex items-center gap-2 text-xs text-muted-foreground mt-0.5">
                                        {item.file_size ? <span>{formatBytes(item.file_size)}</span> : null}
                                        {item.created_at && <span>{formatDate(item.created_at)}</span>}
                                    </div>
                                </div>
                            </div>
                            {canManage && pack.status === 'collecting' && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-6 w-6 shrink-0 p-0 text-muted-foreground hover:text-destructive"
                                    onClick={() => onDeleteItem(item.id)}
                                >
                                    <X className="h-3.5 w-3.5" />
                                </Button>
                            )}
                        </div>
                    ))}

                    {/* Action buttons */}
                    {canManage && (
                        <div className="flex items-center gap-2 pt-1">
                            {pack.status === 'collecting' && (
                                <>
                                    {/* Hidden file input */}
                                    <input
                                        ref={(el) => {
                                            fileInputRefs.current[pack.id] = el;
                                        }}
                                        type="file"
                                        className="hidden"
                                        accept="image/*,.pdf,.doc,.docx"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            if (file) {
                                                onFileUpload(pack.id, file);
                                                e.target.value = '';
                                            }
                                        }}
                                    />

                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button size="sm" variant="outline">
                                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                                Add Evidence
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="start">
                                            <DropdownMenuItem
                                                onClick={() => fileInputRefs.current[pack.id]?.click()}
                                            >
                                                <Upload className="mr-2 h-4 w-4" />
                                                Upload File
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => onOpenNoteDialog(pack.id)}>
                                                <StickyNote className="mr-2 h-4 w-4" />
                                                Add Note
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => onOpenCctvDialog(pack.id)}>
                                                <Video className="mr-2 h-4 w-4" />
                                                Add CCTV Bookmark
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>

                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => onCompletePack(pack.id)}
                                    >
                                        <CheckCircle className="mr-1.5 h-3.5 w-3.5" />
                                        Mark Complete
                                    </Button>
                                </>
                            )}

                            {pack.status === 'complete' && (
                                <a
                                    href={`/control-room/evidence/${pack.id}/export`}
                                    className="inline-flex"
                                >
                                    <Button size="sm" variant="outline">
                                        <Download className="mr-1.5 h-3.5 w-3.5" />
                                        Export ZIP
                                    </Button>
                                </a>
                            )}
                        </div>
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
