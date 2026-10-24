import { CloseButton } from './ui';
import { useCallback, useContext, useEffect, useRef, useState } from 'react';
import { DashboardFeedbackContext } from './feedbackContext';

export type FeedbackTone = 'success' | 'error' | 'warning';

export type Feedback = { tone: FeedbackTone; message: string };

/** A reusable, screen-reader accessible, nonblocking message. */
export default function FeedbackToast({ feedback, onDismiss }: {
  feedback: Feedback | null;
  onDismiss: () => void;
}) {
  const context = useContext(DashboardFeedbackContext);
  const previous = useRef('');
  const dismissRef = useRef(onDismiss);
  dismissRef.current = onDismiss;
  useEffect(() => {
    const signature = feedback ? `${feedback.tone}:${feedback.message}` : '';
    if (context && feedback && signature !== previous.current) context.publish(feedback, () => dismissRef.current());
    previous.current = signature;
  }, [context, feedback]);
  if (context) return null;
  return <ToastView feedback={feedback} onDismiss={onDismiss}/>;
}

export function ToastView({ feedback, onDismiss }: {
  feedback: Feedback | null;
  onDismiss: () => void;
}) {
  const element = useRef<HTMLDivElement>(null);
  const onDismissRef = useRef(onDismiss);
  onDismissRef.current = onDismiss;
  const [closing, setClosing] = useState(false);
  const dismiss = useCallback(() => setClosing(true), []);
  useEffect(() => {
    if (!feedback) return;
    setClosing(false);
    const handle = window.setTimeout(dismiss, feedback.tone === 'error' ? 7000 : 4500);
    const outside = (event: PointerEvent) => {
      if (!element.current?.contains(event.target as Node)) dismiss();
    };
    const keyboard = (event: KeyboardEvent) => {if (event.key === 'Escape') dismiss();};
    document.addEventListener('pointerdown', outside);
    document.addEventListener('keydown', keyboard);
    return () => {window.clearTimeout(handle);document.removeEventListener('pointerdown', outside);document.removeEventListener('keydown', keyboard);};
  }, [feedback, dismiss]);
  useEffect(() => {
    if (!closing) return;
    const handle = window.setTimeout(() => onDismissRef.current(), 160);
    return () => window.clearTimeout(handle);
  }, [closing]);

  if (!feedback) return null;
  return <div ref={element} className={`amc-feedback-toast amc-feedback-${feedback.tone}${closing ? ' is-closing' : ''}`}
      role={feedback.tone === 'success' ? 'status' : 'alert'}
      aria-live={feedback.tone === 'success' ? 'polite' : 'assertive'}>
    <span className="amc-feedback-symbol" aria-hidden="true">
      {feedback.tone === 'success' ? '✓' : feedback.tone === 'warning' ? '!' : '×'}
    </span>
    <span>{feedback.message}</span>
    <CloseButton type="button" onClick={dismiss} aria-label="Dismiss notification"/>
  </div>;
}
