import { fireEvent, render, screen, within } from '@testing-library/react';
import { existsSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { TopologyMap } from './topology-map';
import type { TopologyMapData } from './topology-types';
const directory = process.env.OBLI_TOPOLOGY_FIXTURE;
describe('Actual PHP topology projections', () => {
    it.skipIf(
        !directory || !existsSync(directory + '/topology-map-fixture.json'),
    )(
        'renders current canonical isolated nodes and exact historical comparisons',
        () => {
            const current = JSON.parse(
                readFileSync(directory + '/topology-map-fixture.json', 'utf8'),
            ) as TopologyMapData;
            const first = render(<TopologyMap topology={current} />);
            for (const node of current.nodes)
                expect(
                    screen.getByRole('button', {
                        name: 'Inspect ' + node.name,
                    }),
                ).toBeInTheDocument();
            expect(
                current.nodes.some(
                    (node) => node.topologyEvidence === 'not_observed',
                ),
            ).toBe(true);
            first.unmount();
            const history = JSON.parse(
                readFileSync(
                    directory + '/topology-history-fixture.json',
                    'utf8',
                ),
            ) as NonNullable<TopologyMapData['history']>;
            const entry = history.entries[0];
            render(
                <TopologyMap
                    topology={{
                        nodes: entry.nodes,
                        edges: entry.edges,
                        history,
                    }}
                />,
            );
            const select = screen.getByRole('combobox', {
                name: 'Topology snapshot',
            });
            fireEvent.keyDown(select, { key: 'ArrowDown' });
            fireEvent.click(
                screen.getByRole('option', { name: /Fixture Site/ }),
            );
            const comparison = screen.getByRole('region', {
                name: 'Snapshot comparison',
            });
            expect(
                screen.getByText('Historical connections'),
            ).toBeInTheDocument();
            for (const [label, entries] of [
                ['Added', entry.comparison.addedEdges],
                ['Removed', entry.comparison.removedEdges],
                ['Changed', entry.comparison.changedEdges],
            ] as const)
                expect(
                    within(comparison).getByRole('heading', {
                        name: label + ' · ' + entries.length,
                    }),
                ).toBeInTheDocument();
        },
    );
});

describe('Actual PHP topology pagination', () => {
    it.skipIf(
        !directory ||
            !existsSync(directory + '/topology-pagination-fixture.json'),
    )(
        'replaces complete server pages and exposes their exact navigation boundaries',
        () => {
            const pages = JSON.parse(
                readFileSync(
                    directory + '/topology-pagination-fixture.json',
                    'utf8',
                ),
            ) as Record<'first' | 'older' | 'newer' | 'reset', TopologyMapData>;
            const view = render(<TopologyMap topology={pages.first} />);
            expect(
                screen.getByRole('link', { name: 'Older snapshots' }),
            ).toHaveAttribute('href', pages.first.history!.nextHref);
            expect(
                screen.queryByRole('link', { name: 'Newer snapshots' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('Historical connections'),
            ).not.toBeInTheDocument();
            view.rerender(<TopologyMap topology={pages.older} />);
            expect(
                screen.getByText('Historical connections'),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('combobox', { name: 'Topology snapshot' }),
            ).toHaveTextContent(
                'Snapshot ' + pages.older.history!.entries[0].id + ' ·',
            );
            expect(
                screen.getByRole('link', { name: 'Newer snapshots' }),
            ).toHaveAttribute('href', pages.older.history!.previousHref);
            expect(
                screen.getByRole('link', { name: 'Older snapshots' }),
            ).toHaveAttribute('href', pages.older.history!.nextHref);
            view.rerender(<TopologyMap topology={pages.newer} />);
            expect(
                screen.queryByText('Historical connections'),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('combobox', { name: 'Topology snapshot' }),
            ).toHaveTextContent('Latest visible topology');
            view.rerender(<TopologyMap topology={pages.reset} />);
            expect(
                within(
                    screen.getByRole('region', { name: 'Snapshot history' }),
                ).getByRole('status'),
            ).toHaveTextContent('That history position is no longer available');
            expect(
                screen.getByRole('link', { name: 'Refresh view' }),
            ).toHaveAttribute('href', pages.reset.history!.latestHref);
        },
    );
});

describe('Actual PHP topology inventory', () => {
    it.skipIf(
        !directory ||
            !existsSync(directory + '/topology-inventory-fixture.json'),
    )(
        'reaches scoped devices beyond the first hundred and preserves recoverable empty views',
        () => {
            const pages = JSON.parse(
                readFileSync(
                    directory + '/topology-inventory-fixture.json',
                    'utf8',
                ),
            ) as Record<
                'first' | 'second' | 'site' | 'search' | 'empty' | 'reset',
                TopologyMapData
            >;
            const view = render(<TopologyMap topology={pages.first} />);
            expect(pages.first.inventory!.total).toBe(205);
            expect(
                screen.getByRole('link', { name: 'More devices' }),
            ).toHaveAttribute('href', pages.first.inventory!.nextHref);
            expect(
                screen.queryByRole('button', {
                    name: 'Inspect Device 101',
                }),
            ).not.toBeInTheDocument();

            // Search reveals a readable selection; keyboard modality is verified in the real browser.
            const zoom = () =>
                Number(
                    screen
                        .getByLabelText('Map zoom')
                        .textContent!.replace('%', ''),
                );
            expect(zoom()).toBeLessThan(50);
            fireEvent.change(screen.getByLabelText('Find within this view'), {
                target: { value: 'Device 99' },
            });
            fireEvent.click(
                within(
                    screen.getByLabelText('Device search results'),
                ).getByRole('button', { name: 'Device 99' }),
            );
            expect(zoom()).toBeGreaterThanOrEqual(80);
            expect(
                screen.getByRole('link', { name: 'Open Device 99' }),
            ).toHaveAttribute('href', '/security-devices/devices/99');
            fireEvent.click(screen.getByRole('button', { name: 'Fit map' }));
            expect(zoom()).toBeLessThan(50);

            view.rerender(<TopologyMap topology={pages.second} />);
            expect(
                screen.queryByRole('link', { name: 'Open Device 99' }),
            ).not.toBeInTheDocument();
            expect(screen.getByLabelText('Find within this view')).toHaveValue(
                '',
            );
            expect(
                screen.getByRole('button', {
                    name: 'Inspect Device 101',
                }),
            ).toBeInTheDocument();
            expect(
                screen.queryByRole('button', {
                    name: 'Inspect Device 1',
                }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: 'Previous devices' }),
            ).toHaveAttribute('href', pages.second.inventory!.previousHref);
            expect(
                screen.getByRole('link', { name: 'Older snapshots' }),
            ).toHaveAttribute('href', pages.second.history!.nextHref);
            expect(
                new URL(
                    pages.second.history!.nextHref!,
                    'https://example.test',
                ).searchParams.get('map_device_cursor'),
            ).toBe(
                new URL(
                    pages.first.inventory!.nextHref!,
                    'https://example.test',
                ).searchParams.get('map_device_cursor'),
            );
            expect(
                screen.getByRole('button', {
                    name: 'Inspect connection Device 101 to Device 102',
                }),
            ).toBeInTheDocument();

            for (const page of [pages.site, pages.search]) {
                view.rerender(<TopologyMap topology={page} />);
                const directory = screen.getByRole('region', {
                    name: 'Device directory',
                });
                expect(within(directory).getAllByRole('link')).toHaveLength(
                    page.inventory!.shown,
                );
                for (const node of page.nodes)
                    expect(
                        within(directory).getByRole('link', {
                            name: node.name,
                        }),
                    ).toHaveAttribute('href', node.href);
                expect(
                    screen.queryByText(/Hidden %_!|Healthcare %_!/),
                ).not.toBeInTheDocument();
                expect(
                    screen.getByRole('link', { name: 'Clear view' }),
                ).toHaveAttribute(
                    'href',
                    '/security-devices/network-it?tab=map',
                );
            }
            expect(screen.getByLabelText('Device name')).toHaveValue('%_!');

            view.rerender(<TopologyMap topology={pages.empty} />);
            expect(
                screen.getByText('No devices match this view'),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('form', { name: 'Choose topology devices' }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: 'Clear view' }),
            ).toBeInTheDocument();
            view.rerender(<TopologyMap topology={pages.reset} />);
            expect(
                screen.getByText(/That device page is no longer available/),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: 'More devices' }),
            ).toHaveAttribute('href', pages.reset.inventory!.nextHref);
        },
    );
});

describe('Actual PHP current connection exploration', () => {
    it.skipIf(
        !directory ||
            !existsSync(directory + '/topology-exploration-fixture.json'),
    )(
        'follows devices beyond inventory pages with exact connection and current-view boundaries',
        () => {
            const pages = JSON.parse(
                readFileSync(
                    directory + '/topology-exploration-fixture.json',
                    'utf8',
                ),
            ) as Record<
                | 'inventory'
                | 'first'
                | 'second'
                | 'third'
                | 'recentered'
                | 'reset'
                | 'empty'
                | 'manual',
                TopologyMapData
            >;
            const view = render(<TopologyMap topology={pages.inventory} />);
            expect(
                screen.queryByRole('button', { name: 'Inspect Anchor switch' }),
            ).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Inspect Device 40' }),
            );
            expect(
                screen.getByRole('link', {
                    name: 'Explore current connections',
                }),
            ).toHaveAttribute(
                'href',
                pages.recentered.exploration!.refreshHref,
            );
            view.rerender(<TopologyMap topology={pages.recentered} />);
            expect(
                screen.getByRole('link', { name: 'Open Device 40' }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: 'Inspect Device 140' }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: 'Inspect Anchor switch' }),
            ).toBeInTheDocument();
            expect(
                screen.queryByRole('region', { name: 'Snapshot history' }),
            ).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Inspect Anchor switch' }),
            );
            expect(
                screen.getByRole('link', {
                    name: 'Explore current connections',
                }),
            ).toHaveAttribute('href', pages.first.exploration!.refreshHref);
            for (const page of [pages.first, pages.second, pages.third]) {
                view.rerender(<TopologyMap topology={page} />);
                expect(
                    screen.getByRole('link', { name: 'Open Anchor switch' }),
                ).toBeInTheDocument();
                expect(page.exploration!.neighborsTotal).toBe(117);
                expect(
                    screen.getByText(
                        'Showing ' +
                            page.exploration!.neighborsShown +
                            ' of 117 visible connected devices in this evidence view.',
                    ),
                ).toBeInTheDocument();
                for (const edge of page.edges)
                    expect(
                        screen.getByRole('button', {
                            name:
                                'Inspect connection ' +
                                edge.parentName +
                                ' to ' +
                                edge.childName +
                                (edge.port ? ' on ' + edge.port : ''),
                        }),
                    ).toBeInTheDocument();
            }
            expect(
                screen.getByRole('button', { name: 'Inspect Device 117' }),
            ).toBeInTheDocument();
            for (const id of [118, 119, 120])
                expect(
                    screen.queryByRole('button', {
                        name: 'Inspect Device ' + id,
                    }),
                ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('link', { name: 'More connected devices' }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('link', {
                    name: 'Previous connected devices',
                }),
            ).toHaveAttribute('href', pages.third.exploration!.previousHref);
            view.rerender(<TopologyMap topology={pages.reset} />);
            expect(
                screen.getByText(/That connection page is no longer available/),
            ).toBeInTheDocument();
            view.rerender(<TopologyMap topology={pages.empty} />);
            expect(
                screen.getByText(
                    'No visible connected devices were found in this evidence view.',
                ),
            ).toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /Inspect connection/ }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Inspect Device 201' }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: 'Site map' }),
            ).toHaveAttribute('href', pages.empty.exploration!.inventoryHref);
            view.rerender(<TopologyMap topology={pages.manual} />);
            expect(
                screen.getByRole('link', { name: 'Open Device 300' }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('button', {
                    name: 'Inspect connection Device 300 to Device 301',
                }),
            ).toBeInTheDocument();
            expect(
                screen.getByText(
                    'Reviewed canonical Device relationship · 100% confidence',
                ),
            ).toBeInTheDocument();
        },
    );
});
