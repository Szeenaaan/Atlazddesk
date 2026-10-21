import { useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { MoreHorizontal } from 'lucide-react';

type Action = { label: string; icon: ReactNode; onClick: () => void; disabled?: boolean; danger?: boolean };
export default function RowActions({ name, actions }: { name: string; actions: Action[] }) {
  const trigger = useRef<HTMLButtonElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const [position, setPosition] = useState<{ top: number; left: number } | null>(null);
  function close() { setPosition(null); trigger.current?.focus(); }
  useEffect(() => {
    if (!position) return;
    menu.current?.querySelector<HTMLButtonElement>('button:not(:disabled)')?.focus({ preventScroll: true });
    const pointer = (event: PointerEvent) => { if (!menu.current?.contains(event.target as Node) && !trigger.current?.contains(event.target as Node)) setPosition(null); };
    const dismiss = () => setPosition(null);
    document.addEventListener('pointerdown', pointer);
    window.addEventListener('resize', dismiss);
    window.addEventListener('scroll', dismiss, true);
    return () => { document.removeEventListener('pointerdown', pointer); window.removeEventListener('resize', dismiss); window.removeEventListener('scroll', dismiss, true); };
  }, [position]);
  return <>
    <button ref={trigger} type="button" className="row-actions-trigger" aria-label={`Actions for ${name}`} aria-haspopup="menu" aria-expanded={!!position}
      onClick={() => { if (position) { close(); return; } const rect = trigger.current!.getBoundingClientRect(); setPosition({ left: Math.max(8, Math.min(rect.right - 232, window.innerWidth - 240)), top: rect.bottom + actions.length * 44 + 16 > window.innerHeight ? Math.max(8, rect.top - actions.length * 44 - 16) : rect.bottom + 8 }); }}><MoreHorizontal size={20}/></button>
    {position && createPortal(<div ref={menu} className="row-actions-menu" role="menu" aria-label={`Actions for ${name}`} style={position}
      onKeyDown={event => {
        if (event.key === 'Escape' || event.key === 'Tab') { event.preventDefault(); close(); }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
          event.preventDefault(); const buttons = Array.from(menu.current!.querySelectorAll<HTMLButtonElement>('button:not(:disabled)'));
          const current = buttons.indexOf(document.activeElement as HTMLButtonElement);
          const index = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (current + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
          buttons[index]?.focus();
        }
      }}>{actions.map(action => <button key={action.label} type="button" role="menuitem" className={action.danger ? 'is-danger' : ''} disabled={action.disabled}
        onClick={() => { close(); action.onClick(); }}>{action.icon}<span>{action.label}</span></button>)}</div>, document.body)}
  </>;
}
