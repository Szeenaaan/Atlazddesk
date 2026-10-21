/** Compare displayed grid values; date columns use timestamps rather than lexical order. */
export function compareGridValues(a: unknown, b: unknown, label = ''): number {
  const left = String(a ?? '').trim(), right = String(b ?? '').trim();
  const empty = (v: string) => !v || v === '—';
  if (empty(left) || empty(right)) return Number(empty(right)) - Number(empty(left));
  if (/date|created|recorded|expiry|due|seen|failed at/i.test(label)) {
    const x = Date.parse(left), y = Date.parse(right);
    if (Number.isFinite(x) && Number.isFinite(y)) return x - y;
  }
  const numeric = /^-?[\d,.]+\s*(ms|s|%)?$/i;
  if (numeric.test(left) && numeric.test(right)) return parseFloat(left.replaceAll(',', '')) - parseFloat(right.replaceAll(',', ''));
  return left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
}
