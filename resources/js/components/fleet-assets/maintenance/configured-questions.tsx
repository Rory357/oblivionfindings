import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { useState } from 'react';

export type ConfiguredQuestion = {
    id: string;
    pass_values: string[];
    allow_na: boolean;
    evidence_required?: boolean;
    na_evidence_exempt?: boolean;
    when?: { question_id: string; equals: string };
};
export type ConfiguredAnswer = { result: string; notes?: string; evidence_attachment_id?: number };
export type ConfiguredItem = { id?: string; label?: string; options?: string[] | null };
export type EvidenceChoice = { id: number; original_name: string };

export function applicableAnswers(answers: Record<string, ConfiguredAnswer>, questions: ConfiguredQuestion[]) {
    const saved: Record<string, ConfiguredAnswer> = {};
    for (const question of questions) {
        if (question.when && saved[question.when.question_id]?.result !== question.when.equals) continue;
        if (answers[question.id]?.result) saved[question.id] = answers[question.id];
    }
    return saved;
}

export function ConfiguredQuestions({ items, questions, answers, attachments, onChange, onUpload, stagedFiles, onStage, busy }: {
    items: ConfiguredItem[];
    questions: ConfiguredQuestion[];
    answers: Record<string, ConfiguredAnswer>;
    attachments: EvidenceChoice[];
    onChange: (answers: Record<string, ConfiguredAnswer>) => void;
    onUpload?: (questionId: string, file: File) => void;
    stagedFiles?: Record<string, File | null>;
    onStage?: (questionId: string, file: File | null) => void;
    busy: boolean;
}) {
    const [localFiles, setLocalFiles] = useState<Record<string, File | null>>({});
    const files = stagedFiles ?? localFiles;
    const stage = (id: string, file: File | null) => {
        if (onStage) onStage(id, file);
        else setLocalFiles((current) => ({ ...current, [id]: file }));
    };
    const live = applicableAnswers(answers, questions);
    const change = (id: string, patch: Partial<ConfiguredAnswer>) =>
        onChange({ ...answers, [id]: { ...(answers[id] ?? { result: '' }), ...patch } });

    return <div className="space-y-3">
        {questions.map((question, index) => {
            const item = items[index];
            const applicable = !question.when || live[question.when.question_id]?.result === question.when.equals;
            const answer = answers[question.id];
            const options = (item?.options?.length ? item.options : [...new Set([...question.pass_values, 'fail'])])
                .filter((option) => option !== 'na' || question.allow_na);
            const needsEvidence = question.evidence_required &&
                (answer?.result !== 'na' || !question.na_evidence_exempt);
            return <div key={question.id} className="rounded-lg border p-3">
                <Label htmlFor={`configured-${question.id}`}>{item?.label ?? question.id}</Label>
                {question.when && <p className="mt-1 text-xs text-muted-foreground">
                    Applies when {items.find((candidate) => candidate.id === question.when?.question_id)?.label ?? question.when.question_id} is {question.when.equals}.
                </p>}
                {!applicable ? <p className="mt-2 text-sm text-muted-foreground">Not applicable to this answer.</p> : <>
                    <select id={`configured-${question.id}`} className="mt-2 h-10 w-full rounded-md border bg-background px-3"
                        value={answer?.result ?? ''} onChange={(event) => change(question.id, { result: event.target.value })}>
                        <option value="">Choose a result</option>
                        {options.map((option) => <option key={option} value={option}>{option}</option>)}
                        {question.allow_na && !options.includes('na') && <option value="na">N/A</option>}
                    </select>
                    <Input aria-label={`Notes for ${item?.label ?? question.id}`} className="mt-2" value={answer?.notes ?? ''}
                        onChange={(event) => change(question.id, { notes: event.target.value })} placeholder="Optional observation" />
                    {needsEvidence && <div className="mt-3 space-y-2">
                        <Label htmlFor={`evidence-${question.id}`}>Private evidence required</Label>
                        {attachments.length > 0 && <select id={`evidence-${question.id}`} className="h-10 w-full rounded-md border bg-background px-3"
                            value={answer?.evidence_attachment_id ?? ''}
                            onChange={(event) => change(question.id, { evidence_attachment_id: Number(event.target.value) || undefined })}>
                            <option value="">Choose saved evidence</option>
                            {attachments.map((file) => <option key={file.id} value={file.id}>{file.original_name} · #{file.id}</option>)}
                        </select>}
                        <FileDropzone id={`configured-upload-${question.id}`} multiple={false} disabled={busy}
                            accept="image/jpeg,image/png,application/pdf"
                            title={`Choose evidence for ${item?.label ?? question.id}`}
                            hint="Private PDF, JPG or PNG · up to 10 MB"
                            onFiles={(chosen) => stage(question.id, chosen[0] ?? null)} />
                        {files[question.id] && <StagedFileCard file={files[question.id]!}
                            onRemove={() => stage(question.id, null)} />}
                        {onStage ? <p className="text-xs text-muted-foreground">The file is saved privately with this check when you submit.</p> : <Button type="button" variant="outline" size="sm" disabled={busy || !files[question.id]}
                            onClick={() => { const file = files[question.id]; if (file) onUpload?.(question.id, file); }}>Save private evidence</Button>}
                    </div>}
                </>}
            </div>;
        })}
    </div>;
}
