import { Children, cloneElement, isValidElement, useState, type ReactNode, type ReactElement, type TableHTMLAttributes } from 'react';
import { compareGridValues } from '../utils/gridSorting';
export type GridSort = { column: number; direction: 'asc' | 'desc' };
function textOf(node: ReactNode): string {
  if (node == null || typeof node === 'boolean') return '';
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (isValidElement(node)) return textOf((node.props as any).children);
  return Children.toArray(node).map(textOf).join(' ');
}
export default function SortableTable({ children, onSort, ...props }: TableHTMLAttributes<HTMLTableElement> & { onSort?: (sort: GridSort) => void }) {
  const [sort, setSort] = useState<GridSort | null>(null);
  let labels: string[] = [];
  Children.forEach(children, section => {
    if (isValidElement(section) && section.type === 'thead') Children.forEach((section.props as any).children, row => {
      if (isValidElement(row)) labels = Children.toArray((row.props as any).children).map(textOf);
    });
  });
  return <table {...props} className={`ui-table ${props.className || ''}`}>{Children.map(children, section => {
    if (!isValidElement(section)) return section;
    const element = section as ReactElement<any>;
    if (section.type === 'thead') return cloneElement(element, {}, Children.map(element.props.children, row => {
      if (!isValidElement(row)) return row;
      return cloneElement(row as ReactElement<any>, {}, Children.map((row.props as any).children, (cell, column) => {
        if (!isValidElement(cell) || !labels[column]?.trim() || /^(actions?|documents)$/i.test(labels[column].trim())) return cell;
        const active = sort?.column === column;
        return cloneElement(cell as ReactElement<any>, { 'aria-sort': active ? sort.direction === 'asc' ? 'ascending' : 'descending' : 'none' },
          <button type="button" className="grid-sort" onClick={() => { const next: GridSort = { column, direction: active && sort.direction === 'desc' ? 'asc' : 'desc' }; setSort(next); onSort?.(next); }}>{(cell.props as any).children}<span aria-hidden="true">{active ? sort.direction === 'asc' ? ' ↑' : ' ↓' : ' ↕'}</span></button>);
      }));
    }));
    if (section.type === 'tbody' && sort && !onSort) {
      const rows = Children.toArray(element.props.children);
      const value = (row: ReactNode) => {
        if (!isValidElement(row)) return '';
        const cell = Children.toArray((row.props as any).children)[sort.column];
        return isValidElement(cell) ? (cell.props as any)['data-sort-value'] ?? textOf(cell) : '';
      };
      rows.sort((a, b) => compareGridValues(value(a), value(b), labels[sort.column]) * (sort.direction === 'asc' ? 1 : -1));
      return cloneElement(element, {}, rows);
    }
    return section;
  })}</table>;
}
