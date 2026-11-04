<?php

namespace App\Libraries;

use App\Models\DirectoryCategoryModel;

/**
 * A regulated professional's registration number, ORCID and ResearchGate
 * link: the rules for each person on a team (owner edit and admin edit), and
 * the one reading the team card and the JSON-LD render through. Per person
 * since 4 Nov 2026; the profile level columns it used to fill are unused.
 *
 * The regulator is never stored. Config\Registrations derives it from the
 * category, and a category with none gets no fields and shows nothing, even
 * when an old number is still on the row. So a doctor who moves category
 * keeps the number for when they move back, and an unregulated business can
 * never be made to display a "registration".
 *
 * Self declared: nothing here checks a number against a register. The profile
 * links to the regulator's own lookup so the visitor can, and says "checked"
 * only once an admin has set reg_checked_at, which columns() clears the
 * moment the owner changes the number.
 */
final class ProfessionalRegistration
{
    /**
     * Posted with the section. Absent = leave every column alone, the
     * ServiceArea convention: the fieldset is disabled for an unregulated
     * category, so it posts nothing and a stored number survives.
     */
    public const MARKER = 'registration_present';

    /** What any register's number may contain once normalised. */
    private const GENERIC = '#^[A-Z0-9][A-Z0-9 ./-]{1,38}$#';

    private const ORCID = '/^(\d{4})-?(\d{4})-?(\d{4})-?(\d{3}[\dX])$/';

    /**
     * The regulator for a category, or null when it is not a regulated one.
     *
     * @return array{key:string,short:string,name:string,pattern:?string,example:string,register:string,research:bool}|null
     */
    public static function regulatorFor(?string $slug, ?string $group): ?array
    {
        $config = config('Registrations');
        $key    = $slug !== null && array_key_exists($slug, $config->bySlug)
            ? $config->bySlug[$slug]
            : ($config->byGroup[(string) $group] ?? null);

        return $key !== null && isset($config->regulators[$key])
            ? ['key' => $key] + $config->regulators[$key]
            : null;
    }

    /** As regulatorFor(), for a submitted category id. One query. */
    public static function regulatorForCategoryId(?int $id): ?array
    {
        if ($id === null || $id < 1) {
            return null;
        }
        $row = (new DirectoryCategoryModel())->select('slug, group_name')->find($id);

        return is_array($row) ? self::regulatorFor($row['slug'] ?? null, $row['group_name'] ?? null) : null;
    }

    /** Upper cased and single spaced, '' when empty. Format is judged by problems(). */
    public static function normaliseNumber(mixed $raw): string
    {
        return is_string($raw) ? strtoupper(trim((string) preg_replace('/\s+/', ' ', $raw))) : '';
    }

    /**
     * An ORCID iD as 0000-0000-0000-000X, '' when empty, null when it is not
     * one. Takes the bare iD or a pasted orcid.org link, and checks the last
     * character (ISO 7064 MOD 11-2), so a typo is caught here rather than
     * published as a link to somebody else.
     */
    public static function normaliseOrcid(mixed $raw): ?string
    {
        $value = is_string($raw) ? strtoupper(trim($raw)) : '';
        if ($value === '') {
            return '';
        }
        $value = (string) preg_replace('#^(HTTPS?://)?(WWW\.)?ORCID\.ORG/#', '', $value);
        if (preg_match(self::ORCID, $value, $m) !== 1) {
            return null;
        }
        $digits = $m[1] . $m[2] . $m[3] . substr($m[4], 0, 3);
        $total  = 0;
        foreach (str_split($digits) as $d) {
            $total = ($total + (int) $d) * 2;
        }
        $check = (12 - $total % 11) % 11;
        if (substr($m[4], 3) !== ($check === 10 ? 'X' : (string) $check)) {
            return null;
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3] . '-' . $m[4];
    }

    /**
     * A ResearchGate profile link, '' when empty, null when it is not one.
     * Only researchgate.net/profile/… gets through, rebuilt from its parts, so
     * the "ResearchGate" label cannot be spent on any other outbound link.
     */
    public static function normaliseResearchGate(mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) !== 1) {
            $value = 'https://' . $value;
        }
        $parts = parse_url($value);
        $host  = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, ['researchgate.net', 'www.researchgate.net'], true)
            || preg_match('#^/profile/([A-Za-z0-9._%-]{2,120})/?$#', (string) ($parts['path'] ?? ''), $m) !== 1) {
            return null;
        }

        return 'https://www.researchgate.net/profile/' . $m[1];
    }

    /**
     * Why the posted section cannot be saved, keyed by field. Empty when the
     * section was not posted, or when it is fine.
     *
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public static function problems(array $input, ?array $regulator): array
    {
        if (! array_key_exists(self::MARKER, $input)) {
            return [];
        }

        $errors = [];
        $number = self::normaliseNumber($input['reg_number'] ?? '');
        if ($number !== '' && $regulator !== null) {
            $pattern = $regulator['pattern'] ?? null;
            if (preg_match(self::GENERIC, $number) !== 1 || ($pattern !== null && preg_match($pattern, $number) !== 1)) {
                $errors['reg_number'] = sprintf(
                    'Please enter your %s registration number as it appears on the register, for example %s.',
                    $regulator['short'],
                    $regulator['example']
                );
            }
        }
        if (self::normaliseOrcid($input['orcid'] ?? '') === null) {
            $errors['orcid'] = 'Please enter your 16 digit ORCID iD, for example 0000-0002-1825-0097.';
        }
        $rg = self::normaliseResearchGate($input['researchgate_url'] ?? '');
        if ($rg === null) {
            $errors['researchgate_url'] = 'Please enter the link to your ResearchGate profile, for example researchgate.net/profile/Your-Name.';
        } elseif (strlen($rg) > 255) {
            $errors['researchgate_url'] = 'Please keep the ResearchGate link under 256 characters.';
        }

        return $errors;
    }

    /**
     * The columns to write, or [] when the form did not carry the section.
     * Assumes problems() has already been checked.
     *
     * $stored is the current row on an edit, [] on a new listing.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    public static function columns(array $input, array $stored = []): array
    {
        if (! array_key_exists(self::MARKER, $input)) {
            return [];
        }

        $number = self::normaliseNumber($input['reg_number'] ?? '');
        $data   = [
            'reg_number'           => $number !== '' ? $number : null,
            'orcid'                => self::normaliseOrcid($input['orcid'] ?? '') ?: null,
            'researchgate_url'     => self::normaliseResearchGate($input['researchgate_url'] ?? '') ?: null,
            'registration_visible' => (string) ($input['registration_visible'] ?? '') === '1' ? 1 : 0,
        ];
        // An admin checked the old number, not this one.
        if ($number !== (string) ($stored['reg_number'] ?? '')) {
            $data['reg_checked_at'] = null;
        }

        return $data;
    }

    /**
     * What a person's card shows, or null when it shows nothing: the
     * category is not regulated, or there is nothing filled in. Per person
     * since 4 Nov 2026 (owner): $person is a team row, $regulator the
     * listing's (regulatorFor() on its category). The team is a Verified
     * feature, so its gate is the team's own (getProfile()).
     *
     * @param array<string,mixed> $person
     * @return array{short:string,name:string,number:string,register:string,checked:bool,orcid:?string,researchgate:?string}|null
     */
    public static function forPerson(array $person, ?array $regulator): ?array
    {
        if ($regulator === null) {
            return null;
        }

        $number = self::normaliseNumber($person['reg_number'] ?? '');
        $orcid  = $regulator['research'] ? self::normaliseOrcid($person['orcid'] ?? '') : '';
        $rg     = $regulator['research'] ? self::normaliseResearchGate($person['researchgate_url'] ?? '') : '';
        if ($number === '' && ! $orcid && ! $rg) {
            return null;
        }

        return [
            'short'        => $regulator['short'],
            'name'         => $regulator['name'],
            'number'       => $number,
            'register'     => $regulator['register'],
            'checked'      => $number !== '' && ! empty($person['reg_checked_at']),
            'orcid'        => $orcid ? 'https://orcid.org/' . $orcid : null,
            'researchgate' => $rg ?: null,
        ];
    }

    /**
     * A team row's registration columns from its posted fields, or [] when
     * the row did not carry them (an unregulated category posts none, so a
     * stored number survives). Errors are keyed by field, for the caller to
     * prefix with the row.
     *
     * The admin's check is kept while the number is unchanged; $adminCheck
     * (admin form only: null = not posted) sets or clears it.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $stored
     * @return array{columns:array<string,mixed>,errors:array<string,string>}
     */
    public static function personColumns(array $row, ?array $stored, ?array $regulator, ?bool $adminCheck = null): array
    {
        if (! array_key_exists(self::MARKER, $row)) {
            return ['columns' => [], 'errors' => []];
        }
        $errors = self::problems($row, $regulator);
        if ($errors !== []) {
            return ['columns' => [], 'errors' => $errors];
        }

        $columns = self::columns($row, $stored ?? []);
        unset($columns['registration_visible']);
        if (! array_key_exists('reg_checked_at', $columns)) {
            $columns['reg_checked_at'] = $stored['reg_checked_at'] ?? null;
        }
        if ($adminCheck !== null) {
            $columns['reg_checked_at'] = $adminCheck && $columns['reg_number'] !== null
                ? ($columns['reg_checked_at'] ?? date('Y-m-d H:i:s'))
                : null;
        }

        return ['columns' => $columns, 'errors' => []];
    }
}
