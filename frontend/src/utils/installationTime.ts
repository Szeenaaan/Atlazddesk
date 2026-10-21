let displayTimezone = 'UTC';
let displayCountry: string | null = null;

/** Time storage and the Laravel scheduler stay UTC. This only controls presentation. */
export function setInstallationTimezone(next: string, country: string | null = null): void {
  try {
    new Intl.DateTimeFormat('en-GB', { timeZone: next });
    displayTimezone = next;
    displayCountry = country;
    window.dispatchEvent(new CustomEvent('amc:timezone-updated'));
  } catch {
    displayTimezone = 'UTC';
    displayCountry = null;
  }
}

export function getInstallationCountry(): string | null { return displayCountry; }

export function getInstallationTimezone(): string { return displayTimezone; }

function dateFromServer(input: string | Date): Date {
  if (input instanceof Date) return input;
  // Laravel API should supply ISO-8601 UTC timestamps. Never interpret an unzoned
  // database timestamp as the browser's current local timezone.
  const iso = /^\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:\.\d+)?$/.test(input)
    ? `${input.replace(' ', 'T')}Z`
    : input;
  return new Date(iso);
}

export function formatInstallationDate(input: string | Date): string {
  const date = dateFromServer(input);
  if (Number.isNaN(date.getTime())) return 'Date unavailable';
  return new Intl.DateTimeFormat('en-GB', { timeZone: displayTimezone, day: '2-digit', month: 'short', year: 'numeric' }).format(date);
}

export function formatInstallationDateTime(input: string | Date): string {
  const date = dateFromServer(input);
  if (Number.isNaN(date.getTime())) return 'Date unavailable';
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: displayTimezone, day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).format(date);
}

export function formatInstallationTime(input: string | Date): string {
  const date = dateFromServer(input);
  if (Number.isNaN(date.getTime())) return 'Time unavailable';
  return new Intl.DateTimeFormat('en-GB', { timeZone: displayTimezone, hour: '2-digit', minute: '2-digit' }).format(date);
}
