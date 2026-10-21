import { CloseButton } from './ui';
import { Dialog } from './ui/Dialog';
import { Input } from './ui';
import { useEffect, useState } from 'react';
import { Search } from 'lucide-react';
type Props = { options: string[]; onChoose: (option: string) => void; onClose: () => void };
export default function QuickNavigator({ options, onChoose, onClose }: Props) {
  const [text, setText] = useState('');
  const matches = options.filter(option => option.toLowerCase().includes(text.toLowerCase())).slice(0, 15);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);
  return <Dialog label="Quick navigation" onClose={onClose}><div className="command-dialog" role="dialog" aria-modal="true" aria-label="Quick navigation" onMouseDown={e => e.stopPropagation()}>
    <div className="command-search"><Search size={20}/><Input autoFocus value={text} placeholder="Find a page…" onChange={e => setText(e.target.value)}
      onKeyDown={e => { if (e.key === 'Enter' && matches.length) onChoose(matches[0]); }}/><CloseButton className="icon-button" aria-label="Close" onClick={onClose}/></div>
    <div className="command-results">{matches.map(option => <button type="button" key={option} onClick={() => onChoose(option)}>{option}</button>)}
      {!matches.length && <p className="muted">No matching page</p>}</div>
    <small className="hint">Enter to select · Esc to close</small>
  </div></Dialog>;
}
