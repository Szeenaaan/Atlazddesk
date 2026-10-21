import { useEffect, useRef, type ReactNode } from 'react';
import { Button } from './index';

/** Native modal supplies focus containment, Escape handling and focus restoration. */
export function Dialog({children, label, onClose, className = ''}: {children:ReactNode;label:string;onClose:()=>void;className?:string}) {
  const element = useRef<HTMLDialogElement>(null);
  const close = useRef(onClose);
  useEffect(() => { close.current = onClose; }, [onClose]);
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    const dialog = element.current;
    dialog?.showModal();
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { dialog?.close(); document.body.style.overflow = overflow; previous?.focus(); };
  }, []);
  return <dialog ref={element} aria-label={label} className={`ui-dialog ${className}`} onCancel={event => {event.preventDefault();close.current();}}
    onClick={event => {if(event.target === event.currentTarget){const rect=event.currentTarget.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)close.current();}}}>{children}</dialog>;
}
export function ConfirmationDialog({title, children, confirmLabel, busy = false, onConfirm, onClose}: {title:string;children:ReactNode;confirmLabel:string;busy?:boolean;onConfirm:()=>void;onClose:()=>void}) {
  return <Dialog label={title} onClose={onClose}><div className="ui-dialog-body"><h2>{title}</h2>{children}<div className="ui-actions"><Button type="button" disabled={busy} onClick={onClose}>Cancel</Button><Button type="button" variant="danger" disabled={busy} onClick={onConfirm}>{confirmLabel}</Button></div></div></Dialog>;
}
