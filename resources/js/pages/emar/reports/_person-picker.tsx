import { PageHeaderFilterButton } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Check, ChevronsUpDown } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Person } from './_types';

/** A scoped server search; a growing people directory is never truncated into a select. */
export function PersonPicker({ value, onChange, siteId, initial, header = false }: { value: number | null; onChange: (id: number | null) => void; siteId: number | null; initial: Person[]; header?: boolean }) {
    const [open, setOpen] = useState(false), [query, setQuery] = useState(''), [people, setPeople] = useState(initial), [busy, setBusy] = useState(false), [error, setError] = useState(false), [retry, setRetry] = useState(0);
    const [chosen, setChosen] = useState<Person | undefined>(initial.find((p) => p.id === value));
    useEffect(() => { if (!value) setChosen(undefined); else setChosen((old) => initial.find((p) => p.id === value) ?? (old?.id === value ? old : undefined)); }, [value, initial]);
    useEffect(() => {
        if (!open) return;
        const abort = new AbortController();
        setBusy(true); setError(false);
        const timer = setTimeout(() => {
            const params = new URLSearchParams({ q: query, ...(siteId ? { site_id: String(siteId) } : {}) });
            fetch(`/emar/reports/people?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: abort.signal })
                .then(async (response) => { if (!response.ok) throw new Error(); return response.json(); })
                .then((result) => { if (!abort.signal.aborted) setPeople(result.people); })
                .catch(() => { if (!abort.signal.aborted) setError(true); })
                .finally(() => { if (!abort.signal.aborted) setBusy(false); });
        }, 180);
        return () => { clearTimeout(timer); abort.abort(); };
    }, [open, query, siteId, retry]);
    const content = <><span className="max-w-48 truncate">{value ? chosen?.name ?? 'Selected person' : 'All permitted people'}</span><ChevronsUpDown className="size-4 shrink-0" /></>;
    return <Popover open={open} onOpenChange={setOpen}><PopoverTrigger asChild>{header ? <PageHeaderFilterButton role="combobox" aria-label="Person" aria-expanded={open} active={value !== null}>{content}</PageHeaderFilterButton> : <Button variant="outline" role="combobox" aria-label="Person" aria-expanded={open} className="w-full justify-between">{content}</Button>}</PopoverTrigger>
        <PopoverContent align="start" className="w-80 max-w-[90vw] p-0"><Command shouldFilter={false}><CommandInput value={query} onValueChange={setQuery} placeholder="Search people…" aria-label="Search permitted people" /><CommandList>
            <CommandItem onSelect={() => { onChange(null); setOpen(false); }}>All permitted people</CommandItem>
            {busy && <p className="text-caption px-3 py-2" role="status">Searching…</p>}
            {error ? <div className="p-3" role="alert"><p className="text-subtle">People could not be loaded.</p><Button variant="outline" onClick={() => setRetry((n) => n + 1)}>Try again</Button></div> : people.map((person) => <CommandItem key={person.id} value={String(person.id)} onSelect={() => { setChosen(person); onChange(person.id); setOpen(false); }}><Check className={value === person.id ? 'opacity-100' : 'opacity-0'} />{person.name}</CommandItem>)}
            {!busy && !error && <CommandEmpty>No permitted people match.</CommandEmpty>}
        </CommandList></Command></PopoverContent></Popover>;
}
