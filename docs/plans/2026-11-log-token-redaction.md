# Keep URL keys out of the access logs

Status: **steps 1 to 5 applied on production 25 Nov 2026** (owner's go ahead; backup
`/root/apache2.bak-20261125-2023`, verified: `/book/[key]`, `/preview/[key]`, no query
string, port 80 in `redirect-access.log`). Steps 6 and 7 (old logs, still valid keys)
and the Csp app fix are still open. Written 25 Nov 2026.

## Why

Several links carry their key in the URL path, and Apache's `cloudflare` LogFormat logs
the full request line (`%r`) and the Referer. Read only check of production on
25 Nov 2026 (35 days of logs kept, `/etc/logrotate.d/apache2`):

| Key in the path | Lines in the logs | Still valid? |
|---|---|---|
| `preview/{64 hex}` | 84 | some (14 days) |
| `manage/{64 hex}` | 43 | no (1 hour) |
| `welcome-pack/{64 hex}` | 1 | yes (no expiry) |
| `book/{64 hex}` (change or cancel) | 1 | until 30 days after the appointment |
| `directory/{slug}/book/returning/{64 hex}` | 0 (not released) | 10 minutes, once |

Port 80 redirects are logged to `other_vhosts_access.log` with the full request line,
because the `*:80` vhosts have no CustomLog of their own. The app's own log also has 15
lines with keys: `Csp` reports log the page address (`document=…/preview/{key}`,
`…?invite=…`). Offsite backups do not include `/var/log`.

## What changes (branch `ops/log-token-redaction`)

1. `deploy/apache/conf-available/webscheduler-logformat.conf`
   - Server wide `SetEnvIf` lines build `LOG_PATH`: the path with up to three segments
     of 32 or more hex characters replaced by `[key]`. `LOG_REF` does the same for the
     Referer, after any `/` or `=`.
   - The `cloudflare` LogFormat logs `"%m %<{LOG_PATH}e %H"` instead of `"%r"`, and
     `%<{LOG_REF}e` instead of the Referer. The `<` reads the original request: after
     CI's `.htaccess` rewrite to `index.php`, the final request only has
     `REDIRECT_LOG_PATH`.
   - **The query string is no longer logged.** `?invite=` and `?handover=` tokens ride
     there, and so do search words. All fields keep their positions.
2. The four vhost files get a `CustomLog …/redirect-access.log cloudflare` in their
   `*:80` block. Those redirects then stop going to `other_vhosts_access.log`.
3. Not in this change: the `Csp` controller should log the page address without its
   query and with hex segments redacted. That is an app fix with a test, on its own
   branch, released with the next app release.

Checked here: `httpd -t` on Apache 2.4.67 says Syntax OK for the new log format file.
Production runs 2.4.58 with `log_config`, `logio` and `setenvif` loaded. Not tested
yet: the redaction itself on a running Apache. Doing that locally needs a short lived
Apache on `127.0.0.1:65080`, which the one port rule does not allow without the owner's
OK. Otherwise it is verified on production in step 5 below, with the rollback ready.

## Deployment steps (after approval)

All over `ssh lightsail`, as `ubuntu` with `sudo`.

1. **Backup:** `sudo cp -a /etc/apache2 /root/apache2.bak-$(date +%Y%m%d-%H%M)`.
2. **Copy** the five files from the branch to `/etc/apache2/conf-available/` and
   `/etc/apache2/sites-available/` (the repo copies matched production byte for byte on
   25 Nov 2026; check with `md5sum` first).
3. `sudo apache2ctl configtest`. Anything but `Syntax OK`: stop and restore the backup.
4. `sudo systemctl reload apache2`, which is graceful: requests in flight finish.
5. **Verify** (a minute):
   - `curl -s -o /dev/null https://local.webscheduler.co.za/book/$(openssl rand -hex 32)`
     and the same for `/preview/…`. In `listing-access.log` the line reads
     `GET /book/[key] HTTP/2`.
   - A normal page (`/directory?q=dentist`) logs as `GET /directory HTTP/2`.
   - `/health` is still not logged. The sites, `/business` sign in and a booking page
     answer 200.
   - `curl -sI http://local.webscheduler.co.za/x` lands in `redirect-access.log`, not
     `other_vhosts_access.log`.
6. **Clean the old logs** (separate approval). Rewrite the current and rotated logs with
   the same rule, for example
   `sed -E -i 's#/([0-9A-Fa-f]{32,})#/[key]#g'` on the plain files, and a zcat, sed and
   gzip round trip on the `.gz` files, for `*access.log*` and `other_vhosts_access.log*`.
   Then redact the 15 app log lines the same way. Done after the new format is live, so
   nothing new arrives during the clean.
7. **Rotate** the keys that are still valid: send fresh previews to profiles with a live
   preview token, and reissue the welcome pack link for the one listed. Each is a click in
   admin, and the old links stop.

## Rollback

`sudo cp -a /root/apache2.bak-…/. /etc/apache2/ && sudo apache2ctl configtest && sudo systemctl reload apache2`.
Seconds, no downtime. Logs written in the meantime stay redacted, which is harmless.

## Afterwards

- The handbook's `operations.php` and `stack.php` describe the new format, with a
  `changelog.php` line, in the commit that records the change as live.
- Longer term, and optional: put the returning patient key in the URL fragment (`#…`),
  which browsers never send to any server. That needs a small script on the confirm
  page and keeps the code path for people without script.
- Residual: Cloudflare's security events show the path of a request a WAF rule acts on;
  browsers keep history; mail scanners open links (our GETs only ask). These are covered
  by single use, short lived keys.
