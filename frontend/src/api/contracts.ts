/** The standard envelope emitted by migrated Laravel API v2 controllers. */
export type ApiResponse<T = never> = {
  success: boolean;
  statuscode: number;
  message: string;
  data?: T;
  pagination?: {
    current_page: number;
    per_page: number;
    last_page: number;
    total: number;
  };
};

export type ApiErrorResponse = {
  success: false;
  statuscode: number;
  message: string;
  errors?: Record<string, string[]>;
  request_id?: string;
};

/** Displays validation details when available without exposing raw server errors. */
export function getApiErrorMessage(error: unknown, fallback = 'The request could not be completed.'): string {
  const response = (error as { response?: { data?: Partial<ApiErrorResponse> } })?.response?.data;
  const firstValidationError = response?.errors
    ? Object.values(response.errors).flat().find(message => typeof message === 'string' && message.length > 0)
    : undefined;

  const message = firstValidationError || response?.message || fallback;
  const reference = response?.request_id;
  return !firstValidationError && (response?.statuscode ?? 0) >= 500
    && typeof reference === 'string' && /^[a-zA-Z0-9-]{8,64}$/.test(reference)
    ? `${message} Reference: ${reference}.` : message;
}
