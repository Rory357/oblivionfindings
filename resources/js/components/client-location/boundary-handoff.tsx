import { Button } from '@/components/ui/button';
import { request } from '@/pages/fleet-assets/geofences/workspace/api';
import { useState } from 'react';
import type { Boundary } from './types';

/** The person draft stays mounted. Only an opaque same-actor token enters Fleet. */
export function BoundaryHandoff({
    url,
    fingerprint,
    onSelect,
}: {
    url: string;
    fingerprint: string;
    onSelect: (boundary: Boundary) => void;
}) {
    const [handoff, setHandoff] = useState<{
            token: string;
            href: string;
        } | null>(null),
        [busy, setBusy] = useState(false),
        [message, setMessage] = useState(''),
        [failed, setFailed] = useState(false);
    const root = url.replace(/\/\d+$/, '');
    const start = async () => {
        setBusy(true);
        setMessage('');
        setFailed(false);
        try {
            setHandoff(
                await request(root + '/boundary-handoff', 'POST', {
                    access_fingerprint: fingerprint,
                }),
            );
        } catch (e) {
            setMessage((e as Error).message);
            setFailed(true);
        } finally {
            setBusy(false);
        }
    };
    const cancel = async () => {
        if (!handoff) return;
        setBusy(true);
        try {
            await request(
                root + '/boundary-handoff/' + handoff.token,
                'DELETE',
            );
            setHandoff(null);
            setMessage('Return request cancelled. Your draft is retained.');
            setFailed(false);
        } catch (e) {
            setMessage((e as Error).message);
            setFailed(true);
        } finally {
            setBusy(false);
        }
    };
    const take = async () => {
        if (!handoff) return;
        setBusy(true);
        setMessage('');
        setFailed(false);
        try {
            const result = await request<{
                ready: boolean;
                boundary?: Boundary;
            }>(root + '/boundary-handoff/' + handoff.token);
            if (result.ready && result.boundary) {
                onSelect(result.boundary);
                setHandoff(null);
                setMessage(
                    'Shared geometry returned. Review this person’s purpose and schedule before saving the inactive draft.',
                );
            } else
                setMessage(
                    'No boundary has been returned yet. Select an eligible area in Maps & boundaries; this draft is still here.',
                );
        } catch (e) {
            setMessage((e as Error).message);
            setFailed(true);
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="space-y-3 rounded-lg border p-4">
            <strong className="block text-sm">
                Use the shared boundary builder
            </strong>
            <p className="text-sm text-muted-foreground">
                Keep this person’s draft open while selecting, creating or
                copying an area. The return request expires after 15 minutes.
            </p>
            {handoff ? (
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline">
                        <a
                            href={handoff.href}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Open Maps & boundaries
                        </a>
                    </Button>
                    <Button disabled={busy} onClick={take}>
                        Use returned boundary
                    </Button>
                    <Button variant="ghost" disabled={busy} onClick={cancel}>
                        Cancel return request
                    </Button>
                </div>
            ) : (
                <Button variant="outline" disabled={busy} onClick={start}>
                    Prepare shared boundary selection
                </Button>
            )}
            {message && (
                <p
                    role={failed ? 'alert' : 'status'}
                    className={
                        failed ? 'text-sm text-status-critical' : 'text-sm'
                    }
                >
                    {message}
                </p>
            )}
        </div>
    );
}
