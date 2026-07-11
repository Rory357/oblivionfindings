import { useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { AlertCircle, CalendarDays, FolderOpen, Loader2 } from 'lucide-react';

export interface MeetingWithoutPack {
    id: number;
    title: string;
    scheduled_at: string | null;
    status: string;
    agenda_items_count: number;
}

interface GenerateBoardPackDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetings: MeetingWithoutPack[];
}

function formatScheduled(iso: string | null): string {
    if (!iso) return 'Not scheduled';
    return new Date(iso).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function GenerateBoardPackDialog({
    isOpen,
    onClose,
    meetings,
}: GenerateBoardPackDialogProps) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [generating, setGenerating] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleGenerate = async () => {
        if (!selectedId) return;
        setGenerating(true);
        setError(null);
        try {
            const response = await axios.post(
                `/governance/meetings/${selectedId}/packs`,
                {},
                { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
            );
            const status = response.data?.status as string | undefined;
            if (status === 'generated' && response.data?.pack_id) {
                onClose();
                router.visit(`/governance/packs/${response.data.pack_id}`);
            } else {
                onClose();
                router.reload();
            }
        } catch (err: any) {
            const status = err?.response?.status;
            const message =
                err?.response?.data?.message ??
                (status === 403
                    ? 'You do not have permission to generate this pack.'
                    : 'Failed to generate the board pack. Make sure the meeting has at least one agenda item.');
            setError(message);
        } finally {
            setGenerating(false);
        }
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[85vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <FolderOpen className="h-4 w-4 text-primary" />
                        Generate Board Pack
                    </DialogTitle>
                    <DialogDescription>
                        A board pack is generated from a meeting&apos;s agenda, CEO report,
                        resolutions, and attendance. Pick a meeting below — one pack per meeting.
                    </DialogDescription>
                </DialogHeader>

                {meetings.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                        Every scheduled meeting already has a board pack. Add a new meeting first.
                    </div>
                ) : (
                    <div className="space-y-2">
                        {meetings.map((m) => {
                            const active = selectedId === m.id;
                            const hasAgenda = m.agenda_items_count > 0;
                            return (
                                <Button unstyled
                                    key={m.id}
                                    type="button"
                                    onClick={() => setSelectedId(m.id)}
                                    className={cn(
                                        'flex w-full items-start gap-3 rounded-xl border bg-card/40 p-3 text-left transition-all',
                                        'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                                        active
                                            ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                            : 'border-border',
                                    )}
                                    aria-pressed={active}
                                    dusk={`pack-meeting-${m.id}`}
                                >
                                    <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                                        <CalendarDays className="h-4 w-4 text-status-info" />
                                    </span>
                                    <span className="min-w-0 flex-1 space-y-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="truncate text-sm font-medium">{m.title}</span>
                                            <Badge variant="outline" className="text-[10px] uppercase">
                                                {m.status}
                                            </Badge>
                                            <Badge
                                                variant="outline"
                                                className={cn(
                                                    'text-[10px]',
                                                    hasAgenda
                                                        ? 'border-status-success/30 text-status-success'
                                                        : 'border-status-warning/30 text-status-warning',
                                                )}
                                            >
                                                {m.agenda_items_count} agenda{' '}
                                                {m.agenda_items_count === 1 ? 'item' : 'items'}
                                            </Badge>
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            Scheduled {formatScheduled(m.scheduled_at)}
                                        </span>
                                        {!hasAgenda && (
                                            <span className="block text-xs italic text-status-warning">
                                                Add at least one agenda item before generating.
                                            </span>
                                        )}
                                    </span>
                                </Button>
                            );
                        })}
                    </div>
                )}

                {error && (
                    <div className="flex items-start gap-2 rounded-md border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                        <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{error}</span>
                    </div>
                )}

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={handleGenerate}
                        disabled={!selectedId || generating}
                        dusk="generate-pack-confirm"
                    >
                        {generating && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Generate pack
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default GenerateBoardPackDialog;
