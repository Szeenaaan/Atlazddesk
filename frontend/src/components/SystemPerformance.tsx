import { useFeedbackState } from './feedbackContext';
import { Field } from './ui';
import { Button, Card, Checkbox, Input, InlineNotice, UIState } from './ui';
import SearchableDropdown from './SearchableDropdown';
import SortableTable from './SortableTable';
import { formatInstallationDateTime } from '../utils/installationTime';
import { useCallback, useEffect, useState } from 'react';
import { Activity, AlertTriangle, RefreshCw, Search, Settings2 } from 'lucide-react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';

type Settings = {
  enabled: boolean;
  sample_percent: number;
  retention_days: number;
  slow_request_ms: number;
};
type RouteMetrics = {
  route: string;
  samples: number;
  avg_ms: number;
  p95_ms: number;
  avg_db_ms: number;
  avg_queries: number;
  server_errors: number;
};
type Trace = {
  request_id: string;
  trace_id: string;
  route: string;
  method: string;
  status_code: number;
  duration_ms: number;
  db_duration_ms: number;
  db_query_count: number;
  created_at: string;
};
type Performance = {
  window_hours: number;
  sampled_requests: number;
  server_error_observations: number;
  average_latency_ms: number;
  average_db_ms: number;
  slowest_observed_ms: number;
  routes: RouteMetrics[];
  recent_traces: Trace[];
  settings: Settings;
  scope: string;
  limitations: string[];
};
type ApiEnvelope<T> = { success: boolean; statuscode: number; message: string; data: T };

function duration(value: number): string { return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })} ms`; }

export default function SystemPerformance() {
  const [hours, setHours] = useState(24);
  const [data, setData] = useState<Performance | null>(null);
  const [settings, setSettings] = useState<Settings | null>(null);
  const [password, setPassword] = useState('');
  const [filter, setFilter] = useState('');
  const [error, setError] = useFeedbackState('error');
  const [, setNotice] = useFeedbackState('success');
  const [busy, setBusy] = useState(false);
  const [trace, setTrace] = useState<Trace[] | null>(null);

  const load = useCallback(async (period: number) => {
    setBusy(true); setError('');
    try {
      const response = await client.get<ApiEnvelope<Performance>>('/system/support/telemetry', { params: { hours: period } });
      setData(response.data.data);
      setSettings(response.data.data.settings);
    } catch (err) { setError(getApiErrorMessage(err)); }
    finally { setBusy(false); }
  }, []);

  useEffect(() => { void load(hours); }, [hours, load]);

  async function saveSettings(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!settings) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const response = await client.put<ApiEnvelope<Settings>>('/system/support/telemetry/settings', {
        ...settings,
        current_password: password,
      });
      setSettings(response.data.data);
      setNotice('Performance monitoring settings saved.');
      setPassword('');
      await load(hours);
    } catch (err) { setError(getApiErrorMessage(err)); }
    finally { setBusy(false); }
  }

  async function openTrace(traceId: string) {
    setBusy(true); setError('');
    try {
      const response = await client.get<ApiEnvelope<Trace[]>>(`/system/support/telemetry/traces/${traceId}`);
      setTrace(response.data.data);
    } catch (err) { setError(getApiErrorMessage(err)); }
    finally { setBusy(false); }
  }

  return <div className="telemetry-panel">
    <Card className="panel support-section">
      <div className="support-section-title"><Activity size={20}/><h3>Application performance</h3></div>
      <div className="telemetry-toolbar">
        <Field>Time window <SearchableDropdown value={hours} onChange={e => setHours(Number(e.target.value))}>
          <option value={1}>Last hour</option><option value={24}>Last 24 hours</option><option value={168}>Last 7 days</option>
        </SearchableDropdown></Field>
        <Button className="btn btn-secondary" type="button" disabled={busy} onClick={() => void load(hours)}><RefreshCw size={16}/> Refresh</Button>
      </div>
      {error && <InlineNotice tone="error">{error}</InlineNotice>}

      {busy && <p role="status" className="hint">Loading technical metrics…</p>}
      {data && <>
        <div className="telemetry-kpis">
          <div><small>Sampled requests</small><strong>{data.sampled_requests.toLocaleString()}</strong></div>
          <div><small>Average response</small><strong>{duration(data.average_latency_ms)}</strong></div>
          <div><small>Average database time</small><strong>{duration(data.average_db_ms)}</strong></div>
          <div><small>Observed server errors</small><strong>{data.server_error_observations}</strong></div>
        </div>
        <p className="hint">{data.scope}</p>
        <h4>HTTP routes · sampled latency and database profiling</h4>
        {data.routes.length ? <div className="table-scroll"><SortableTable><thead><tr>
          <th>Route</th><th>Samples</th><th>Avg</th><th>P95</th><th>DB avg</th><th>Avg queries</th><th>5xx observations</th>
        </tr></thead><tbody>{data.routes.map(route => <tr key={route.route}>
          <td><code>{route.route}</code></td><td>{route.samples}</td><td>{duration(route.avg_ms)}</td><td>{duration(route.p95_ms)}</td>
          <td>{duration(route.avg_db_ms)}</td><td>{route.avg_queries}</td><td>{route.server_errors}</td>
        </tr>)}</tbody></SortableTable></div> : <p className="hint">No request observations in this time window. Enable collection below, then use the application to generate normal traffic.</p>}
        <div className="support-section-title telemetry-subheading"><Search size={16}/><h4>Recent request traces</h4></div>
        <Field className="telemetry-filter">Filter route or trace ID
          <Input value={filter} onChange={e => setFilter(e.target.value)} placeholder="Search technical observations"/>
        </Field>
        <div className="table-scroll"><SortableTable><thead><tr><th>Request / trace</th><th>Route</th><th>HTTP</th><th>Latency</th><th>Database</th><th>Recorded</th></tr></thead><tbody>
          {data.recent_traces.filter(t => `${t.route} ${t.trace_id} ${t.request_id}`.toLowerCase().includes(filter.toLowerCase())).map(t => <tr key={t.request_id}>
            <td><button type="button" className="telemetry-trace-link" onClick={() => void openTrace(t.trace_id)} title="Show requests sharing this trace ID">{t.trace_id.slice(0, 12)}…</button></td>
            <td><code>{t.method} {t.route}</code></td><td>{t.status_code}</td><td>{duration(t.duration_ms)}</td>
            <td data-sort-value={t.db_duration_ms}>{duration(t.db_duration_ms)} ({t.db_query_count} queries)</td><td data-sort-value={t.created_at}>{formatInstallationDateTime(t.created_at)}</td>
          </tr>)}
          {!data.recent_traces.some(t => `${t.route} ${t.trace_id} ${t.request_id}`.toLowerCase().includes(filter.toLowerCase())) && <tr><td colSpan={6}><UIState>{filter ? 'No requests match your search.' : 'No request observations in this time window.'}</UIState></td></tr>}
        </tbody></SortableTable></div>
        {trace && <div className="telemetry-trace-detail" role="region" aria-label="Trace detail">
          <div className="telemetry-detail-head"><b>Trace correlation · {trace[0]?.trace_id || 'Not found'}</b><Button type="button" className="btn btn-secondary" onClick={() => setTrace(null)}>Close</Button></div>
          {trace.length ? trace.map(item => <p key={item.request_id}><code>{item.method} {item.route}</code> — {duration(item.duration_ms)}; {item.db_query_count} database queries; HTTP {item.status_code}</p>) : <p>No observations retained for this trace.</p>}
        </div>}
        <InlineNotice><AlertTriangle size={16}/><div><b>Measurement limitations</b><ul>{data.limitations.map(value => <li key={value}>{value}</li>)}</ul></div></InlineNotice>
      </>}
    </Card>
    {settings && <Card className="panel support-section">
      <div className="support-section-title"><Settings2 size={20}/><h3>Metrics and tracing configuration</h3></div>
      <p>Controlled by this installation's Super Admin. Metrics are sampled and retained locally. No private client data or SQL statements are collected.</p>
      <form className="support-form telemetry-settings" onSubmit={e => void saveSettings(e)}>
        <Field className="telemetry-switch"><Checkbox type="checkbox" checked={settings.enabled} onChange={e => setSettings(s => s && ({ ...s, enabled: e.target.checked }))}/> Enable request telemetry</Field>
        <Field>Normal request sampling (%)<Input type="number" min={0} max={100} required value={settings.sample_percent} onChange={e => setSettings(s => s && ({ ...s, sample_percent: Number(e.target.value) }))}/></Field>
        <Field>Retention (days)<Input type="number" min={1} max={30} required value={settings.retention_days} onChange={e => setSettings(s => s && ({ ...s, retention_days: Number(e.target.value) }))}/></Field>
        <Field>Slow request threshold (ms)<Input type="number" min={100} max={60000} required value={settings.slow_request_ms} onChange={e => setSettings(s => s && ({ ...s, slow_request_ms: Number(e.target.value) }))}/></Field>
        <Field>Confirm Super Admin password<Input type="password" autoComplete="current-password" required value={password} onChange={e => setPassword(e.target.value)}/></Field>
        <Button type="submit" className="btn btn-primary" disabled={busy}>Save technical settings</Button>
      </form>
    </Card>}
  </div>;
}
