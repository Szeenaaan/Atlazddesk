import { useFeedbackState } from './feedbackContext';
import { CloseButton } from './ui';
import { formatInstallationDateTime } from '../utils/installationTime';
import { useEffect, useRef, useState } from 'react';
import { Bell, RefreshCw } from 'lucide-react';
import { InlineNotice, UIState } from './ui';
import { client, DEMO } from '../api/client';
import type { ApiResponse } from '../api/contracts';

type Notice = { id: number; ticket_id: number | null; event: string; title: string; message: string; read_at: string | null; created_at: string };
type Props = { onTicket: (id: number) => void };
export default function NotificationsPanel({ onTicket }: Props) {
  const holder = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<Notice[]>([]);
  const [unread, setUnread] = useState(0);
  const [error, setError] = useFeedbackState('error');
  const refreshing = useRef(false);
  useEffect(() => {
    if (!open) return;
    const outside = (event: PointerEvent) => {if (!holder.current?.contains(event.target as Node)) setOpen(false);};
    const escape = (event: KeyboardEvent) => {if(event.key === 'Escape'){setOpen(false);trigger.current?.focus();}};
    document.addEventListener('pointerdown', outside);
    document.addEventListener('keydown', escape);
    return () => {document.removeEventListener('pointerdown', outside);document.removeEventListener('keydown', escape);};
  }, [open]);
  async function refresh() {
    if (DEMO || refreshing.current) return;
    refreshing.current = true;
    try {
      const { data } = await client.get<ApiResponse<{ unread_count: number; items: Notice[] }>>('/notifications');
      setItems(data.data?.items ?? []); setUnread(data.data?.unread_count ?? 0); setError('');
    } catch { setError('Notifications could not be loaded.'); }
    finally { refreshing.current = false; }
  }
  useEffect(() => {
    if (DEMO) return;
    const refreshVisible = () => { if (document.visibilityState === 'visible') void refresh(); };
    refreshVisible();
    const interval = window.setInterval(refreshVisible, 30000);
    document.addEventListener('visibilitychange', refreshVisible);
    return () => {
      window.clearInterval(interval);
      document.removeEventListener('visibilitychange', refreshVisible);
    };
  }, []);
  async function openNotice(note: Notice) {
    if (!note.read_at) {
      try { await client.patch(`/notifications/${note.id}/read`); await refresh(); }
      catch { setError('Could not mark this notification as read.'); }
    }
    if (note.ticket_id) { onTicket(note.ticket_id); setOpen(false); }
  }
  return <div ref={holder} className="notification-holder">
    <button ref={trigger} className="icon-button notification-toggle" type="button" aria-label={`Notifications, ${unread} unread`}
      aria-expanded={open} onClick={() => setOpen(v => !v)}><Bell size={20}/>
      {unread > 0 && <span className="notification-count">{Math.min(unread, 99)}</span>}
    </button>
    {open && <div className="notification-popover" role="dialog" aria-label="Your notifications">
      <div className="popover-head"><b>Notifications</b><button type="button" className="icon-button" aria-label="Refresh notifications" title="Refresh" onClick={() => void refresh()}><RefreshCw size={16}/></button><CloseButton aria-label="Close notifications" onClick={() => setOpen(false)}/></div>
      {error && <InlineNotice tone="error">{error}</InlineNotice>}
      {!items.length ? <UIState>No notifications yet.</UIState> :
        <div className="notice-list">{items.map(note => <button key={note.id} type="button" className={`notice-item ${!note.read_at ? 'unread' : ''}`}
          onClick={() => void openNotice(note)}><b>{note.title}</b><span>{note.message}</span><small>{formatInstallationDateTime(note.created_at)}{!note.read_at ? ' · Unread' : ''}</small></button>)}</div>}
    </div>}
  </div>;
}
