import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpRight,
    CheckCheck,
    Mail,
    Phone,
    Plus,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { hubRequest } from './_api';
import type {
    HubChecklistProgress,
    HubContent,
    HubKnowledge,
    HubKnowledgeContact,
    HubProps,
} from './_types';

export function isKnowledgePage(kind: string, content: HubContent) {
    return (
        kind === 'knowledge' ||
        (kind === 'page' &&
            ['Guide', 'Policy', 'Checklist', 'Form'].includes(
                content.resource_type ?? '',
            ))
    );
}

export function knowledgePreview(
    content: HubContent,
    page: { id: number; owner_id: number; draft_version: number },
    data: HubProps,
): HubKnowledge {
    const teams = (data.interests?.available_teams ?? []).filter((team) =>
        content.team_ids?.includes(team.id),
    );
    const related = data.pages.filter(
        (item) =>
            item.id !== page.id &&
            item.team_ids?.some((id) => teams.some((team) => team.id === id)),
    );
    return {
        actor_id: 0,
        page_id: page.id,
        revision_id: 0,
        version: page.draft_version,
        steps: [],
        owner:
            data.people?.find((person) => person.user_id === page.owner_id) ??
            null,
        teams,
        contacts: [],
        related: related
            .filter((item) => ['page', 'knowledge'].includes(item.kind))
            .slice(0, 3),
        spaces: [],
    };
}

export function ChecklistSettings({
    value = [],
    onChange,
}: {
    value?: HubContent['checklist'];
    onChange: (steps: NonNullable<HubContent['checklist']>) => void;
}) {
    const move = (index: number, direction: number) => {
        const next = [...value];
        [next[index], next[index + direction]] = [
            next[index + direction],
            next[index],
        ];
        onChange(next);
    };
    return (
        <section
            className="space-y-3 border-t pt-4"
            aria-label="Checklist settings"
        >
            <h3 className="font-semibold">Personal checklist</h3>
            <p className="text-caption text-muted-foreground">
                Add up to 12 steps. Each person keeps their own progress.
                Changing a step’s wording starts that step again; moving it
                keeps its ticks.
            </p>
            {value.map((step, index) => (
                <div key={step.id} className="space-y-2 rounded-lg border p-3">
                    <Label className="grid gap-2">
                        Step {index + 1}
                        <Input
                            maxLength={300}
                            value={step.title}
                            onChange={(e) =>
                                onChange(
                                    value.map((s) =>
                                        s.id === step.id
                                            ? { ...s, title: e.target.value }
                                            : s,
                                    ),
                                )
                            }
                        />
                    </Label>
                    <div className="flex gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Move step ${index + 1} up`}
                            disabled={index === 0}
                            onClick={() => move(index, -1)}
                        >
                            <ArrowUp className="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Move step ${index + 1} down`}
                            disabled={index === value.length - 1}
                            onClick={() => move(index, 1)}
                        >
                            <ArrowDown className="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label={`Remove step ${index + 1}`}
                            onClick={() =>
                                onChange(value.filter((s) => s.id !== step.id))
                            }
                        >
                            <Trash2 className="size-4" />
                            Remove
                        </Button>
                    </div>
                </div>
            ))}
            <Button
                variant="outline"
                disabled={value.length >= 12}
                onClick={() =>
                    onChange([
                        ...value,
                        {
                            id: crypto.randomUUID(),
                            title: 'New checklist step',
                        },
                    ])
                }
            >
                <Plus className="size-4" />
                Add checklist step
            </Button>
        </section>
    );
}

export function PersonalChecklist({
    progress,
}: {
    progress: HubChecklistProgress;
}) {
    const [steps, setSteps] = useState(progress.steps);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const pending = useRef(false);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const update = async (id: string, key: string, done: boolean) => {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        setError('');
        setSaved(false);
        try {
            const result = await hubRequest<HubChecklistProgress>(
                `/company-hub/pages/${progress.page_id}/checklist`,
                'PUT',
                {
                    actor_id: progress.actor_id,
                    revision_id: progress.revision_id,
                    step_id: id,
                    step_key: key,
                    done,
                },
            );
            if (!alive.current) return;
            if (
                result.actor_id !== progress.actor_id ||
                result.page_id !== progress.page_id ||
                result.revision_id !== progress.revision_id ||
                result.steps.map((s) => s.key).join('|') !==
                    progress.steps.map((s) => s.key).join('|')
            ) {
                throw new Error(
                    'This guide or your account has changed. Reload before continuing.',
                );
            }
            setSteps(result.steps);
            setSaved(true);
        } catch (e) {
            if (alive.current) setError((e as Error).message);
        } finally {
            pending.current = false;
            if (alive.current) setBusy(false);
        }
    };
    const complete = steps.filter((s) => s.done).length;
    return (
        <Card
            className="hub-guide-checklist space-y-4 p-5"
            aria-label="Your personal checklist"
        >
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-section-title flex items-center gap-2">
                    <CheckCheck className="size-5 text-primary" />
                    Your checklist
                </h2>
                <span className="text-caption font-medium">
                    {complete} of {steps.length} complete
                </span>
            </div>
            <progress
                className="h-1.5 w-full accent-primary"
                max={steps.length}
                value={complete}
                aria-label="Checklist completion"
            />
            <div className="space-y-2">
                {steps.map((step) => (
                    <label
                        key={step.id}
                        className="hub-guide-step"
                        data-done={step.done}
                    >
                        <input
                            type="checkbox"
                            checked={step.done}
                            disabled={busy}
                            onChange={(e) =>
                                void update(step.id, step.key, e.target.checked)
                            }
                        />
                        <span>{step.title}</span>
                    </label>
                ))}
            </div>
            <p className="text-caption text-muted-foreground">
                These ticks are only shown to you. They do not record a policy
                read confirmation or complete assigned training.
            </p>
            <p
                role="status"
                className="text-caption min-h-4 text-muted-foreground"
            >
                {busy ? 'Saving your progress…' : saved ? 'Progress saved' : ''}
            </p>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </Card>
    );
}

function WorkContact({ person }: { person: HubKnowledgeContact }) {
    return (
        <div className="hub-guide-contact">
            <Link
                className="font-medium text-primary hover:underline"
                href={`/company-hub/people/${person.id}`}
            >
                {person.name}
                <ArrowUpRight className="ml-1 inline size-3" />
            </Link>
            {person.position && (
                <p className="text-caption text-muted-foreground">
                    {person.position}
                </p>
            )}
            {person.work_email && (
                <a
                    href={`mailto:${person.work_email}`}
                    className="text-caption flex items-center gap-2 break-all"
                >
                    <Mail className="size-3.5 shrink-0" />
                    {person.work_email}
                </a>
            )}
            {person.work_phone && (
                <a
                    href={`tel:${person.work_phone.replace(/[^+\d]/g, '')}`}
                    className="text-caption flex items-center gap-2"
                >
                    <Phone className="size-3.5" />
                    {person.work_phone}
                </a>
            )}
        </div>
    );
}

export function KnowledgeReader({
    content,
    knowledge,
    children,
    publishedAt,
    preview = false,
}: {
    content: HubContent;
    knowledge?: HubKnowledge | null;
    children: ReactNode;
    publishedAt?: string;
    preview?: boolean;
}) {
    const blocks = content.sections.flatMap((section) => section.blocks);
    const steps =
        content.resource_type === 'Checklist' ? (content.checklist ?? []) : [];
    return (
        <div className="hub-knowledge-reader">
            <div className="hub-guide-main">
                <div className="hub-guide-metadata">
                    <span>
                        {content.resource_type || 'Guide'}
                        {preview
                            ? ' · Draft preview'
                            : knowledge
                              ? ` · Published version ${knowledge.version}`
                              : ''}
                    </span>
                    {publishedAt && (
                        <span>Updated {formatDate(publishedAt)}</span>
                    )}
                    <span>
                        Next review:{' '}
                        {content.review_on
                            ? formatDate(content.review_on)
                            : 'Not scheduled'}
                    </span>
                </div>
                {steps.length > 0 &&
                    (preview ? (
                        <Card
                            className="space-y-3 p-5"
                            aria-label="Personal checklist preview"
                        >
                            <h2 className="text-section-title">
                                Your checklist
                            </h2>
                            {steps.map((step) => (
                                <label className="hub-guide-step" key={step.id}>
                                    <input type="checkbox" disabled />
                                    <span>{step.title}</span>
                                </label>
                            ))}
                            <p className="text-caption text-muted-foreground">
                                Staff can tick these steps in their own reading
                                view after publication.
                            </p>
                        </Card>
                    ) : (
                        knowledge && (
                            <PersonalChecklist
                                key={`${knowledge.actor_id}:${knowledge.page_id}:${knowledge.revision_id}:${knowledge.steps.map((s) => s.done).join(',')}`}
                                progress={knowledge}
                            />
                        )
                    ))}
                {children}
            </div>
            <aside className="hub-guide-sidebar" aria-label="About this guide">
                {blocks.length > 0 && (
                    <section>
                        <h2 className="text-section-title">On this page</h2>
                        <nav
                            aria-label="Guide contents"
                            className="hub-guide-contents"
                        >
                            {blocks.map((block) => (
                                <a key={block.id} href={`#block-${block.id}`}>
                                    {block.title}
                                </a>
                            ))}
                        </nav>
                    </section>
                )}
                <section>
                    <h2 className="text-section-title">Content owner</h2>
                    {knowledge?.owner ? (
                        <WorkContact person={knowledge.owner} />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {preview
                                ? 'The current owner’s available work contacts appear here when staff open the published guide.'
                                : 'No owner contact is available. Your manager can point you to the right person.'}
                        </p>
                    )}
                    {!!knowledge?.teams.length && (
                        <p className="text-caption text-muted-foreground">
                            Related{' '}
                            {knowledge.teams.length === 1 ? 'team' : 'teams'}:{' '}
                            {knowledge.teams
                                .map((team) => team.name)
                                .join(', ')}
                        </p>
                    )}
                </section>
                {!!knowledge?.related.length && (
                    <section>
                        <h2 className="text-section-title">
                            Related resources
                        </h2>
                        {knowledge.related.map((page) => (
                            <Link
                                className="hub-guide-related"
                                key={page.id}
                                href={`/company-hub/read/${page.slug}`}
                            >
                                <span>{page.title}</span>
                                <ArrowUpRight className="size-4 shrink-0" />
                            </Link>
                        ))}
                    </section>
                )}
                {!preview && (
                    <section>
                        <h2 className="text-section-title">Ask the team</h2>
                        {knowledge?.contacts.length ? (
                            knowledge.contacts.map((person) => (
                                <WorkContact key={person.id} person={person} />
                            ))
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Use the help directory to find support for your
                                question.
                            </p>
                        )}
                        {knowledge?.spaces.map((page) => (
                            <Link
                                className="hub-guide-related"
                                key={page.id}
                                href={`/company-hub/read/${page.slug}`}
                            >
                                Visit {page.title}
                                <ArrowUpRight className="size-4 shrink-0" />
                            </Link>
                        ))}
                        <Link
                            className="text-sm font-medium text-primary hover:underline"
                            href="/company-hub?view=services"
                        >
                            Open Get help{' '}
                            <ArrowUpRight className="inline size-4" />
                        </Link>
                    </section>
                )}
            </aside>
        </div>
    );
}
