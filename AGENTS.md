# AMC Platform

- Self-hosted per contractor: `backend-overlay/` Laravel 12/PHP, MariaDB; `frontend/` React/TypeScript/Vite; `scripts/` guarded installers; `infra/` deployment examples.
- Source in the current workspace is authoritative. Preserve local edits and use targeted changes. Never store credentials in documentation or patch payloads.
- Super Admin is technical only. Contractor Admin (`admin`) owns operations and contracts with clients. Customer Admin manages own organization users; customer users see authorized own tickets. Technicians, including freelancers, see assigned work only.
- Preserve `AmcAccess` scopes, technical boundary middleware, ticket ownership, internal comment privacy, Sanctum cookie authentication, MFA and recovery. Apply scope before search/filter conditions; group OR searches.
- API routes: `backend-overlay/routes/api_v2.php`; domain models: `app/Models`; ticket mutations: `app/Services/TicketService.php`; frontend workspace: `frontend/src/App.tsx`, API client: `src/api/client.ts`.
- Assets belong to one customer and one existing ticket category. Category is the sole user-facing classification. Legacy asset type data is retained only for compatibility. Never infer category from type, matching IDs or names.
- Backend tests: from `backend-overlay`, `APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=amc_platform_test php artisan test` (add `--filter=...` for focused tests). First verify effective Laravel connection/database, including config cache and DB_URL. Never reset/migrate development or production databases or delete business records.
- Frontend production validation: from `frontend`, `npm run build` (`tsc -b && vite build`).
- Deliver patches with read-only dry run, source guards and backups. Deployment verification remains separate from local test results.

- Customer users may have zero or multiple assigned customer-owned assets; shared assets can be assigned to multiple users. Contractor Admin and own Customer Admin manage assignments. Customer-user asset reads and new ticket associations require assignment; ticket ownership remains independent.

## Shared UI system

- Import application CSS only from `frontend/src/styles/index.css` in `main.tsx`. Cascade order is `tokens`, `screens`, `components`; do not add another override stylesheet, component-local CSS import, or `!important` rule.
- `styles/tokens.css` owns theme palettes, semantic colors, typography, spacing, radii, shadows and control dimensions. Use semantic `--color-*` variables in new components; keep meaningful error/warning/success distinctions. Use the existing `data-theme` toggle for both themes.
- Spacing follows 4px multiples: 8px label gaps, 12px action gaps, 16px field gaps, 24px sections/card padding. Controls are at least 44px tall; body/input text is 16px, labels/table/helper text at least 14px, headings 20/24px. Thin borders and focus rings are optical exceptions. Responsive boundaries are 480, 600, 800, 1152 and 1280px; CSS custom properties cannot be used inside media query conditions.
- Reuse `components/ui` Button, Field, Input, Textarea, Checkbox, Switch, FileUpload, Card, Badge, Tabs, Pagination, FilterRow and UIState. Native props, refs, form validation and event types must pass through unchanged. Do not give submit buttons a new implicit behavior.
- Reuse SearchableDropdown/SearchableSelect, SortableTable, SlidingDrawer, FeedbackToast and the native Dialog/ConfirmationDialog primitives. Dropdown menus render in the active dialog or body to avoid clipping. Dialogs must preserve focus containment, Escape handling and focus restoration. Use ConfirmationDialog for an existing confirmation flow, not to introduce new approval steps.
- Keep screen-specific layout in `styles/screens.css`; edit shared control/state rules in `styles/components.css`. Search wrappers own their border; their inner inputs must remain borderless. Keep keyboard focus visible. Avoid fixed text widths that clip long names or translations.
- System Support Configuration is vertical. Retention/cleanup UI is mounted only in System Support → Configuration. Maintenance is hidden by default and appears for Contractor Admin when `AMC_MAINTENANCE_ENABLED=true`; technicians have no independent asset directory; ticket asset context remains available within authorized assigned tickets.
- UI browser checks may mock API responses without writing business records. Report mocked browser coverage separately from real Laravel regression results; never claim unvisited screens or live authentication workflows were verified.

- Shared table headers use sentence case, 14px/20px text, consistent padding, and square corners. Use `InlineNotice` for inline information/errors/success; keep semantic tones and avoid nested decorative cards. Empty data tables must explain the empty result.

- Typography minimum is 14px for small/helper/status/table text, 16px/24px for body/controls, 20px/28px section headings and 24px/32px page titles. Use tokens even for chart labels; never reduce helper or responsive text below 14px. Spacing remains on the 4px grid; font sizes follow this type scale.

- Use shared `CloseButton` for dialog/drawer/popover/toast dismissal: 44px target, 24px icon, 8px padding, accessible label and visible focus. Preserve responsive mobile-navigation visibility when styling its close control.

- Keep TypeScript unused-local/parameter checks enabled. Run `npm run typecheck`, `npm test`, and `npm run build` from `frontend`.
- Notification list, unread count, and mark-read operations must re-check current ticket access, including reassignment and customer boundaries. Private staff events must remain hidden from customer accounts even for legacy notification records.
- Guard maintenance availability responses against stale requests; every availability fetch must have a timeout and cleanup. A delayed ready response must not override a newer maintenance signal.
