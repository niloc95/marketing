# Manual LAMP setup — bare Ubuntu to live, in the browser terminal

Start to finish, by hand, in the **Lightsail browser terminal**. No provisioning
script, no GitHub Actions, no FileZilla, no FTP. The code arrives by `git clone`
and is built on the box; the data arrives by `scp` pushed straight from
Hostinger. Nothing travels via your laptop.

**Target:** Ubuntu 24.04 LTS **OS Only**, 4 GB / 2 vCPU / 80 GB, `ap-south-1`
(Mumbai). Apache 2.4 + PHP 8.3-FPM + MySQL 8.0, all from Ubuntu's own `main`
repository — no PPA.

> **Not the Bitnami LAMP blueprint.** It ships its own tree under `/opt/bitnami`
> with non-standard paths and `AllowOverride None`, which fights the
> `.htaccess`-dependent layout this app needs. Pick **OS Only**.

Two hostnames, one instance:

| | Serves | DocumentRoot |
|---|---|---|
| `webscheduler.co.za` | static marketing site + `contact.php` | `/var/www/marketing` |
| `listing.webscheduler.co.za` | the CI4 directory app | `/var/www/listing/public_html` |

Hostinger stays live and untouched throughout. It is the source of the data and
the rollback, right up to Part 11.

---

## Contents

| Part | |
|---|---|
| 0 | [Before you start](#part-0--before-you-start) |
| 1 | [Create the instance](#part-1--create-the-instance) |
| 2 | [Install the LAMP stack](#part-2--install-the-lamp-stack) |
| 3 | [The `deploy` user and the directory tree](#part-3--the-deploy-user-and-the-directory-tree) |
| 4 | [Clone and build on the server](#part-4--clone-and-build-on-the-server) |
| 5 | [System configuration](#part-5--system-configuration) |
| 6 | [MySQL](#part-6--mysql) |
| 7 | [TLS, then enable the vhosts](#part-7--tls-then-enable-the-vhosts) |
| 8 | [Secrets, pushed from Hostinger](#part-8--secrets-pushed-from-hostinger) |
| 9 | [Import the live data](#part-9--import-the-live-data) |
| 10 | [Verify before DNS moves](#part-10--verify-before-dns-moves) |
| 11 | [Cutover](#part-11--cutover) |
| 12 | [Afterwards](#part-12--afterwards) |
| — | [Releasing changes after this](#releasing-changes-after-this) |
| — | [Things that will bite](#things-that-will-bite) |

---

## Part 0 — Before you start

**The repo must be pushed first.** The server pulls its Apache vhosts, PHP ini,
MySQL config and cron jobs out of the git clone in Part 5. If `deploy/` is not
committed and pushed, Part 5 has nothing to install.

Have these open in a browser tab: the Lightsail console, the Cloudflare dashboard
for `webscheduler.co.za`, and hPanel for the Hostinger account (you need the FTP
credentials from **Files → FTP Accounts**).

---

## Part 1 — Create the instance

Lightsail console → **Create instance**:

1. Region **ap-south-1 (Mumbai)**.
2. **Linux/Unix → OS Only → Ubuntu 24.04 LTS.**
3. Plan: **4 GB RAM / 2 vCPU / 80 GB SSD**.
4. Create, then **Networking → Attach a static IP**. Write it down — it is `$IP`
   for the rest of this document, and both Cloudflare A records point at it in
   Part 11. Without a static IP the address changes on every stop/start.
5. **Networking → IPv4 Firewall**: HTTP (80) and HTTPS (443) open to anywhere.

> **Leave SSH (22) at its default "anywhere".** Lightsail's browser terminal
> connects over the same port and is subject to this firewall — narrowing 22 to
> your own IP locks you out of the terminal this entire runbook is typed into.
> Narrow it at the very end, once key-based SSH from your Mac is set up and
> tested, or leave it and rely on there being no password login.

6. **Snapshots → Enable automatic snapshots.** Do it now, while there is nothing
   to lose, so it is not a thing you meant to get to later.

Then **Connect using SSH**. You land as `ubuntu`, with `sudo`, no key handling.

---

## Part 2 — Install the LAMP stack

**Work inside tmux.** The browser session drops when idle and takes anything
running with it. `npm ci`, `composer install` and the database import are each
long enough to be at risk:

```bash
sudo apt-get update
sudo apt-get install -y tmux
tmux new -s setup
```

Disconnected? Reconnect and `tmux attach -t setup`.

> **Pasting** into the browser terminal goes through the clipboard icon in the
> window, not ⌘V. Long pastes can truncate — Part 7 depends on noticing when
> they do.

### The packages

Ubuntu 24.04 ships Apache 2.4, PHP 8.3 and MySQL 8.0 in `main`. There is no
`ondrej/php` PPA to add and no Bitnami tree to work around.

```bash
sudo apt-get upgrade -y
sudo apt-get install -y \
  apache2 mysql-server \
  php8.3-fpm php8.3-intl php8.3-mbstring php8.3-mysql php8.3-curl \
  php8.3-gd php8.3-xml php8.3-zip php8.3-opcache \
  composer git unzip nodejs npm jq
```

### Verify before building anything on top of it

The extension list is traced from the application code, not from
`composer.json` — several are needed and undeclared:

```bash
php -v                  # 8.3.x
mysql --version         # 8.0.x
systemctl is-active apache2 mysql php8.3-fpm    # active ×3

for e in intl mbstring mysqli curl gd exif fileinfo openssl json; do
  php -m | grep -qix "$e" && echo "ok      $e" || echo "MISSING $e"
done

php -r 'var_dump(function_exists("imagewebp") && !empty(gd_info()["WebP Support"]));'
```

The last line must print `bool(true)`. `ListingImageProcessor` converts every
upload to WebP and guards the call with `function_exists('imagewebp')` — GD built
without WebP does not throw, it just quietly stops producing images.

MySQL must be **8.0.3 or newer**: `SpatialSupport::supportsSridColumns()` gates
the `POINT … SRID 4326` path on that version, and below it the schema silently
takes the MariaDB fallback instead.

### Swap

Nothing here should reach swap in steady state. This exists so that a
`composer install` or an image resize spike fails slowly instead of being
OOM-killed:

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee -a /etc/sysctl.conf
sudo sysctl -w vm.swappiness=10
```

### Apache modules

```bash
sudo a2enmod rewrite headers proxy_fcgi setenvif expires ssl
sudo a2enconf php8.3-fpm
sudo a2dissite 000-default
```

`proxy_fcgi` is how Apache reaches PHP-FPM over
`unix:/run/php/php8.3-fpm.sock`; the vhosts name that socket explicitly. Do not
install `libapache2-mod-php` — two PHP SAPIs fighting over the same requests is
a confusing way to spend an afternoon.

---

## Part 3 — The `deploy` user and the directory tree

There is no CI any more, but the `deploy` user still earns its place: it owns
`/var/www`, it runs the cron jobs, and it is the account the build runs as, which
keeps root out of `writable/`.

```bash
sudo adduser --disabled-password --gecos "" deploy
sudo usermod -aG www-data deploy
```

```bash
sudo install -d -o deploy -g www-data -m 755 /var/www/marketing
sudo install -d -o deploy -g www-data -m 755 /var/www/listing
sudo install -d -o deploy -g www-data -m 755 /var/www/listing/directory-app
sudo install -d -o deploy -g www-data -m 755 /var/www/listing/public_html
sudo install -d -o deploy -g deploy   -m 700 /home/deploy/backups

for d in cache session logs uploads verification debugbar; do
  sudo install -d -o deploy -g www-data -m 2775 \
    "/var/www/listing/directory-app/writable/$d"
done
sudo chmod 2770 /var/www/listing/directory-app/writable/verification

sudo install -d -o deploy -g www-data -m 2775 /var/www/listing/public_html/assets/listings
sudo install -d -o deploy -g www-data -m 2775 /var/www/listing/public_html/assets/listings/gallery
```

**The `2` in `2775` is the point.** Setgid means files created here by `deploy`
inherit the `www-data` group, so PHP-FPM can still write them afterwards. Without
it, the first release silently breaks the file cache — and a broken
`writable/cache` takes every rate limit with it while the site keeps returning
200.

`writable/verification` holds uploaded identity documents and is never
web-served (a controller streams it). `2770` keeps it out of `other`.

---

## Part 4 — Clone and build on the server

The repo is public, so this needs no credentials, no deploy key and no upload.

```bash
sudo -u deploy git clone https://github.com/niloc95/marketing.git /home/deploy/src
sudo -u deploy -H bash -lc 'cd ~/src && composer install --no-dev --optimize-autoloader'
sudo -u deploy -H bash -lc 'cd ~/src && npm ci --ignore-scripts'
sudo -u deploy -H bash -lc 'cd ~/src && npm run list:build && npm run site:build'
```

- `--no-dev` still installs PHPMailer (a production dependency). The marketing
  build fails without `vendor/phpmailer` — it copies three of its classes into
  `dist/site/lib/`.
- `--ignore-scripts` on `npm ci` skips Playwright's ~300 MB browser download.
  Playwright is a devDependency used only by `npm run site:shots`, and the
  screenshots the site build asserts on are committed. Tailwind, PostCSS and
  Autoprefixer are pure JS and need no install script.
- **Both builds must exit 0.** They run the guards — no `.env` in the bundle, no
  dev dependencies, CSRF filter enabled, no `http://` downgrade in `.htaccess`,
  category seeder present, `index.php` repointed at `../directory-app`, no
  external CDN or font host in the HTML. Building on the box means a guard
  failure surfaces here rather than as a broken production page.

### Copy the output into place

```bash
sudo cp -a /home/deploy/src/dist/site/.                       /var/www/marketing/
sudo cp -a /home/deploy/src/dist/listing/directory-app/.      /var/www/listing/directory-app/
sudo cp -a /home/deploy/src/dist/listing/docroot/.            /var/www/listing/public_html/
```

> **Do not point the vhosts at `dist/` and skip this copy.** The listing build
> begins by deleting its whole output directory, so the next rebuild would take
> every uploaded logo and every verification document with it. `dist/` is a build
> artifact; `/var/www` is the server.

### Re-apply ownership

`cp -a` run under `sudo` leaves everything owned by `root`, and no amount of
correct directory setup survives that:

```bash
sudo chown -R deploy:www-data /var/www/listing /var/www/marketing
sudo chmod -R 2775 /var/www/listing/directory-app/writable
sudo chmod 2770 /var/www/listing/directory-app/writable/verification
sudo find /var/www/listing/public_html/assets/listings -type d -exec chmod 2775 {} +
sudo chmod 755 /var/www/listing/directory-app/spark
```

Run this block again after **every** copy, extract or import in this document.

---

## Part 5 — System configuration

All of these live in the repo you just cloned, with their reasoning in comments.
Read them rather than trusting this summary.

```bash
S=/home/deploy/src/deploy

# PHP — BOTH SAPIs. The CLI one is what `php spark migrate` and the nightly
# mysqldump use; setting only fpm is a silent half-configuration.
for sapi in fpm cli; do
  sudo install -m 644 "$S/php/99-webscheduler.ini" "/etc/php/8.3/$sapi/conf.d/"
done

sudo install -m 644 "$S/mysql/99-webscheduler.cnf" /etc/mysql/mysql.conf.d/

# The LogFormat must be enabled BEFORE the vhosts — both reference the
# "cloudflare" format by name and configtest fails on an unknown one.
sudo install -m 644 "$S/apache/conf-available/webscheduler-logformat.conf" /etc/apache2/conf-available/
sudo a2enconf webscheduler-logformat

sudo install -m 644 "$S/apache/webscheduler.co.za.conf"         /etc/apache2/sites-available/
sudo install -m 644 "$S/apache/listing.webscheduler.co.za.conf" /etc/apache2/sites-available/

sudo install -m 644 -o root -g root "$S/cron/webscheduler" /etc/cron.d/webscheduler

sudo systemctl restart php8.3-fpm mysql
```

`/etc/cron.d/webscheduler` must be mode `644`, owned by root, **not executable**,
and must end with a newline. Violate any of those and cron ignores the file
entirely — no error, no log entry, no sweep, no backup.

**Do not `a2ensite` yet.** The vhosts reference certificate files that do not
exist until Part 7, and Apache will refuse to reload.

---

## Part 6 — MySQL

```bash
sudo mysql -e "CREATE DATABASE webscheduler_directory
               CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

DBPW=$(openssl rand -hex 24); echo "DBPW=$DBPW"      # write this down now
sudo mysql -e "CREATE USER 'webscheduler'@'localhost' IDENTIFIED BY '$DBPW';
               GRANT ALL PRIVILEGES ON webscheduler_directory.* TO 'webscheduler'@'localhost';
               FLUSH PRIVILEGES;"
```

`utf8mb4` is required, not cosmetic: the listings table carries a FULLTEXT index,
and dumping or creating as `latin1` mangles accented practice names in a way you
only discover at restore time.

Credentials for the nightly dump, so no password ever appears in the process
list where any user on the box could read it with `ps`:

```bash
sudo -u deploy bash -c "umask 077; printf '[client]\nuser=webscheduler\npassword=%s\n' '$DBPW' > /home/deploy/.my.cnf"
sudo chmod 600 /home/deploy/.my.cnf
sudo -u deploy mysql --defaults-file=/home/deploy/.my.cnf -e 'SELECT VERSION();'
```

---

## Part 7 — TLS, then enable the vhosts

Nothing serves HTTPS until this is done, and CodeIgniter's
`CI_ENVIRONMENT=production` turns on `Secure` cookies — over plain `http://`
every request 500s with `forInsecureCookie`. HTTPS first, then the app.

**Cloudflare → SSL/TLS → Origin Server → Create Certificate.** Defaults are fine
(RSA 2048, 15 years). Set the hostname list to:

```
webscheduler.co.za, *.webscheduler.co.za
```

> A **new** origin certificate, not Let's Encrypt. Certbot's HTTP-01 challenge
> cannot validate a hostname whose DNS still points at Hostinger, and this box
> has to serve working HTTPS *before* DNS moves. The origin cert is issued by
> Cloudflare on the spot and trusted by Cloudflare's edge, which is the only
> client that matters once you are Full (strict).

You get two blocks, and **the private key is shown once**. On the server:

```bash
sudo mkdir -p /etc/ssl/cloudflare
sudo tee /etc/ssl/cloudflare/webscheduler.co.za.pem >/dev/null <<'EOF'
-----BEGIN CERTIFICATE-----
…paste the Origin Certificate block…
-----END CERTIFICATE-----
EOF

sudo tee /etc/ssl/cloudflare/webscheduler.co.za.key >/dev/null <<'EOF'
-----BEGIN PRIVATE KEY-----
…paste the Private Key block…
-----END PRIVATE KEY-----
EOF

sudo chmod 644 /etc/ssl/cloudflare/webscheduler.co.za.pem
sudo chmod 600 /etc/ssl/cloudflare/webscheduler.co.za.key
```

The key is ~25 lines and the browser terminal truncates long pastes. **Verify
rather than assume** — a truncated key produces an Apache that starts fine and
fails only at handshake time:

```bash
sudo openssl x509 -noout -subject -enddate -in /etc/ssl/cloudflare/webscheduler.co.za.pem
sudo openssl rsa  -check -noout        -in /etc/ssl/cloudflare/webscheduler.co.za.key

# These two must print the SAME hash, or the cert and key are not a pair:
sudo openssl x509 -noout -modulus -in /etc/ssl/cloudflare/webscheduler.co.za.pem | openssl md5
sudo openssl rsa  -noout -modulus -in /etc/ssl/cloudflare/webscheduler.co.za.key | openssl md5
```

Then enable both sites:

```bash
sudo a2ensite webscheduler.co.za listing.webscheduler.co.za
sudo apache2ctl configtest         # must print: Syntax OK
sudo systemctl reload apache2
sudo apache2ctl -S                 # both vhosts, correct docroots
```

Finally, Cloudflare → SSL/TLS → Overview → **Full (strict)**.

---

## Part 8 — Secrets, pushed from Hostinger

Two files hold everything that must not be regenerated. Both come off the old
host as-is.

> `.env.production` in this repo is **not** a substitute — it carries placeholder
> values, not the live credentials. It is *not*, however, missing keys: its key
> set is identical to the live file's.

### Do not use FTP for this

Earlier versions of this runbook said to `lftp ftp.webscheduler.co.za` and `get`
both files. That cannot work, for three independent reasons:

- **`ftp.webscheduler.co.za` is behind Cloudflare.** It resolves to `172.67.x` /
  `104.21.x`, which proxy HTTP and HTTPS only. Nothing answers on port 21, so the
  connect black-holes and lftp sits at `[Connecting...]` until it times out. The
  real host is `194.164.74.32`.
- **The per-domain FTP accounts are jailed to their own `public_html`.** There is
  one per site, and neither secret file lives inside a docroot. Both sit above
  the jail and are simply not addressable. Confirm the exact usernames in hPanel
  before typing them — they are derived from the domain, and a transposed
  character is a silent auth failure.
- **That the files are outside the docroot is the point.** `.ws-contact.env` sits
  at the account home root precisely so nothing can serve it;
  `curl -sI https://webscheduler.co.za/.ws-contact.env` returning 404 is a
  release check in `DEPLOY.md`. An FTP path that reached it would be a bug.

### Push them instead

Both files live above every docroot:

```
~/.ws-contact.env
~/domains/listing.webscheduler.co.za/directory-app/.env
```

The new instance has a public IP and sshd, so push from **hPanel → Advanced →
Terminal** rather than pulling. This also keeps the password out of the process
list, which is the only thing the interactive `lftp` login ever bought:

```bash
scp ~/domains/listing.webscheduler.co.za/directory-app/.env \
    ~/.ws-contact.env  deploy@$IP:/home/deploy/
```

That needs `deploy` to accept an inbound SSH login, which a stock Lightsail image
will not do — `PasswordAuthentication` is off and `deploy` has no password
anyway. Grant it for the migration only. On Hostinger:

```bash
ssh-keygen -t ed25519 -N '' -f ~/.ssh/migrate
cat ~/.ssh/migrate.pub
```

On the new instance, paste that one line in:

```bash
sudo -u deploy -H mkdir -p ~deploy/.ssh && sudo -u deploy -H chmod 700 ~deploy/.ssh
sudo -u deploy -H nano ~deploy/.ssh/authorized_keys      # paste, save
sudo -u deploy -H chmod 600 ~deploy/.ssh/authorized_keys
```

Then `scp -i ~/.ssh/migrate …` from Hostinger. **Revoke it once Part 9 is done** —
empty `authorized_keys` on the new box, `rm ~/.ssh/migrate*` on Hostinger. The old
host keeping a working key into the new one outlives its usefulness the moment
the data has moved.

No terminal on the plan? **hPanel → Files → File Manager** browses the real home,
not a jail. Download each path and move it across yourself. These are small text
files — worst case, open each one and paste it into
`sudo -u deploy -H nano /home/deploy/dotenv` on the new box.

They arrive under their own names. Rename both, since the install step below
expects `dotenv` and `ws-contact.env`:

```bash
sudo -u deploy mv /home/deploy/.env          /home/deploy/dotenv
sudo -u deploy mv /home/deploy/.ws-contact.env /home/deploy/ws-contact.env
```

Check the transfer is byte-identical rather than hunting for individual keys:

```bash
# on Hostinger
sha256sum ~/domains/listing.webscheduler.co.za/directory-app/.env
# on the new instance
sudo sha256sum /home/deploy/dotenv
```

> Earlier versions of this runbook told you to check for `encryption.key`,
> `directory.adminPasswordHash` and `app.indexPage`, and to treat anything else
> as a failed transfer. **None of those three exist in the live file.** The app
> never uses the encrypter, admin auth runs on the deprecated-but-supported
> plaintext `directory.adminPassword`, and `app.indexPage` is left at its
> `App::$indexPage` default. Run as written, that check reports `MISSING` three
> times on a perfectly good copy.

Then edit `/home/deploy/dotenv` and change **only** these
four lines:

```
database.default.hostname = localhost
database.default.database = webscheduler_directory
database.default.username = webscheduler
database.default.password = 'THE-DBPW-FROM-PART-6'
```

Leave everything else exactly as it is. `app.baseURL` stays
`https://listing.webscheduler.co.za/` — the domain is not changing.

> Optional, and a behaviour change rather than a fix: adding `app.indexPage = ''`
> strips the `/index.php/` that CodeIgniter currently puts in generated URLs and
> redirects. Hostinger does the same thing today, so leaving it out reproduces
> production exactly. Set it only as a deliberate improvement, not as part of the
> migration.

### Install both, and mind the modes

```bash
sudo install -o deploy -g www-data -m 640 /home/deploy/dotenv \
     /var/www/listing/directory-app/.env
sudo install -o deploy -g www-data -m 640 /home/deploy/ws-contact.env \
     /var/www/.ws-contact.env
sudo rm /home/deploy/dotenv /home/deploy/ws-contact.env
```

Two corrections to older versions of these instructions, both of which produce
failures that look like application bugs:

- **`.env` must be `640 deploy:www-data`, not `600`.** CodeIgniter reads it at
  boot as the PHP-FPM user, which is `www-data`. At `600` owned by `deploy`,
  every page 500s with an empty database name while static files keep serving
  perfectly.
- **`.ws-contact.env` belongs at `/var/www/.ws-contact.env`**, not in
  `/home/deploy/`. `contact.php` resolves it as `__DIR__ . '/../.ws-contact.env'`
  — one level above its own docroot, which here is `/var/www/marketing`. It also
  has to be readable by `www-data` for the same reason as above. `/var/www` is
  not itself a DocumentRoot, so nothing serves it.

---

## Part 9 — Import the live data

Three things exist in exactly one place and are in no git repo: the database, the
uploaded logos and gallery photos, and the verification documents.

**On Hostinger** — hPanel → Advanced → Terminal:

```bash
mysqldump --default-character-set=utf8mb4 --single-transaction \
  --routines --triggers <cpuser>_directory > ~/directory.sql
cd ~/domains/listing.webscheduler.co.za
tar czf ~/uploads.tgz      public_html/assets/listings/
tar czf ~/verification.tgz directory-app/writable/verification/
ls -lh ~/directory.sql ~/uploads.tgz ~/verification.tgz
```

No terminal on the plan? Use hPanel → Databases → **phpMyAdmin** → Export →
Quick → SQL → Go for the database, and download the two folders in a file manager.

Move all three the same way as Part 8, and for the same reasons — FTP reaches
none of them, and a data connection that stalls halfway through a dump is worse
than one that never starts. Still from the **Hostinger terminal**, reusing the
`~/.ssh/migrate` key:

```bash
scp -i ~/.ssh/migrate ~/directory.sql ~/uploads.tgz ~/verification.tgz \
    deploy@$IP:/tmp/
```

Compare the sizes against what `ls -lh` printed on Hostinger before going any
further. A truncated `directory.sql` imports partially and then fails much later,
in ways that look like application bugs.

### The database

```bash
mysql -u webscheduler -p webscheduler_directory < /tmp/directory.sql
```

Verify the spatial column survived — the piece most likely to come back subtly
wrong, and the one nothing else will tell you about. Test the behaviour, not the
schema:

```bash
mysql -u webscheduler -p webscheduler_directory -e \
 "SELECT COUNT(*) FROM xs_directory_listing_points
   WHERE ST_Distance_Sphere(location, ST_GeomFromText('POINT(28.0473 -26.2041)')) < 50000;"
```

A non-zero count means proximity search works. You want a `SPATIAL KEY` on the
column; **do not expect `SRID 4326`** — the source database does not declare one,
and `ST_Distance_Sphere` reads the point regardless, as the migration that
created the table says in its own comments. Then the row counts:

```bash
mysql -u webscheduler -p webscheduler_directory -e \
  "SELECT COUNT(*) AS listings FROM xs_directory_listings;
   SELECT COUNT(*) AS categories FROM xs_directory_categories;"
```

Compare against the source rather than a remembered figure — count both sides
before the dump and after the import. (An older version of this runbook asserted
147; production actually carries 129, and the number moves as categories are
edited.) Seed only if the table is genuinely empty:

```bash
cd /var/www/listing/directory-app && sudo -u deploy php spark db:seed DirectoryCategoriesSeeder
```

### The uploaded files

```bash
sudo tar xzf /tmp/uploads.tgz -C /tmp
sudo cp -a /tmp/public_html/assets/listings/. /var/www/listing/public_html/assets/listings/

sudo tar xzf /tmp/verification.tgz -C /tmp
sudo cp -a /tmp/directory-app/writable/verification/. /var/www/listing/directory-app/writable/verification/

sudo rm -rf /tmp/public_html /tmp/directory-app /tmp/*.tgz /tmp/directory.sql
```

**Now re-run the ownership block from Part 4.** `tar` does not preserve the
setgid bits, and extracting under `sudo` leaves everything root-owned.

### Migrations

```bash
cd /var/www/listing/directory-app && sudo -u deploy php spark migrate --all
```

Should be a no-op — the dump already carries all 18. Run it as `deploy`, never as
root: spark writes to `writable/logs/`, and a root-owned log file shows up much
later as a log that silently stopped updating.

---

## Part 10 — Verify before DNS moves

### PHP can actually write, and actually read

```bash
sudo -u www-data touch /var/www/listing/directory-app/writable/cache/.probe && echo "cache OK"
sudo -u www-data touch /var/www/listing/public_html/assets/listings/.probe   && echo "uploads OK"
sudo -u www-data head -1 /var/www/listing/directory-app/.env >/dev/null      && echo "env OK"
sudo -u www-data head -1 /var/www/.ws-contact.env >/dev/null                 && echo "contact env OK"
sudo rm -f /var/www/listing/directory-app/writable/cache/.probe \
           /var/www/listing/public_html/assets/listings/.probe
```

All four must print. These are the failures that do not break the home page: a
dead `writable/cache` disables every rate limit while the site returns 200, and
an unreadable `.ws-contact.env` makes the contact form accept submissions and
deliver nothing.

### From your Mac, without touching public DNS

`--resolve` beats editing `/etc/hosts` — no sudo, no cleanup to forget. `-k` is
required because a Cloudflare origin certificate is deliberately not trusted by
anything except Cloudflare:

macOS ships zsh, which does **not** word-split an unquoted `$R` the way bash
does — a string here makes curl fail with `option --resolve ...: is unknown`.
Use an array, which behaves the same in both shells:

```bash
IP=<the-static-ip>
R=(--resolve "listing.webscheduler.co.za:443:$IP"
   --resolve "webscheduler.co.za:443:$IP"
   --resolve "www.webscheduler.co.za:443:$IP")

# Rewriting is on — the #1 failure mode
curl -k "${R[@]}" -sI https://listing.webscheduler.co.za/directory | head -1   # 200, NOT 404
curl -k "${R[@]}" -sI https://listing.webscheduler.co.za/about/    | head -1   # 301, slash stripped
curl -k "${R[@]}" -sI https://www.webscheduler.co.za/              | head -1   # 301 -> https://

# The app is healthy, not merely responding
curl -k "${R[@]}" -s "https://listing.webscheduler.co.za/health?token=<healthToken>" | jq .

# Security headers and CSRF. Use -s, not -sI: a HEAD request emits no Set-Cookie.
curl -k "${R[@]}" -s -o /dev/null -D- https://listing.webscheduler.co.za/add-listing \
  | grep -i set-cookie          # secure; HttpOnly; SameSite=Lax, plus csrf_cookie_name
curl -k "${R[@]}" -s -o /dev/null -w '%{http_code}\n' -X POST \
     -d "display_name=x" https://listing.webscheduler.co.za/add-listing     # 303
       # 303, not 403: with csrfProtection = 'cookie' CodeIgniter redirects a
       # tokenless POST rather than aborting. The POST is still rejected — that
       # is the property being tested. Anything that reaches the controller (a
       # 200, or a 302 to a success page) is the real failure.

# URLs use the real domain, not CHANGE-ME or localhost
curl -k "${R[@]}" -s https://listing.webscheduler.co.za/sitemap.xml | head -5
```

If `/directory` returns 404 but `/index.php/directory` returns 200, `.htaccess`
is missing or `AllowOverride` is not `All` — rewriting is off and **nothing else
is wrong**.

### In a browser

Point your Mac at the box with `/etc/hosts` (`$IP webscheduler.co.za
www.webscheduler.co.za listing.webscheduler.co.za`), flush with
`sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder`, and expect a
certificate warning — you are hitting the origin directly, and the origin cert is
only trusted by Cloudflare. Click through it.

1. Load the marketing site, submit the contact form → proves `.ws-contact.env`
2. Browse the directory, open a listing → proves the database and the photos
3. Search by location → proves the spatial import
4. Submit a new listing, confirm the verification email arrives → proves SMTP:465
5. Upload a logo → proves GD/WebP and the write permissions
6. Sign in at `/admin`, feature and unfeature something

**Remove the `/etc/hosts` lines when you are done.**

---

## Part 11 — Cutover

1. A day ahead: Cloudflare → DNS → set both records' TTL to **2 minutes**.
2. On the day, **re-export and re-import** the database and the uploads (Part 9).
   Your test copy is hours stale by now.
3. Cloudflare → DNS → point the `A` records for `webscheduler.co.za` and
   `listing.webscheduler.co.za` at `$IP`. **Keep them orange-clouded**, keep
   Full (strict).
4. Watch for an hour:
   ```bash
   curl -s https://listing.webscheduler.co.za/health
   sudo tail -f /var/www/listing/directory-app/writable/logs/log-*.php
   sudo tail -f /var/log/apache2/listing-error.log
   ```
5. Submit a real listing, end to end.
6. Restore the TTLs to Auto.

Leave `directory.webscheduler.co.za` alone. It is a leftover Cloudflare record
with nothing behind it that answers `526`. Don't probe it, don't "fix" it
mid-cutover — that mistake has already been made once.

**Leave Hostinger running, untouched, for at least a week.** Rollback is
repointing those two A records — which only works while it still exists, and only
while its database is not stale. Any listing created after cutover would have to
be replayed by hand.

---

## Part 12 — Afterwards

**Retire the old Lightsail instance.**

> ⚠️ **Identify it by instance name, never by IP.** The static IP
> `13.203.131.32` was detached from the old instance and reattached to this one,
> so that address now points at the box you just migrated *to*. Deleting "the
> instance at `13.203.131.32`" after cutover destroys production and drops the
> address both Cloudflare A records resolve to. Confirm in the Lightsail console
> which instance currently holds the static IP before deleting anything.

Delete the old instance itself. **Keep the static IP** — it is attached to the new
box and in active use. Only release a static IP that is genuinely unattached: an
unattached static IP is billed, and it is exactly the kind of charge nobody
notices for six months.

**Delete the dead GitHub secrets:** `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`,
`SSH_KNOWN_HOSTS`, and the older `FTP_*` set. Nothing reads them; the deploy
workflow no longer fires on push.

**Confirm the cron jobs fire.** The nightly dump is the backstop for everything
in Part 9:

```bash
ls -lh /home/deploy/backups/          # a db-YYYY-MM-DD.sql.gz appears after 02:15
grep CRON /var/log/syslog | tail
```

**Uptime monitor** — point it at `https://listing.webscheduler.co.za/health`. It
must be a **GET**, not HEAD: the endpoint returns 503 when a check fails, which
is the entire value of monitoring it rather than the home page.

**Firewall, optionally** — narrow 80/443 to Cloudflare's published ranges so the
origin IP cannot be used to bypass the WAF. Do this *after* cutover, or you lock
yourself out of your own testing.

---

## Releasing changes after this

No script, no workflow. Four commands in the browser terminal:

```bash
sudo -u deploy -H bash -lc 'cd ~/src && git pull \
  && composer install --no-dev --optimize-autoloader \
  && npm ci --ignore-scripts && npm run list:build && npm run site:build'

sudo cp -a /home/deploy/src/dist/site/.                       /var/www/marketing/
sudo cp -a /home/deploy/src/dist/listing/directory-app/.      /var/www/listing/directory-app/
sudo cp -a /home/deploy/src/dist/listing/docroot/.            /var/www/listing/public_html/
```

Then, every time:

```bash
# the ownership block from Part 4, then:
cd /var/www/listing/directory-app && sudo -u deploy php spark migrate --all
sudo systemctl reload php8.3-fpm
```

And purge the Cloudflare cache. Asset filenames are not content-hashed, so a
release with an unpurged cache looks exactly like a release that did nothing.

`cp -a` copies, it does not mirror. That is deliberate — it is why `.env`,
`writable/` and uploaded images survive a listing release. The cost is that
**files deleted from the repo linger on the server** and must be removed by hand.

> **`/var/www/marketing/developer/` used to depend on that.** The API portal was
> built from `niloc95/xscheduler_ci4` and uploaded straight into the old
> Hostinger docroot, so a marketing release left it untouched. That stopped
> working the moment the apex moved here — the new box builds the marketing site
> from this repo, which had never contained the portal, and `/developer/` began
> 404ing while all eight pages went on linking to it. It is vendored at
> `marketing-site/developer/` now and emitted by `site:build`, so a release
> overwrites it like any other page. If the app repo's
> `.github/workflows/developer-docs.yml` still runs, retire it or point it here —
> `openapi.yaml` is generated from the API, and two copies will drift.

---

## Things that will bite

**`AllowOverride All` is load-bearing.** Ubuntu ships `AllowOverride None` for
`/var/www/`. The listing vhost sets `All` for its own docroot, so this is normally
fine — but if that line is ever "tidied up", the home page still loads and every
other URL 404s, which looks like an application bug and is not.

**`php_admin_flag` does not exist under PHP-FPM.** It is a mod_php directive and
Apache refuses to start with "Invalid command". Disarm PHP in a directory with
`SetHandler None` instead — which is what the listing vhost does for the uploads
folder.

**Don't add `mod_remoteip`.** It rewrites `REMOTE_ADDR`, which is precisely what
`App\Config\App::$proxyIPs` inspects before trusting `CF-Connecting-IP`. The
throttles keep working afterwards, so nothing tells you the trust path changed.
The `cloudflare` LogFormat already puts real client IPs in the access log.

**Outbound port 25 is blocked by AWS** on new accounts. Irrelevant here — mail
goes out over 465 — but it is the trap if anyone later reaches for `sendmail`.

**Migrations are forward-only.** `migrate:rollback` against production stays
banned. Code rolls back by rebuilding an older commit; schema is fixed forward
with a new migration.
