import { router } from '@inertiajs/react';
import { Check, Copy, ExternalLink, RefreshCw, Rss } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

/**
 * Surfaces the existing personal iCal feed (ICalController) — copy the URL, add
 * it to Google / Outlook / Apple, or regenerate the token. The feed itself is
 * unchanged (and now tenant-scoped); this is purely the UI that was missing.
 */
export function ICalSubscribeDialog({
    open,
    onClose,
    url,
}: {
    open: boolean;
    onClose: () => void;
    url: string | null;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (!url) return;
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            toast.success('Feed URL copied');
            window.setTimeout(() => setCopied(false), 1800);
        } catch {
            toast.error('Could not copy — select and copy manually');
        }
    };

    const regenerate = () => {
        router.post(
            '/hr/ical/token',
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('A fresh feed URL was generated'),
            },
        );
    };

    const webcal = url ? url.replace(/^https?:\/\//, 'webcal://') : null;
    const googleUrl = url
        ? `https://calendar.google.com/calendar/r?cid=${encodeURIComponent(url)}`
        : null;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Rss className="h-5 w-5 text-primary" />
                        Subscribe to your calendar
                    </DialogTitle>
                    <DialogDescription>
                        Add your events and approved leave to any calendar app. The feed
                        updates automatically — your app refreshes it periodically.
                    </DialogDescription>
                </DialogHeader>

                {url ? (
                    <div className="space-y-4">
                        <div>
                            <label className="mb-1.5 block text-xs font-semibold text-muted-foreground">
                                Feed URL
                            </label>
                            <div className="flex gap-2">
                                <Input readOnly value={url} className="font-mono text-xs" onFocus={(e) => e.currentTarget.select()} />
                                <Button type="button" variant="outline" size="icon" onClick={copy} aria-label="Copy feed URL">
                                    {copied ? <Check className="h-4 w-4 text-status-success" /> : <Copy className="h-4 w-4" />}
                                </Button>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            {googleUrl ? (
                                <a href={googleUrl} target="_blank" rel="noopener noreferrer">
                                    <Button type="button" variant="outline" size="sm" className="gap-1.5">
                                        <ExternalLink className="h-3.5 w-3.5" /> Google Calendar
                                    </Button>
                                </a>
                            ) : null}
                            {webcal ? (
                                <a href={webcal}>
                                    <Button type="button" variant="outline" size="sm" className="gap-1.5">
                                        <ExternalLink className="h-3.5 w-3.5" /> Apple Calendar
                                    </Button>
                                </a>
                            ) : null}
                            {webcal ? (
                                <a href={webcal}>
                                    <Button type="button" variant="outline" size="sm" className="gap-1.5">
                                        <ExternalLink className="h-3.5 w-3.5" /> Outlook
                                    </Button>
                                </a>
                            ) : null}
                        </div>

                        <div className="rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground">
                            Includes your approved leave and company / HR events from the last
                            three months onward. Keep this URL private — anyone with it can read
                            your feed. Lost it or shared it by mistake? Regenerate to revoke the old one.
                        </div>

                        <div className="flex justify-end">
                            <Button type="button" variant="ghost" size="sm" onClick={regenerate} className="gap-1.5 text-muted-foreground">
                                <RefreshCw className="h-3.5 w-3.5" /> Regenerate URL
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            You don't have a feed URL yet. Generate one to subscribe from your
                            calendar app.
                        </p>
                        <Button type="button" onClick={regenerate} className="gap-1.5">
                            <Rss className="h-4 w-4" /> Generate feed URL
                        </Button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default ICalSubscribeDialog;
