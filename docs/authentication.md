# Authentication and access

How people sign in, how the second sign-in step works, and how roles decide what
each person may do. This page is for developers and for whoever runs an
installation. For the screens themselves, see the [user manual](user-manual.md#users-and-roles).
For every endpoint's request and response, see [openapi.yaml](openapi.yaml)
(tags *Sign in*, *Account*, *Roles and users*).

**Contents**

- [In brief](#in-brief)
- [Signing in](#signing-in)
- [The second step](#the-second-step)
- [Invitations, resets and one-time links](#invitations-resets-and-one-time-links)
- [Protections](#protections)
- [Roles and permissions](#roles-and-permissions)
- [Configuration](#configuration)
- [Data model](#data-model)
- [API](#api)
- [Running an installation](#running-an-installation)
- [Where the code is](#where-the-code-is)
- [Tests](#tests)
- [Extending](#extending)

## In brief

- People sign in with their email and a password. Then comes a second step: a code
  from an authenticator app, or a code sent by email. By default everyone must have
  a second step.
- Everything except the sign-in screens needs a completed sign-in. A browser
  opening a page without one is sent to `/login` and brought back afterwards. An
  API call without one gets `401`.
- Nobody is given permissions directly. People hold **roles**, and a role is a named
  set of permissions. A person can hold any number of roles. Each role applies
  either at all entities or only at the entities named. What a person may do is
  everything their roles allow, put together.
- An organisation can add as many roles as it needs. Only people holding
  `users.manage` can change roles or who holds them.

## Signing in

A sign-in moves through stages, which are kept in the session
([SignIn.php](../app/Libraries/SignIn.php)):

```
signedOut ──password──▶ mfa ───code──────────────────▶ done
                   │
                   └──▶ enrol ──set up a second step──▶ done
```

| Stage | Meaning | What the browser may call |
|---|---|---|
| `signedOut` | Nobody is signed in | `POST /api/auth/login` |
| `mfa` | Password accepted; the person has a second step and must give a code | `POST /api/auth/verify`, `POST /api/auth/code` (resend the emailed code) |
| `enrol` | Password accepted; the person must have a second step but has not set one up | `POST /api/auth/enrol/start`, `POST /api/auth/enrol/confirm` |
| `done` | Signed in | The whole application |

Every response from `/api/auth/*` includes `stage`. The sign-in page
([auth.js](../public/assets/js/auth.js)) shows whatever that stage needs next.

A password moves the session to one of three stages:

- `mfa`, if the person has a second step set up.
- `enrol`, if [`auth.mfaRequired`](#configuration) says they must have one but they
  have not set one up yet. They cannot do anything else until they set one up.
- `done`, otherwise.

A password alone is also refused in these cases:

| Case | What they see |
|---|---|
| The account is suspended | "This account is suspended…" |
| The person holds no role | "This account has no role yet…" |
| The person was invited but has not chosen a password | They are told to use the link in their invitation |

The session id is replaced at every stage, so an id seen before sign-in is useless
afterwards. A session ends after `auth.idleMinutes` (30 by default) with no requests.
The [SignedIn](../app/Filters/SignedIn.php) filter also checks every request against
the database. A session whose user has since been suspended, or has lost every
role, ends at their next request.

Signing out is `POST /api/auth/logout`.

## The second step

Two methods are offered, in the order set by `auth.mfaMethods`.

### Authenticator app (TOTP)

This follows RFC 6238: SHA-1, six digits, 30-second steps
([Totp.php](../app/Libraries/Totp.php)). These are the settings every mainstream
authenticator app supports, including Google Authenticator, Microsoft Authenticator,
Okta Verify and 1Password.

1. `enrol/start` (or `account/mfa/start`) creates a 160-bit secret. It is kept in
   the session, not the database. The response contains the secret, an
   `otpauth://` URI and the secret in groups of four for typing by hand. The page
   draws the QR code itself ([mfa.js](../public/assets/js/mfa.js)), so the secret
   never goes to a third-party service.
2. The person scans it. The first code their app shows is sent to `enrol/confirm`.
   Only once that code checks out is the secret saved, encrypted with the
   application key ([Secret.php](../app/Libraries/Secret.php)), in `users.mfa_secret`.
3. When checking a code, one step either side of the current one is accepted, to
   allow for clock drift. The matched step is stored in `users.mfa_last_step`, and a
   code for that step or an earlier one is refused, so a code cannot be used twice.

The authenticator app needs `encryption.key` to be set. Without it, the app option
is still shown, but marked unavailable, and only emailed codes can be used.

### Code by email

A six-digit code is sent when the password is accepted and whenever the person asks
for another (`POST /api/auth/code`).

- The code works for `auth.emailCodeMinutes` (10 by default).
- Sending a new code cancels the earlier one. A new code can be sent at most once
  every 30 seconds.
- Only a hash of the code is stored (in `auth_tokens`). After `auth.maxCodeAttempts`
  wrong tries the code stops working.

### Recovery codes

Setting up a second step issues `auth.recoveryCodes` (10) one-time codes, such as
`abcde-fghjk`. They use an alphabet without 0/o and 1/l/i, which are easy to
confuse. A recovery code can be typed anywhere a second-step code is asked for.
Each works once, and using one is recorded in the audit log along with how many are
left. **My account → New recovery codes** replaces the whole set.

### Wrong codes

A sign-in allows `auth.maxCodeAttempts` (5) wrong codes, whatever the method. After
that the session is ended and the person starts again from the password.

### Changing or removing it

On **My account**, a person can:

- Switch methods.
- Get new recovery codes.
- Turn the second step off, but only if `auth.mfaRequired` does not require it
  for them.

Each of these asks for their current password again
([Account.php](../app/Controllers/Api/Account.php)). This is so that someone using
a session left open cannot make these changes.

Someone with `users.manage` can **Reset second step** for another person, for
example after a lost phone. That person then sets up a new one at their next
sign-in.

## Invitations, resets and one-time links

| Link | Issued by | Valid for | Page |
|---|---|---|---|
| Invitation | **Settings → Users → Invite user**, or **Send the invitation again** | `auth.inviteHours` (7 days) | `/accept-invite?token=…` |
| Password reset | **Forgot your password?** on the sign-in page | `auth.resetHours` (1 hour) | `/reset-password?token=…` |
| Set-password link | **Email a link to set a password** (for existing people with no password), `php spark user:link`, `php spark install` | 7 days | `/accept-invite?token=…` |

- Each link is 32 random bytes. Only its SHA-256 hash is stored in `auth_tokens`.
  A link works once, and a new link for the same purpose cancels the old one.
- Setting a password from a link counts as the password step, because it proves
  the person has access to the mailbox. The sign-in then carries on to the second
  step.
- A reset request gets the same answer whether or not the email has an account.

### Password rules

Checked in `AuthRepository::assertStrong()`:

- At least `auth.minPasswordLength` characters (12 by default), and no more than
  200.
- At least five different characters.
- It must not contain the part of the email address before the `@`, if that part is
  three characters or longer.
- It must not be one of a short list of obvious passwords.

Passwords are stored with PHP's `password_hash()`. If PHP's default algorithm
changes, a password is re-hashed at the next successful sign-in.

### Mail

Emails are sent through CodeIgniter's `Email` service, configured with the
`email.*` settings ([AuthMail.php](../app/Libraries/AuthMail.php)). If mail is not
configured, or sending fails, the message and its link or code are written to
`writable/logs/` instead. This happens everywhere except production, so development
and demo instances still work without mail. The screen says when this has happened.

## Protections

| Threat | Protection |
|---|---|
| Guessing one person's password | After `auth.maxFailedSignIns` (5) wrong passwords in a row, the account is locked for `auth.lockMinutes` (15). A password reset unlocks it straight away. |
| Guessing across many accounts | Each IP address gets 10 attempts a minute at the password, codes, resets and links. After that it gets `429`. |
| Finding out who has an account | An unknown email and a wrong password get the same message and take the same time. A dummy hash is checked when there is no account. (The locked-account message does show that the account exists.) |
| A stolen copy of the database | Links, emailed codes and recovery codes are stored only as hashes. TOTP secrets are encrypted with the application key. |
| Another site posting forms as the signed-in person (CSRF) | Every non-GET API request must carry an `X-Requested-With` header. A form on another site cannot add one without a CORS preflight, which this server never allows. The page scripts ([ui.js](../public/assets/js/ui.js)) send it on every request. |
| Session fixation and an unattended session | The session id is replaced at every stage, and idle sessions time out. |
| Replaying a TOTP code | Each time step can be used only once. |

Every sign-in, refusal, lockout, recovery-code use and change to a second step is
recorded in the audit log with the address it came from. So is every change to
roles and to who holds them.

## Roles and permissions

### The model

```
users ──< user_entity_roles >── roles ──< role_permissions >── permissions
                │
             entities
```

Each row of `user_entity_roles` says that a person holds a role at an entity:

- Holding a role at every entity is shown as **all entities**.
- A person can hold role A at every entity and role B at only one.
- A person's permissions are the distinct set of permissions across all their
  roles.

### Permissions

The application defines the permissions (`BaselineSeeder::PERMISSIONS`), because
each one is checked somewhere in the code. They cannot be added from the screen.

| Key | Allows |
|---|---|
| `ledger.view` | View the ledger, reports and supporting records |
| `journal.prepare` | Prepare and submit journals and documents |
| `journal.approve` | Approve documents within the role's ceiling |
| `journal.post` | Post approved journals to the ledger |
| `requisition.raise` | Raise purchase requisitions |
| `payroll.view` | View payroll records (every read is logged) |
| `settings.manage` | Change organisation, ledger and approval settings |
| `period.close` | Confirm the management review and close a period |
| `period.authorise` | Authorise a period close and reopen a closed period |
| `chart.manage` | Add, change, import and archive accounts |
| `users.manage` | Invite users, assign their roles and define roles |

A role with no permission other than `ledger.view` is marked read only.

### Roles

The eight built-in roles (`BaselineSeeder::ROLES`: Finance Manager, Senior
Accountant, Accountant, Programme Officer, Executive Director, Auditor (read only),
Finance Director, Grants Lead) are named by the approval policy and the close
checklist. Their permissions can be changed, but they **cannot be renamed or
deleted**.

Roles added under **Settings → Roles** can be renamed, given any permissions, and
deleted once nobody holds them. There is no limit on how many roles an organisation
adds.

### The safety rule

`RoleRepository::assertManaged()` runs inside the same transaction as every change
to a role, to who holds a role, or to whether someone is suspended. It refuses any
change that would leave **nobody active** holding `settings.manage` or
`users.manage`, because then nobody could undo the change. Also:

- A person cannot suspend themselves.
- Every person must keep at least one role. To stop someone signing in, suspend
  them instead.

### Checking a permission in code

Controllers extend [BaseApiController](../app/Controllers/Api/BaseApiController.php):

```php
if (!$this->can('users.manage')) {
    return $this->denied('That needs a role with users.manage.');
}
```

`can()` looks at the permissions of the *acting* user, which
`UserRepository::actorById()` works out from their roles. Entity-level rules (which
entities a person can reach) come from the same `user_entity_roles` rows.

### Act as (training only)

When `auth.actAs = true`, the user menu offers **Act as**. It sets an `elog_actor`
cookie, and `actor()` then acts as that person. This lets one trainer play
preparer and approver in turn. It is off by default and on in `phpunit.dist.xml`.
**Never turn it on for an installation holding real books.**

## Configuration

Every value is in [app/Config/Auth.php](../app/Config/Auth.php). Any of them can be
overridden in `.env` as `auth.<name>` (or `auth_<name>` in an environment variable).

| Setting | Default | Meaning |
|---|---|---|
| `mfaRequired` | `all` | Who must have a second step: `all`, `privileged` (anyone holding a permission in `privileged`) or `optional` |
| `privileged` | `journal.approve`, `journal.post`, `settings.manage`, `users.manage`, `period.authorise` | Used when `mfaRequired = privileged` |
| `mfaMethods` | `totp`, `email` | The methods offered, in order |
| `issuer` | the organisation's brand name | The name an authenticator app files the account under |
| `emailCodeMinutes` | 10 | How long an emailed code works |
| `maxCodeAttempts` | 5 | Wrong codes allowed per code and per sign-in |
| `maxFailedSignIns` / `lockMinutes` | 5 / 15 | Wrong passwords before a lockout, and how long it lasts |
| `idleMinutes` | 30 | Idle time before a session ends |
| `inviteHours` / `resetHours` | 168 / 1 | How long invitation and reset links work |
| `minPasswordLength` | 12 | |
| `recoveryCodes` | 10 | |
| `actAs` | `false` | See [Act as](#act-as-training-only) |

These settings also matter:

- `encryption.key` (`php spark key:generate`): needed for the authenticator app.
- `email.*`: needed to send invitations, resets and codes.

## Data model

The migration `2026-09-21-100033_CreateAuthentication` adds these to the existing
`users`, `roles`, `permissions`, `role_permissions` and `user_entity_roles` tables:

| Table / column | Holds |
|---|---|
| `users.mfa_method` | `totp` or `email` (with `mfa_enabled` and `mfa_secret`, which already existed) |
| `users.mfa_last_step` | The last TOTP step accepted |
| `users.failed_sign_ins`, `users.locked_until` | The lockout counter |
| `users.password_changed_at` | |
| `auth_tokens` | Invitation and reset links (SHA-256) and emailed codes (`password_hash`). Columns: `purpose`, `expires_at`, `attempts`, `used_at` |
| `user_recovery_codes` | Hashed recovery codes and when each was used |
| permission `users.manage` | Given to every role that already holds `settings.manage`, so nobody loses access when the migration runs |

## API

All of these are under `/api`. See [openapi.yaml](openapi.yaml) for the full
request and response bodies.

| Endpoint | Purpose |
|---|---|
| `GET auth` | The current stage and what the screen needs to show next |
| `POST auth/login` | `{email, password}` |
| `POST auth/verify` | `{code}`: an authenticator code, an emailed code or a recovery code |
| `POST auth/code` | Send a new emailed code |
| `POST auth/enrol/start`, `auth/enrol/confirm` | Set up a second step during sign-in |
| `POST auth/logout` | |
| `POST auth/forgot` | `{email}`: send a reset link |
| `GET` / `POST auth/invite`, `auth/reset` | Check a link's token, then set the password from it |
| `GET account` | The person's details, roles, second step, and recovery codes left |
| `POST account/password`, `account/mfa/start`, `account/mfa/confirm`, `account/mfa/remove`, `account/recovery-codes` | Changes on My account; these ask for the current password where noted above |
| `GET roles` | Roles, their permissions and holders, the permission catalogue, `canManage` |
| `POST roles`, `roles/{id}`, `roles/{id}/delete` | Add, change or delete a role (`users.manage`) |
| `POST users/{id}/access` | `{access: [{role, entities: "all" \| [codes]}]}`: replace a person's roles (`users.manage`) |
| `POST users/{id}/suspend`, `/reinstate`, `/invite`, `/reset-mfa` | (`users.manage`) |
| `POST settings/invite` | `{name, email, roles: [...], entities}`: invite someone with one or more roles |

Only `/login`, `/accept-invite`, `/reset-password` and `/api/auth/*` can be reached
without a sign-in (`Config\Filters::$globals`).

Errors: `401` means not signed in. `403` means not permitted, or the
`X-Requested-With` header is missing. `409` means a step was called at the wrong
stage. `422` means a rule refused the request; the message says why. `429` means
too many attempts.

To call the API with curl, keep a cookie jar and send the header:

```sh
curl -c jar -b jar -H 'Content-Type: application/json' -H 'X-Requested-With: curl' \
     -d '{"email":"w.kamau@elog.or.ke","password":"elog-demo-password"}' \
     http://localhost:8080/api/auth/login
curl -b jar http://localhost:8080/api/journals
```

## Running an installation

- **Demo data:** every seeded user's password is `elog-demo-password`
  (`OrganisationSeeder::DEMO_PASSWORD`). See [setup.md](setup.md#who-to-sign-in-as).
- **A new installation:** `php spark install` prints a one-time link for the first
  user, or sets `userPassword` if the config file gives one.
- **Upgrading a database from before this module:** run `php spark migrate`.
  Existing people have no password yet. Give each one a link, either with
  **Email a link to set a password** in their Settings → Users drawer, or with
  `php spark user:link <email>`.
- **Someone locked out, or the only administrator's phone is lost:**

  ```sh
  php spark user:link w.kamau@elog.or.ke              # a one-time link to choose a new password
  php spark user:link w.kamau@elog.or.ke --reset-mfa  # …and remove their second step
  ```

  Anyone who can run `spark` can already read the database, so this gives away
  nothing new. It is recorded in the audit log.
- **Relaxing the second step**, for example on a development machine: set
  `auth.mfaRequired = optional` in `.env`. The run-fms skill does this by default
  ([run-fms.md](run-fms.md)).

## Where the code is

| File | Role |
|---|---|
| [Config/Auth.php](../app/Config/Auth.php) | Settings |
| [Filters/SignedIn.php](../app/Filters/SignedIn.php) | Requires a completed sign-in, ends sessions for suspended or role-less users, checks the CSRF header |
| [Libraries/SignIn.php](../app/Libraries/SignIn.php) | Session stages, idle timeout, wrong-code counter |
| [Libraries/Totp.php](../app/Libraries/Totp.php) | RFC 6238 codes, otpauth URI, Base32 |
| [Libraries/AuthMail.php](../app/Libraries/AuthMail.php) | Invitation, reset and code emails (with the log fallback) |
| [Repositories/AuthRepository.php](../app/Repositories/AuthRepository.php) | Passwords, lockout, second step, links, recovery codes, audit |
| [Repositories/RoleRepository.php](../app/Repositories/RoleRepository.php) | Roles, who holds which, suspension, `assertManaged()` |
| [Repositories/UserRepository.php](../app/Repositories/UserRepository.php) | Works out the acting user's permissions and entities from their roles |
| [Controllers/Api/Auth.php](../app/Controllers/Api/Auth.php) | The sign-in API and IP throttling |
| [Controllers/Api/Account.php](../app/Controllers/Api/Account.php) | My account |
| [Controllers/Api/Roles.php](../app/Controllers/Api/Roles.php), [Users.php](../app/Controllers/Api/Users.php) | Settings → Roles and Settings → Users |
| [Commands/UserLink.php](../app/Commands/UserLink.php) | `php spark user:link` |
| [Views/auth.php](../app/Views/auth.php), [auth.js](../public/assets/js/auth.js), [mfa.js](../public/assets/js/mfa.js) | Sign-in, invitation and reset pages |
| [Views/pages/account.php](../app/Views/pages/account.php), [account.js](../public/assets/js/pages/account.js) | My account |
| [pages/settings.js](../public/assets/js/pages/settings.js) | The Roles cards and the Users access drawer |

## Tests

- [AuthTest.php](../tests/unit/AuthTest.php) covers signing in, lockout,
  throttling, TOTP and emailed codes, recovery codes, links, the password rules and
  the CSRF header.
- [RolesTest.php](../tests/unit/RolesTest.php) covers roles, holding several roles
  at different entities, invitations with several roles, built-in roles, the safety
  rule, and `users.manage`.
- Every other feature test starts signed in through the
  [SignsIn](../tests/_support/SignsIn.php) trait. By default it signs in as the
  first active settings manager and sends `X-Requested-With`. Use
  `$this->signIn('someone@…')` to be someone else, or `$this->signOut()` to start
  signed out.

```sh
vendor/bin/phpunit tests/unit/AuthTest.php tests/unit/RolesTest.php
```

## Extending

**Adding a permission:**

1. Add it to `BaselineSeeder::PERMISSIONS`, and to `ROLE_PERMISSIONS` for any
   built-in roles that should start with it.
2. Write a migration that inserts it for existing databases, as
   `CreateAuthentication::grantUsersManage()` does.
3. Check it with `$this->can('your.permission')` in the controller.
4. If its key starts with a new prefix, add that prefix to
   `RoleRepository::GROUPS` so the Roles screen files it under the right heading.

**Adding a second-step method:**

1. Add it to `AuthRepository::METHODS` and `auth.mfaMethods`.
2. Handle it in `verifyFactor()` and in enrolment
   (`Api\Auth::startEnrolment()` / `confirmEnrolment()`).
3. Offer it in `mfa.js`.

`users.mfa_method` is a plain string column, so no schema change is needed.
