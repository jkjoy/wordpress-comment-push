# Comment Push integration checks

These checks load the installed plugin inside the real WordPress runtime and its
SQLite database. Run against this disposable local test site, with no simultaneous
settings edits or comment submissions.

```powershell
& 'D:\EServer\core\childApp\php\php-8.4\php.exe' `
  -c 'D:\EServer\core\etc\php-8.4\php.ini' `
  'E:\blog\WORDPRESS\.runtime\wp-cli.phar' `
  --path='E:\blog\WORDPRESS\wordpress' `
  eval-file 'E:\blog\WORDPRESS\wordpress\wp-content\plugins\comment-push\tests\integration.php'
```

The plugin must already be active. Sodium is required for the QQ Webhook checks.
The command exits with code 0 only if every check passes.

Before changing plugin state, the harness intercepts **all** WordPress HTTP calls
through `pre_http_request`; protocol tests also inject fixed transport responses.
No requests are sent to QQ or Weixin. WordPress email is intercepted as well.

The suite checks configuration validation, credential preservation, non-autoload
storage, QQ token caching and one-time 401 retry, ambiguous failures, official
Ed25519 vectors, signed Webhook challenges, expiring one-time private/group
binding, exclusive binding locks under stale negative caches, expired-lock
recovery and ownership-safe unlock, trusted Weixin domains, QR login, owner-only recipient sync, context
tokens, login expiry, read timeouts, and secret-free administrator rendering.

It also creates real WordPress comments to verify the insertion hook, approved
and pending filters, administrator opt-in, ordinary logged-in users, spam/trash
and pingbacks/trackbacks, moderation without duplicate notifications, independent
channel failures, and preservation of comments after delivery errors.

All `wpcpush_` options and their original `autoload` values are snapshotted and
restored in `finally`. Test users, posts, and comments are deleted. Existing
administrator credentials are never changed. The HTTP and email interception
filters remain installed through cleanup and are then removed.

Run the management suite with the same command, replacing the final filename
with `tests\management.php`:

```powershell
& 'D:\EServer\core\childApp\php\php-8.4\php.exe' `
  -c 'D:\EServer\core\etc\php-8.4\php.ini' `
  'E:\blog\WORDPRESS\.runtime\wp-cli.phar' `
  --path='E:\blog\WORDPRESS\wordpress' `
  eval-file 'E:\blog\WORDPRESS\wordpress\wp-content\plugins\comment-push\tests\management.php'
```

The original suite retains its 61 checks. The management suite adds 33 checks:
exact command parsing, lists and details, native moderation hooks and metadata,
trash and restore, replies with the bound WordPress identity, preservation of
backslashes and quotes, WordPress permissions, private sender authorization,
independent QQ group member binding and management revocation on rebinding,
message age and activation boundaries,
signed Webhook execution, passive acknowledgements, replay protection under
stale caches, timeout recovery, and Weixin polling with exact big integer IDs.

Both suites snapshot and restore the `cron` option along with every raw
`wpcpush_` option and original `autoload` value. This includes scalar command
claim timestamps. The management suite removes its replies through deletion of
the temporary fixture posts. Stop the independent receiver before testing, then
run the two commands sequentially; they temporarily replace plugin settings.

For the administration menu and independent settings forms, replace the final
filename with `tests\admin.php`. Its 12 checks verify section URL routing,
isolated checkbox and credential saves, preservation of the existing manager,
explicit authorization from the management page, scoped validation when another
channel is unavailable, rejection of unknown or read-only sections, and
revocation after changing QQ credentials. It uses the same HTTP/email blockers
and exact plugin/cron restoration as the other suites. Stop the independent
receiver before running it.

Read-only browser checks are available at
`E:\blog\WORDPRESS\.runtime\qa\visual-qa.cjs`. They inspect the five menu pages
at desktop and mobile sizes, check form section/field isolation, empty password
inputs, submenu links, resources, and horizontal overflow, and save ten PNGs
plus `admin-qa-report.json`. They use the saved local administrator session and
block every external request and every non-GET/HEAD request.

`E:\blog\WORDPRESS\.runtime\qa\save-qa.cjs` additionally checks authenticated
`admin-post.php` saves on the four settings pages. It refuses to run if any
notification or management toggle is enabled. With the receiver stopped, it
submits each form's existing values, expects a 303 back to that same page and
one success notice, and compares a SHA-256 fingerprint of the effective
configuration after every save. It never starts login, binding, or test-send
operations and never prints credentials.

For offline GitHub update checks, replace the final filename with
`tests\updater.php`. Its 25 checks cover the fixed repository and 1.2.0 headers,
stable release parsing, exact uploaded asset paths, rejection of drafts,
prereleases and source archives, requirement metadata, success/failure cache
lifetimes, refresh permissions, safe plugin information, and isolation from
other plugins using GitHub.

The updater suite calls the real `wp_update_plugins()` function with intercepted
GitHub and WordPress.org responses: a newer release must appear in the native
update response, while older and equal versions must not be offered as upgrades.
It also exercises WordPress.org connection failures and HTTP 503 responses:
GitHub updates must remain available, equal/older versions must enter
`no_update`, and unrelated plugin updates, checked versions, and translations
must survive. Repeated transient writes must not recurse or duplicate the
GitHub request.
Four package checks exercise the real `upgrader_source_selection` filter with a
native `Plugin_Upgrader` package checker: a compatible plugin is accepted, PHP
and WordPress requirements above the local versions are rejected, and existing
errors and unrelated installation targets pass through unchanged. The suite
never calls the upgrader's `init()`, `upgrade()`, or `install()` methods or
replaces plugin files. Temporary package fixtures are removed in `finally`.
Only the release and native plugin-update site transients are snapshotted and
restored, including their raw values and `autoload` flags; configuration and
cron are untouched, so the independent message receiver can keep running. Avoid
simultaneous manual update checks while this suite runs. All HTTP and mail
remain blocked in its process.
