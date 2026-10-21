import { getApiErrorMessage } from '../api/contracts.ts';

/** Preserve API error messages when Axios returns an error body as a Blob. */
export async function loadPrivateDocument(load: () => Promise<{ data: Blob }>): Promise<Blob> {
  try {
    const { data } = await load();
    return data;
  } catch (error) {
    const response = (error as { response?: { status?: number; data?: unknown } })?.response;
    if (response?.status === 401 || response?.status === 419) {
      throw new Error('Your session has expired. Sign in again to open this document.');
    }
    let data = response?.data;
    if (data instanceof Blob) {
      try { data = JSON.parse(await data.text()); }
      catch { data = undefined; }
    }
    throw new Error(getApiErrorMessage({ response: { data } }, 'Could not open the document. Please try again.'));
  }
}
