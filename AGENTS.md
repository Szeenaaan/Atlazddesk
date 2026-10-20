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
