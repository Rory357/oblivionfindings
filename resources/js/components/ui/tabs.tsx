import { Tab } from '@headlessui/react';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

type TabItem = { key: string; label: ReactNode; content: ReactNode };

export function Tabs({
  tabs,
  defaultIndex = 0,
  listClassName,
  panelClassName,
}: {
  tabs: TabItem[];
  defaultIndex?: number;
  listClassName?: string;
  panelClassName?: string;
}) {
  return (
    <Tab.Group defaultIndex={defaultIndex}>
      <Tab.List
        className={cn(
          'inline-flex w-full flex-wrap items-center gap-2 rounded-xl border bg-background p-1',
          listClassName,
        )}
      >
        {tabs.map((t) => (
          <Tab
            key={t.key}
            className={({ selected }) =>
              cn(
                'rounded-lg px-3 py-1.5 text-sm outline-none transition',
                selected
                  ? 'bg-muted font-medium text-foreground'
                  : 'text-muted-foreground hover:bg-muted/50',
              )
            }
          >
            {t.label}
          </Tab>
        ))}
      </Tab.List>

      <Tab.Panels className={cn('mt-4', panelClassName)}>
        {tabs.map((t) => (
          <Tab.Panel key={t.key} className="outline-none">
            {t.content}
          </Tab.Panel>
        ))}
      </Tab.Panels>
    </Tab.Group>
  );
}
