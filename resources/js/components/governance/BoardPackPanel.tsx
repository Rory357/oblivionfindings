import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { Link } from '@inertiajs/react';
import { BookOpen, Eye, FileText, Upload } from 'lucide-react';

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
export function BoardPackPanel({
    pack,
    canUploadPack = false,
}: BoardPackPanelProps) {
    if (!pack) {
        return (
            <Card data-dusk="cockpit-board-pack">
                <CardHeader>
                    <CardTitle className="text-section-title">
                        Board Pack &amp; Pre-read
                    </CardTitle>
                    <CardDescription>
                        Documents to read before the next meeting.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <EmptyState
                        variant="compact"
                        icon={BookOpen}
                        title="No board pack yet"
                        description="A board pack will appear here when the next meeting is scheduled."
                    />
                </CardContent>
            </Card>
        );
    }

    if (!pack.ready) {
        return (
            <Card data-dusk="cockpit-board-pack">
                <CardHeader>
                    <CardTitle className="text-section-title">
                        Board Pack &amp; Pre-read
                    </CardTitle>
                    <CardDescription>{pack.meeting_title}</CardDescription>
                </CardHeader>
                <CardContent>
                    <EmptyState
                        variant="compact"
                        icon={Upload}
                        title="Pack not yet generated"
                        description="Once the agenda is finalised, generate the board pack so members can pre-read."
                        action={
                            canUploadPack ? (
                                <Button asChild size="sm">
                                    <Link href={pack.href}>
                                        Upload board pack
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                </CardContent>
            </Card>
        );
    }

    return (
        <Card data-dusk="cockpit-board-pack">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-section-title">
                            Board Pack &amp; Pre-read
                        </CardTitle>
                        <CardDescription>{pack.meeting_title}</CardDescription>
                    </div>
                    <StatusBadge
                        variant={pack.distributed ? 'success' : 'warning'}
                    >
                        {pack.distributed
                            ? 'Distributed'
                            : 'Ready to distribute'}
                    </StatusBadge>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <div className="grid grid-cols-3 gap-3">
                    <div className="rounded-md bg-muted/60 p-3">
                        <p className="text-[10px] tracking-wide text-muted-foreground uppercase">
                            Documents
                        </p>
                        <p className="mt-1 text-section-title tabular-nums">
                            {pack.doc_count}
                        </p>
                    </div>
                    <div className="rounded-md bg-muted/60 p-3">
                        <p className="text-[10px] tracking-wide text-muted-foreground uppercase">
                            Sent to
                        </p>
                        <p className="mt-1 text-section-title tabular-nums">
                            {pack.distributed_count}
                        </p>
                    </div>
                    <div className="rounded-md bg-muted/60 p-3">
                        <p className="text-[10px] tracking-wide text-muted-foreground uppercase">
                            Read
                        </p>
                        <p className="mt-1 text-section-title tabular-nums">
                            {pack.read_count}
                        </p>
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
