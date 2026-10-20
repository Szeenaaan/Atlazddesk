import { useEffect } from 'react';

export type FeedbackTone = 'success' | 'error' | 'warning';

export type Feedback = { tone: FeedbackTone; message: string };

/** A reusable, screen-reader accessible, nonblocking message. */
export default function FeedbackToast({ feedback, onDismiss }: {
  feedback: Feedback | null;
  onDismiss: () => void;
}) {
  useEffect(() => {
    if (!feedback) return;
    const handle = window.setTimeout(onDismiss, feedback.tone === 'error' ? 7000 : 4500);
    return () => window.clearTimeout(handle);
  }, [feedback, onDismiss]);

  if (!feedback) return null;
  return <div className={`amc-feedback-toast amc-feedback-${feedback.tone}`}
      role={feedback.tone === 'success' ? 'status' : 'alert'}
      aria-live={feedback.tone === 'success' ? 'polite' : 'assertive'}>
    <span className="amc-feedback-symbol" aria-hidden="true">
      {feedback.tone === 'success' ? '✓' : feedback.tone === 'warning' ? '!' : '×'}
    </span>
    <span>{feedback.message}</span>
    <button type="button" onClick={onDismiss} aria-label="Dismiss notification">×</button>
  </div>;
}
