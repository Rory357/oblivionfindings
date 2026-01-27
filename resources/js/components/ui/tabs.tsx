import { Tab } from '@headlessui/react';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

type TabItem = { key: string; label: ReactNode; content?: ReactNode };

type TabsProps = {
  tabs: TabItem[];
  listClassName?: string;
  panelClassName?: string;
} & (
  | {
      // Uncontrolled mode
      defaultIndex?: number;
      value?: never;
      onValueChange?: never;
    }
  | {
      // Controlled mode
      value: string;
      onValueChange: (value: string) => void;
      defaultIndex?: never;
    }
);

export function Tabs({
  tabs,
  defaultIndex,
  value,
  onValueChange,
  listClassName,
  panelClassName,
}: TabsProps) {
  // Controlled mode: use value/onValueChange
  if (value !== undefined && onValueChange) {
    const selectedIndex = tabs.findIndex((t) => t.key === value);

    return (
      <Tab.Group
        selectedIndex={selectedIndex >= 0 ? selectedIndex : 0}
        onChange={(index) => onValueChange(tabs[index]?.key || '')}
      >
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

        {tabs.some((t) => t.content) && (
          <Tab.Panels className={cn('mt-4', panelClassName)}>
            {tabs.map((t) => (
              <Tab.Panel key={t.key} className="outline-none">
                {t.content}
              </Tab.Panel>
            ))}
          </Tab.Panels>
        )}
      </Tab.Group>
    );
  }

  // Uncontrolled mode: use defaultIndex
  return (
    <Tab.Group defaultIndex={defaultIndex || 0}>
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

      {tabs.some((t) => t.content) && (
        <Tab.Panels className={cn('mt-4', panelClassName)}>
          {tabs.map((t) => (
            <Tab.Panel key={t.key} className="outline-none">
              {t.content}
            </Tab.Panel>
          ))}
        </Tab.Panels>
      )}
    </Tab.Group>
  );
}
