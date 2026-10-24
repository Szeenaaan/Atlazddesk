import { useFeedbackState } from './feedbackContext';
import { Field, InlineNotice } from './ui';
import { Tabs } from './ui';
import { Button, Card, Input } from './ui';
import SearchableDropdown from './SearchableDropdown';
import SortableTable from './SortableTable';
import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Activity, AlertTriangle, CheckCircle2, Download, RefreshCw, Search, ShieldCheck, Wrench } from 'lucide-react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import SystemLogs from './SystemLogs';
import SystemPerformance from './SystemPerformance';
import TechnicalSecuritySettings from './TechnicalSecuritySettings';
import countries from '../data/countries.json';
import { formatInstallationDateTime, setInstallationTimezone } from '../utils/installationTime';

type Check = { status: 'healthy' | 'warning' | 'unhealthy' | 'unknown'; detail: string; pending?: number | null; count?: number; free_percent?: number | null; last_seen_seconds_ago?: number | null };
type Overview = {
  generated_at: string;
  installation: string;
  checks: Record<string, Check>;
  runtime: Record<string, string | number | boolean | null>;
  limitations: string[];
};
type Settings = { installation_label: string; support_email: string; queue_warning_depth: number; disk_warning_percent: number; country_code: string; timezone: string };
type AuditRow = { id: number; created_at: string; user_id: number | null; event: string; request_id: string | null };
type FailedJob = { uuid: string; connection: string; queue: string; failed_at: string };

type Tab = 'overview' | 'performance' | 'failures' | 'audit' | 'configuration' | 'logs';
const fallbackSettings: Settings = { installation_label: 'SupportDesk', support_email: '', queue_warning_depth: 100, disk_warning_percent: 15, country_code: '', timezone: 'UTC' };

export default function SystemSupport({ initialTab = 'overview', retentionSettings }: { initialTab?: Tab; retentionSettings?: ReactNode }) {
  const [tab, setTab] = useState<Tab>(initialTab);
  const [overview, setOverview] = useState<Overview | null>(null);
  const [settings, setSettings] = useState<Settings>(fallbackSettings);
  const [failedJobs, setFailedJobs] = useState<FailedJob[]>([]);
  // AMC-DIAGNOSTIC-SEARCH-V10.9
  const [jobSearch, setJobSearch] = useState('');
  const [appliedJobSearch, setAppliedJobSearch] = useState('');
  const [audit, setAudit] = useState<AuditRow[]>([]);
  const [eventFilter, setEventFilter] = useState('');
  const [requestFilter, setRequestFilter] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useFeedbackState('error');
  const [, setNotice] = useFeedbackState('success');
  const [loading, setLoading] = useState(false);
  const [zones, setZones] = useState<string[]>(['UTC']);

  const getError = (error: unknown): string => getApiErrorMessage(error, 'The requested system information is unavailable. Check your connection and try again.');

  const load = useCallback(async (what: Tab, filters?: { event: string; request_id: string; q?: string }) => {
    setLoading(true); setError('');
    try {
      if (what === 'overview') {
        const { data } = await client.get<{ data: Overview }>('/system/support/overview');
        setOverview(data.data);
      } else if (what === 'failures') {
        const { data } = await client.get<{ data: FailedJob[] }>('/system/support/failed-jobs', {
          params: { q: filters?.q || undefined },
        });
        setFailedJobs(data.data);
      } else if (what === 'audit') {
        const { data } = await client.get<{ data: AuditRow[] }>('/system/support/audit', {
          params: { event: filters?.event || undefined, request_id: filters?.request_id || undefined },
        });
        setAudit(data.data);
      } else if (what === 'configuration') {
        const { data } = await client.get<{ data: Settings }>('/system/support/settings');
        setSettings(data.data);
      }
    } catch (e) { setError(getError(e)); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => {
    if (tab !== 'performance' && tab !== 'logs') void load(tab, tab === 'failures' ? { event: '', request_id: '', q: appliedJobSearch } : undefined);
  }, [tab, load, appliedJobSearch]);
  useEffect(() => {
    const country = settings.country_code;
    if (!country) { setZones(['UTC']); return; }
    let cancelled = false;
    client.get<{ data: string[] }>('/system/support/timezones', { params: { country } })
      .then(({ data }) => {
        if (!cancelled) {
          setZones(data.data);
          setSettings(current => current.country_code !== country ? current : {
            ...current,
            timezone: data.data.includes(current.timezone) ? current.timezone : data.data.length === 1 ? data.data[0] : '',
          });
        }
      }).catch(e => { if (!cancelled) setError(getError(e)); });
    return () => { cancelled = true; };
  }, [settings.country_code]);

  async function exportReport() {
    setLoading(true); setError('');
    try {
      const response = await client.get('/system/support/report', { responseType: 'blob' });
      const url = URL.createObjectURL(response.data);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'amc-support-diagnostics.json';
      document.body.appendChild(a);
      a.click(); a.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      setNotice('Diagnostic report downloaded. Review it before sharing with support.');
    } catch (e) { setError(getError(e)); }
    finally { setLoading(false); }
  }

  async function saveSettings(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault(); setLoading(true); setNotice(''); setError('');
    try {
      const { data } = await client.put<{ data: Settings }>('/system/support/settings', { ...settings, current_password: password });
      setSettings(data.data); setInstallationTimezone(data.data.timezone, data.data.country_code); setPassword(''); setNotice('Preferences saved.');
    } catch (e) { setError(getError(e)); }
    finally { setLoading(false); }
  }

  return <div className="system-support">
    <Card className="panel support-intro">
      <div className="support-intro-head"><div><h3>Technical support console</h3><p>Diagnostics for this contractor's own installation. No access to customer records, ticket management, server shell, or other contractors' VPSs.</p></div><ShieldCheck size={24}/></div>
      <div className="support-actions">
        {tab !== 'performance' && tab !== 'logs' && <Button type="button" className="btn btn-secondary" disabled={loading} onClick={() => void load(tab, { event: eventFilter, request_id: requestFilter, q: appliedJobSearch })}><RefreshCw size={16}/> Refresh</Button>}
        <Button type="button" className="btn btn-secondary" disabled={loading} onClick={() => void exportReport()}><Download size={16}/> Export safe diagnostics</Button>
      </div>
    </Card>
    <Tabs label="System support sections" value={tab} items={[
      ['overview','Health & runtime'],['performance','Metrics & traces'],['failures','Failed jobs'],['logs','System logs'],['audit','Audit history'],['configuration','Configuration'],
    ]} onChange={key => {setTab(key);setError('');setNotice('');}}/>
    {error && <InlineNotice tone="error">{error}</InlineNotice>}

    {loading && <p className="hint" role="status">Checking installation…</p>}

    {tab === 'overview' && overview && <>
      <div className="support-checks">
        {Object.entries(overview.checks).map(([name, check]) => <div className="panel support-check" key={name}>
          <div className="support-check-head">{check.status === 'healthy' ? <CheckCircle2 size={20} color="var(--color-success)"/> : <AlertTriangle size={20} color={check.status === 'unhealthy' ? 'var(--color-danger)' : 'var(--color-warning)'}/>}<b>{name.replaceAll('_',' ')}</b><span className={'support-status ' + check.status}>{check.status}</span></div>
          <p>{check.detail}</p>
          {check.pending !== undefined && check.pending !== null && <small>Pending: {check.pending}</small>}
          {check.count !== undefined && <small>Count: {check.count}</small>}
          {check.free_percent !== undefined && <small>Free: {check.free_percent ?? 'unknown'}%</small>}
          {check.last_seen_seconds_ago !== undefined && <small>Scheduler age: {check.last_seen_seconds_ago === null ? 'unknown' : `${check.last_seen_seconds_ago}s`}</small>}
        </div>)}
      </div>
      <Card className="panel support-section"><div className="support-section-title"><Activity size={16}/><h3>Runtime information</h3></div>
        <div className="support-runtime">{Object.entries(overview.runtime).map(([key, value]) => <div key={key}><span>{key.replaceAll('_',' ')}</span><b>{value === null ? 'Unavailable' : String(value)}</b></div>)}</div>
        <p className="hint">Generated {formatInstallationDateTime(overview.generated_at)}. Configuration values are intentionally limited to non-secret metadata.</p>
      </Card>
      <Card className="panel support-section"><h3>What this console cannot verify</h3><ul>{overview.limitations.map(x => <li key={x}>{x}</li>)}</ul><p>For VPS outages, failed backups, PHP-FPM restarts, firewall issues, disk corruption, or full server logs, use authorized SSH and independent monitoring.</p></Card>
    </>}

    {tab === 'performance' && <SystemPerformance />}

    {tab === 'logs' && <SystemLogs />}

    {tab === 'failures' && <Card className="panel support-section"><div className="support-section-title"><AlertTriangle size={16}/><h3>Recent failed jobs</h3></div>
      <p>Read-only metadata for the 50 most recent failed jobs. Exception traces, job payloads, and client data are intentionally hidden. Job retry is disabled here to prevent duplicate notifications or maintenance operations.</p>
      <form className="support-search-form" role="search" onSubmit={e => {
        e.preventDefault();
        const q = jobSearch.trim();
        if (q && q.length < 2) { setError('Enter at least 2 characters to search.'); return; }
        setError('');
        if (q === appliedJobSearch) void load('failures', { event: '', request_id: '', q });
        else setAppliedJobSearch(q);
      }}>
        <Field htmlFor="amc-failed-job-search">Search failed jobs</Field>
        <div className="support-search-line">
          <Input id="amc-failed-job-search" type="search" maxLength={100} value={jobSearch}
            placeholder="Job UUID, connection or queue" onChange={e => setJobSearch(e.target.value)}/>
          <Button type="submit" className="btn btn-secondary" disabled={loading}>Search</Button>
          <Button type="button" className="btn btn-secondary" disabled={loading || (!jobSearch && !appliedJobSearch)} onClick={() => {
            setJobSearch(''); setError('');
            if (appliedJobSearch === '') void load('failures', { event: '', request_id: '', q: '' });
            else setAppliedJobSearch('');
          }}>Clear</Button>
        </div>
      </form>
      <div className="table-scroll"><SortableTable><thead><tr><th>Failure UUID</th><th>Connection</th><th>Queue</th><th>Failed at</th></tr></thead><tbody>{failedJobs.map(job => <tr key={job.uuid}><td><code>{job.uuid}</code></td><td>{job.connection}</td><td>{job.queue}</td><td>{job.failed_at}</td></tr>)}</tbody></SortableTable></div>
      {!failedJobs.length && !loading && <p>{appliedJobSearch ? 'No failed jobs match your search.' : 'No failed jobs found.'}</p>}
    </Card>}

    {tab === 'audit' && <Card className="panel support-section"><div className="support-section-title"><Search size={16}/><h3>Activity audit</h3></div>
      <form className="support-filters" onSubmit={e => { e.preventDefault(); void load('audit', { event: eventFilter, request_id: requestFilter }); }}>
        <Field>Event<Input value={eventFilter} maxLength={100} placeholder="e.g. system.support_settings.updated" onChange={e => setEventFilter(e.target.value)}/></Field>
        <Field>Request ID<Input value={requestFilter} maxLength={100} onChange={e => setRequestFilter(e.target.value)}/></Field>
        <Button type="submit" className="btn btn-secondary" disabled={loading}>Search</Button>
      </form>
      <p>Most recent 30 matching events. Sensitive event properties and user-agent details are not shown.</p>
      <div className="table-scroll"><SortableTable><thead><tr><th>Date</th><th>Event</th><th>Actor ID</th><th>Request ID</th></tr></thead><tbody>{audit.map(item => <tr key={item.id}><td>{item.created_at}</td><td>{item.event}</td><td>{item.user_id ?? 'System'}</td><td><code>{item.request_id || '—'}</code></td></tr>)}</tbody></SortableTable></div>
    </Card>}

    {tab === 'configuration' && <div className="support-config-grid">
      <Card className="panel support-section"><div className="support-section-title"><Wrench size={16}/><h3>Installation settings</h3></div>
        <p>Nonsecret settings stored in this installation's database. These do not change VPS services, SMTP credentials, or contractor business records.</p>
        <form className="support-form" onSubmit={e => void saveSettings(e)}>
          <Field>Installation label<Input required minLength={2} maxLength={90} value={settings.installation_label} onChange={e => setSettings(p => ({ ...p, installation_label: e.target.value }))}/></Field>
          <Field>Technical support email (optional)<Input type="email" value={settings.support_email} onChange={e => setSettings(p => ({ ...p, support_email: e.target.value }))}/></Field>
          <Field>Country
            <SearchableDropdown value={settings.country_code} onChange={e => { const country = e.target.value; setZones(country ? [] : ['UTC']); setSettings(p => ({ ...p, country_code: country, timezone: country ? '' : 'UTC' })); }}>
              <option value="">No country selected (UTC)</option>
              {countries.map(c => <option key={c.code} value={c.code}>{c.name}</option>)}
            </SearchableDropdown>
          </Field>
          <Field>Display timezone
            <SearchableDropdown required value={settings.timezone} onChange={e => setSettings(p => ({ ...p, timezone: e.target.value }))}>
              {!zones.includes(settings.timezone) && <option value="" disabled>Select a timezone</option>}
              {zones.map(z => <option key={z} value={z}>{z.replaceAll('_', ' ')}</option>)}
            </SearchableDropdown>
          </Field>
          <p className="hint">All timestamps and background schedules remain UTC. Select a timezone and save settings below to apply it to all users. This controls display only.</p>
          <Field>Queue backlog warning threshold<Input type="number" required min={1} max={100000} value={settings.queue_warning_depth} onChange={e => setSettings(p => ({ ...p, queue_warning_depth: Number(e.target.value) }))}/></Field>
          <Field>Free disk space warning threshold (%)<Input type="number" required min={1} max={70} value={settings.disk_warning_percent} onChange={e => setSettings(p => ({ ...p, disk_warning_percent: Number(e.target.value) }))}/></Field>
          <Field>Confirm Super Admin password<Input type="password" autoComplete="current-password" required value={password} onChange={e => setPassword(e.target.value)}/></Field>
          <Button className="btn btn-primary" type="submit" disabled={loading || !settings.timezone || !zones.includes(settings.timezone)}>Save settings</Button>
        </form>
      </Card>
      <TechnicalSecuritySettings />
      {/* AMC-V1011-CONFIG-ONLY-20261019: configuration-only retention */}
      {retentionSettings}
    </div>}
  </div>;
}
