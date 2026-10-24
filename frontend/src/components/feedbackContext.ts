import { createContext, useCallback, useContext, useEffect, useState, type Dispatch, type SetStateAction } from 'react';
import type { Feedback } from './FeedbackToast';

export const DashboardFeedbackContext = createContext<{
  publish: (feedback: Feedback, onDismiss?: () => void) => void;
} | null>(null);

/** Keep form state available for validation while announcing it at the dashboard level. */
export function useFeedbackState(tone: Feedback['tone']) {
  const [{ message, revision }, update] = useState({ message: '', revision: 0 });
  const setMessage: Dispatch<SetStateAction<string>> = useCallback(value => update(previous => ({
    message: typeof value === 'function' ? value(previous.message) : value,
    revision: previous.revision + 1,
  })), []);
  const context = useContext(DashboardFeedbackContext);
  useEffect(() => {
    if (message) context?.publish({ tone, message });
  }, [context, message, revision, tone]);
  return [message, setMessage] as const;
}

export function useDashboardFeedback() {
  return useContext(DashboardFeedbackContext)?.publish;
}
