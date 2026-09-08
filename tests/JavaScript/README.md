# Login submission tests

Run from the repository root with Node.js 22 or newer; no npm dependencies are needed:

```sh
node --test tests/JavaScript/login-submission.test.cjs
```

The suite executes the login template's JavaScript functions and event handlers.
Form controls, HTTP responses, navigation and timers are simulated so failures and
overlapping actions can be tested deterministically. PHP substitutions use inert
values. The tests do not contact LDAP, an OAuth2 provider or a Duo service.

Before release, also verify these flows in a configured browser environment:

- Local and LDAP login: rapid Enter/click submissions send one `identify_user`
  request; a refusal restores the form, and success keeps it locked until navigation.
- Network failure: an error is visible and the form can be submitted again.
- MFA: Google enrollment and verification, YubiKey input, and changing MFA methods
  between attempts retain focus and remain usable.
- Duo and OAuth2: provider redirects and callbacks complete; failures restore the form.
  Returning with the browser Back button reloads a cached, locked login page.
- Expired session: renewal replays the pending credentials once while the form stays
  locked; failed renewal leads to the existing refresh dialog.
- Returning to an idle tab: a pending session check completes before login is sent.

This is a client-side duplicate-submission guard. Server-side authentication and
brute-force controls remain necessary. It does not establish the cause of issue #5365.
