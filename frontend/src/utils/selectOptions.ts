export type SelectOption = { value: string; label: string; description?: string; disabled?: boolean };
export function filterSelectOptions(options: SelectOption[], search: string): SelectOption[] {
  const term = search.trim().toLocaleLowerCase();
  return options.filter(option => !option.disabled && `${option.label} ${option.description || ''} ${option.value}`.toLocaleLowerCase().includes(term));
}
