import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field, InfoCard, TilePicker } from '@/components/wizard/primitives';
import { formatDateTimeLong } from '@/lib/datetime';
import { meetingStatusLabel } from '@/lib/governance-labels';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    CalendarDays,
    CalendarX2,
    FolderOpen,
    Info,
    Loader2,
} from 'lucide-react';
import { useState } from 'react';

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

/** Meetings split into the ones a pack can be prepared for and the ones that need an agenda first. */
export function splitMeetingsForPacks(meetings: MeetingWithoutPack[]): {
    ready: MeetingWithoutPack[];
    needsAgenda: MeetingWithoutPack[];
} {
    return {
        ready: meetings.filter((m) => m.agenda_items_count > 0),
        needsAgenda: meetings.filter((m) => m.agenda_items_count === 0),
    };
}

function meetingFacts(meeting: MeetingWithoutPack): string {
    return [
        meeting.scheduled_at
            ? formatDateTimeLong(meeting.scheduled_at)
            : 'Date not set',
        meetingStatusLabel(meeting.status),
    ].join(' · ');
}

export function GenerateBoardPackDialog({
    isOpen,
    onClose,
    meetings,
}: GenerateBoardPackDialogProps) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[85vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                {isOpen ? (
                    <GenerateBoardPackBody onClose={onClose} meetings={meetings} />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function GenerateBoardPackBody({
    onClose,
    meetings,
}: {
    onClose: () => void;
    meetings: MeetingWithoutPack[];
}) {
    const { ready, needsAgenda } = splitMeetingsForPacks(meetings);
    const [selectedId, setSelectedId] = useState<string>(
        ready.length === 1 ? String(ready[0].id) : '',
    );
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
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );
            const status = response.data?.status as string | undefined;
            onClose();
            if (status === 'generated' && response.data?.pack_id) {
                router.visit(`/governance/packs/${response.data.pack_id}`);
            } else {
                router.reload();
            }
        } catch (err: unknown) {
            const response = (
                err as {
                    response?: { status?: number; data?: { message?: string } };
                }
            )?.response;
            setError(
                response?.data?.message ??
                    (response?.status === 403
                        ? "You don't have permission to generate a board pack for this meeting."
                        : "The board pack couldn't be generated. Try again in a few minutes."),
            );
        } finally {
            setGenerating(false);
        }
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <FolderOpen className="h-4 w-4 text-primary" />
                    Generate a board pack
                </DialogTitle>
                <DialogDescription>
                    Choose a meeting. The pack is put together from its agenda,
                    CEO report and resolutions.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 flex flex-col gap-4">
                <InfoCard icon={Info}>
                    Generating creates a draft pack — nothing is sent. Members
                    won&apos;t see it until you send it to them.
                </InfoCard>

                {meetings.length === 0 ? (
                    <EmptyState
                        variant="compact"
                        icon={CalendarX2}
                        title="No meetings need a pack"
                        description="Every upcoming meeting already has a board pack. Schedule a meeting first, then come back here."
                    />
                ) : null}

                {ready.length > 0 ? (
                    <Field label="Meeting" required>
                        <TilePicker
                            value={selectedId}
                            onChange={setSelectedId}
                            options={ready.map((meeting) => ({
                                key: String(meeting.id),
                                label: meeting.title,
                                description: meetingFacts(meeting),
                                icon: CalendarDays,
                                meta:
                                    meeting.agenda_items_count === 1
                                        ? '1 agenda item'
                                        : `${meeting.agenda_items_count} agenda items`,
                            }))}
                        />
                    </Field>
                ) : null}

                {needsAgenda.length > 0 ? (
                    <div className="flex flex-col gap-2">
                        <p className="text-sm font-medium">
                            Add an agenda first
                        </p>
                        <p className="text-caption">
                            These meetings have no agenda items yet, so a pack
                            can&apos;t be generated for them.
                        </p>
                        <ul className="flex flex-col gap-2">
                            {needsAgenda.map((meeting) => (
                                <li
                                    key={meeting.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-dashed border-border px-3 py-2"
                                    aria-disabled="true"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm text-muted-foreground">
                                            {meeting.title}
                                        </span>
                                        <span className="text-caption block">
                                            {meetingFacts(meeting)}
                                        </span>
                                    </span>
                                    <Button asChild variant="outline" size="sm">
                                        <Link
                                            href={`/governance/meetings/${meeting.id}`}
                                        >
                                            Add an agenda
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {error ? (
                    <InfoCard icon={AlertCircle} tone="crit">
                        {error}
                    </InfoCard>
                ) : null}
            </div>

            <DialogFooter className="mt-4">
                {ready.length > 0 && !selectedId ? (
                    <p className="text-caption mr-auto self-center">
                        Choose a meeting to continue.
                    </p>
                ) : null}
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={handleGenerate}
                    disabled={!selectedId || generating}
                    dusk="generate-pack-confirm"
                >
                    {generating ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : null}
                    Generate draft pack
                </Button>
            </DialogFooter>
        </>
    );
}

export default GenerateBoardPackDialog;
