import { useCallback, useMemo, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { DashboardFeedbackContext } from './feedbackContext';
import { ToastView, type Feedback } from './FeedbackToast';

type Item = { id: number; feedback: Feedback; onDismiss?: () => void };

export default function DashboardFeedbackProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Item[]>([]);
  const nextId = useRef(0);
  const publish = useCallback((feedback: Feedback, onDismiss?: () => void) => {
    const item = { id: ++nextId.current, feedback, onDismiss };
    setItems(previous => {
      const existing = previous.find(entry => entry.feedback.tone === feedback.tone && entry.feedback.message === feedback.message);
      if (!existing) return [...previous, item];
      if (existing.onDismiss || !onDismiss) return previous;
      return previous.map(entry => entry.id === existing.id ? {...entry, onDismiss} : entry);
    });
  }, []);
  const context = useMemo(() => ({ publish }), [publish]);
  const current = items[0];
  const dismiss = () => {
    if (!current) return;
    setItems(previous => previous.filter(item => item.id !== current.id));
    current.onDismiss?.();
  };
  // Native dialogs occupy the browser top layer; their feedback must stay in that layer.
  const target = document.querySelector('dialog[open]') ?? document.body;
  return <DashboardFeedbackContext.Provider value={context}>
    {children}
    {current && createPortal(<ToastView key={current.id} feedback={current.feedback} onDismiss={dismiss}/>, target)}
  </DashboardFeedbackContext.Provider>;
}
