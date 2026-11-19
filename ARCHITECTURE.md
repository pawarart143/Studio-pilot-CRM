# Photographer CRM — Architecture (WordPress edition)

Multi-studio SaaS CRM for photographers, delivered as a single WordPress plugin.

## 1. High-level design

```
Browser (React SPA at /crm)  ──REST (cookie + nonce)──►  WordPress
   React 18 + TypeScript                                  └─ plugin: photographer-crm
   Tailwind CSS (design tokens, light/dark)                    ├─ REST API  /wp-json/pcrm/v1/*
   React Query (server state)                                  ├─ Tenant context (studio_id per request)
   Recharts, dnd-kit (Kanban)                                  ├─ Role/permission matrix
                                                               ├─ Custom tables  {prefix}pcrm_*
                                                               ├─ PDF (Dompdf)   quotations, receipts
                                                               └─ WhatsApp (click-to-chat + Cloud API slot)
```

* **One plugin, any host.** Upload the zip, activate, visit `/crm`. The compiled React app ships inside the plugin, so no Node.js is needed on the server.
* **App shell outside the theme.** `/crm/*` is a rewrite rule that prints a bare HTML shell (no theme CSS), so the CRM looks like a standalone SaaS product.
* **Server is the source of truth.** Every list, filter, search, chart and action calls the REST API; nothing is kept only in the browser.

## 2. Multi-tenancy (studios)

* `pcrm_studios` is the tenant table. Every business table has a `studio_id` column and an index on it.
* `pcrm_members` links a WordPress user to a studio with a CRM role. One user may belong to several studios (studio switcher). Team members without a login (e.g. freelance second shooters) are members with `user_id = NULL`.
* Each REST request resolves the **current member** (logged-in user + current studio stored in user meta, verified against `pcrm_members`). All queries go through `PCRM\Db` helpers that always add `studio_id = %d`. A record id from another studio returns 404.
* Studio status `suspended` blocks all API access for that studio (super admin control).
* Plans: Free, Starter, Professional, Enterprise with limits (team members, leads per month). Limits are enforced server-side on create.

## 3. Roles & permissions

| Permission            | Owner/Admin | Sales | Production Mgr | Photographer / Cinematographer | Editor / Album Designer |
|-----------------------|:-:|:-:|:-:|:-:|:-:|
| Leads, pipeline, calls | ✔ | ✔ | view | – | – |
| Quotations             | ✔ | ✔ | view | – | – |
| Clients                | ✔ | ✔ | ✔ | view | view |
| Bookings               | ✔ | create/edit | ✔ | view (assigned) | view (assigned) |
| Team assignment        | ✔ | – | ✔ | – | – |
| Payments & expenses    | ✔ (add/edit/delete: owner & admin only) | view | – | – | – |
| Deliverables           | ✔ | view all | ✔ | update own | update own |
| Client portal & PIN    | ✔ | – | – | – | – |
| Tasks                  | ✔ all | own + create | ✔ all | own | own |
| Reports                | ✔ | own performance | ✔ | – | – |
| Team & settings        | ✔ | – | – | – | – |

The matrix lives in `includes/class-permissions.php` and is sent to the SPA so menus hide what a role cannot use. The server re-checks every request.

**Super admin** = WordPress administrator (`manage_options`). Gets `/crm/admin`: all studios, plans, suspend/activate, usage.

## 4. Database (custom tables, InnoDB, utf8mb4)

All tables are prefixed `{wp_prefix}pcrm_`. Money is `DECIMAL(12,2)` in INR. Dates are stored in site time (Asia/Kolkata by default) as `DATE` / `DATETIME`.

| Table | Purpose | Key relations |
|---|---|---|
| studios | Tenant: business profile, GSTIN, plan, settings JSON | owner_user_id → wp_users |
| members | Team member + CRM role + directory fields (photo, team_role, specialization, city, availability, joining_date, notes). Plays the role of `team_members`. | studio_id, user_id → wp_users (nullable) |
| clients | Client profile | studio_id |
| leads | Enquiries; stage, call status, priority, budget, source | client_id, assigned_to → members |
| activities | Timeline for every entity (created, stage change, call, note, payment…) | lead_id, client_id, booking_id |
| calls | Call log with status, notes, next call date | lead_id, member_id |
| followups | Scheduled follow-ups with reminders | lead_id, assigned_to |
| services | Studio service catalogue (photo, cinema, pre-wedding, album, drone, reels, other) | studio_id |
| quotations | Quotation header: discount, GST, totals, status, public token | lead_id, client_id |
| quotation_items | Line items | quotation_id, service_id |
| bookings | Confirmed project + delivery status | client_id, lead_id, quotation_id |
| booking_events | Multiple event dates / venues per booking | booking_id |
| event_assignments | Team member assigned to an event (double-booking check) | booking_event_id, member_id |
| installments | Payment schedule (Advance, 2nd, Final) with due dates | booking_id |
| payments | Payment records (method, transaction id, receipt no., payment_type, status, created_by, updated_by). Plays the role of `payment_transactions`. Only status=received counts as paid. | booking_id, installment_id |
| payment_activity_logs | Audit trail of payment create/update/delete with field changes, user and IP | payment_id, booking_id |
| booking_services | Services on a booking (catalogue or custom), on_site flag | booking_id, service_id |
| service_team_assignments | Team members per booking service | booking_service_id, booking_id, member_id |
| deliverable_templates / deliverable_template_items | Reusable deliverable lists with days after last event | template_id |
| booking_deliverables | Deliverables per booking: status, progress, expected date, link, admin notes | booking_id, assigned_to → members |
| client_portals | One per booking: random token, PIN hash, pin_version, enabled flag, lock-out counters, views | booking_id |
| expenses | Studio and per-booking expenses | booking_id (nullable) |
| tasks | Tasks with priority, deadline, assignee, status | assigned_to, related entity |
| documents | Files (WordPress media) attached to clients/bookings | client_id, booking_id |
| wa_templates | WhatsApp message templates with {{placeholders}} | studio_id |
| notifications | Task / follow-up reminders: event, due time, email status, alerted / read (v0.6.0, `PCRM\\Reminders`, cron `pcrm_reminders_tick` every 5 min) | member_id, item_id |

Referential integrity is enforced in the service layer (WordPress' dbDelta does not manage foreign keys); deletes cascade in code inside a transaction.

## 5. Customer journey mapping

New Enquiry → First Call → Interested → Follow-up → Quotation Sent → Negotiation → **Booking Confirmed** (lead → client + booking) → Advance Payment → Event Execution → Editing → Album Design → Delivery → Completed.

* Lead `stage` drives the Kanban pipeline.
* Accepting a quotation or clicking **Convert to booking** creates/links the client, creates the booking with its events, and an installment plan.
* Booking `status` (confirmed → shoot_done → editing → album_design → delivered → completed) drives Project & Delivery tracking.

## 6. Folder structure

```
photographer-crm/
├─ photographer-crm.php          Plugin bootstrap
├─ uninstall.php                 Drops tables only if the admin opted in
├─ includes/
│  ├─ class-plugin.php           Wiring, hooks, rewrite for /crm
│  ├─ class-installer.php        Schema (dbDelta), versioned migrations
│  ├─ class-db.php               Tenant-scoped query helpers
│  ├─ class-tenant.php           Current user / studio / member resolution
│  ├─ class-permissions.php      Role → permission matrix
│  ├─ class-plans.php            Subscription plans and limits
│  ├─ class-validator.php        Input validation and sanitising
│  ├─ class-pdf.php              Dompdf rendering
│  ├─ class-whatsapp.php         Templates, wa.me links, Cloud API sender
│  ├─ class-seeder.php           Default catalogue + optional sample data
│  ├─ class-app-shell.php        Prints the SPA shell
│  ├─ rest/                      One controller per module
│  └─ templates/                 PDF / public quotation templates
├─ app/                          React + TypeScript source (Vite)
│  └─ src/{components,lib,modules,pages}
├─ assets/app/                   Compiled SPA (shipped)
└─ vendor/                       Composer deps (dompdf)
```

## 7. Localisation defaults

INR (₹, Indian digit grouping 1,00,000), Asia/Kolkata, DD-MM-YYYY, +91, GST 18% default. All UI strings go through one `t()` helper so translations can be added later.

## 8. Integrations — honest status

* **WhatsApp:** click-to-chat (`wa.me`) with filled templates works without any account. WhatsApp Cloud API sending is implemented behind settings (phone number ID + access token) and is marked *untested* until real credentials are supplied.
* **Payments gateway (subscriptions):** plans are assigned by the super admin. Razorpay checkout is a planned phase, not faked.
* **Email:** uses `wp_mail`; the host must have working mail/SMTP for password reset emails.
* **Google Calendar (v0.6.0-gcal):** one-way push of bookings (per event date), tasks, follow-ups and payment due dates to each studio's own Google Calendar. `includes/class-gcal.php` (OAuth, encrypted tokens, queue worker with retries, WP-Cron triggers), `includes/class-gcal-events.php` (builds events from CRM tables), `includes/rest/class-gcal.php` (Settings API). Tables `gcal_accounts`, `gcal_links`, `gcal_queue` with their own schema version. Tested against a stand-in Google server, not yet against real Google.
