import { useCallback, useEffect, useState } from 'react';
import { AlertCircle, AlertTriangle, CheckCircle2, RefreshCw } from 'lucide-react';
import { client } from '../api/client';

type Category = 'all' | 'application' | 'security' | 'jobs' | 'mail' | 'audit';
type Level = 'all' | 'info' | 'warning' | 'error' | 'critical';
type Entry = {
  id: string;
  category: Exclude<Category, 'all'>;
  level: Exclude<Level, 'all'>;
  message: string;
  occurred_at: string;
  request_id: string | null;
};

const categories: { value: Category; label: string }[] = [
  { value: 'all', label: 'All categories' },
  { value: 'application', label: 'Application' },
  { value: 'security', label: 'Security' },
  { value: 'jobs', label: 'Background jobs' },
  { value: 'mail', label: 'Email delivery' },
  { value: 'audit', label: 'Technical audit' },
];
const levels: { value: Level; label: string }[] = [
  { value: 'all', label: 'All levels' },
  { value: 'info', label: 'Info / success' },
  { value: 'warning', label: 'Warning' },
  { value: 'error', label: 'Error' },
  { value: 'critical', label: 'Critical' },
];

function safeDate(value: string): string {
  if (!value) return 'Unknown';
  // Laravel timestamps without a timezone suffix are persisted in UTC.
  const utc = /(?:Z|[+-]\d\d:\d\d)$/.test(value) ? value : value.replace(' ', 'T') + 'Z';
  const date = new Date(utc);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

export default function SystemLogs() {
  const [category, setCategory] = useState<Category>('all');
  const [level, setLevel] = useState<Level>('all');
  // AMC-DIAGNOSTIC-SEARCH-V10.9: search only permitted technical event metadata.
  const [search, setSearch] = useState('');
  const [appliedSearch, setAppliedSearch] = useState('');
  const [entries, setEntries] = useState<Entry[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const { data } = await client.get<{ data: Entry[] }>('/system/support/logs', {
        params: { category, level, q: appliedSearch || undefined },
      });
      setEntries(data.data);
    } catch {
      setEntries([]);
      setError('Could not retrieve system logs. Check your connection and try again.');
    } finally { setLoading(false); }
  }, [category, level, appliedSearch]);

  useEffect(() => { void load(); }, [load]);

  return <section className="panel support-section support-log-explorer">
    <div className="support-section-title">
      <h3>System logs</h3>
    </div>
    <p>Recent technical events from this installation. Secrets, customer data, stack traces and raw log files are intentionally excluded.</p>
    <form className="support-search-form" role="search" onSubmit={e => {
      e.preventDefault();
      const q = search.trim();
      if (q && q.length < 2) { setError('Enter at least 2 characters to search.'); return; }
      setError('');
      if (q === appliedSearch) void load(); else setAppliedSearch(q);
    }}>
      <label htmlFor="amc-log-search">Search technical events</label>
      <div className="support-search-line">
        <input id="amc-log-search" type="search" maxLength={100} value={search}
          placeholder="Event, exception class, request ID or job UUID"
          onChange={e => setSearch(e.target.value)}/>
        <button className="btn btn-secondary" type="submit" disabled={loading}>Search</button>
        <button className="btn btn-secondary" type="button" disabled={loading || (!search && !appliedSearch)} onClick={() => {
          setSearch(''); setError('');
          if (appliedSearch === '') void load(); else setAppliedSearch('');
        }}>Clear</button>
      </div>
    </form>
    <div className="support-log-controls">
      <label>Category
        <select value={category} onChange={e => setCategory(e.target.value as Category)}>
          {categories.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
        </select>
      </label>
      <label>Severity
        <select value={level} onChange={e => setLevel(e.target.value as Level)}>
          {levels.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
        </select>
      </label>
      <button className="btn btn-secondary" type="button" onClick={() => void load()} disabled={loading}>
        <RefreshCw size={16}/> Refresh
      </button>
    </div>
    {error && <p className="support-log-error" role="alert">{error}</p>}
    {loading && <p role="status" className="hint">Loading system logs…</p>}
    <div className="support-log-list" aria-live="polite">
      {entries.map(entry => <article className="support-log-row" key={entry.id}>
        <div className="support-log-status" aria-label={entry.level}>
          {entry.level === 'error' || entry.level === 'critical'
            ? <AlertCircle size={18} className="support-log-icon-error" />
            : entry.level === 'warning'
              ? <AlertTriangle size={18} className="support-log-icon-warning" />
              : <CheckCircle2 size={18} className="support-log-icon-info" />}
        </div>
        <div className="support-log-body">
          <div className="support-log-message">{entry.message}</div>
          <div className="support-log-meta">
            <span className={'support-log-level support-log-level-' + entry.level}>{entry.level}</span>
            <span>{categories.find(item => item.value === entry.category)?.label || entry.category}</span>
            {entry.request_id && <span>Request: <code>{entry.request_id}</code></span>}
            {entry.category === 'jobs' && entry.id.startsWith('job:') && <span>Job: <code>{entry.id.slice(4)}</code></span>}
          </div>
        </div>
        <time className="support-log-time">{safeDate(entry.occurred_at)}</time>
      </article>)}
    </div>
    {!loading && !error && !entries.length && <p className="support-log-empty">{appliedSearch ? 'No technical events match this search in the current reporting window.' : 'No technical events in the current reporting window.'}</p>}
    <p className="hint">Displays at most 100 events from the past 30 days. For full server-level diagnostics, use authorized VPS access.</p>
  </section>;
}
