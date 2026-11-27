# Origin only reachable through Cloudflare

Status: **done 26 Nov 2026.** SSH 22 now allows only 197.87.0.0/16, 197.90.0.0/16 and Lightsail browser SSH (owner, console; `ssh lightsail` checked from the Mac, sites 200). Undo: untick Restrict to IP address on the SSH rule. Earlier status: **web ports already done; SSH still open to all.** Console read by the owner
26 Nov 2026 (one rule list covers IPv4 and IPv6): HTTP 80 and HTTPS 443 each allow 22
sources, which is Cloudflare's 15 IPv4 plus 7 IPv6 ranges; SSH 22 allows any IPv4 or
IPv6 address plus Lightsail browser SSH. Static IPv4 13.203.131.32, IPv6
2406:da1a:dce:1c00:2aa3:e9dd:6afa:a79. Steps 4 and 5 below are therefore done; step 3
(SSH) is what is left. Written 25 Nov 2026.

## SSH findings (26 Nov 2026)

- sshd takes keys only (`passwordauthentication no`, `kbdinteractiveauthentication no`,
  root `without-password`). 1,390 failed tries from 78 addresses in 7 days: noise
  against keys, but noise worth closing.
- Accepted logins in 30 days: `ubuntu` from the owner's two home ranges (197.87.x.x and
  197.90.x.x: the address changes), `ubuntu` from 52.95.x.x (browser SSH), and
  `mauticexport` from 3.6.x.x, the retired Mautic server, nightly at 23:15 until
  3 Nov 2026. That account has a forced command (`listing-export.php`) and MySQL user
  `mautic_export` with SELECT on `xs_directory_listings` and `xs_directory_categories`.
  Mautic is retired, so it should be removed (lock the account, delete its key, drop the
  MySQL user), whatever the firewall does.
- Nothing else connects: the brand site release and every release run from the owner's
  Mac; the GitHub deploy workflow is dormant.
- `Config\App::$proxyIPs` matches Cloudflare's published lists exactly.

## What we know (read only checks, 25 Nov 2026)

| Check | Result |
|---|---|
| Lightsail firewall rules | **Not read.** There is no AWS CLI or key on the dev Mac. The owner reads them in the console (instance, Networking, IPv4 and IPv6 firewall) |
| TCP 80 and 443 to the origin's IPv4, from the owner's (non Cloudflare) address | no answer (filtered) |
| TCP 22 from the same address | open |
| TCP 3306, 8080, 25 | no answer. MySQL listens on 127.0.0.1 only |
| Every connecting address in 2 days of Apache logs (5,977 distinct, IPv4 and IPv6) | all inside Cloudflare's published ranges |
| ufw on the server | inactive (the Lightsail firewall is the only one) |
| Public IPv6 on the instance | yes, one global address. Not probed: the dev network has no IPv6 |
| SSH logins (`last`) | the owner's address, and 52.95.x.x, which is AWS: Lightsail's browser SSH |

**Reading:** web traffic over IPv4 already looks limited, either to Cloudflare or at
least away from ordinary addresses. Whether the IPv6 rules match, and whether SSH is
open to everyone or only some addresses, needs the console. The app is safe either way:
a forged `CF-Connecting-IP` from a non Cloudflare address is ignored
(`Config\App::$proxyIPs`, test `testTheLimitFollowsTheRealClientBehindCloudflare`).

## Target rules

| Rule | IPv4 | IPv6 |
|---|---|---|
| HTTP 80 | Cloudflare's 15 ranges (cloudflare.com/ips-v4) | Cloudflare's 7 ranges (cloudflare.com/ips-v6) |
| HTTPS 443 | the same 15 | the same 7 |
| SSH 22 | the owner's home ranges (the address changes, so the ISP blocks the last month used, 197.87.0.0/16 and 197.90.0.0/16, or the current /32 if the owner prefers tighter), **plus Lightsail browser SSH** | none, unless the owner uses IPv6 SSH |
| Anything else | closed | closed |

Keeping browser SSH means a changed home address can never lock us out.

These are the same ranges as `Config\App::$proxyIPs`. Both must change together when
Cloudflare publishes a change, which is rare.

## Steps (after approval)

1. **Record the current rules.** Screenshots of the IPv4 and IPv6 firewall pages, or
   `aws lightsail get-instance-port-states --instance-name <name>` saved as JSON. This
   is the rollback.
2. **Take a snapshot of the instance** (Lightsail, Snapshots). This is not needed for a
   firewall change, but it is cheap insurance before touching production.
3. **SSH first, on its own.** Add the owner's /32 and keep `lightsail-connect`, then
   remove any `0.0.0.0/0` (and `::/0`) on 22. Check that `ssh lightsail` works from the
   Mac **and** browser SSH opens, before going on.
4. **Web, IPv4 then IPv6.** Set 80 and 443 to the Cloudflare ranges. Lightsail takes a
   list of CIDRs per rule. Remove any all addresses rule on 80 and 443.
5. **Verify:**
   - Through Cloudflare: both Local sites, the brand site and `listing.` answer; `/health`
     is 200; a booking page and `/business` sign in work.
   - Payments: a Paystack test charge returns, and PayFast's ITN reaches us, because it
     posts through Cloudflare to `local.webscheduler.co.za`. Look in the app log for the
     next notify.
   - From outside: `curl --resolve local.webscheduler.co.za:443:<origin IPv4> …` times out.
     The same over IPv6 from a network that has it, or ask someone who has.
   - Cron on the box, outgoing mail (SES), the offsite backup (S3) and Google stats are
     outgoing or local, so not affected. Check the next morning's `system:watch` summary.
6. **Watch Cloudflare's list.** A small monthly cron compares cloudflare.com/ips-v4 and
   ips-v6 with `Config\App::$proxyIPs` and emails the admin on a difference. That is an
   app change for a later release.

## Rollback

Put back the recorded rules (console, or `aws lightsail put-instance-public-ports` with
the saved JSON). It takes effect in seconds. Browser SSH stays available throughout, so
the rollback can always be done.

## Worth knowing

- **A Cloudflare IP allowlist admits every Cloudflare customer.** Someone can point their
  own Cloudflare zone at our address and reach the origin through Cloudflare's IPs. The
  full fix is **Authenticated Origin Pulls**: Cloudflare presents a client certificate
  and Apache refuses connections without it (`SSLVerifyClient require` with
  Cloudflare's origin pull CA). It is a separate step with its own plan and rollback,
  because a mistake there takes the sites down.
- ufw stays off: two firewalls to keep in step make a lockout more likely, not less.
