# Origin only reachable through Cloudflare

Status: **investigation done, plan not applied.** Production change: needs the owner's
approval, separate from any app release. Written 25 Nov 2026.

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
| SSH 22 | the owner's fixed addresses, each as /32, **plus Lightsail browser SSH** (`lightsail-connect`) | none, unless the owner uses IPv6 SSH |
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
