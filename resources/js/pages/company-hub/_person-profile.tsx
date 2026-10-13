import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
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
import { Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    HeartHandshake,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Plus,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import type { HubPageSummary, HubPerson } from './_types';

function ProfileEditor({
    person,
    onClose,
    onSaved,
}: {
    person: HubPerson;
    onClose: () => void;
    onSaved: (person: HubPerson) => void;
}) {
    const initial = person.shared_profile;
    const [about, setAbout] = useState(initial?.about ?? '');
    const [skills, setSkills] = useState(initial?.help_with ?? []);
    const [skill, setSkill] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [confirm, setConfirm] = useState<'discard' | 'reload' | null>(null);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const dirty =
        about !== (initial?.about ?? '') ||
        JSON.stringify(skills) !== JSON.stringify(initial?.help_with ?? []) ||
        !!skill.trim();
    const close = () => {
        if (!saving) {
            if (dirty) setConfirm('discard');
            else onClose();
        }
    };
    const appendSkill = (current: string[]) => {
        const value = skill.trim();
        if (!value) return current;
        if (value.length < 2 || value.length > 60)
            throw new Error('Use between 2 and 60 characters for each skill.');
        if (current.some((s) => s.toLowerCase() === value.toLowerCase()))
            return current;
        if (current.length >= 10)
            throw new Error(
                'You can share up to 10 skills. Remove one before adding another.',
            );
        return [...current, value];
    };
    const add = () => {
        try {
            setSkills(appendSkill(skills));
            setSkill('');
            setError('');
        } catch (e) {
            setError((e as Error).message);
        }
    };
    const save = async () => {
        if (saving) return;
        let next: string[];
        try {
            next = appendSkill(skills);
        } catch (e) {
            setError((e as Error).message);
            return;
        }
        setSaving(true);
        setError('');
        try {
            const result = await hubRequest<{ person: HubPerson }>(
                `/company-hub/people/${person.id}/profile`,
                'PUT',
                {
                    expected_version: initial?.version ?? 0,
                    about,
                    help_with: next,
                },
            );
            if (alive.current) onSaved(result.person);
        } catch (e) {
            if (alive.current) setError((e as Error).message);
        } finally {
            if (alive.current) setSaving(false);
        }
    };
    return (
        <>
            <Dialog open onOpenChange={(open) => !open && close()}>
                <DialogContent
                    className="max-h-[85vh] overflow-y-auto sm:max-w-2xl"
                    showCloseButton={!saving}
                >
                    <DialogHeader>
                        <DialogTitle>Edit your shared profile</DialogTitle>
                        <DialogDescription>
                            Your introduction and skills are visible to
                            colleagues who can see your directory profile.
                            Choose what you want to share.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <div className="space-y-2">
                            <Label htmlFor="hub-profile-about">About me</Label>
                            <Textarea
                                id="hub-profile-about"
                                value={about}
                                maxLength={1600}
                                rows={5}
                                disabled={saving}
                                onChange={(e) => setAbout(e.target.value)}
                                placeholder="Introduce yourself, your work and what you enjoy about your role…"
                                aria-describedby="hub-profile-about-hint"
                            />
                            <p
                                id="hub-profile-about-hint"
                                className="text-xs text-muted-foreground"
                            >
                                {about.length}/1,600 characters · Leave empty to
                                remove your introduction.
                            </p>
                        </div>
                        <div className="space-y-3">
                            <Label htmlFor="hub-profile-skill">
                                I can help with
                            </Label>
                            <div className="flex gap-2">
                                <Input
                                    id="hub-profile-skill"
                                    value={skill}
                                    maxLength={60}
                                    disabled={saving}
                                    onChange={(e) => setSkill(e.target.value)}
                                    placeholder="Add a skill or area of knowledge…"
                                    aria-describedby="hub-profile-skills-hint"
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            add();
                                        }
                                    }}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={add}
                                    disabled={saving || !skill.trim()}
                                >
                                    <Plus className="size-4" />
                                    Add
                                </Button>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {skills.map((s) => (
                                    <span
                                        key={s}
                                        className="flex items-center gap-1 rounded-full bg-muted py-1 pr-1 pl-3 text-sm"
                                    >
                                        {s}
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            className="size-7 rounded-full"
                                            aria-label={`Remove ${s}`}
                                            disabled={saving}
                                            onClick={() =>
                                                setSkills(
                                                    skills.filter(
                                                        (value) => value !== s,
                                                    ),
                                                )
                                            }
                                        >
                                            <X className="size-3.5" />
                                        </Button>
                                    </span>
                                ))}
                            </div>
                            <p
                                id="hub-profile-skills-hint"
                                className="text-xs text-muted-foreground"
                            >
                                {skills.length}/10 skills · Colleagues can
                                search for these in the Hub.
                            </p>
                        </div>
                        <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                            Your role, department and work contacts come from
                            your staff record. Contact People & Culture to
                            correct these details.
                        </p>
                        {error && (
                            <div
                                role="alert"
                                className="space-y-2 text-sm text-destructive"
                            >
                                <p>{error}</p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setConfirm('reload')}
                                >
                                    Reload current profile
                                </Button>
                            </div>
                        )}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={saving}
                                onClick={close}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={saving || !dirty}>
                                {saving ? 'Saving…' : 'Save shared profile'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog
                open={!!confirm}
                onOpenChange={(open) => !open && setConfirm(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {confirm === 'reload'
                                ? 'Reload your current profile?'
                                : 'Discard profile changes?'}
                        </DialogTitle>
                        <DialogDescription>
                            Your unsaved introduction and skills will be
                            discarded. Your saved shared profile stays
                            available.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirm(null)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            onClick={() => {
                                if (confirm === 'reload')
                                    router.reload({
                                        only: ['selectedPerson', 'people'],
                                    });
                                onClose();
                            }}
                        >
                            {confirm === 'reload'
                                ? 'Discard and reload'
                                : 'Discard changes'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

export function PersonProfile({
    initial,
    people,
    canViewOrg,
    onRecognise,
    pages = [],
}: {
    initial: HubPerson;
    people: HubPerson[];
    canViewOrg?: boolean;
    onRecognise?: (id: number) => void;
    pages?: HubPageSummary[];
}) {
    // A fresh server projection always supersedes a local save, including
    // current work details and access after an account or permission change.
    const [change, setChange] = useState<{
        source: HubPerson;
        person: HubPerson;
    } | null>(null);
    const person = change?.source === initial ? change.person : initial;
    const [editing, setEditing] = useState(false);
    const [saved, setSaved] = useState(false);
    const manager =
        canViewOrg && people.find((p) => p.id === person.manager_id);
    const reports = canViewOrg
        ? people.filter((p) => p.manager_id === person.id)
        : [];
    const skills = person.shared_profile?.help_with ?? [];
    const teams = pages.filter(
        (p) =>
            p.kind === 'department' &&
            !!person.department_id &&
            p.team_ids?.includes(person.department_id),
    );
    return (
        <section
            className="space-y-6"
            aria-label={`${person.name} shared profile`}
        >
            <Button asChild variant="ghost" className="-ml-3">
                <Link href="/company-hub?view=people">
                    <ArrowLeft className="size-4" />
                    All people
                </Link>
            </Button>
            <Card className="hub-profile-heading flex-row items-center gap-6 p-7">
                <span
                    className="hub-person-avatar hub-profile-avatar"
                    aria-hidden
                >
                    {person.name
                        .split(' ')
                        .slice(0, 2)
                        .map((n) => n[0])
                        .join('')}
                </span>
                <div className="min-w-0 flex-1 space-y-2">
                    <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                        Our people
                    </p>
                    <h1 className="text-3xl font-semibold tracking-tight">
                        {person.name}
                    </h1>
                    <p className="text-muted-foreground">
                        {[person.position || 'Colleague', person.department]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    {person.site && (
                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            <MapPin className="size-4" />
                            {person.site}
                        </p>
                    )}
                </div>
                <div className="flex shrink-0 flex-col gap-2">
                    {person.can_edit_profile && (
                        <Button
                            variant="outline"
                            onClick={() => {
                                setSaved(false);
                                setEditing(true);
                            }}
                        >
                            <Pencil className="size-4" />
                            Edit shared profile
                        </Button>
                    )}
                    {onRecognise && !person.can_edit_profile && (
                        <Button onClick={() => onRecognise(person.user_id)}>
                            <HeartHandshake className="size-4" />
                            Give a shout-out
                        </Button>
                    )}
                </div>
            </Card>
            {saved && (
                <p role="status" className="text-sm text-muted-foreground">
                    Your shared profile has been saved.
                </p>
            )}
            <div className="grid grid-cols-[minmax(0,1fr)_320px] items-start gap-6">
                <div className="space-y-6">
                    <Card className="gap-3 p-6">
                        <h2 className="text-lg font-semibold">About</h2>
                        <p className="text-sm leading-7 whitespace-pre-wrap">
                            {person.shared_profile?.about ||
                                (person.can_edit_profile
                                    ? 'Help colleagues get to know you. Add a short introduction to your shared profile.'
                                    : 'This colleague hasn’t added an introduction yet.')}
                        </p>
                    </Card>
                    <Card className="gap-3 p-6">
                        <h2 className="text-lg font-semibold">
                            I can help with
                        </h2>
                        {skills.length ? (
                            <div className="flex flex-wrap gap-2">
                                {skills.map((skill) => (
                                    <span
                                        className="rounded-full bg-primary/10 px-3 py-1.5 text-sm font-medium text-primary"
                                        key={skill}
                                    >
                                        {skill}
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm leading-7 text-muted-foreground">
                                {person.can_edit_profile
                                    ? 'Share skills or areas of knowledge so colleagues know when to reach out.'
                                    : 'This colleague hasn’t added skills yet.'}
                            </p>
                        )}
                    </Card>
                </div>
                <div className="space-y-6">
                    {teams.length > 0 && (
                        <Card className="gap-4 p-6">
                            <h2 className="text-base font-semibold">
                                Explore the team
                            </h2>
                            {teams.map((team) => (
                                <div key={team.id}>
                                    <Link
                                        className="text-sm font-medium text-primary"
                                        href={`/company-hub/read/${team.slug}`}
                                    >
                                        {team.title}
                                    </Link>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {team.summary}
                                    </p>
                                </div>
                            ))}
                        </Card>
                    )}
                    <Card className="gap-4 p-6">
                        <h2 className="text-base font-semibold">
                            Work contacts
                        </h2>
                        {person.work_email && (
                            <a
                                className="flex items-start gap-2 text-sm break-all text-primary"
                                href={`mailto:${person.work_email}`}
                            >
                                <Mail className="mt-0.5 size-4 shrink-0" />
                                {person.work_email}
                            </a>
                        )}
                        {person.work_phone && (
                            <a
                                className="flex items-start gap-2 text-sm text-primary"
                                href={`tel:${person.work_phone}`}
                            >
                                <Phone className="mt-0.5 size-4 shrink-0" />
                                {person.work_phone}
                            </a>
                        )}
                        {!person.work_email && !person.work_phone && (
                            <p className="text-sm text-muted-foreground">
                                Work contact details haven’t been added yet.
                            </p>
                        )}
                        {(person.first_aider || person.fire_warden) && (
                            <div className="hub-person-badges">
                                {person.first_aider && <span>First aider</span>}
                                {person.fire_warden && <span>Fire warden</span>}
                            </div>
                        )}
                    </Card>
                    {(manager || reports.length > 0) && (
                        <Card className="gap-4 p-6">
                            <h2 className="text-base font-semibold">
                                Working together
                            </h2>
                            {manager && (
                                <div>
                                    <p className="mb-1 text-xs text-muted-foreground">
                                        Reports to
                                    </p>
                                    <Link
                                        className="text-sm font-medium text-primary"
                                        href={`/company-hub/people/${manager.id}`}
                                    >
                                        {manager.name}
                                    </Link>
                                </div>
                            )}
                            {reports.length > 0 && (
                                <div>
                                    <p className="mb-2 text-xs text-muted-foreground">
                                        Direct reports
                                    </p>
                                    <div className="flex flex-col gap-2">
                                        {reports.map((p) => (
                                            <Link
                                                key={p.id}
                                                className="text-sm font-medium text-primary"
                                                href={`/company-hub/people/${p.id}`}
                                            >
                                                {p.name}
                                            </Link>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </Card>
                    )}
                    {person.can_edit_profile && (
                        <p className="px-1 text-xs leading-5 text-muted-foreground">
                            Your introduction and skills are shared with
                            colleagues who can see your directory profile.
                        </p>
                    )}
                </div>
            </div>
            <footer className="flex items-center justify-between border-t pt-4 text-xs text-muted-foreground">
                <span>Work details are maintained in staff records.</span>
                {person.staff_record_url && (
                    <Link
                        className="font-medium text-primary"
                        href={person.staff_record_url}
                    >
                        Open staff record
                    </Link>
                )}
            </footer>
            {editing && person.can_edit_profile && (
                <ProfileEditor
                    person={person}
                    onClose={() => setEditing(false)}
                    onSaved={(fresh) => {
                        setChange({ source: initial, person: fresh });
                        setEditing(false);
                        setSaved(true);
                    }}
                />
            )}
        </section>
    );
}
