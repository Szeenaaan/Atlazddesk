# Atlazddesk testing

## Database safety

Backend tests must use the isolated MySQL database `amc_platform_test`. Several integration tests reset that test schema. Never point them at development or production data.

Before running tests, inspect effective Laravel configuration, including configuration cache and `DB_URL`. With the test environment below, verify `app.env=testing`, `database.default=mysql`, the configured and resolved database names are `amc_platform_test`, and no database URL overrides the connection. If configuration is cached, clear it before rechecking. Supply test database credentials locally; do not commit `.env.testing`.

## Automated checks

From `backend-overlay`:

```bash
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=amc_platform_test DB_URL= php artisan test
```

Add `--filter=TestClassName` for focused changes. The shared test base rejects an incorrect environment, connection, or database name.

From `frontend`:

```bash
npm ci
node --test tests/*.test.mjs
npm run build
```

Use a Node.js version supporting the tests’ TypeScript imports. The build runs TypeScript checking and Vite production compilation. A bundle-size warning is distinct from a failed build.

## Browser and integration checks

- Open frontend and API using the same server hostname or LAN IP with ports 5173 and 8000 during development.
- Confirm preflight returns the exact trusted frontend origin and allows credentials; untrusted origins must not receive a usable allow-origin response.
- Confirm `/sanctum/csrf-cookie` sets `XSRF-TOKEN` and the session cookie. The following login POST must send cookies and `X-XSRF-TOKEN`; omitting CSRF must be rejected.
- Test valid/invalid login, required MFA, recovery, logout, and subsequent authenticated requests. Verify another LAN device separately.
- Check Super Admin cannot access business data; Contractor Admin cannot access technical tools; customers cannot cross tenant boundaries; technicians see assigned work only.
- Check customer-user asset assignments, ticket ownership, customer/category validation, and internal-comment privacy.
- Check work reports, reassignment, notification ownership, queue delivery, search/filter scopes, and CSV spreadsheet-formula protection.
- Verify production builds do not expose demo fixtures or secrets. Environment values prefixed `VITE_` are public build inputs.

## Latest local baseline

Before documentation consolidation: 136 backend tests passed (758 assertions), all five frontend test files passed, and the production build passed with a large-bundle warning. The stale contract test label was corrected to the existing `Contracts` navigation label. These results do not verify deployment or production browser behavior.
