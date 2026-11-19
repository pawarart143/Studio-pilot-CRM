# ShootPilot (WordPress plugin)

ShootPilot is a CRM for photography businesses. One WordPress site can host many studios, and each studio's data is kept separate.

Modules include:
- leads, pipeline and calling
- quotations (GST and PDF)
- bookings (events, team and double-booking checks)
- payments, Razorpay payment links and expenses
- calendar, team, tasks, clients and reports
- crew scheduling for freelancers (v0.5.0)
- public enquiry form that creates leads (v0.5.0)
- WhatsApp sharing and settings

Defaults are INR (₹), India, DD-MM-YYYY, Asia/Kolkata and +91.

## What's new in v0.6.0-quotation (Quotation generator)

Update with "Replace current with uploaded". Nothing is removed. Existing quotations keep their numbers, totals and links.

- **Step-by-step builder** (Quotations → New): 1. Client & events, 2. Services & packages, 3. Deliverables & payments, 4. Terms & review. Click any step at any time. The summary on the right updates the total, GST and every payment milestone as you type. Step 4 shows a live preview of exactly what the client will see.
- **Several events in one quotation:** each day has a name, type, date, time, venue, city, notes and the team you promise (traditional / candid photographer, traditional videographer, cinematographer, drone, assistant, with counts). "Copy to the next day" repeats a day. A quotation made from a lead gets one day per date of the lead.
- **Packages** (Settings → Quote packages): a package is a set of services with your rates, the deliverables and the team per day. One tap adds it to a quotation; every line stays editable. "Save as package" on the builder turns the current lines into a package. Three starter packages (Wedding – Essentials, Wedding – Signature, Pre-Wedding Shoot) are created from your own price list on update; they are skipped if your catalogue doesn't have matching services.
- **Lines are grouped** as Packages, Services and Add-ons. Units from your price list (day, album, set) are shown.
- **GST:** CGST + SGST for clients in your state or IGST for other states, per quotation, with a studio default. Flat or % discount as before.
- **Payment plan:** up to 6 milestones that must add up to 100% (quick splits 50/30/20, 50/50, 30/40/30…). Amounts follow the total.
- **Deliverables and "Not included"** lists with one-tap suggestions.
- **Set once in Settings → Quotes & invoices:** introduction, closing message, terms, default deliverables, "Not included", payment plan, GST type, quotation colour and whether to show the rate of each line.
- **Premium PDF:** brand band in your quotation colour with logo, event schedule with team, grouped investment table, GST split, amount in words, payment plan with amounts, deliverables, not included, terms, payment details, signature lines and an "Accepted online by …" stamp once accepted.
- **Client link** (`/crm/q/…`): redesigned page for phone and desktop. The client sees events, investment, payment plan and deliverables, downloads the PDF, asks on WhatsApp (when the studio phone is set), and **accepts by typing their name and ticking the terms**, or declines with a reason. Their message is shown on the quotation. Views are counted (staff who are signed in are not counted). **New client link** makes the old link stop working. Copying the link of a draft marks it Sent.
- **Timeline** on every quotation: created, edited (with old → new total), sent, opened by client, accepted / declined, client message, PDF downloaded by client, new link.
- **Convert to booking** now carries over every event day (date, time, venue, city, notes), the quoted services, and the payment plan as the booking's installments. Accepting still moves the lead to Negotiation, and creating the booking still marks the quotation Accepted.

### Database changes (v0.6.0-quotation)

Versioned on its own option `pcrm_quote_db_version` (= 1), separate from `pcrm_db_version`.

| Table | Change |
|---|---|
| `pcrm_quotations` | new columns: intro, closing, deliverables, exclusions, payment_schedule (JSON), gst_type, show_prices, package_id, view_count, last_viewed_at, accepted_name, client_note, response_ip_hash |
| `pcrm_quotation_items` | new columns: kind (package / service / addon), unit |
| `pcrm_quotation_events` | new: event days per quotation with coverage (team counts) |
| `pcrm_quote_packages`, `pcrm_quote_package_items` | new: reusable packages |

On update each existing quotation with a date or venue gets one event row from it. Every new table has `studio_id`, and every query is limited to the signed-in studio. Package edits need Settings rights (owner/admin); sales can use packages on quotations.

## What's new in v0.5.0 (Crew Scheduling and Public Enquiry Form)

Update with "Replace current with uploaded". Nothing is removed. The update adds new tables and a few new columns on leads (see "Database changes (v0.5.0)").

### Crew Scheduling (Operations → Crew Scheduling)

Crew are freelancers and outside people you hire for shoots. They live in their **own directory** and never use the Team members table, so adding crew does not use up team logins.

- **Crew directory:** name, phone, WhatsApp, email, photo, category (11 types: candid / traditional photographer, cinematographer, traditional videographer, drone operator, assistant photographer, lighting assistant, production assistant, photo editor, video editor, other), specialization, city, daily rate, availability, notes. Search, filter by category, availability and city. Remove keeps past assignments and payments.
- **Crew profile:** contact, stats, upcoming and past events, payments and a change log.
- **Schedule crew:** one schedule per event day. Pick a booking and its event to fill in the client, date, time, venue and city, or make a standalone event. Add any number of crew with role, service, rate and status. Transport, accommodation and special instructions are included. Use **Schedule another day** to put different crew on another day of the same booking.
- **Calendar:** Month, Week, Day and List views, with filters for date range, event search, crew member, role, city and status.
- **Clash check:** while you fill the form, ShootPilot warns if someone is already booked at overlapping times (reporting time counts, overnight events are handled, declined / reassigned / cancelled are ignored) and shows the other event. Saving asks you to confirm, with an optional reason that is stored on the assignment.
- **Statuses:** Scheduled, Confirmation Pending, Confirmed, Declined, Reassigned, Completed, Cancelled. **Reassign** marks the old person Reassigned and adds the new one with the same role.
- **WhatsApp:** every assignment has a WhatsApp button with the event details already typed.
- **Crew payments:** agreed rate, advance, payments, remaining, last payment date and status (Pending / Partially Paid / Paid). A record is created automatically when an assignment has a rate. Crew payments are **separate from client payments** and appear as business expenses in **Reports** (Expenses tile shows "incl. crew", and the CSV has a "Crew payments" column).
- **Who sees what:** owner and admin see everything. Production manager can view and schedule crew but never sees rates or payments. Sales, photographers and other roles don't see Crew Scheduling.

### Public enquiry form (`/enquire/your-studio`)

- A 4-step, mobile-first form: client details, event details, services, budget and a final step. Fields show and hide by event type (for example "Bride & groom names" only for weddings). Multi-day events get one date per day. Budget has ranges plus a custom amount. Double submits are blocked.
- **Every submission creates a lead** in your existing Leads table with source **Website / Enquiry Form**, status New Lead and your lead assignment rule. Name, phone, WhatsApp, email, event type, dates, city, venue, budget, services and UTM tags are mapped; all other answers go into the lead notes. The lead shows in Leads, Lead detail, Pipeline, Dashboard and source reports.
- **Duplicates:** if an open lead already has the same phone number in your studio, nothing is overwritten. The enquiry is flagged "Possible duplicate" and Leads shows a **Review** banner. You choose: add to the existing lead (only empty fields are filled), create a separate lead, or dismiss.
- **Settings → Enquiry form:** turn on/off, copy link, preview, share on WhatsApp, title, description, logo, brand colour (default #F3724C), form address, consent text, thank-you headline and message, WhatsApp number for the thank-you button; turn questions on/off, make them required, rename, reorder, limit to event types, add up to 25 custom questions; lead assignment (unassigned, one person, or round robin); Cloudflare Turnstile keys; submission history.
- **Lead detail** gets an "Enquiry" card: services, submission date, form link, UTM tags and **View original enquiry**. The Leads list shows an "Enquiry" badge.
- **Spam protection:** hidden bot trap, signed form token (too fast or older than 24 hours is rejected), limits per device (5 per 10 minutes, 20 per day), per phone (3 per day) and per form (200 per hour). Optional Cloudflare Turnstile. IP addresses are stored only as a salted hash.
- **New enquiry email:** sent to the studio email using WordPress `wp_mail` (needs working email on the server).

### Database changes (v0.5.0, DB version 5)

| Spec name | Table |
|---|---|
| crew_members | `pcrm_crew_members` |
| crew_schedules | `pcrm_crew_schedules` |
| crew_schedule_assignments | `pcrm_crew_schedule_assignments` |
| crew_expenses | `pcrm_crew_expenses` and `pcrm_crew_expense_payments` (each advance or payment) |
| public_enquiry_forms | `pcrm_public_enquiry_forms` (one per studio, created on update) |
| enquiry_form_fields | `pcrm_enquiry_form_fields` |
| enquiry_submissions | `pcrm_enquiry_submissions` |
| audit logs | `pcrm_audit_logs` |

`pcrm_leads` gets `services`, `enquiry_submission_id`, `form_url`, `utm_source`, `utm_medium` and `utm_campaign`. Every new table has `studio_id`, and every query is limited to the signed-in studio.

## What's new in v0.4.1 (client portal PIN fix)

**Problem:** clients saw "Your portal is being set up… will share your PIN shortly" and could not get in.

**What was wrong:**
1. **Hosting page cache.** The portal page did not tell page caches (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, SG Optimizer, Nginx/Cloudflare) to skip it. A cache could save the "being set up" page the first time the link was opened and keep showing it, even after a PIN was created.
2. **The portal looked "On" before it had a PIN**, the PIN controls sat at the side of the "Events & team" tab, and a PIN was shown only once. So it was easy to share the link before any PIN existed, and hard to find the PIN again.

**What changed:**
- Portal, CRM app, quotation links and CRM API now send "do not cache" signals to every common cache, and the portal's cached copy is purged whenever its PIN, status or link changes.
- **Portal status:** *Not activated* (no PIN yet) → *Active* (after Generate PIN) → *Disabled* (turned off). The client sees "Portal not activated yet" until a PIN exists. You can't turn a portal on without a PIN.
- The **Client portal panel is now at the top of every booking**, with Generate PIN, View PIN, Copy link, Copy link + PIN, Regenerate PIN, Change PIN, Disable/Enable, Share on WhatsApp and New link.
- **Settings → Client portal** lists every booking with its portal status, search and a status filter, and Generate PIN / Manage for each.
- **View PIN again (owners and admins only).** Besides the hash used to check the PIN, the PIN is kept encrypted (libsodium secretbox, key derived from your site's `SECURE_AUTH` keys in wp-config.php). It is never stored as plain text, and every view is written to the booking's activity log. PINs made before v0.4.1 can't be shown; click Regenerate PIN once to get a viewable one. If you change the security keys in wp-config.php, PINs can't be shown any more (they still work); regenerate to view.
- The "Client portal link + PIN" WhatsApp message now fills in the current PIN automatically for owners and admins.
- A wrong PIN shows "Incorrect PIN. Please try again." with attempts left. Lock-out rules are unchanged.
- After a correct PIN the page reloads on the same address the client used (www or not), so the unlock is never lost.
- The PIN save is checked against the database before it is shown, so you never see a PIN that wouldn't work.

**After updating:** purge your cache plugin once (for example LiteSpeed Cache → Purge All), so any old "being set up" pages are cleared.

**Database changes (v0.4.1, DB version 4):** `pcrm_client_portals` gets `pin_encrypted` and `activated_at`. Portals that already had a PIN are marked activated. Nothing is removed.

## What's new in v0.4.0

Update with "Replace current with uploaded". Existing data is kept: the update adds columns and new tables, and fills in sensible values (see "Database changes" below).

- **Payments (admin only edits).** Owners and admins can add, edit and delete payment records from Payments → Payment records or from a booking. Each record has client, booking, package total, amount, date, method, transaction ID, type (Advance / Installment / Final), status (Received / Pending / Failed / Refunded), notes, created by and last modified by. Deleting asks for confirmation. Every create, edit (with old → new values) and delete is written to the **Payment activity log** (Payments → Activity log, or the history icon on a payment). Other roles get view-only access where their role can see money; the server refuses edits from them (HTTP 403), not just the buttons. Only *Received* payments count toward paid totals, dues, reports and receipts.
- **Team directory.** Photo, phone, email, team role (Lead Photographer, Candid, Traditional, Cinematographer, Traditional Videographer, Drone Operator, Photo Editor, Video Editor, Album Designer, Assistant Photographer, Production Manager), specialization, city, availability, joining date and internal notes. Search, filter by role and availability, deactivate/activate, and a profile page with upcoming assignments, assigned bookings and deliverables. Internal notes are hidden from users without team edit rights.
- **Services and team on bookings.** A booking can have many services (from your price list or custom), each with many team members. On-site services block the member on every event date of the booking, so the calendar, availability grid and double-booking check include them. Post-production services (toggle "On-site" off) don't block shoot dates. A clash shows a warning; only an admin or production manager can confirm and save anyway, and the override is logged.
- **Deliverable templates.** "Wedding Package" and "Pre-Wedding" are added for every studio. Pick one when creating a booking, edit the list, then save. Expected dates count from the last event date. Manage templates in Settings → Deliverable templates; apply one later from the booking's Deliverables tab.
- **Deliverables.** Name, description, assignee, expected date, status (Not Started, In Progress, Editing, Awaiting Client Selection, Under Review, Ready for Delivery, Delivered, Revision Requested), progress %, delivery link, admin notes and last updated. A Deliverables page lists everything across bookings with an Overdue filter. Assigned editors/photographers can update status, progress and link on their own items only.
- **Client portal.** Every confirmed booking gets a private page at `https://your-site.com/portal/<random token>`. No client account is needed. It shows studio name and logo, client name, booking number, event dates and venues, booking status, overall delivery progress, each deliverable (name, status, progress, expected date, link, last updated). It never shows notes, payments, team or internal IDs.
  - **PIN protection** from the booking page: generate, set a custom PIN, change, reset, turn on/off, copy link, share on WhatsApp, and "New link" (old link stops working). A new PIN is shown **only once**, because only a hash is stored.
  - **Security:** 192-bit random token; PIN hashed with WordPress password hashing; 5 wrong PINs lock the portal for 15 minutes, doubling up to 24 hours; at most 20 wrong PINs per IP address per hour; the unlock cookie is signed and HttpOnly and stops working when the PIN changes; the page sends `noindex`, no-referrer and anti-framing headers.
  - If a WordPress page uses the slug `portal`, it will clash with the portal URL. Rename that page.
- **WhatsApp templates.** New templates: client portal link, PIN, delivery progress update and deliverable completed. Like all WhatsApp buttons without the Cloud API, they open WhatsApp with the message typed, and **you press send**. Nothing is sent automatically.
- **Navigation:** Dashboard, Leads, Bookings, Payments, Deliverables, Team, Calendar, Reports, Settings (each shown only to roles that can use it).

### Database changes (v0.4.0)

| Requested name | Table in this plugin | Notes |
|---|---|---|
| team_members | `pcrm_members` (extended) | Existing team table, so logins and past assignments keep working. New columns: photo_url, team_role, specialization, city, availability, joining_date, notes. |
| payment_transactions | `pcrm_payments` (extended) | Existing payments table, so receipts keep their numbers. New columns: payment_type, status, updated_by, updated_at. Old payments become *Received*; each booking's first payment becomes *Advance* and the one that cleared the total becomes *Final*. |
| payment_activity_logs | `pcrm_payment_activity_logs` | New |
| booking_services | `pcrm_booking_services` | New |
| service_team_assignments | `pcrm_service_team_assignments` | New |
| deliverable_templates | `pcrm_deliverable_templates` + `pcrm_deliverable_template_items` | New |
| booking_deliverables | `pcrm_booking_deliverables` | New |
| client_portals | `pcrm_client_portals` | New (token, PIN hash, lock-out counters, views) |

Every table has `studio_id`, and every query is scoped to the signed-in user's studio. Permissions are checked on the server for each request (`includes/class-permissions.php`).

## Marketing homepage (v0.3.0)

ShootPilot includes a public marketing homepage, served by the plugin outside your theme.

- It shows at your **site homepage** (`https://your-site.com/`) by default. To use your theme's homepage instead, go to wp-admin → **ShootPilot** → "Marketing homepage" and untick "Use as site homepage".
- It is always available at `https://your-site.com/shootpilot/`.
- All buttons link to the real CRM: **Start Free / Get Started** → `/crm/register`, **Login** → `/crm/login`. Signed-in users see **Open dashboard** → `/crm/`.
- On the same wp-admin page you can set the Contact email, About and Terms URLs, and Instagram, Facebook and YouTube links. Footer links without a URL show "Soon". Privacy Policy uses Settings → Privacy.
- **Pricing is placeholder.** The amounts come from `includes/class-plans.php` (Starter, Professional, and Enterprise shown as "Business"). Yearly = 10 × monthly. Change them there, or with the `pcrm_landing_pricing` filter.
- **Testimonials are placeholders**, clearly labelled on the page. Replace them in `includes/landing/sections/testimonials.php` or with the `pcrm_landing_testimonials` filter, only with real quotes you have permission to use.
- Files: `includes/class-landing.php`, `includes/landing/` (one partial per section, product previews in `mock/`), `assets/landing/landing.css` and `landing.js`. No build step.

## Requirements

- WordPress 6.2 or newer
- PHP 7.4 or newer (tested on PHP 8.3 with MySQL/MariaDB)
- **Pretty permalinks.** Go to Settings → Permalinks and choose "Post name". The CRM lives at `/crm/`. If permalinks are plain, the plugin shows an error notice in wp-admin.
- Working outgoing email (an SMTP plugin is recommended) for password reset and team invites

## Install or update

1. **New install:** go to Plugins → Add New → Upload Plugin, choose `photographer-crm.zip`, then Install and Activate.
2. **Update:** upload the new zip the same way and choose "Replace current with uploaded". Your data stays, and new tables are added automatically.
3. Open `https://your-site.com/crm/register` and create the first studio. You become its owner.
4. Optionally, load sample data from Settings → Data → "Load sample data" inside the CRM.

## Who is who

- **Studio owner / admin**: full access to their own studio.
- **Sales, production manager, photographer, cinematographer, editor, album designer**: limited access by role. Creative roles never see money or lead details.
- **Super admin (platform owner)**: only the WordPress administrator whose email matches Settings → General → "Administration Email Address". Other WordPress administrators and all studio users do not see Super Admin.
  - To choose a different person, run `wp pcrm super-admin <email>`. The user must be a WordPress administrator.
  - The wp-admin "ShootPilot" page shows who the super admin is.
  - The super admin can list all studios, change plans, expiry and status, suspend a studio, open a studio as support, and set up Google sign-in.

## Google sign-in

1. Sign in as super admin and open **Super Admin** in the CRM. The "Google sign-in" card shows the exact URLs to copy.
2. In [Google Cloud → Credentials](https://console.cloud.google.com/apis/credentials):
   - Set up the OAuth consent screen (External) and publish it.
   - Create an **OAuth client ID** of type **Web application**.
   - Add the **Authorised JavaScript origin** (your site address).
   - Add the **Authorised redirect URI**: `https://your-site.com/?pcrm_google=callback`.
3. Paste the Client ID and Client secret into the card and save. A "Continue with Google" button then appears on the login and sign-up pages.

A Google account whose email already exists signs in to that account. A new email gets a new account and is asked for a studio name.

## Razorpay (online payments from clients)

Each studio connects **its own** Razorpay account, so money goes straight to the studio.

1. In the Razorpay Dashboard, go to Account & Settings → **API Keys** and generate a key.
2. In ShootPilot, go to Settings → **Online payments** and paste the Key ID and Key Secret. Choose a webhook secret (any strong password) and save.
3. In Razorpay, go to Account & Settings → **Webhooks** → Add:
   - **URL:** shown on the Online payments page (`https://your-site.com/wp-json/pcrm/v1/razorpay/webhook/<studio id>`)
   - **Secret:** the same webhook secret as in ShootPilot
   - **Events:** `payment_link.paid`, `payment_link.cancelled` and `payment_link.expired`
4. To collect a payment, open a booking → Payments → **Payment link**. Then copy the link or share it on WhatsApp.
5. When the client pays, the webhook records the payment and receipt on the booking automatically. "Check status" does the same without a webhook.

Test Mode keys (`rzp_test_…`) work the same way, so you can try it before going live.

## WhatsApp

- **Without any setup:** every WhatsApp button opens WhatsApp (phone or WhatsApp Web) with the message already typed. You press send. This works on every plan.
- **WhatsApp Cloud API (Professional and Enterprise plans):** ShootPilot can send directly. Settings → WhatsApp → "How to get these from Meta" lists the steps:
  1. Create a Business app on developers.facebook.com and add the WhatsApp product.
  2. Add and verify a business number. This number can't stay on the normal WhatsApp app.
  3. Copy the Phone Number ID.
  4. Create a System User token (permanent) with `whatsapp_business_messaging` and `whatsapp_business_management`.
  5. Add a payment method in WhatsApp Manager.
  6. Paste the Phone Number ID and token in ShootPilot.

  Meta only delivers free-form messages to people who messaged you in the last 24 hours. Template messages are not managed by ShootPilot yet.

## Integration status (honest)

| Feature | Status |
|---|---|
| Quotation and receipt PDFs (₹, logo) | Working, tested |
| Quotation generator (v0.6.0) | Working. Tested by API (59 checks) and in the browser: create from a lead, package, extra event day, payment plan, edit, PDF, copy link, client accept and decline on a phone, convert to booking with events, services and installments, two studios kept apart, role limits, upgrade from v0.5.0 with data unchanged |
| WhatsApp share links with templates | Working, tested |
| Client portal with PIN (v0.4.1) | Working. Tested on a fresh install and an install upgraded from v0.3.0: generate, view, verify, wrong PIN, refresh, second device, regenerate, disable, and two studios kept apart |
| Crew Scheduling (v0.5.0) | Working. Tested by API (59 checks) and in the browser on a fresh install and an upgraded install, including clashes, override, reassign, payments, Reports and role limits |
| Public enquiry form (v0.5.0) | Working. Tested by API (58 checks) and on a phone-size browser: steps, show/hide, multi-day, lead creation, UTM, duplicate review and merge, rate limits, two studios kept apart |
| Cloudflare Turnstile on the enquiry form | Built, **not tested with real Turnstile keys** |
| New-enquiry email | Uses `wp_mail`; only sent if the studio has an email and the server can send email |
| WhatsApp Cloud API sending | Built, **not tested against Meta** (no credentials yet) |
| Razorpay payment links and webhook | Built and tested against simulated Razorpay responses, including webhook signature checks and duplicate webhooks. **Not yet tested with real Razorpay keys.** Try it with Test Mode keys first. |
| Google sign-in | Built and tested against simulated Google responses. **Not yet tested with a real Google client ID.** |
| Plan billing for studios | Not included. The super admin sets plans by hand. |
| Email (reset, invites) | Uses WordPress `wp_mail`, so it needs working email on the server |

## Time zone

Dates such as "today" use the studio's time zone (Asia/Kolkata by default), even if WordPress itself is set to UTC.

## Plans

Plans are Free, Starter, Professional and Enterprise. Their limits are in `includes/class-plans.php`:
- team logins
- leads per month
- WhatsApp Cloud API

Crew members without a login are unlimited. When a plan expires, the studio falls back to Free limits.

## WP-CLI

```
wp pcrm create-studio <user> "<Studio name>"
wp pcrm seed <studio_id>
wp pcrm super-admin <user-email>
```

## Data and uninstall

Deactivating or deleting the plugin **keeps** all data. To remove it, drop the `{prefix}pcrm_*` tables manually.

## Building from source

```
cd app && npm install && npm run build
```

The PHP dependency (Dompdf) is in `vendor/`. To rebuild it, run `composer install --no-dev`.
