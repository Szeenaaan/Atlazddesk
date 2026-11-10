import { useEffect, useState } from 'react';
import { Download } from 'lucide-react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import { downloadReport, REPORT_EXPORTS, type ReportFormat } from '../utils/reportDownload';
import { Button, Card, Field, FilterRow, InlineNotice, Input, Pagination, RefreshButton, UIState } from './ui';
import SearchableDropdown from './SearchableDropdown';
import SortableTable, { type GridSort } from './SortableTable';
import RowActions from './RowActions';
import { useFeedbackState } from './feedbackContext';

const metrics = [
  ['completed_work', 'Work completed'], ['escalation_requests', 'Escalations requested'], ['approved_escalations', 'Escalations approved'],
  ['sla_on_time', 'Within SLA'], ['sla_late', 'Outside SLA'], ['sla_rate', 'SLA compliance'],
] as const;
type Metric = typeof metrics[number][0];
type Technician = { id: number; name: string; designation: string | null } & Record<Metric, number | null>;
const blankFilters = { search: '', from: '', to: '', workforce_type: '', status: '' };

export default function TechnicianPerformance() {
  const [filters, setFilters] = useState(blankFilters);
  const [page, setPage] = useState(1);
  const [last, setLast] = useState(1);
  const [sort, setSort] = useState<GridSort | null>(null);
  const [items, setItems] = useState<Technician[]>([]);
  const [busy, setBusy] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [revision, setRevision] = useState(0);
  const [error, setError] = useFeedbackState('error');
  const [, setDownloadError] = useFeedbackState('error');
  const params = () => ({ ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value)),
    sort: sort ? ['name', ...metrics.map(([key]) => key)][sort.column] : 'completed_work', direction: sort?.direction ?? 'desc' });
  useEffect(() => {
    const controller = new AbortController();
    setBusy(true); setError('');
    const timer = window.setTimeout(() => {
      client.get<{ data: Technician[]; last_page: number }>('/reports/technicians', {
        params: { ...params(), page }, signal: controller.signal,
      }).then(({ data }) => {
        if (!controller.signal.aborted) { setItems(data.data); setLast(data.last_page); }
      }).catch(e => { if (!controller.signal.aborted) setError(getApiErrorMessage(e, 'Technician performance could not be loaded. Retry or contact your administrator.')); })
        .finally(() => { if (!controller.signal.aborted) setBusy(false); });
    }, 250);
    return () => { controller.abort(); window.clearTimeout(timer); };
  }, [filters, page, sort, revision]);
  function filter(key: keyof typeof filters, value: string) { setFilters(previous => ({ ...previous, [key]: value })); setPage(1); }
  async function exportFile(format: ReportFormat) {
    if (exporting || busy || error) return;
    setExporting(true);
    try { await downloadReport('/reports/technicians', params(), format, 'supportdesk-technician-performance'); }
    catch (e) { setDownloadError(getApiErrorMessage(e, 'The technician report could not be downloaded. Retry the download or contact your administrator.')); }
    finally { setExporting(false); }
  }
  return <div className="reports"><Card className="panel tickets-panel report-panel" aria-label="Technician performance">
    <div className="panel-head"><div><h3>Technician performance</h3><p>Completed work and escalation activity.</p></div>
      <div className="ui-actions">
      <RowActions name="technician report formats" triggerLabel="Download report" actions={REPORT_EXPORTS.map(({ format, label }) => ({
        label, icon: <Download size={16}/>, disabled: exporting || busy || !!error, onClick: () => void exportFile(format),
      }))}/><RefreshButton label="Refresh technician performance" disabled={busy} onClick={() => setRevision(value => value + 1)}/></div>
    </div>
    <FilterRow className="report-filters">
      <Field>Search technicians<Input type="search" maxLength={100} placeholder="Name or designation" value={filters.search} onChange={e => filter('search', e.target.value)}/></Field>
      <Field>Activity from<Input type="date" value={filters.from} onChange={e => filter('from', e.target.value)}/></Field>
      <Field>Activity to<Input type="date" min={filters.from || undefined} value={filters.to} onChange={e => filter('to', e.target.value)}/></Field>
      <Field>Workforce<SearchableDropdown value={filters.workforce_type} onChange={e => filter('workforce_type', e.target.value)}>
        <option value="">All workforce</option><option value="employee">Employee</option><option value="freelancer">Freelancer</option>
      </SearchableDropdown></Field>
      <Field>Status<SearchableDropdown value={filters.status} onChange={e => filter('status', e.target.value)}>
        <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
      </SearchableDropdown></Field>
      <Button type="button" onClick={() => { setFilters(blankFilters); setPage(1); }}>Clear filters</Button>
    </FilterRow>
    {error ? <InlineNotice tone="error">{error}</InlineNotice>
      : <div className="table-scroll">
        <SortableTable aria-busy={busy} onSort={value => { setSort(value); setPage(1); }}><thead><tr><th>Technician</th>{metrics.map(([key, label]) => <th key={key}>{label}</th>)}</tr></thead>
          <tbody>{busy ? <tr><td colSpan={metrics.length + 1}><UIState tone="loading">Loading technician performance…</UIState></td></tr> : <>{items.map(item => <tr key={item.id}><td>{item.name}{item.designation && <div className="muted">{item.designation}</div>}</td>
            {metrics.map(([key]) => <td key={key} data-sort-value={item[key]}>{item[key] === null ? '—' : key === 'sla_rate' ? `${item[key]}%` : item[key]}</td>)}
          </tr>)}{!items.length && <tr><td colSpan={metrics.length + 1}>No technicians match these filters. Clear the filters to view all technicians.</td></tr>}</>}</tbody>
        </SortableTable>
      </div>}
    {last > 1 && <div className="toolbar directory-pagination">
      <Pagination page={page} last={last} busy={busy} onPage={setPage} label="Technician performance pages"/>
    </div>}
  </Card></div>;
}
