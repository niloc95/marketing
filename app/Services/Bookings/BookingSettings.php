<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingSettingModel;
use Config\Bookings;

/**
 * One business's booking settings (docs/bookings/settings.md). The only writer
 * of xs_booking_settings.
 *
 * Bookings kept one global key value table; here every business has its own
 * rows, and a key not in Config\Bookings::$settingDefaults can be neither read
 * nor written, so a typo fails loudly instead of storing a setting nothing
 * reads. Anything the profile already holds (name, address, timezone, hours)
 * is read from the profile, never copied here.
 */
class BookingSettings
{
    /** @var array<string,string>|null */
    private ?array $stored = null;

    private function __construct(private int $listingId, private Bookings $config)
    {
    }

    public static function for(int $listingId): self
    {
        return new self($listingId, config('Bookings'));
    }

    public function get(string $key): string
    {
        $this->assertKnown($key);
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : $this->config->settingDefaults[$key];
    }

    /** A whole number setting (see Config\Bookings::$settingRanges). */
    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return array<string,string> every known key with its current value */
    public function all(): array
    {
        $values = [];
        foreach (array_keys($this->config->settingDefaults) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Save several keys. Unknown keys and values outside a key's choices are
     * refused before anything is written.
     *
     * @param array<string,string> $values
     *
     * @return array{errors:array<string,string>,changed:array<string,array{0:string,1:string}>}
     */
    public function save(array $values, ?int $accountId): array
    {
        $errors  = [];
        $changed = [];
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->config->settingDefaults)) {
                $errors[$key] = 'Unknown setting.';

                continue;
            }
            $value   = trim((string) $value);
            $choices = $this->config->settingChoices[$key] ?? null;
            if ($choices !== null && ! in_array($value, $choices, true)) {
                $errors[$key] = 'Choose one of the options.';

                continue;
            }
            $range = $this->config->settingRanges[$key] ?? null;
            if ($range !== null) {
                $int = filter_var($value, FILTER_VALIDATE_INT);
                if ($int === false || $int < $range[0] || $int > $range[1]) {
                    $errors[$key] = 'Use a whole number from ' . $range[0] . ' to ' . $range[1] . '.';

                    continue;
                }
                $value = (string) $int;
            }
            $max = $this->config->settingMaxLength[$key] ?? Bookings::CUSTOM_FIELD_TITLE_MAX;
            if ($choices === null && $range === null && mb_strlen($value) > $max) {
                $errors[$key] = 'Keep it to ' . $max . ' characters.';

                continue;
            }
            $old = $this->get($key);
            if ($old !== $value) {
                $changed[$key] = [$old, $value];
            }
        }
        // We need a way to reach every customer who books online.
        $after = fn (string $k): string => array_key_exists($k, $values) ? trim((string) $values[$k]) : $this->get($k);
        $asked = static fn (string $stem): bool => $after('booking.' . $stem . '_display') === '1' && $after('booking.' . $stem . '_required') === '1';
        if (! $asked('email') && ! $asked('phone')) {
            $errors['booking.email_required'] = 'Ask for an email or a phone number, and make it required, so you can reach every customer.';
        }
        foreach (['legal.terms_url', 'legal.privacy_url'] as $key) {
            if (array_key_exists($key, $values) && $after($key) !== '' && safe_external_url($after($key)) === '') {
                $errors[$key] = 'Use a full link that starts with https://';
            }
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'changed' => []];
        }

        foreach ($changed as $key => [, $new]) {
            $model    = BookingSettingModel::forBusiness($this->listingId);
            $existing = $model->where('name', $key)->first();
            if (is_array($existing)) {
                BookingSettingModel::forBusiness($this->listingId)->update((int) $existing['id'], ['value' => $new, 'updated_by' => $accountId]);
            } else {
                BookingSettingModel::forBusiness($this->listingId)->insert(['name' => $key, 'value' => $new, 'updated_by' => $accountId]);
            }
        }
        $this->stored = null;

        return ['errors' => [], 'changed' => $changed];
    }

    /** @return array<string,string> */
    private function stored(): array
    {
        if ($this->stored === null) {
            $rows         = BookingSettingModel::forBusiness($this->listingId)->findAll();
            $this->stored = array_column($rows, 'value', 'name');
        }

        return $this->stored;
    }

    private function assertKnown(string $key): void
    {
        if (! array_key_exists($key, $this->config->settingDefaults)) {
            throw new \InvalidArgumentException('Unknown booking setting ' . $key);
        }
    }
}
