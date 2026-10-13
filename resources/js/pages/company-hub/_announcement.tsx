import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { CheckCheck, FileDown, Megaphone, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { hubRequest } from './_api';

export type HubAnnouncement = {
    id: number;
    title: string;
    content: string;
    priority: string;
    author: string | null;
    published_at: string;
    ack_deadline: string | null;
    requires_acknowledgement: boolean;
    acknowledged_at: string | null;
    version: string;
    read_version?: number;
    previously_acknowledged?: boolean;
    attachments: Array<{ id: number; name: string; size: number; url: string }>;
};

export function HubAnnouncementDialog({
    id,
    onClose,
}: {
    id: number;
    onClose: () => void;
}) {
    const [notice, setNotice] = useState<HubAnnouncement | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [reload, setReload] = useState(0);
    useEffect(() => {
        let active = true;
        setLoading(true);
        setError('');
        setNotice(null);
        hubRequest<HubAnnouncement>(`/company-hub/announcements/${id}`, 'GET')
            .then((result) => {
                if (active) setNotice(result);
            })
            .catch(() => {
                if (active)
                    setError(
                        'This announcement is no longer available, or you do not have access.',
                    );
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [id, reload]);
    async function acknowledge() {
        if (!notice || saving) return;
        setSaving(true);
        setError('');
        try {
            const result = await hubRequest<HubAnnouncement>(
                `/company-hub/announcements/${id}/acknowledge`,
                'POST',
                { version: notice.version },
            );
            setNotice(result);
            router.reload({ only: ['announcements', 'updates'] });
        } catch (err) {
            setError((err as Error).message);
        } finally {
            setSaving(false);
        }
    }
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !saving) onClose();
            }}
        >
            <DialogContent className="max-h-[85vh] overflow-x-hidden overflow-y-auto sm:max-w-3xl">
                <DialogHeader className="min-w-0">
                    <span className="mb-1 flex items-center gap-2 text-xs font-medium text-primary">
                        <Megaphone className="size-4" />
                        Company announcement
                    </span>
                    <DialogTitle className="pr-6 text-2xl leading-tight break-words">
                        {notice?.title ?? 'Announcement'}
                    </DialogTitle>
                    <DialogDescription>
                        {notice
                            ? [
                                  notice.author,
                                  formatDateTime(notice.published_at),
                              ]
                                  .filter(Boolean)
                                  .join(' · ')
                            : 'Read company information and confirm when requested.'}
                    </DialogDescription>
                </DialogHeader>
                {loading && (
                    <p
                        role="status"
                        className="py-8 text-center text-sm text-muted-foreground"
                    >
                        Loading announcement…
                    </p>
                )}
                {error && (
                    <p
                        role="alert"
                        className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
                    >
                        {error}
                    </p>
                )}
                {notice && (
                    <>
                        {notice.previously_acknowledged &&
                            !notice.acknowledged_at &&
                            notice.requires_acknowledgement && (
                                <p className="rounded-lg bg-primary/10 p-3 text-sm text-primary">
                                    This announcement has changed since you
                                    confirmed it. Read the updated version
                                    before confirming again.
                                </p>
                            )}
                        {['high', 'urgent'].includes(notice.priority) && (
                            <p className="text-sm font-medium text-primary">
                                {notice.priority === 'urgent'
                                    ? 'Urgent update'
                                    : 'Important update'}
                            </p>
                        )}
                        <div className="py-3 text-sm leading-7 whitespace-pre-wrap">
                            {notice.content}
                        </div>
                        {notice.attachments.length > 0 && (
                            <section
                                aria-label="Announcement attachments"
                                className="space-y-2"
                            >
                                <h3 className="text-sm font-semibold">
                                    Related files
                                </h3>
                                {notice.attachments.map((file) => (
                                    <Button
                                        key={file.id}
                                        variant="outline"
                                        asChild
                                        className="mr-2 h-auto max-w-full whitespace-normal"
                                    >
                                        <a
                                            href={file.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <FileDown className="size-4 shrink-0" />
                                            {file.name}
                                        </a>
                                    </Button>
                                ))}
                            </section>
                        )}
                        {notice.requires_acknowledgement && (
                            <div className="rounded-lg bg-muted/50 p-4 text-sm">
                                {notice.acknowledged_at ? (
                                    <p
                                        role="status"
                                        className="flex items-center gap-2"
                                    >
                                        <CheckCheck className="size-4 text-primary" />
                                        Read confirmed ·{' '}
                                        {formatDateTime(notice.acknowledged_at)}
                                    </p>
                                ) : (
                                    <>
                                        <p className="font-medium">
                                            Please confirm you have read this
                                            announcement.
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            {notice.ack_deadline
                                                ? `Confirmation due ${formatDateTime(notice.ack_deadline)}. `
                                                : ''}
                                            Opening this announcement does not
                                            mark it as read.
                                        </p>
                                    </>
                                )}
                            </div>
                        )}
                    </>
                )}
                <DialogFooter className="mt-3 gap-2 border-t pt-4">
                    <Button
                        variant="ghost"
                        disabled={loading || saving}
                        onClick={() => setReload(reload + 1)}
                    >
                        <RefreshCw className="size-4" />
                        Refresh announcement
                    </Button>
                    <Button
                        variant="outline"
                        disabled={saving}
                        onClick={onClose}
                    >
                        Close
                    </Button>
                    {notice?.requires_acknowledgement &&
                        !notice.acknowledged_at && (
                            <Button
                                disabled={saving || loading}
                                onClick={acknowledge}
                            >
                                <CheckCheck className="size-4" />
                                {saving ? 'Confirming…' : 'I have read this'}
                            </Button>
                        )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
