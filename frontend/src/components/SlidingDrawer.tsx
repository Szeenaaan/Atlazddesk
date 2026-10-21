import { CloseButton } from './ui';
import { useEffect, useRef, useState, type ReactNode } from 'react';

export default function SlidingDrawer({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode | ((close: () => void) => ReactNode) }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const closeHandler = useRef(onClose);
  useEffect(() => { closeHandler.current = onClose; }, [onClose]);
  const [closing, setClosing] = useState(false);
  useEffect(() => {
    const element = dialog.current;
    element?.showModal();
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { element?.close(); document.body.style.overflow = previous; };
  }, []);
  useEffect(() => {
    if (!closing) return;
    const timeout = window.setTimeout(() => closeHandler.current(), window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 200);
    return () => window.clearTimeout(timeout);
  }, [closing]);
  return <dialog ref={dialog} className={`sliding-drawer ${closing ? 'is-closing' : ''}`} aria-label={title}
    onCancel={event => { event.preventDefault(); setClosing(true); }}
    onClick={event => { if (event.target === event.currentTarget) { const box = event.currentTarget.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) setClosing(true); } }}>
    <div className="drawer-top"><span>{title}</span><CloseButton className="drawer-close" aria-label={`Close ${title.toLowerCase()}`} onClick={() => setClosing(true)}/></div>
    {typeof children === 'function' ? children(() => setClosing(true)) : children}
  </dialog>;
}
