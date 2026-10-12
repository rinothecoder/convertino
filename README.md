# Convertino

A separate WordPress plugin for one selected page: visits, named button clicks,
traffic sources and conversions. Data stays on the website's own WordPress server.

## Current version

The 0.1.3 build includes:

- Page visits and page views, using short first-party sessions.
- Named button selectors and click usage per source.
- UTM and referrer attribution, including recognized AI referrals.
- Successful Fluent Forms submission attribution.
- FluentCart paid live orders and net revenue after refunds.
- Basic FluentPlayer play and end events.
- Consent gating, administrator exclusion and 180-day retention.
- Direct public GitHub release updates without a site token.

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

## GitHub updates

Convertino uses published stable releases in this public repository. No GitHub
token, Adminino connection, or wp-config.php change is needed. Existing site
tokens are ignored by this updater.

Saving commits does not update websites. Publishing a release makes it available
in WordPress, where the site's normal auto-update choice controls installation.
The plugin never enables automatic updates itself.

For a release, bump the plugin header, core version and readme stable tag to the
same version, test on `https://plugins.testingwithpixels.com`, commit the tested
source, then publish a GitHub Release with a matching tag such as **v0.1.3**.
Drafts and prereleases are excluded. The release tag must contain the plugin
files at the repository root. Do not move existing release tags; publish a new
version instead. GitHub provides the archive itself, so no ZIP upload or separate
hosting is required. The repository contains no analytics, settings or secrets.

For the first installation or a manual upgrade, build the installable ZIP with:

```sh
python3 scripts/build-release.py --output /path/to/release
```

The ZIP builder includes only runtime files and the license, and writes a SHA256
file for manual verification. Direct GitHub updates download the published tag's
source archive, pinned to the commit offered by WordPress. The updater checks
plugin/core version and required files and keeps the `convertino` folder name.
It does not claim a separate publisher signature or manifest checksum.

Checks are cached for six hours, failures for fifteen minutes. Use
**Convertino → Plugin updates → Check for updates** to refresh immediately.
Unavailable repositories and releases are shown
clearly and do not interrupt analytics. Settings and tables are never deleted
or reset by the updater. WordPress handles installation and rollback.

Public GitHub API and archive requests send no site cookies or credentials.
The temporary archive redirect is checked before downloading. Update metadata
contains no credentials or signed download URLs. Checks send no analytics
records or site URL; GitHub sees the server IP and updater version. Other
plugins' update and download requests are left unchanged.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
