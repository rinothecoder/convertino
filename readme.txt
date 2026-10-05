=== Convertino ===
Contributors: rino
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

One selected page: visits, page views, selected button clicks, successful Fluent
Forms submissions, FluentPlayer play/end events and FluentCart live purchases.

== Setup ==
Open WordPress > Convertino. Select one published page, name buttons and supply
their CSS selectors. Select goal form/product IDs and enable tracking.
Integrate analytics consent before expecting measurements:
  window.ConvertinoConsent = {granted: true}; // before the tracker loads
  window.Convertino.setConsent(true); // after analytics consent
  window.Convertino.setConsent(false); // after withdrawal
Complianz's statistics category is recognized. Other banners need the adapter
above. Convertino does not display or replace a site's consent banner.

== Measurement ==
A visit is a random first-party session, expiring after 30 minutes without
tracked activity. There is no cross-device or persistent unique-person metric.
UTM source takes precedence over external referrer; unknown source is labeled
Direct / unknown. ChatGPT prompts are never available. Apps, blocked tracking,
consent refusal, stripped referrers and device changes create missing data.
Clicks count actual separate clicks; retries share IDs. Source conversion rates
are visits with at least one successful goal / measured visits, never orders /
people. All source outcomes belong to the landing-page session's source.

Fluent Forms: fluentform/submission_inserted; saved entry IDs deduplicate.
FluentCart: persist session plus product scope at checkout/prepare_other_data;
order_paid_done records paid live orders by order ID; cumulative refunds update
net revenue. Mixed carts, renewals, child orders and test payments are excluded.
Currencies are reported separately. Payment dates currently reflect receipt of
the completion hook; results are grouped by landing visits, not payment cohorts.
FluentPlayer: observed DOM media-player play and ended events under
fluent_player_* containers. No claim of full watched percentage. Adapter needs
testing against the installed player version; not a guaranteed public API.

== Storage and privacy ==
Three own tables: convertino_sessions, convertino_events, convertino_orders.
Own options and daily retention job. No Independent Analytics dependency or code
copied from it. No production migration is performed. Original Convert and its
fce_* data remain intact. No other plugin files/settings are changed.
First-party HttpOnly SameSite=Lax cookie. Consent-gated, DNT/GPC respected.
No raw IP, fingerprint, form content or customer personal details stored.
Referrer host and sanitized UTM source/medium/campaign only, never full URLs.
Do not put personal information in UTM values. Short-lived HMAC address counters
limit requests; hosting/server logs may independently contain addresses.
Consent withdrawal expires attribution; already collected results follow the
180-day retention period. Local storage alone does not settle legal obligations.

== Compatibility and limits ==
Browser tracking posts to uncached WordPress REST routes. Page configuration is
cacheable. Purge page caches after changing the tracked page/buttons. REST POST
responses must not be cached. Admins and common bot UAs are excluded, but bot
filtering and public-endpoint abuse prevention cannot be perfect.
Multiple tabs arriving simultaneously before the first cookie can split visits.
Client events cannot prove human behavior. Basic same-origin, signed page proof,
input validation and rate limits reduce accidental/unsophisticated abuse.
Consent adapter must propagate withdrawals on all pages; tracker loads across
the frontend for this reason. Cookie attribution applies only within this site
and the short session. No retroactive attribution when consent was absent.
Deactivation stops cleanup/tracking; it does not delete data. Uninstall does not
delete data. No A/B testing, page versions, automatic winners or migration yet.
