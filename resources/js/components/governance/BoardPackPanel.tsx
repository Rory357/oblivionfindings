import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/react';
import { BookOpen, Upload, FileText, Eye } from 'lucide-react';

export interface BoardPackPayload {
    meeting_id: number;
    meeting_title: string;
    ready: boolean;
    distributed: boolean;
    distributed_label: string | null;
    doc_count: number;
    distributed_count: number;
    read_count: number;
    href: string;
    updated_at: string | null;
}

interface BoardPackPanelProps {
    pack: BoardPackPayload | null;
    canUploadPack?: boolean;
}

/**
 * Board Pack panel — makes reading and acknowledging the next meeting's
 * pre-read pack obvious. Renders a "Read board pack" CTA when ready, an
 * "Upload pack" CTA when not yet generated, or an empty state otherwise.
 */
export function BoardPackPanel({ pack, canUploadPack = false }: BoardPackPanelProps) {
    if (!pack) {
        return (
            <Card data-dusk="cockpit-board-pack">
                <CardHeader>
                    <CardTitle className="text-lg">Board Pack &amp; Pre-read</CardTitle>
                    <CardDescription>Documents to read before the next meeting.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="rounded-lg border border-dashed border-border p-6 text-center">
                        <BookOpen className="mx-auto h-5 w-5 text-muted-foreground" aria-hidden="true" />
                        <p className="mt-2 text-sm font-medium text-foreground">No board pack yet</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            A board pack will appear here when the next meeting is scheduled.
                        </p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    if (!pack.ready) {
        return (
            <Card data-dusk="cockpit-board-pack">
                <CardHeader>
                    <CardTitle className="text-lg">Board Pack &amp; Pre-read</CardTitle>
                    <CardDescription>{pack.meeting_title}</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="rounded-lg border border-dashed border-border p-6 text-center">
                        <Upload className="mx-auto h-5 w-5 text-status-warning" aria-hidden="true" />
                        <p className="mt-2 text-sm font-medium text-foreground">Pack not yet generated</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Once the agenda is finalised, generate the board pack so members can pre-read.
                        </p>
                        {canUploadPack ? (
                            <Button asChild size="sm" className="mt-3">
                                <Link href={pack.href}>Upload board pack</Link>
                            </Button>
                        ) : null}
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card data-dusk="cockpit-board-pack">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-lg">Board Pack &amp; Pre-read</CardTitle>
                        <CardDescription>{pack.meeting_title}</CardDescription>
                    </div>
                    <Badge
                        className={
                            pack.distributed
                                ? 'border border-status-success/30 bg-status-success-bg text-status-success'
                                : 'border border-status-warning/30 bg-status-warning-bg text-status-warning'
                        }
                    >
                        {pack.distributed ? 'Distributed' : 'Ready to distribute'}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid grid-cols-3 gap-3">
                    <div className="rounded-md bg-muted/60 p-3">
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">Documents</p>
                        <p className="mt-1 text-xl font-semibold text-foreground">{pack.doc_count}</p>
                    </div>
                    <div className="rounded-md bg-muted/60 p-3">
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">Sent to</p>
                        <p className="mt-1 text-xl font-semibold text-foreground">{pack.distributed_count}</p>
                    </div>
                    <div className="rounded-md bg-muted/60 p-3">
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">Read</p>
                        <p className="mt-1 text-xl font-semibold text-foreground">{pack.read_count}</p>
                    </div>
                </div>

                {pack.distributed_label ? (
                    <p className="text-xs text-muted-foreground">
                        Distributed {pack.distributed_label}.
                    </p>
                ) : null}

                <div className="flex flex-wrap items-center gap-2">
                    <Button asChild>
                        <Link href={pack.href}>
                            <Eye className="mr-1.5 h-4 w-4" />
                            Read board pack
                        </Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={`/governance/meetings/${pack.meeting_id}`}>
                            <FileText className="mr-1.5 h-4 w-4" />
                            View meeting
                        </Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export default BoardPackPanel;
