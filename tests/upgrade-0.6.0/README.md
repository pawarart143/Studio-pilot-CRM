# v0.5.0 → v0.6.0 upgrade checks

Run against a WordPress site served at http://127.0.0.1:8080 (with pretty permalinks).

1. Install the v0.5.0 plugin, create a studio (`wp pcrm create-studio`), seed it (`wp pcrm seed 1`), and set its plan to `enterprise` so team logins can be added. Optionally add a second studio on `free`.
2. `wp eval-file before.php` adds one login per role and saves a snapshot to `before.json`.
3. Replace the plugin with the v0.6.0 build and load any page (migrations run).
4. `wp eval-file after.php` (data kept, dashboards per role, business area closed to team roles, plans, read-only after trial, tenant isolation).
5. `wp eval-file features.php` (one check set per 0.6.0 feature).
6. `node browser-e2e.js` (Playwright: owner desktop, sales desktop, photographer and owner on a phone, homepage pricing).

`after.php` reads table names from `sqlite_master`; on MySQL replace that query with `SHOW TABLES LIKE 'wp_pcrm_%'`.
