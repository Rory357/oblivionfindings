import { Note, QuietLine, Ring, type SgTone } from '@/components/signature/blocks';
import { timedText } from '@/lib/security-devices/device-time';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { CameraOff, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import type { CameraTile } from './_types';

const RING: Record<string, SgTone> = {
    online: 'success',
    offline: 'critical',
    problem: 'warning',
    cant_check: 'none',
    not_watched: 'none',
};

/**
 * The Cameras tab as a camera wall (drawing 2b). Oblivion doesn't keep camera pictures yet, so
 * each tile says so and opens the camera's page, where watching live asks for a reason (D6).
 */
export function CameraWall({
    items,
    cantCheck,
    head,
    now,
}: {
    items: CameraTile[];
    cantCheck: { site: string; text: string }[];
    head: ReactNode;
    now: Date;
}) {
    return (
        <div className="flex flex-col gap-5">
            <Note icon={Info}>
                <b className="font-semibold">Watch live asks for a reason</b> and keeps it with your name.
                Cameras are never put in bedrooms or bathrooms.
            </Note>
            <section className="rounded-[14px] border border-border bg-card shadow-sm">
                <div className="border-b border-border px-4 py-3.5">{head}</div>
                {items.length === 0 ? (
                    <p className="text-subtle px-4 py-6">No cameras here yet.</p>
                ) : (
                    <ul className="grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-3">
                        {items.map((camera) => (
                            <li key={camera.id}>
                                {/* eslint-disable-next-line no-restricted-syntax -- a camera tile that opens the camera page, not a form button. */}
                                <button
                                    type="button"
                                    onClick={() => router.visit(camera.href)}
                                    className="group block w-full rounded-xl text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <span
                                        className={cn(
                                            'flex aspect-video w-full flex-col items-center justify-center gap-2 rounded-xl border border-border bg-muted px-4 text-center text-[13px] text-muted-foreground transition-colors group-hover:border-primary/50',
                                        )}
                                    >
                                        <CameraOff aria-hidden="true" className="size-6" />
                                        {camera.state === 'online'
                                            ? 'Picture not available · Oblivion doesn’t keep camera pictures yet'
                                            : `No picture · ${timedText({ text: camera.line, at: camera.line_at }, now)}`}
                                    </span>
                                    <span className="mt-2 block text-sm font-semibold">{camera.name}</span>
                                    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <Ring tone={RING[camera.state] ?? 'none'} />
                                        {[camera.site ?? 'No Site', timedText({ text: camera.line, at: camera.line_at }, now)].join(' · ')}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            {cantCheck.map((group) => (
                <QuietLine key={group.site} title={group.site} text={group.text} />
            ))}
        </div>
    );
}
