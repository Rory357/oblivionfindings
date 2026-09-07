/**
 * The Event Horizon list contracts (design_styles/LIST_STYLE_GUIDE.md):
 * every listable record renders through EntityCard / EntityTable, with the
 * kebab + right-click context menu fed by one MenuItem[].
 */
export {
    EntityCard,
    EntityCardGrid,
    type EntityCardFooter,
    type EntityCardMetric,
    type EntityCardProps,
    type EntityMeridian,
} from './entity-card';
export {
    CounterPill,
    EmptyValue,
    EntityChip,
    EntityStatusChip,
    PersonCell,
    PersonDisc,
    ProgressValue,
    initialsFromName,
} from './entity-cells';
export {
    EntityContextMenu,
    EntityKebab,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from './entity-menu';
export {
    EntityTable,
    type EntityTableColumn,
    type EntityTableIdentity,
    type EntityTableProps,
} from './entity-table';
export { ListCaption } from './list-caption';
