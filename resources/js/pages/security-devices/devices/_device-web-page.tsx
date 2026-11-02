/**
 * Pop-up 61, a device's web page (S&D rebuild Phase G): the device's own page in a frame, through
 * the Site's hub, with one line saying how it got there. Nothing is opened to the internet, and
 * opening it is logged. The frame is sandboxed: the device's scripts can't reach Oblivion.
 */
import { PopupViewer } from '@/components/popup';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import axios from 'axios';
import { Globe, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

export function WebPagePopup({
    open,
    device,
    hub,
    onClose,
}: {
    open: boolean;
    device: { id: number; name: string; address: string | null };
    hub: string | null;
    onClose: () => void;
}) {
    const [url, setUrl] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            setUrl(null);
            setError(null);
            return;
        }
        let cancelled = false;
        axios
            .post<{ url: string }>(`/security-devices/devices/${device.id}/web`)
            .then((response) => {
                if (!cancelled) setUrl(response.data.url);
            })
            .catch((failure) => {
                const data = axios.isAxiosError(failure) ? failure.response?.data : undefined;
                if (!cancelled) setError((data?.errors?.web?.[0] as string | undefined) ?? 'The device’s page didn’t open. Try again.');
            });
        return () => {
            cancelled = true;
        };
    }, [open, device.id]);

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="lg"
            icon={Globe}
            title={`${device.name} · web page`}
            description={`Through ${hub ?? 'the Site hub'}. Nothing is opened to the internet, and opening it is logged.`}
            action={
                url ? (
                    <Button asChild variant="outline">
                        <a href={url} target="_blank" rel="noopener noreferrer">
                            Open in a new tab
                        </a>
                    </Button>
                ) : null
            }
        >
            {error ? (
                <Note tone="critical">
                    <span role="alert">{error}</span>
                </Note>
            ) : url ? (
                <iframe
                    title={`${device.name} web page`}
                    src={url}
                    sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"
                    referrerPolicy="no-referrer"
                    className="h-[60vh] w-full rounded-lg border border-border bg-background"
                />
            ) : (
                <p className="flex items-center gap-2 text-subtle" role="status">
                    <LoaderCircle className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                    Opening {device.address ?? 'the device'} through {hub ?? 'the Site hub'}…
                </p>
            )}
        </PopupViewer>
    );
}
