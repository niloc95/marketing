<?php

namespace App\Services;

use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use Config\Directory as DirectoryConfig;
use FilesystemIterator;
use Throwable;

/**
 * The numbers behind the admin status page.
 *
 * Read-only and defensive throughout: this page's whole job is to be readable
 * when something else is broken, so every figure that depends on the database
 * or the filesystem degrades to "unavailable" rather than throwing and taking
 * the diagnosis down with the fault.
 */
class SystemStatusService
{
    private DirectoryListingModel $listings;
    private DirectoryListingPhotoModel $photos;
    private DirectoryConfig $config;

    public function __construct()
    {
        $this->listings = new DirectoryListingModel();
        $this->photos   = new DirectoryListingPhotoModel();
        $this->config   = config('Directory');
    }

    /**
     * Listings still awaiting verification whose link has already expired.
     *
     * The most diagnostic number on the page. These owners cannot verify — the
     * token is dead and there is no self-serve way to request another — so a
     * non-zero figure that keeps climbing is the fingerprint of a mail outage,
     * past or present. Uses the stored verify_expires rather than recomputing
     * from created_at, because verifyTtl can change after rows are written.
     */
    public function stalePendingCount(): ?int
    {
        try {
            return $this->listings
                ->where('status', 'pending')
                ->where('verify_expires <', date('Y-m-d H:i:s'))
                ->countAllResults();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Uploaded files on disk.
     *
     * Two non-recursive passes, not one recursive walk: the gallery directory
     * lives *inside* the logo directory, so recursion counts every gallery
     * image twice — once as itself and once as a logo.
     *
     * @return array{logos:array{count:int,bytes:int},gallery:array{count:int,bytes:int},orphans:int|null}
     */
    public function storage(): array
    {
        $base    = rtrim(FCPATH, '/') . '/assets/listings';
        $logos   = $this->scanDir($base);
        $gallery = $this->scanDir($base . '/gallery');

        return [
            'logos'   => ['count' => count($logos), 'bytes' => array_sum($logos)],
            'gallery' => ['count' => count($gallery), 'bytes' => array_sum($gallery)],
            'orphans' => $this->countOrphans(array_keys($logos), array_keys($gallery)),
        ];
    }

    /**
     * Files in one directory, non-recursively.
     *
     * @return array<string,int> FCPATH-relative path => bytes
     */
    private function scanDir(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $root = rtrim(FCPATH, '/');
        $out  = [];

        try {
            foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $file) {
                if (! $file->isFile()) {
                    continue; // the gallery subdirectory, counted separately
                }
                $out[ltrim(str_replace($root, '', $file->getPathname()), '/')] = $file->getSize();
            }
        } catch (Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * Files on disk that nothing in the database points at.
     *
     * Should be zero. purge() deletes files before the row cascade precisely so
     * these cannot accumulate, so anything here means a write failed partway or
     * predates that fix. Soft-deleted listings count as referenced — their
     * files must survive for restore to work.
     */
    private function countOrphans(array $logoPaths, array $galleryPaths): ?int
    {
        try {
            $referenced = [];

            foreach ($this->listings->withDeleted()->select('logo_path')->findAll() as $row) {
                if (! empty($row['logo_path'])) {
                    $referenced[ltrim((string) $row['logo_path'], '/')] = true;
                }
            }
            foreach ($this->photos->select('path')->findAll() as $row) {
                if (! empty($row['path'])) {
                    $referenced[ltrim((string) $row['path'], '/')] = true;
                }
            }

            $onDisk = array_merge($logoPaths, $galleryPaths);

            return count(array_filter($onDisk, static fn ($p) => ! isset($referenced[$p])));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Total size of this database, or null if the host won't say.
     *
     * Shared hosts commonly restrict information_schema, in which case the sum
     * comes back NULL. That is "unavailable", not zero — reporting 0 bytes for
     * a live database would be worse than admitting we can't tell.
     */
    public function databaseBytes(): ?int
    {
        try {
            $row = db_connect()->query(
                'SELECT SUM(data_length + index_length) AS bytes
                 FROM information_schema.TABLES WHERE table_schema = DATABASE()'
            )->getRowArray();

            return isset($row['bytes']) && $row['bytes'] !== null ? (int) $row['bytes'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Configuration an operator might need to check, with the traps made
     * explicit.
     *
     * Secrets are reported as configured/not — never their values. healthToken
     * is listed in Config\Exceptions::$sensitiveDataInTrace precisely because
     * it is a credential; printing it here would undo that.
     *
     * @return list<array{label:string,value:string,env:bool,warn:bool}>
     */
    public function configSummary(): array
    {
        $c = $this->config;

        $analytics    = $c->analyticsId();
        $hash         = $c->adminPasswordHash();
        $plaintext    = $c->adminPassword();
        $healthToken  = $c->healthToken();
        $payfastReady = $c->payfastMerchantId() !== '' && $c->payfastMerchantKey() !== '';

        return [
            ['label' => 'Site name', 'value' => $c->siteName(), 'env' => true, 'warn' => false],
            ['label' => 'Admin notification email', 'value' => $c->adminEmail() ?: 'not set', 'env' => true, 'warn' => $c->adminEmail() === ''],

            // Empty here does not mean "unset" — Config\Directory treats a
            // present-but-empty env value as a deliberate switch-off, so the
            // difference is worth spelling out rather than showing a blank.
            ['label' => 'Analytics ID', 'value' => $analytics === '' ? 'off (disabled)' : $analytics, 'env' => true, 'warn' => false],

            // The deprecated path: a hash beats plaintext, and running on
            // plaintext logs a warning on every single login.
            [
                'label' => 'Admin password',
                'value' => $hash !== '' ? 'hashed (correct)' : ($plaintext !== '' ? 'PLAINTEXT — deprecated, run spark directory:adminhash' : 'not set'),
                'env'   => true,
                'warn'  => $hash === '',
            ],
            [
                'label' => 'Health token',
                'value' => $healthToken !== '' ? 'configured' : 'not set — /health detail is disabled',
                'env'   => true,
                'warn'  => $healthToken === '',
            ],
            ['label' => 'Map tile host', 'value' => (string) parse_url($c->mapTileUrl(), PHP_URL_HOST), 'env' => true, 'warn' => false],

            // PayFast. Credentials are reported as present or not, never shown,
            // for the same reason as the two above. Sandbox is called out as a
            // warning in its own right: a production site quietly running
            // against the sandbox takes no money at all, and nothing else in the
            // system would ever complain about it.
            [
                'label' => 'PayFast credentials',
                'value' => $payfastReady ? 'configured' : 'not set — the Verified Business badge is switched off',
                'env'   => true,
                'warn'  => ! $payfastReady,
            ],
            [
                'label' => 'PayFast passphrase',
                'value' => $c->payfastPassphrase() !== '' ? 'configured' : 'not set — notifications cannot be authenticated',
                'env'   => true,
                'warn'  => $payfastReady && $c->payfastPassphrase() === '',
            ],
            [
                'label' => 'PayFast mode',
                'value' => $c->payfastSandbox() ? 'SANDBOX — no real payments' : 'live',
                'env'   => true,
                'warn'  => $c->payfastSandbox(),
            ],
            ['label' => 'Verified Business price', 'value' => 'R' . $c->verifiedMonthlyAmount() . ' / month', 'env' => true, 'warn' => false],

            // These three have no getters, so .env cannot override them — a
            // genuinely non-obvious split worth surfacing.
            ['label' => 'Verification link TTL', 'value' => $this->duration($c->verifyTtl), 'env' => false, 'warn' => false],
            ['label' => 'Manage link TTL', 'value' => $this->duration($c->manageTtl), 'env' => false, 'warn' => false],
            ['label' => 'Landing page minimum profiles', 'value' => (string) $c->landingMinListings, 'env' => false, 'warn' => false],
        ];
    }

    public function duration(int $seconds): string
    {
        if ($seconds % 86400 === 0) {
            return ($seconds / 86400) . ' day' . ($seconds === 86400 ? '' : 's');
        }
        if ($seconds % 3600 === 0) {
            return ($seconds / 3600) . ' hour' . ($seconds === 3600 ? '' : 's');
        }

        return ($seconds / 60) . ' minutes';
    }

    public function bytes(?int $n): string
    {
        if ($n === null) {
            return 'unavailable';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;
        $v     = (float) $n;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
