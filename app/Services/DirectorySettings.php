<?php

namespace App\Services;

use App\Models\DirectorySettingModel;
use Config\Directory as DirectoryConfig;

/**
 * Operational settings that an operator can change from the admin panel,
 * layered over the ones baked into config and .env.
 *
 * Three sources, in order: the database (set from the admin page), then .env,
 * then the committed default in Config\Directory. Database wins because that is
 * what makes "change it in one place" true — a leftover .env line that silently
 * overrode an admin edit would be indistinguishable from a bug. The settings
 * page reports which source the live value came from for exactly that reason.
 *
 * Deliberately NOT folded into Config\Directory. Config objects are built early
 * and in contexts with no database — CLI commands, migrations, the test
 * bootstrap — so opening a connection from one is a category error. Config
 * keeps owning the default, the .env override and the currency normalising;
 * this layers storage on top and reuses that normaliser rather than growing a
 * second copy of it.
 *
 * Nothing secret belongs here. See the migration for why.
 */
class DirectorySettings
{
    /** One cache entry for the whole table — see the model's allSettings(). */
    private const CACHE_KEY = 'directory_settings_map';

    /**
     * A day. The number barely matters because every write drops the entry;
     * it is a backstop against a cache that outlives a database restored from
     * a backup, not a freshness policy.
     */
    private const CACHE_TTL = 86400;

    /** Sanity ceiling. A mistyped extra digit is far likelier than a real one. */
    public const MAX_PRICE = 100000.0;

    public const SOURCE_DATABASE    = 'database';
    public const SOURCE_ENVIRONMENT = 'environment';
    public const SOURCE_DEFAULT     = 'default';

    private DirectoryConfig $config;
    private DirectorySettingModel $model;

    /** Read once per request even when the cache is unavailable. */
    private ?array $loaded = null;

    public function __construct(?DirectoryConfig $config = null)
    {
        $this->config = $config ?? config('Directory');
        $this->model  = new DirectorySettingModel();
    }

    // ------------------------------------------------------------------ reads

    public function badgePrice(): string
    {
        $stored = $this->stored(DirectorySettingModel::BADGE_PRICE);

        // Round-trips through the config getter even when the value came from
        // the database, so a stored "29,99" normalises identically to one typed
        // into .env. One normaliser, one set of rules, one place to fix.
        return $stored === null
            ? $this->config->verifiedMonthlyAmount()
            : $this->config->normaliseAmount($stored);
    }

    public function badgeEnabled(): bool
    {
        $stored = $this->stored(DirectorySettingModel::BADGE_ENABLED);

        return $stored === null
            ? $this->config->verifiedBadgeEnabled()
            : $this->isTruthy($stored);
    }

    public function internationalPrice(): string
    {
        $stored = $this->stored(DirectorySettingModel::INTERNATIONAL_PRICE);

        return $stored === null
            ? $this->config->internationalMonthlyAmount()
            : $this->config->normaliseAmount($stored);
    }

    public function internationalEnabled(): bool
    {
        $stored = $this->stored(DirectorySettingModel::INTERNATIONAL_ENABLED);

        return $stored === null
            ? $this->config->internationalListingEnabled()
            : $this->isTruthy($stored);
    }

    /**
     * Where the live price is actually coming from.
     *
     * Rendered on the settings page because "I changed it and nothing happened"
     * is otherwise unanswerable without a shell — the usual cause being an .env
     * line nobody remembers setting.
     */
    public function priceSource(): string
    {
        if ($this->stored(DirectorySettingModel::BADGE_PRICE) !== null) {
            return self::SOURCE_DATABASE;
        }

        return env('directory.verifiedMonthlyAmount') !== null
            ? self::SOURCE_ENVIRONMENT
            : self::SOURCE_DEFAULT;
    }

    public function enabledSource(): string
    {
        if ($this->stored(DirectorySettingModel::BADGE_ENABLED) !== null) {
            return self::SOURCE_DATABASE;
        }

        return env('directory.verifiedBadgeEnabled') !== null
            ? self::SOURCE_ENVIRONMENT
            : self::SOURCE_DEFAULT;
    }

    public function internationalPriceSource(): string
    {
        if ($this->stored(DirectorySettingModel::INTERNATIONAL_PRICE) !== null) {
            return self::SOURCE_DATABASE;
        }

        return env('directory.internationalMonthlyAmount') !== null
            ? self::SOURCE_ENVIRONMENT
            : self::SOURCE_DEFAULT;
    }

    public function internationalEnabledSource(): string
    {
        if ($this->stored(DirectorySettingModel::INTERNATIONAL_ENABLED) !== null) {
            return self::SOURCE_DATABASE;
        }

        return env('directory.internationalListingEnabled') !== null
            ? self::SOURCE_ENVIRONMENT
            : self::SOURCE_DEFAULT;
    }

    /**
     * Who last changed a setting, and when — or null if it has never been set
     * from the admin panel.
     *
     * @return array{by:?string,at:?string}|null
     */
    public function lastChange(string $name): ?array
    {
        $row = $this->map()[$name] ?? null;
        if ($row === null) {
            return null;
        }

        return ['by' => $row['updated_by'] ?? null, 'at' => $row['updated_at'] ?? null];
    }

    // ----------------------------------------------------------------- writes

    /**
     * Validate and store a batch of settings.
     *
     * Validates everything before writing anything: a form that saved the valid
     * half of its fields and reported an error would leave the operator unsure
     * what actually took effect.
     *
     * @param array<string,mixed> $input raw POST
     *
     * @return array{ok:bool,errors:array<string,string>,message:string}
     */
    public function save(array $input, string $by): array
    {
        $errors = [];

        // Each plan is one section of the form, and a section is saved only if
        // its price field was posted.
        //
        // That test is doing real work, and it is the price field specifically.
        // An unticked checkbox sends nothing, so "absent" and "off" look
        // identical in a POST — the only thing that distinguishes them is
        // whether the section was on screen at all, and the price input is
        // always present when it was. Keying off the checkbox instead would
        // mean a form that omitted a section silently switched that feature
        // off; keying off nothing at all would mean this method could not be
        // called with a subset of the settings, which is how every existing
        // caller uses it.
        $writes = [];

        if (array_key_exists('badge_price', $input)) {
            $price = $this->validatePrice($input, 'badge_price', $errors);

            $writes[DirectorySettingModel::BADGE_PRICE]   = static fn (): string => (string) $price;
            $writes[DirectorySettingModel::BADGE_ENABLED] = static fn (): string => empty($input['badge_enabled']) ? '0' : '1';
        }

        if (array_key_exists('international_price', $input)) {
            $internationalPrice = $this->validatePrice($input, 'international_price', $errors);

            $writes[DirectorySettingModel::INTERNATIONAL_PRICE]   = static fn (): string => (string) $internationalPrice;
            $writes[DirectorySettingModel::INTERNATIONAL_ENABLED] = static fn (): string => empty($input['international_enabled']) ? '0' : '1';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => 'Nothing was saved — please check the highlighted field.'];
        }

        if ($writes === []) {
            return ['ok' => false, 'errors' => [], 'message' => 'Nothing to save.'];
        }

        try {
            foreach ($writes as $name => $value) {
                $this->model->put($name, $value(), $by);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Could not save directory settings: ' . $e->getMessage());

            return ['ok' => false, 'errors' => [], 'message' => 'Could not save those settings. Please try again.'];
        }

        $this->forget();

        return ['ok' => true, 'errors' => [], 'message' => 'Settings saved. A new price applies to new subscriptions only.'];
    }

    /**
     * One price field, validated.
     *
     * Shared by the badge and the International Listing plan so the two can
     * never drift into different ideas of what counts as a price — which is
     * exactly what a copied-and-edited second block would do the first time
     * one of them gained a rule.
     *
     * @param array<string,mixed>   $input
     * @param array<string,string> &$errors collected by field name
     */
    private function validatePrice(array $input, string $field, array &$errors): ?string
    {
        $raw = trim((string) ($input[$field] ?? ''));
        if ($raw === '') {
            $errors[$field] = 'Enter a monthly price.';

            return null;
        }

        $price = $this->config->normaliseAmount($raw);

        // normaliseAmount() always returns a formatted number, so a
        // non-numeric input arrives here as "0.00" rather than as a failure.
        // Check the raw string actually contained a digit before trusting it —
        // otherwise "abc" would quietly become free.
        if (! preg_match('/\d/', $raw) || (float) $price <= 0) {
            $errors[$field] = 'The price must be a number greater than zero.';
        } elseif ((float) $price > self::MAX_PRICE) {
            $errors[$field] = 'That price looks like a typo — the maximum is R' . number_format(self::MAX_PRICE, 2) . '.';
        }

        return $price;
    }

    /** Drop the cached map. Called on every write, and by tests. */
    public function forget(): void
    {
        $this->loaded = null;

        try {
            cache()->delete(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // A cache we cannot clear is a cache we should not have trusted;
            // the per-request copy above is already gone.
            log_message('warning', 'Could not clear the settings cache: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------- internals

    /** The stored value for a name, or null when it has never been set. */
    private function stored(string $name): ?string
    {
        $value = $this->map()[$name]['value'] ?? null;

        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }

    /**
     * The settings table, cached.
     *
     * The price renders on the signup form, the manage dashboard, /verified and
     * the FAQ, so an uncached read here is a query on most page loads.
     *
     * Every failure returns an empty map rather than propagating. That is the
     * whole point: with no settings rows the callers fall through to .env and
     * then to the committed default, so a database or cache outage costs a
     * possibly-stale price rather than a 500 on every public page. Same posture
     * SystemStatusService takes.
     *
     * @return array<string,array{value:?string,updated_by:?string,updated_at:?string}>
     */
    private function map(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            $cached = cache()->get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $this->loaded = $cached;
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Settings cache unavailable, reading through: ' . $e->getMessage());
        }

        try {
            $map = $this->model->allSettings();
        } catch (\Throwable $e) {
            log_message('error', 'Could not read directory settings, falling back to config: ' . $e->getMessage());

            return $this->loaded = [];
        }

        try {
            cache()->save(self::CACHE_KEY, $map, self::CACHE_TTL);
        } catch (\Throwable $e) {
            log_message('warning', 'Could not cache directory settings: ' . $e->getMessage());
        }

        return $this->loaded = $map;
    }

    private function isTruthy(string $value): bool
    {
        return ! in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off', ''], true);
    }
}
