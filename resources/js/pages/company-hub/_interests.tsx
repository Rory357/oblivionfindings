import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Building2,
    Check,
    GraduationCap,
    Heart,
    HeartHandshake,
    Monitor,
    Newspaper,
} from 'lucide-react';
import { useState } from 'react';
import { hubRequest } from './_api';
import type { HubContent, HubInterests } from './_types';

export const hubTopics = [
    'People & culture',
    'Wellbeing',
    'Learning',
    'Digital tools',
    'Company news',
];

function TopicIcon({ topic }: { topic: string }) {
    const Icon =
        topic === 'Learning'
            ? GraduationCap
            : topic === 'Digital tools'
              ? Monitor
              : topic === 'Company news'
                ? Newspaper
                : topic === 'Wellbeing'
                  ? Heart
                  : HeartHandshake;
    return <Icon className="size-4 shrink-0 text-primary" aria-hidden />;
}

function Choices<T extends string | number>({
    legend,
    options,
    value,
    onChange,
    disabled,
}: {
    legend: string;
    options: Array<{ id: T; name: string }>;
    value: T[];
    onChange: (value: T[]) => void;
    disabled?: boolean;
}) {
    return (
        <fieldset disabled={disabled} className="space-y-3">
            <legend className="mb-2 text-sm font-semibold">{legend}</legend>
            <div className="hub-interest-grid">
                {options.map((option) => (
                    <label
                        key={option.id}
                        className="hub-interest-choice"
                        data-selected={value.includes(option.id)}
                    >
                        <input
                            type="checkbox"
                            checked={value.includes(option.id)}
                            onChange={(e) =>
                                onChange(
                                    e.target.checked
                                        ? [...value, option.id]
                                        : value.filter(
                                              (id) => id !== option.id,
                                          ),
                                )
                            }
                        />
                        {legend === 'Topics' ? (
                            <TopicIcon topic={option.name} />
                        ) : (
                            <Building2 className="size-4" aria-hidden />
                        )}
                        <span>{option.name}</span>
                    </label>
                ))}
            </div>
            {options.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No teams are currently available.
                </p>
            )}
        </fieldset>
    );
}

export function ContentInterests({
    content,
    teams,
    onChange,
}: {
    content: HubContent;
    teams: HubInterests['available_teams'];
    onChange: (changes: Partial<HubContent>) => void;
}) {
    return (
        <div className="hub-content-interests space-y-4 border-t pt-4">
            <h3 className="font-semibold">Help people discover this page</h3>
            <p className="text-xs text-muted-foreground">
                Topics and related teams connect this page to Following. The
                page audience still controls who can read it.
            </p>
            <Choices
                legend="Topics"
                options={hubTopics.map((name) => ({ id: name, name }))}
                value={content.topics ?? []}
                onChange={(topics) => onChange({ topics })}
            />
            <Choices
                legend="Related teams"
                options={[
                    ...teams,
                    ...(content.team_ids ?? [])
                        .filter((id) => !teams.some((team) => team.id === id))
                        .map((id) => ({
                            id,
                            name: 'Unavailable team — remove before saving',
                        })),
                ]}
                value={content.team_ids ?? []}
                onChange={(team_ids) => onChange({ team_ids })}
            />
        </div>
    );
}

export function InterestsDialog({
    initial,
    onClose,
    onSaved,
}: {
    initial: HubInterests;
    onClose: () => void;
    onSaved: (result: HubInterests) => void;
}) {
    const [draft, setDraft] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const save = async () => {
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<HubInterests>(
                '/company-hub/interests',
                'PUT',
                {
                    version: draft.version,
                    topics: draft.topics,
                    teams: draft.teams,
                },
            );
            onSaved(result);
            onClose();
        } catch (err) {
            setError((err as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const reload = async () => {
        setBusy(true);
        try {
            setDraft(
                await hubRequest<HubInterests>('/company-hub/interests', 'GET'),
            );
            setError('');
        } catch (err) {
            setError((err as Error).message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <DialogContent className="max-h-[85vh] overflow-x-hidden overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Follow your interests</DialogTitle>
                    <DialogDescription>
                        Choose the topics and teams you want to keep up with.
                    </DialogDescription>
                </DialogHeader>
                <Choices
                    legend="Topics"
                    options={draft.available_topics.map((name) => ({
                        id: name,
                        name,
                    }))}
                    value={draft.topics}
                    disabled={busy}
                    onChange={(topics) => setDraft({ ...draft, topics })}
                />
                <Choices
                    legend="Teams"
                    options={draft.available_teams}
                    value={draft.teams}
                    disabled={busy}
                    onChange={(teams) => setDraft({ ...draft, teams })}
                />
                <p className="rounded-lg bg-muted/50 p-3 text-sm text-muted-foreground">
                    Your choices personalise Following. Everyone keeps the same
                    shared Company Hub design.
                </p>
                {error && (
                    <div
                        role="alert"
                        className="space-y-2 text-sm text-destructive"
                    >
                        <p>{error}</p>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={reload}
                        >
                            Reload saved choices
                        </Button>
                    </div>
                )}
                <DialogFooter>
                    <Button variant="ghost" disabled={busy} onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={busy} onClick={save}>
                        <Check className="size-4" />
                        {busy ? 'Saving…' : 'Save interests'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
