# Convertino

A separate WordPress plugin for one selected page: visits, named button clicks,
traffic sources and conversions. Data stays on the website's own WordPress server.

## Current version

The initial 0.1.0 build includes:

- Page visits and page views, using short first-party sessions.
- Named button selectors and click usage per source.
- UTM and referrer attribution, including recognized AI referrals.
- Successful Fluent Forms submission attribution.
- FluentCart paid live orders and net revenue after refunds.
- Basic FluentPlayer play and end events.
- Consent gating, administrator exclusion and 180-day retention.

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
```

PHP sources should also pass `php -l`. WordPress and public HTTP checks were
performed with disposable records on the development site; no test records are
included in this repository.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
