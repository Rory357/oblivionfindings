import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Check, Search, Users, X } from 'lucide-react';
import { useState } from 'react';
import type { RecognitionPerson } from './recognition-wizard';

export type RecognitionTeam = {
    id: number;
    name: string;
    member_count: number;
};

export function RecognitionRecipients({
    employees,
    teams,
    mode,
    people,
    teamId,
    changeMode,
    changePeople,
    changeTeam,
    error,
}: {
    employees: RecognitionPerson[];
    teams?: RecognitionTeam[];
    mode: 'people' | 'team';
    people: string[];
    teamId: number | null;
    changeMode: (mode: 'people' | 'team') => void;
    changePeople: (ids: string[]) => void;
    changeTeam: (id: number) => void;
    error?: string;
}) {
    const [search, setSearch] = useState('');
    const terms = search
        .toLocaleLowerCase()
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    const matches = (values: Array<string | null | undefined>) =>
        terms.every((term) =>
            values.filter(Boolean).join(' ').toLocaleLowerCase().includes(term),
        );
    const visiblePeople = employees.filter((person) =>
        matches([person.name, person.role, person.site, person.team]),
    );
    const visibleTeams = teams?.filter((team) => matches([team.name])) ?? [];
    return (
        <div className="space-y-5">
            <div>
                <h2 className="text-lg font-semibold">
                    Who made a difference?
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Choose a colleague, a few people, or a whole team.
                </p>
            </div>
            {teams !== undefined && (
                <div
                    className="flex w-fit gap-1 rounded-xl bg-muted/60 p-1"
                    aria-label="Recognition for"
                >
                    {(['people', 'team'] as const).map((value) => (
                        <Button
                            key={value}
                            variant={mode === value ? 'secondary' : 'ghost'}
                            aria-pressed={mode === value}
                            onClick={() => {
                                changeMode(value);
                                setSearch('');
                            }}
                        >
                            {value === 'people' ? 'People' : 'Whole team'}
                        </Button>
                    ))}
                </div>
            )}
            {mode === 'people' && people.length > 0 && (
                <div
                    className="flex flex-wrap gap-2"
                    aria-label="Selected colleagues"
                >
                    {people.map((id) => (
                        <Button
                            key={id}
                            variant="secondary"
                            size="sm"
                            className="h-auto min-h-9 max-w-full whitespace-normal"
                            aria-label={`Remove ${employees.find((person) => String(person.id) === id)?.name || 'unavailable colleague'}`}
                            onClick={() =>
                                changePeople(
                                    people.filter((value) => value !== id),
                                )
                            }
                        >
                            {employees.find(
                                (person) => String(person.id) === id,
                            )?.name || 'No longer available'}
                            <X className="size-3 shrink-0" />
                        </Button>
                    ))}
                </div>
            )}
            <div className="relative">
                <Search className="pointer-events-none absolute top-3.5 left-3 size-4 text-muted-foreground" />
                <Input
                    aria-label={
                        mode === 'people' ? 'Search colleagues' : 'Search teams'
                    }
                    placeholder={
                        mode === 'people'
                            ? 'Search name, role, site or team'
                            : 'Search teams'
                    }
                    className="h-11 pl-10"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />
            </div>
            <div
                className="max-h-64 overflow-y-auto overscroll-contain"
                role="group"
                aria-label={
                    mode === 'people' ? 'Choose colleagues' : 'Choose a team'
                }
            >
                {mode === 'people'
                    ? visiblePeople.map((person) => {
                          const selected = people.includes(String(person.id));
                          return (
                              <Button
                                  key={person.id}
                                  role="checkbox"
                                  aria-checked={selected}
                                  aria-label={person.name}
                                  variant="ghost"
                                  className={cn(
                                      'h-auto min-h-16 w-full justify-start gap-3 rounded-xl px-3 py-3 text-left',
                                      selected && 'bg-primary/5',
                                  )}
                                  onClick={() =>
                                      changePeople(
                                          selected
                                              ? people.filter(
                                                    (id) =>
                                                        id !==
                                                        String(person.id),
                                                )
                                              : [...people, String(person.id)],
                                      )
                                  }
                              >
                                  <Avatar className="size-9 shrink-0">
                                      <AvatarFallback className="bg-primary/10 text-xs text-primary">
                                          {person.name
                                              .split(/\s+/)
                                              .slice(0, 2)
                                              .map((part) => part[0])
                                              .join('')}
                                      </AvatarFallback>
                                  </Avatar>
                                  <span className="min-w-0 flex-1 whitespace-normal">
                                      <span className="block font-semibold">
                                          {person.name}
                                      </span>
                                      <span className="mt-1 block text-xs font-normal text-muted-foreground">
                                          {[
                                              person.role,
                                              person.site,
                                              person.team,
                                          ]
                                              .filter(Boolean)
                                              .join(' · ')}
                                      </span>
                                  </span>
                                  <span
                                      className={cn(
                                          'flex size-5 shrink-0 items-center justify-center rounded-md border',
                                          selected
                                              ? 'border-primary-fill bg-primary-fill text-primary-fill-foreground'
                                              : 'border-input',
                                      )}
                                  >
                                      {selected && (
                                          <Check className="size-3.5" />
                                      )}
                                  </span>
                              </Button>
                          );
                      })
                    : visibleTeams.map((team) => (
                          <Button
                              key={team.id}
                              role="radio"
                              aria-checked={teamId === team.id}
                              aria-label={team.name}
                              variant="ghost"
                              className={cn(
                                  'h-auto min-h-16 w-full justify-start gap-3 rounded-xl px-3 py-3 text-left',
                                  teamId === team.id && 'bg-primary/5',
                              )}
                              onClick={() => changeTeam(team.id)}
                          >
                              <Users className="size-6 shrink-0 text-primary" />
                              <span className="min-w-0 flex-1 whitespace-normal">
                                  <span className="block font-semibold">
                                      {team.name}
                                  </span>
                                  <span className="mt-1 block text-xs font-normal text-muted-foreground">
                                      {team.member_count} current{' '}
                                      {team.member_count === 1
                                          ? 'colleague'
                                          : 'colleagues'}{' '}
                                      · one shared shout-out
                                  </span>
                              </span>
                              {teamId === team.id && (
                                  <Check className="size-5 text-primary" />
                              )}
                          </Button>
                      ))}
                {(mode === 'people'
                    ? !visiblePeople.length
                    : !visibleTeams.length) && (
                    <div className="py-7 text-center text-sm text-muted-foreground">
                        <p>
                            {mode === 'team' && !teams?.length
                                ? 'No teams are available to recognise right now. You can still recognise colleagues individually.'
                                : 'No matches. Try a name or clear your search.'}
                        </p>
                        {search && (
                            <Button
                                variant="ghost"
                                className="mt-2"
                                onClick={() => setSearch('')}
                            >
                                Clear search
                            </Button>
                        )}
                    </div>
                )}
            </div>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {mode === 'people' && (
                <p className="text-xs text-muted-foreground">
                    {people.length ? `${people.length} selected · ` : ''}Each
                    person receives their own shout-out.
                </p>
            )}
        </div>
    );
}
