# Licence Trial Architecture (self-service extension trial)

> Release **3.2.2**. Client contract: `workReadmeFiles/CLIENT-INTEGRATION-TRIAL.md` — **authoritative**,
> and newer than the server reference `_things/licence-server-api/LICENCE-SERVER-API-DOCUMENTATION.md`
> §531-760, which predates the e-mail confirmation flow. Prior design study:
> `workReadmeFiles/licence-trial-self-service-analysis.md`.

Lets an administrator request a 30-day evaluation licence for the browser extension from
**Settings → API → Licence**, instead of writing to the maintainer who ran
`_things/licence-server-api/register-licence.sh` by hand.

## Component map

| Concern | File |
|---|---|
| Decisions (DB-free, unit-tested) | `app/sources/licence_trial_logic.php` |
| HTTP, RSA verification, `teampass_misc` state | `app/sources/licence.functions.php` (plain functions file — **no `public/sources/` shim**) |
| Handlers | `app/sources/admin.queries.php` → `get_licence_panel`, `refresh_licence_status`, `request_licence_trial`, and the rewritten `get_extension_licence_info` |
| Tab markup | `app/pages/api.php` (`#licence` pane) |
| Rendering + throttled buttons | `app/pages/api.js.php` |
| Dashboard widget | `app/pages/admin.js.php` → `loadExtensionLicenceInfo()` |
| Settings | `public/install/install-steps/run.step5.php`, `public/install/upgrade_run_3.2.2.php` |
| Tests | `tests/Unit/LicenceTrialLogicTest.php`, `tests/Unit/LicenceSignatureTest.php` |

## The three routes

| Route | Used for | Signature |
|---|---|---|
| `GET /` | discovery: is the server up, are trials open, `trial_days`, `requires_email_confirmation`, `security.rsa_public_key_fingerprint` | **none sent** (verified 2026-08-22 against the live server) — it only decides whether a button is shown |
| `POST /api/v1.2/trial.php` | the request itself | **strict** |
| `POST /api/v1.2/info.php` | "do I have a licence?" — the only way to learn the confirmation happened | **strict** |

There is deliberately no "is my request confirmed?" endpoint. The only question that matters is
whether a licence exists, and `info.php` answers it without consuming a seat.

**Rule: the caller is the TeamPass server, not the extension and not the browser.** The answers are
RSA-signed and the verification must run on the **raw body**; re-encoding a decoded payload changes
the bytes and breaks every valid answer.

**Rule: a body whose signature does not verify is discarded, whatever it says.** Two exceptions, both
about not crying wolf:

- an HTTP 5xx with a bad or missing signature is reported as *unreachable*, not as tampering — a
  proxy incident page is not an attack (`licenceTrialClassifyResponse()`);
- when `GET /` advertises a `rsa_public_key_fingerprint` that differs from the embedded key, the
  panel says the server **rotated its signing key and this TeamPass is too old**, instead of
  reporting every answer as tampered (`licenceServerKeyFingerprintMatches()`). An unadvertised
  fingerprint is not a mismatch: not knowing is not knowing it is wrong.

The embedded key is RSA-4096, SHA-256 of its DER form
`ac1c599e654c67770e193d0708a3461a46d0f71481f9f2ab8738c2723b7e9256` — pinned by
`LicenceSignatureTest::testEmbeddedKeyFingerprintIsThePublishedOne()` and confirmed equal to what
the production server publishes.

## Settings (`teampass_misc`, type `admin`)

| Key | Role |
|---|---|
| `licence_trial_state` | JSON keyed by product: `state`, `status`, `contact_email`, `fqdn`, `pending_since`, `link_expires_at`, `resend_allowed_at`, `requested_at`, `last_error`. Redacted from bug reports (it carries an address) |
| `licence_server_discovery` | JSON cache of `GET /` — `{fetched_at, reachable, payload}`, 6 h when reachable, 10 min otherwise |
| `licence_info_budget` | JSON `{window_start, count}` — the shared hourly counter |
| `licence_server_base_url` | Staging escape hatch, **not exposed in the interface**; only HTTPS or a loopback address is accepted |
| `extension_licence_cache` / `_at` | Pre-existing keys, reused; the payload shape changed (it now stores the normalised two-product info) |
| `proxy_ip` / `proxy_port` | Seeded since forever, consumed by **nothing** before this feature; now fed to `CURLOPT_PROXY` |

No new table ⇒ **no `install.js` `checkNN` entry**.

## The rate budget

**Rule: never poll in the background.** No cron hook, no scheduler entry. The contract allows 10
`info.php` calls per hour on this bucket and asks for 6.

One shared counter covers every caller — the dashboard widget, the Licence tab and the manual
button — because they all hit the same bucket. A cache hit never consumes it, and neither does a
request that never reached the server: the licence server counts what it receives, and the offline
interval (`LICENCE_INFO_TTL_OFFLINE`, 10 min) is what throttles those. Automatic refresh is every
**15 min while a confirmation is pending** (≤ 4/hour, leaving room for manual checks) and 60 min
otherwise. When the budget is spent the stale cache is served with `budget_exhausted` and a
`retry_after`, and **no request is made**.

## What must never be sent

**Rule: validate the identity before the POST.** A trial is granted once per `(FQDN, product)`,
**forever** — the registry survives the deletion of the licence, so only support can reopen it.
`browser_extension_fqdn` legitimately holds `localhost` or a subfolder name on local installs
(`getDomainFromSettingsUrl()` returns the first path segment there), and sending that spends the
only trial the instance will ever get. `licenceTrialIsValidFqdn()` requires at least two LDH labels
and rejects bare IPs; the handler refuses before any network call, and the button is not rendered.

**Rule: the extension key must never change once a licence is registered.** The licence server has
no update route, so a regenerated key silently desynchronises even a *paid* licence. Commit
`4d59c5762` already hides the generate button when a key exists.

**Rule: only the five documented fields are sent.** `trial.php` rejects an unknown field with `422`
rather than ignoring it.

## Two facts the interface must state

They are the top support drivers, per the contract:

1. **`202 CONFIRMATION_SENT` is a success.** A client treating "anything but 201" as a failure
   reports an outage while everything went well.
2. **A resent link invalidates the previous one.** Administrators click the first e-mail and see
   "invalid link". The pending panel says so before the resend button.

And one after activation: **a trial has no grace period.** `trial: true` removes the 15 days a
subscription keeps to cover a renewal, so the warning has to come *before* the deadline — from D-7
in the Licence panel and in the dashboard widget.

## Products

`extension` and `mobile_app` are independent trials on the same licence. The state, the handlers and
the logic module are keyed by product; **only `extension` is surfaced today**, so adding the mobile
app is a user-interface change. Do not surface a product users cannot install: the trial is
one-shot and irreversible.
