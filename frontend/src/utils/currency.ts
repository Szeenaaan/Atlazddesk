import { COUNTRY_CURRENCIES } from '../data/countryCurrencies.ts';

/** The country saved with the installation timezone is authoritative, not the browser locale. */
export function defaultCurrency(country: string | null): string {
  return country ? COUNTRY_CURRENCIES[country.toUpperCase()]?.[0] ?? '' : '';
}
export const CURRENCIES = [...new Set(Object.values(COUNTRY_CURRENCIES).flat())].sort();
export function currencyLabel(code: string): string {
  try {
    const name = new Intl.DisplayNames(['en'], { type: 'currency' }).of(code);
    return name && name !== code ? `${code} — ${name}` : code;
  } catch { return code; }
}
