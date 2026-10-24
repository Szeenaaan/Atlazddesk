export function idleTimeoutMs(role: string): number {
  return (role === 'super_admin' ? 60 : role === 'admin' ? 180 : 600) * 1000;
}

export function idleActivityKey(userId: number): string {
  return `amc:idle-activity:${userId}`;
}

export function readIdleActivity(value: string | null, now: number): number | null {
  if (value === null || value.trim() === '') return null;
  const timestamp = Number(value);
  return Number.isFinite(timestamp) && timestamp > 0 && timestamp <= now ? timestamp : null;
}

export function startIdleActivity(userId: number): void {
  try { localStorage.setItem(idleActivityKey(userId), String(Date.now())); } catch { /* Timers still work without storage. */ }
}

export function clearIdleActivity(userId: number): void {
  try { localStorage.removeItem(idleActivityKey(userId)); } catch { /* Storage may be disabled. */ }
}
