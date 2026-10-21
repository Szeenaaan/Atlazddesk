import { useEffect, useState, type ReactNode } from 'react';
import { Button, Card, UIState } from './ui';
import { PRODUCT } from '../product';

/** Keep every SPA entry path behind the server's application-maintenance state. */
export default function MaintenanceGate({children}: {children: ReactNode}) {
  const [state, setState] = useState<'checking'|'ready'|'maintenance'|'error'>('checking');
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let disposed = false;
    let checking = false;
    let generation = 0;
    let controller: AbortController | null = null;
    const check = async () => {
      if (checking) return;
      checking = true;
      const requestGeneration = generation;
      controller = new AbortController();
      const timeout = window.setTimeout(() => controller?.abort(), 10000);
      try {
        const response = await fetch('/api/maintenance-status', {cache: 'no-store', headers: {Accept: 'application/json'}, signal: controller.signal});
        if (!response.ok) throw new Error('Status unavailable');
        const data = await response.json();
        if (typeof data.maintenance !== 'boolean') throw new Error('Invalid status');
        if (!disposed && requestGeneration === generation) {
          setState(data.maintenance ? 'maintenance' : 'ready');
          if (data.maintenance && location.pathname !== '/maintenance') history.replaceState(null, '', '/maintenance');
          if (!data.maintenance && location.pathname === '/maintenance') history.replaceState(null, '', '/');
        }
      } catch { if (!disposed && requestGeneration === generation) setState(current => current === 'ready' || current === 'maintenance' ? current : 'error'); }
      finally {window.clearTimeout(timeout);checking = false;}
    };
    const down = () => {generation++;setState('maintenance');history.replaceState(null, '', '/maintenance');};
    void check();
    const timer = window.setInterval(check, 15000);
    window.addEventListener('focus', check);
    window.addEventListener('popstate', check);
    window.addEventListener('application-maintenance', down);
    return () => {disposed = true;controller?.abort();clearInterval(timer);window.removeEventListener('focus', check);window.removeEventListener('popstate', check);window.removeEventListener('application-maintenance', down);};
  }, [retry]);
  if (state === 'ready') return children;
  return <main className="maintenance-screen"><Card>
    <h1>{state === 'maintenance' ? 'Temporarily unavailable' : PRODUCT.name}</h1>
    <UIState tone={state === 'error' ? 'error' : 'loading'}>{state === 'maintenance' ? `${PRODUCT.name} is undergoing maintenance. Please try again shortly.` : state === 'error' ? 'Unable to check application availability.' : 'Checking application availability…'}</UIState>
    {state !== 'checking' && <Button type="button" onClick={() => setRetry(value => value + 1)}>Check again</Button>}
  </Card></main>;
}
