# Shared UI consistency

The application uses the existing single CSS entry point and tokens/screens/components layers. Shared control rules stay in components.css; layout stays in screens.css. No additional stylesheet or important override was introduced.

- Helper, label, status and table text: 14px / 20px.
- Body, input and button text: 16px / 24px.
- Section and dialog headings: 20px / 28px.
- Page titles: 24px / 32px, including mobile.
- Numeric dashboard metrics: 32px / 40px through dedicated tokens.
- Controls and table-sort buttons: minimum 44px height. Shared close controls retain their 44px target and 24px icon. Standard action-button icons use 20px.
- Shared table headers: sentence case, square corners, consistent alignment and 44px minimum height. Tables scroll inside their container on mobile.
- Spacing uses the existing 4px grid: 8px labels/choices, 12px actions, 16px fields and 24px sections. Card-header actions may wrap onto another row rather than being squeezed.
- Statuses remain plain semantic colored text. Error, warning and success retain distinct meanings. Light-theme status colors and dark-theme information colors have explicit text-contrast coverage. Destructive buttons use a separate background token to preserve readable white labels.

## Validation scope

The mocked browser matrix visits available navigation pages for five roles, at 390px and 1440px, in light and dark themes: 156 screen combinations. It checks page and section typography, button/sort target heights, header styling, minimum helper sizes, horizontal page overflow and JavaScript errors. It is repeatable in `frontend/tests/browser/ui-consistency.cjs`; the JSON report defaults to `/tmp/supportdesk-ui-consistency.json`. All API responses are fixtures; it neither authenticates real accounts nor writes business records.

Additional existing browser checks cover Client Security form spacing, email-provider actions/confirmation dialogs and Contractor Admin ticket controls/drawers. These checks and selected screenshot review do not prove that every possible content length, validation state or device is visually identical. Backend behavior is unchanged in this UI pass.

No migration is needed. Refresh local staging to review the deployed UI. Production requires the normal reviewed frontend release; it has not been updated automatically.
