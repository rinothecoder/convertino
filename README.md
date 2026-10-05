# Convertino

A separate WordPress plugin for one selected page: visits, named button clicks,
traffic sources and conversions. Data stays on the website's own WordPress server.

## Current version

The 0.1.1 build includes:

- Page visits and page views, using short first-party sessions.
- Named button selectors and click usage per source.
- UTM and referrer attribution, including recognized AI referrals.
- Successful Fluent Forms submission attribution.
- FluentCart paid live orders and net revenue after refunds.
- Basic FluentPlayer play and end events.
- Consent gating, administrator exclusion and 180-day retention.
- WordPress update notices, release details and checksum-verified update downloads.

Tracking and storage checks passed on the development site. Real Fluent Forms,
FluentCart and FluentPlayer workflows still need end-to-end testing with those
plugins installed. The player adapter uses observed DOM events and must be
verified against the installed version. No A/B testing or page-version comparisons
are included yet.

## Setup

Install the plugin, open **Convertino → Page setup**, select a published page,
name buttons with their CSS selectors, and enter the goal form/product IDs.
Enable tracking and connect the site's analytics consent signal. See
[readme.txt](readme.txt) for the consent API, attribution rules and limitations.

## Development

Development and testing happen only on `https://plugins.testingwithpixels.com`.
The original Convert plugin and analytics on LivingWithPrompts remain separate.
Convertino does not modify other plugins and does not depend on Independent
Analytics. Its own tables are `convertino_sessions`, `convertino_events` and
`convertino_orders`, with the site's WordPress table prefix.

Run the browser tracker checks with:

```sh
node tests/tracker.test.cjs
php tests/updates.test.php
```

PHP sources should also pass `php -l`. WordPress and public HTTP checks were
performed with disposable records on the development site; no test records are
included in this repository.

## Publishing updates

The source repository stays private. Client sites do not need GitHub accounts or
tokens. The updater reads a small HTTPS `update.json` and downloads a published
ZIP from the same publisher. For a GitHub publisher, ZIP URLs are restricted to
release assets in that exact repository. Development branch ZIPs and prerelease
version numbers are rejected. Updates use the WordPress Plugins screen and its
normal auto-update choice; the plugin never enables auto-updates itself.

The download location must be chosen before distribution. `MANIFEST_URL` in
`includes-updates.php` is currently empty, so no external release checks happen.
A site-specific publisher can also be supplied with the
`convertino_update_manifest_url` filter. An empty or unavailable publisher does
not interfere with analytics and never pretends an update succeeded.

To build a release, bump the plugin header, core version and readme stable tag,
run the tests, then run:

```sh
python3 scripts/build-release.py --output /path/to/release \
  --package-url https://YOUR-PUBLISHER/convertino-0.1.1.zip \
  --homepage https://YOUR-PUBLISHER/ --tested 7.1.2
```

Publish the generated ZIP and `update.json` only after validating the build on
the testing site. For GitHub, attach both to a stable release such as `v0.1.1`;
the updater's manifest URL should use `releases/latest/download/update.json`,
while the package URL must point to its specific version's ZIP. Never edit an
already published release ZIP; create a new version instead. The allowlist
builder excludes tests, scripts, local settings, credentials and analytics data.

Release metadata is cached for six hours, failed checks for fifteen minutes.
**Convertino → Plugin updates → Check for updates** refreshes it immediately.
Before installation, the updater checks the exact ZIP against the SHA256 in the
release WordPress offered; a later release cannot change that expected checksum.
This detects corrupted or mismatched downloads, not a compromised publisher.
WordPress handles installation and rollback. Analytics tables and settings are
not deleted or reset by the updater.

Checks contain no analytics records, site URL or account token. The publisher and
its CDN can see the requesting server IP and updater version. A private download
service requires its own access mechanism; this plugin does not embed a shared
GitHub credential or provide a license server.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
