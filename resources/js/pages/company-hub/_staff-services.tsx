/* eslint-disable no-restricted-syntax -- Each complete service row is one keyboard-accessible button or link. */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    BookOpen,
    CalendarDays,
    ContactRound,
    GraduationCap,
    HeartHandshake,
    Mail,
    Phone,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import type { HubPerson } from './_types';

export type StaffService = {
    id: string;
    name: string;
    description: string;
    category: string;
    icon:
        | 'people'
        | 'leave'
        | 'policies'
        | 'learning'
        | 'profile'
        | 'maintenance';
    url: string | null;
};

const icons = {
    people: HeartHandshake,
    leave: CalendarDays,
    policies: BookOpen,
    learning: GraduationCap,
    profile: ContactRound,
    maintenance: Wrench,
};

export function StaffServiceCards({
    items,
    contacts,
}: {
    items: StaffService[];
    contacts: HubPerson[];
}) {
    const [peopleOpen, setPeopleOpen] = useState(false);
    return (
        <>
            <div className="space-y-3" aria-label="Staff services">
                {items.map((item) => {
                    const Icon = icons[item.icon];
                    const contents = (
                        <>
                            <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary transition-colors group-hover:bg-primary/15">
                                <Icon className="size-5" aria-hidden="true" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <strong className="block text-base font-semibold">
                                    {item.name}
                                </strong>
                                <span className="mt-1 block text-sm leading-relaxed text-muted-foreground">
                                    {item.description}
                                </span>
                            </span>
                            <span className="shrink-0 rounded-full bg-muted/60 px-3 py-1.5 text-xs font-medium text-muted-foreground">
                                {item.category}
                            </span>
                            <ArrowUpRight
                                className="size-5 shrink-0 text-muted-foreground transition-colors group-hover:text-primary"
                                aria-hidden="true"
                            />
                        </>
                    );
                    const style =
                        'group flex min-h-24 w-full items-center gap-5 rounded-2xl border border-border bg-card px-5 py-4 text-left transition-colors hover:border-primary/40 hover:bg-primary/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2';
                    return item.url ? (
                        <Link key={item.id} href={item.url} className={style}>
                            {contents}
                        </Link>
                    ) : (
                        <button
                            key={item.id}
                            type="button"
                            className={style}
                            onClick={() => setPeopleOpen(true)}
                        >
                            {contents}
                        </button>
                    );
                })}
            </div>
            <Dialog open={peopleOpen} onOpenChange={setPeopleOpen}>
                <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>People & Culture contacts</DialogTitle>
                        <DialogDescription>
                            Work contacts from the staff directory. Choose the
                            person you need for a private conversation.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        {contacts.length ? (
                            contacts.map((person) => (
                                <article
                                    key={person.id}
                                    className="rounded-xl border border-border bg-card p-4"
                                >
                                    <h3 className="font-semibold">
                                        {person.name}
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {[
                                            person.position,
                                            person.department,
                                            person.site,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {person.work_email && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <a
                                                    href={`mailto:${encodeURIComponent(person.work_email)}`}
                                                >
                                                    <Mail className="size-4" />
                                                    Email {person.name}
                                                </a>
                                            </Button>
                                        )}
                                        {person.work_phone && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <a
                                                    href={`tel:${encodeURIComponent(person.work_phone)}`}
                                                >
                                                    <Phone className="size-4" />
                                                    {person.work_phone}
                                                </a>
                                            </Button>
                                        )}
                                        {!person.work_email &&
                                            !person.work_phone && (
                                                <p className="text-sm text-muted-foreground">
                                                    No work contact details
                                                    listed. Your manager can
                                                    help you reach this
                                                    colleague.
                                                </p>
                                            )}
                                    </div>
                                </article>
                            ))
                        ) : (
                            <p className="rounded-xl bg-muted p-4 text-sm text-muted-foreground">
                                There are no People & Culture work contacts
                                listed for your available sites. Ask your
                                manager who to contact, or look in the people
                                directory.
                            </p>
                        )}
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="ghost"
                            onClick={() => setPeopleOpen(false)}
                        >
                            Close
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/company-hub?view=people">
                                People directory
                                <ArrowUpRight className="size-4" />
                            </Link>
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
