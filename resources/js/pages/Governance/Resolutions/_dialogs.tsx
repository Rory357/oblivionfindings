import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { Gavel, Loader2, ScrollText, Users, Vote, type LucideIcon } from 'lucide-react';
import { store as storeResolution } from '@/routes/governance/resolutions';

// ── Resolution type registry (Send-Kudos-style tile picker) ───────────────

type ResolutionTypeKey = 'ordinary' | 'special' | 'unanimous';

interface ResolutionTypeDef {
    key: ResolutionTypeKey;
    label: string;
    description: string;
    icon: LucideIcon;
    accent: string;
}

export const RESOLUTION_TYPES: ResolutionTypeDef[] = [
    {
        key: 'ordinary',
        label: 'Ordinary',
        description: 'Simple majority (>50%) carries.',
        icon: Vote,
        accent: 'text-status-info',
    },
    {
        key: 'special',
        label: 'Special',
        description: 'Two-thirds majority required.',
        icon: ScrollText,
        accent: 'text-status-warning',
    },
    {
        key: 'unanimous',
        label: 'Unanimous',
        description: 'All voting members must agree.',
        icon: Users,
        accent: 'text-primary',
    },
];

export function getResolutionType(value: string | null | undefined): ResolutionTypeDef {
    return RESOLUTION_TYPES.find((t) => t.key === value) ?? RESOLUTION_TYPES[0]!;
}

// ── Form shape ────────────────────────────────────────────────────────────

export type ResolutionFormValues = {
    title: string;
    description: string;
    type: ResolutionTypeKey | string;
    voting_deadline: string;
    meeting_id: string;
};

export interface MeetingOption {
    id: number;
    title: string;
    scheduled_at: string;
}

// ── Field helpers ─────────────────────────────────────────────────────────

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

function ResolutionTypePicker({
    value,
    onChange,
}: {
    value: string;
    onChange: (v: ResolutionTypeKey) => void;
}) {
    return (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            {RESOLUTION_TYPES.map((t) => {
                const Icon = t.icon;
                const active = value === t.key;
                return (
                    <Button unstyled
                        key={t.key}
                        type="button"
                        onClick={() => onChange(t.key)}
                        className={cn(
                            'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                : 'border-border',
                        )}
                        aria-pressed={active}
                    >
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <Icon className={cn('h-4 w-4', t.accent)} />
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">{t.label}</span>
                            <span className="block text-xs text-muted-foreground">
                                {t.description}
                            </span>
                        </span>
                    </Button>
                );
            })}
        </div>
    );
}

// ── Shared form body ──────────────────────────────────────────────────────

function ResolutionFields({
    form,
    meetings,
    lockMeeting,
}: {
    form: ReturnType<typeof useForm<ResolutionFormValues>>;
    meetings: MeetingOption[];
    lockMeeting?: boolean;
}) {
    const lockedMeeting = lockMeeting
        ? meetings.find((m) => String(m.id) === form.data.meeting_id) ?? null
        : null;

    return (
        <div className="space-y-4">
            <div>
                <Label className="mb-2 block">
                    Resolution type <span className="text-status-critical">*</span>
                </Label>
                <ResolutionTypePicker
                    value={form.data.type}
                    onChange={(v) => form.setData('type', v)}
                />
                <FieldError message={form.errors.type} />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Label htmlFor="r-title">
                        Resolution title <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        id="r-title"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="e.g. Approval of Annual Budget 2026"
                        required
                    />
                    <FieldError message={form.errors.title} />
                </div>

                <div className="sm:col-span-2">
                    <Label htmlFor="r-description">Description</Label>
                    <Textarea
                        id="r-description"
                        rows={4}
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        placeholder="What is being decided, and what context does the board need to vote?"
                    />
                    <FieldError message={form.errors.description} />
                </div>

                <div>
                    <Label htmlFor="r-deadline">Voting deadline</Label>
                    <Input
                        id="r-deadline"
                        type="datetime-local"
                        value={form.data.voting_deadline}
                        onChange={(e) => form.setData('voting_deadline', e.target.value)}
                    />
                    <FieldError message={form.errors.voting_deadline} />
                </div>

                <div>
                    <Label htmlFor="r-meeting">Linked meeting</Label>
                    {lockedMeeting ? (
                        <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
                            <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                                <Gavel className="h-4 w-4 text-primary" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="truncate text-sm font-medium">
                                        {lockedMeeting.title}
                                    </span>
                                    <Badge variant="outline" className="text-[10px]">
                                        From meeting
                                    </Badge>
                                </div>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Locked from the meeting you opened.
                                </p>
                            </div>
                        </div>
                    ) : (
                        <Select
                            value={form.data.meeting_id}
                            onValueChange={(v) => form.setData('meeting_id', v)}
                        >
                            <SelectTrigger id="r-meeting">
                                <SelectValue placeholder="No linked meeting" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">No linked meeting</SelectItem>
                                {meetings.map((m) => (
                                    <SelectItem key={m.id} value={String(m.id)}>
                                        {m.title} (
                                        {new Date(m.scheduled_at).toLocaleDateString('en-NZ')})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <FieldError message={form.errors.meeting_id} />
                </div>
            </div>
        </div>
    );
}

// ── New Resolution dialog ─────────────────────────────────────────────────

interface NewResolutionDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetings: MeetingOption[];
    /** Pre-select / lock to a meeting (e.g. opened from a meeting page). */
    meetingId?: number | string | null;
    lockMeeting?: boolean;
    /** Called after the resolution is created successfully. */
    onCreated?: () => void;
}

export function NewResolutionDialog({
    isOpen,
    onClose,
    meetings,
    meetingId,
    lockMeeting = false,
    onCreated,
}: NewResolutionDialogProps) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {isOpen && (
                    <NewResolutionBody
                        onClose={onClose}
                        meetings={meetings}
                        meetingId={meetingId}
                        lockMeeting={lockMeeting}
                        onCreated={onCreated}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function NewResolutionBody({
    onClose,
    meetings,
    meetingId,
    lockMeeting,
    onCreated,
}: {
    onClose: () => void;
    meetings: MeetingOption[];
    meetingId?: number | string | null;
    lockMeeting: boolean;
    onCreated?: () => void;
}) {
    const initialMeetingId = meetingId != null ? String(meetingId) : 'none';

    const form = useForm<ResolutionFormValues>({
        title: '',
        description: '',
        type: 'ordinary',
        voting_deadline: '',
        meeting_id: initialMeetingId,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((current) => ({
            ...current,
            meeting_id: current.meeting_id === 'none' ? '' : current.meeting_id,
        }));
        form.post(storeResolution.url(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onCreated?.();
                onClose();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Gavel className="h-4 w-4 text-primary" />
                    New Resolution
                </DialogTitle>
                <DialogDescription>
                    Pick the resolution type, then describe what the board is being asked to
                    decide.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3">
                <ResolutionFields form={form} meetings={meetings} lockMeeting={lockMeeting} />
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    Create resolution
                </Button>
            </DialogFooter>
        </form>
    );
}
