import { Button } from '@/components/ui/button';
import { InfoCard } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateTimeInZone } from '@/lib/datetime';
import {
    CalendarRange,
    Check,
    Flag,
    Info,
    NotebookPen,
    PenLine,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { type ShiftNote, clientName, fmtShiftChip, TypeBadge } from './shared';

export function NoteDetailDialog({
    note,
    open,
    onOpenChange,
    onFlag,
    onReview,
    onEdit,
    timeZone,
    canFlag,
    canReview,
}: {
    note: ShiftNote | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onFlag: (note: ShiftNote) => void;
    onReview: (note: ShiftNote) => void;
    onEdit: (note: ShiftNote) => void;
    timeZone: string;
    canFlag: boolean;
    canReview: boolean;
}) {
    const [section, setSection] = useState(0);
    if (!note) return null;
    const sections = [
        {
            key: 'note',
            label: 'Note',
            blurb: 'Recorded support',
            icon: NotebookPen,
        },
        {
            key: 'record',
            label: 'Record & access',
            blurb: 'Person, shift and review',
            icon: ShieldCheck,
        },
    ];
    const date = (value: string | null) =>
        value ? formatDateTimeInZone(value, timeZone) : 'Not recorded';
    return (
        <WizardShell
            open={open}
            onClose={() => onOpenChange(false)}
            title={`Shift note #${note.id}`}
            description="Read the note and its recorded review and editing permissions."
            railIcon={NotebookPen}
            railTitle={`Note #${note.id}`}
            railSub={clientName(note.client)}
            steps={sections}
            stepIndex={section}
            onStepClick={setSection}
            sequential={false}
            headerLabel={sections[section].label}
            frontline
            footerStart={
                <Button variant="outline" onClick={() => onOpenChange(false)}>
                    Close
                </Button>
            }
            footerEnd={
                <>
                    {canReview && note.can_review && !note.reviewed_at && (
                        <Button
                            variant="outline"
                            onClick={() => onReview(note)}
                        >
                            <Check className="size-4" />
                            Mark reviewed
                        </Button>
                    )}
                    {canFlag && note.can_flag && (
                        <Button variant="outline" onClick={() => onFlag(note)}>
                            <Flag className="size-4" />
                            {note.is_flagged
                                ? 'Remove flag'
                                : 'Flag for review'}
                        </Button>
                    )}
                    {note.can_edit && (
                        <Button onClick={() => onEdit(note)}>
                            <PenLine className="size-4" />
                            Edit note
                        </Button>
                    )}
                </>
            }
        >
            {section === 0 ? (
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <TypeBadge type={note.type} />
                        <span className="text-sm text-muted-foreground">
                            {note.user?.name ?? 'Author not recorded'} ·{' '}
                            {date(note.created_at)}
                        </span>
                    </div>
                    <p className="text-base leading-relaxed break-words whitespace-pre-wrap">
                        {note.body}
                    </p>
                    {note.is_flagged && (
                        <InfoCard icon={Flag} tone="warn">
                            <p className="font-semibold">Flagged for review</p>
                            <p className="whitespace-pre-wrap">
                                {note.flagged_reason ?? 'No reason recorded.'}
                            </p>
                        </InfoCard>
                    )}
                    {note.is_private && (
                        <InfoCard icon={ShieldCheck}>
                            This is a private note. Its visibility is restricted
                            by the person’s record permissions.
                        </InfoCard>
                    )}
                </div>
            ) : (
                <div className="space-y-4">
                    <ReviewCard icon={CalendarRange} title="Person and shift">
                        <ReviewRow
                            label="Person"
                            value={clientName(note.client)}
                        />
                        <ReviewRow
                            label="House"
                            value={note.site?.name ?? 'Not recorded'}
                        />
                        <ReviewRow
                            label="Shift"
                            value={
                                note.shift
                                    ? fmtShiftChip(note.shift, timeZone)
                                    : 'Not recorded'
                            }
                        />
                        <ReviewRow label="Time zone" value={timeZone} />
                    </ReviewCard>
                    <ReviewCard icon={ShieldCheck} title="Record history">
                        <ReviewRow
                            label="Written"
                            value={date(note.created_at)}
                        />
                        <ReviewRow
                            label="Edited"
                            value={
                                note.edited_at
                                    ? `${date(note.edited_at)} · ${note.editor?.name ?? 'Editor not recorded'}`
                                    : 'No edit recorded'
                            }
                        />
                        <ReviewRow
                            label="Reviewed"
                            value={
                                note.reviewed_at
                                    ? `${date(note.reviewed_at)} · ${note.reviewer?.name ?? 'Reviewer not recorded'}`
                                    : 'Awaiting review'
                            }
                        />
                    </ReviewCard>
                    <InfoCard icon={Info}>
                        {note.can_edit
                            ? 'You can edit this note under your current permissions. Changes record your name and the edit time.'
                            : 'Editing is unavailable under your current permissions or the author’s edit window. Ask an authorised manager if a correction is needed.'}
                    </InfoCard>
                </div>
            )}
        </WizardShell>
    );
}
