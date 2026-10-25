# SupportDesk roles

SupportDesk is self-hosted per contractor. The technical owner and business operator have separate responsibilities. No hidden vendor/support account is created.

| Role | Responsibilities | Boundary |
|---|---|---|
| Super Admin (`super_admin`) | Technical monitoring, sanitized system logs, installation security, and contractor administrator provisioning | No customer operations, tickets, contracts, or business reports |
| Contractor Admin (`admin`) | Customer onboarding, customer administrators, workforce, contracts, assets, management of existing tickets, operational reports, mail routing, and client security policies | No technical Super Admin tools or ticket creation, including in delegated customer workspaces |
| Technician (`technician`) | Assigned work, permitted updates/comments, and structured work reports | Assigned work only; no user management or ticket reassignment |
| Customer Admin (`customer_admin`) | Own organization’s users, assets, assignments, and authorized tickets/reports | Cannot act for another customer or configure contractor security policies |
| Customer User (`customer_user`) | Own authorized tickets and assigned customer-owned assets | Cannot read other users’ tickets or internal comments |

## Tickets and assets

- Laravel scopes every read and mutation; React navigation only controls presentation.
- Customer-user ticket ownership is independent of asset assignment. Removing an asset assignment does not transfer ticket ownership.
- A customer user may have zero or multiple assigned assets; a shared customer-owned asset may be assigned to multiple users. Customer-user asset reads and new ticket associations require assignment.
- Contractor Admin and the customer’s own Customer Admin manage assignments.
- Each asset belongs to one customer and one existing ticket category. Category is the sole user-facing classification; legacy type data is retained for compatibility.
- Technicians lose access when work is reassigned. Internal comments remain private from customer accounts.
- Customer accounts cannot manage contractor AMC contracts.

## Security

Sanctum cookies, CSRF protection, active-account checks, technical boundary middleware, and MFA policies apply on the server. MFA requirements depend on the installation, role, and customer policy; enrollment and recovery are per account. Do not infer permission from a hidden frontend button.

Legacy `service_manager` accounts are not automatically elevated. Review and explicitly assign an authorized role rather than applying a blanket upgrade. Initial technical administrator provisioning uses `php artisan amc:create-admin`; existing accounts and recovery require the appropriate guarded workflows.
