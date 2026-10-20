import { useEffect, useState } from 'react';
import { Search, X } from 'lucide-react';
type Props = { options: string[]; onChoose: (option: string) => void; onClose: () => void };
export default function QuickNavigator({ options, onChoose, onClose }: Props) {
  const [text, setText] = useState('');
  const matches = options.filter(option => option.toLowerCase().includes(text.toLowerCase())).slice(0, 15);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);
  return <div className="command-overlay" onMouseDown={onClose}><div className="command-dialog" role="dialog" aria-modal="true" aria-label="Quick navigation" onMouseDown={e => e.stopPropagation()}>
    <div className="command-search"><Search size={20}/><input autoFocus value={text} placeholder="Find a page…" onChange={e => setText(e.target.value)}
      onKeyDown={e => { if (e.key === 'Enter' && matches.length) onChoose(matches[0]); }}/><button className="icon-button" aria-label="Close" onClick={onClose}><X size={18}/></button></div>
    <div className="command-results">{matches.map(option => <button type="button" key={option} onClick={() => onChoose(option)}>{option}</button>)}
      {!matches.length && <p className="muted">No matching page</p>}</div>
    <small className="hint">Tip: Ctrl+K / ⌘K to open · Enter to select</small>
  </div></div>;
}
