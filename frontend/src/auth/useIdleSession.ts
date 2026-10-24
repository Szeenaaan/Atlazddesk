import { useEffect, useRef } from 'react';
import { client } from '../api/client';
import { clearIdleActivity, idleActivityKey, idleTimeoutMs, readIdleActivity } from './idleSession';

/** Only trusted input renews activity; API polling and tab focus never do. */
export function useIdleSession(enabled: boolean, userId: number | undefined, role: string, onExpired: () => void): void {
  const expireCallback = useRef(onExpired);
  expireCallback.current = onExpired;
  useEffect(() => {
    if (!enabled || userId === undefined) return;
    const key = idleActivityKey(userId);
    const limit = idleTimeoutMs(role);
    let lastActivity = Date.now();
    try {
      const stored = readIdleActivity(localStorage.getItem(key), Date.now());
      if (stored !== null) lastActivity = stored;
      else localStorage.setItem(key, String(lastActivity));
    } catch { /* Local fallback. */ }
    let ended = false;
    let lastSent = 0;
    let sentActivity = 0;
    let sending = false;
    let pending: number | undefined;
    const controller = new AbortController();
    const expire = () => {
      if (ended) return;
      ended = true;
      window.clearTimeout(pending);
      clearIdleActivity(userId);
      expireCallback.current();
      // Always clear the screen even if the network is unavailable or the server already expired.
      void client.post('/auth/logout').catch(() => {});
    };
    const check = () => {
      if (Date.now() - lastActivity >= limit) expire();
    };
    const reportActivity = async () => {
      pending = undefined;
      if (ended || sending) return;
      check();
      if (ended) return;
      sending = true;
      lastSent = Date.now();
      sentActivity = lastActivity;
      try { await client.post('/auth/activity', undefined, { signal: controller.signal }); }
      catch { /* 401 is handled centrally; other failures must not extend server expiry. */ }
      finally {
        sending = false;
        if (!ended && !controller.signal.aborted && lastActivity > sentActivity && pending === undefined) {
          pending = window.setTimeout(() => void reportActivity(), Math.max(0, 10000 - (Date.now() - lastSent)));
        }
      }
    };
    const activity = (event: Event) => {
      if (!event.isTrusted || ended) return;
      check(); // Input after waking a suspended tab cannot revive an expired session.
      if (ended || Date.now() - lastActivity < 250) return;
      lastActivity = Date.now();
      try { localStorage.setItem(key, String(lastActivity)); } catch { /* Local fallback. */ }
      if (!sending && pending === undefined) {
        const delay = Math.max(0, 10000 - (Date.now() - lastSent));
        if (delay === 0) void reportActivity();
        else pending = window.setTimeout(() => void reportActivity(), delay);
      }
    };
    const storage = (event: StorageEvent) => {
      if (event.key !== key || ended) return;
      if (event.newValue === null) { expire(); return; }
      const timestamp = readIdleActivity(event.newValue, Date.now());
      if (timestamp !== null) lastActivity = Math.max(lastActivity, timestamp);
      check();
    };
    const events = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'touchmove', 'input'];
    for (const name of events) window.addEventListener(name, activity, { passive: true });
    window.addEventListener('storage', storage);
    window.addEventListener('focus', check);
    window.addEventListener('amc:session-expired', expire);
    document.addEventListener('visibilitychange', check);
    const timer = window.setInterval(check, 250);
    check();
    return () => {
      ended = true;
      controller.abort();
      window.clearTimeout(pending);
      window.clearInterval(timer);
      for (const name of events) window.removeEventListener(name, activity);
      window.removeEventListener('storage', storage);
      window.removeEventListener('focus', check);
      window.removeEventListener('amc:session-expired', expire);
      document.removeEventListener('visibilitychange', check);
    };
  }, [enabled, userId, role]);
}
