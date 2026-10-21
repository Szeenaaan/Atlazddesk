export const DASHBOARD_PERIODS = [
  {value:'1h', label:'1 hour', seconds:3600}, {value:'4h', label:'4 hours', seconds:14400},
  {value:'day', label:'Day', seconds:86400}, {value:'week', label:'Week', seconds:604800},
  {value:'month', label:'Month', seconds:2592000}, {value:'year', label:'Year', seconds:31536000},
] as const;
export function inDashboardPeriod(created: string, period: string, now = Date.now()): boolean {
  const seconds = DASHBOARD_PERIODS.find(option => option.value === period)?.seconds ?? 604800;
  const date = Date.parse(created);
  return date >= now - seconds * 1000 && date <= now;
}
