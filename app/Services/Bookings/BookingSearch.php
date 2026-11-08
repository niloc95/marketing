<?php

namespace App\Services\Bookings;

use App\Libraries\PaymentReference;
use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingCustomerModel;
use Normalizer;

/**
 * "Find" inside /bookings: one business's own customers and appointments,
 * for the customer picker, the calendar's Find box and the Customers list.
 *
 * Not Discover. Discover answers "which business" from a learned public
 * model; this answers "which of my customers" with exact rows, and customer
 * data never feeds that model (POPIA). It borrows Discover's habits only:
 * apostrophes vanish ("O'Brien" is obrien) and a typo is one letter, two
 * from eight letters, with the first letter kept (DiscoveryModelService::
 * correct()). Discover's own fold() and words() are not used: they fold
 * plurals ("Adams" would be "adam") and drop short words and place names,
 * which are real surnames here.
 *
 * What a query can be, all at once:
 * - a name, words in any order, each the start of a name word: "nal mab"
 *   and "mabaso naledi" both find Naledi Mabaso; accents and capitals do
 *   not matter (the columns' collation in SQL, fold() in PHP);
 * - a phone number in any form: 0825550121, 082 555 0121, +27 82 555 0121
 *   and 27825550121 are one number; the business's own country code stands
 *   in for the leading 0 abroad;
 * - an email, or part of one;
 * - a reference: WSB-BOOK-000123, BOOK 123, #123 or just 123.
 *
 * Ranked exact, then prefix, then fuzzy (a typo, a sound alike, or letters
 * inside a word); within a rank, customers seen most recently first (their
 * latest appointment, else when they were added), appointments newest first.
 * Always one listing and, when given, the member's provider scope (the rule
 * of CustomerService::visible()). Customers past the keeping period
 * (Privacy\Retention) are never found.
 */
final class BookingSearch
{
    public const EXACT  = 0;
    public const PREFIX = 1;
    public const FUZZY  = 2;

    /** Rows ranked in PHP for one query; more is a sign to type more. */
    private const MAX_MATCHES = 500;

    /** Names compared for typos, those seen most recently first. */
    private const MAX_CANDIDATES = 5000;

    /**
     * First and last name as one lower risk string for LIKE: words split on
     * hyphens too ("Smith-Jones"), apostrophes gone, a space in front so
     * "% w%" finds the start of any word.
     */
    private const NAME_SQL = "CONCAT(' ', REPLACE(REPLACE(REPLACE(CONCAT_WS(' ', xs_booking_customers.first_name, xs_booking_customers.last_name), '-', ' '), '''', ''), '\u{2019}', ''))";

    private ?string $dialCode;

    public function __construct(private int $listingId, ?string $countryCode = null)
    {
        $countryCode ??= (string) (db_connect()->table('xs_directory_listings')->select('country_code')
            ->where('id', $listingId)->get()->getRowArray()['country_code'] ?? 'ZA');
        $this->dialCode = (new \Config\Countries())->profile($countryCode ?: 'ZA')['dialCode'] ?? null;
    }

    /**
     * Customers matching a query, best first.
     *
     * @param list<int>|null $scope provider ids, null for everyone
     *
     * @return list<array{id:int,rank:int}>
     */
    public function customers(string $query, ?array $scope, int $limit = self::MAX_MATCHES): array
    {
        $q = $this->parse($query);
        if ($q === null || $scope === []) {
            return [];
        }

        $found = [];
        foreach ($this->customerRows($q, $scope, false) as $row) {
            $rank = $this->rankCustomer($row, $q, false);
            if ($rank !== null) {
                $found[(int) $row['id']] = ['id' => (int) $row['id'], 'rank' => $rank, 'seen' => (string) $row['seen']];
            }
        }
        // Typos only when the plain matches leave room, and only for words long
        // enough to have one (four letters, as Discover's five less one: names are short).
        if (count($found) < $limit && $this->fuzzyWords($q) !== []) {
            foreach ($this->customerRows($q, $scope, true) as $row) {
                if (isset($found[(int) $row['id']])) {
                    continue;
                }
                $rank = $this->rankCustomer($row, $q, true);
                if ($rank !== null) {
                    $found[(int) $row['id']] = ['id' => (int) $row['id'], 'rank' => $rank, 'seen' => (string) $row['seen']];
                }
            }
        }

        usort($found, static fn (array $a, array $b): int => [$a['rank'], $b['seen'], $b['id']] <=> [$b['rank'], $a['seen'], $a['id']]);

        return array_map(static fn (array $r): array => ['id' => $r['id'], 'rank' => $r['rank']], array_slice($found, 0, $limit));
    }

    /**
     * Appointments matching a query: by reference, by their customer (as
     * customers()) or by service name. Best first, newest first within a rank.
     *
     * @param list<int>|null $scope
     *
     * @return list<array{id:int,rank:int}>
     */
    public function appointments(string $query, ?array $scope, int $limit = 20): array
    {
        $q = $this->parse($query);
        if ($q === null || $scope === []) {
            return [];
        }
        $customers = [];
        foreach ($this->customers($query, $scope) as $c) {
            $customers[$c['id']] = $c['rank'];
        }

        $a     = 'xs_booking_appointments';
        $db    = db_connect();
        $model = BookingAppointmentModel::forBusiness($this->listingId)->select($a . '.id, ' . $a . '.customer_id, ' . $a . '.service_name')->groupStart();
        $model->where('1 = 0', null, false);
        if ($q['reference'] !== null) {
            $model->orWhere($a . '.reference', PaymentReference::make(PaymentReference::BOOKING, $q['reference']));
        }
        if ($customers !== []) {
            $model->orWhereIn($a . '.customer_id', array_keys($customers));
        }
        if ($q['words'] !== []) {
            $model->orGroupStart();
            foreach ($q['words'] as $w) {
                $model->where($a . ".service_name LIKE '%" . $db->escapeLikeString($w) . "%' ESCAPE '!'", null, false);
            }
            $model->groupEnd();
        }
        $model->groupEnd();
        if ($scope !== null) {
            $model->whereIn($a . '.provider_id', array_map('intval', $scope));
        }

        $out = [];
        foreach ($model->orderBy($a . '.start_at', 'DESC')->orderBy($a . '.id', 'DESC')->findAll(self::MAX_MATCHES) as $i => $row) {
            $ranks = [];
            $named = $q['reference'] !== null && (int) $row['id'] === $q['reference'];
            if ($named) {
                $ranks[] = self::EXACT;
            }
            if (isset($customers[(int) $row['customer_id']])) {
                $ranks[] = $customers[(int) $row['customer_id']];
            }
            if ($q['words'] !== []) {
                $service = self::words((string) $row['service_name']);
                $hit     = $this->wordsRank($q['words'], $service, false);
                if ($hit !== null) {
                    $ranks[] = $hit;
                }
            }
            if ($ranks !== []) {
                $out[] = ['id' => (int) $row['id'], 'rank' => min($ranks), 'named' => $named ? 0 : 1, 'order' => $i];
            }
        }
        // The appointment a reference names comes before the rest of its
        // customer's, which the same reference also finds as exact.
        usort($out, static fn (array $x, array $y): int => [$x['rank'], $x['named'], $x['order']] <=> [$y['rank'], $y['named'], $y['order']]);

        return array_map(static fn (array $r): array => ['id' => $r['id'], 'rank' => $r['rank']], array_slice($out, 0, $limit));
    }

    /** Lower case, accents gone ("Müller" is muller), apostrophes gone ("O'Brien" is obrien). */
    public static function fold(string $text): string
    {
        $text  = str_replace(["'", "\u{2019}"], '', $text);
        $plain = class_exists(Normalizer::class) ? Normalizer::normalize($text, Normalizer::FORM_D) : $text;
        $plain = preg_replace('/\p{Mn}+/u', '', is_string($plain) ? $plain : $text) ?? $text;

        return mb_strtolower($plain);
    }

    /**
     * The words of a name or a query, folded, in order. Every length counts:
     * "Ng" and "Li" are surnames.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        return preg_split('/[^\p{L}]+/u', self::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The phone number's national digits, the part every way of writing it
     * shares: "082 555 0121", "+27 82 555 0121" and "27825550121" are all
     * 825550121 for a South African business. A number in another country
     * keeps its own code ("+44 20 7946 0000" is 442079460000). Null when the
     * text is not a phone number.
     */
    public function nationalDigits(string $phone): ?string
    {
        $phone = trim($phone);
        if (preg_match('/^\+?[\d\s().\-]+$/', $phone) !== 1) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $phone);
        $dial   = (string) $this->dialCode;
        if (str_starts_with($phone, '+')) {
            // "+27 (0)82 ...": the local 0 kept after the code goes.
            return $dial !== '' && str_starts_with($digits, $dial) ? ltrim(substr($digits, strlen($dial)), '0') : $digits;
        }
        if (str_starts_with($digits, '0')) {
            return ltrim($digits, '0');
        }
        // Typed without + but with the code: only a full length number can tell.
        if ($dial !== '' && strlen($digits) >= 11 && str_starts_with($digits, $dial)) {
            return ltrim(substr($digits, strlen($dial)), '0');
        }

        return $digits;
    }

    // Internals

    /**
     * What a query can be.
     *
     * @return array{text:string,words:list<string>,phone:?string,reference:?int,referenceCustomer:?int,email:?string}|null
     */
    private function parse(string $query): ?array
    {
        $text = mb_substr(trim($query), 0, 100);
        if ($text === '') {
            return null;
        }
        $phone = $this->nationalDigits($text);
        $ref   = null;
        // WSB-BOOK-000123, BOOK 123, #123, 000123 or 123: the appointment id.
        if (preg_match('/^(?:#|(?:wsb[\s-]*)?book[\s-]*)?0*(\d{1,9})$/i', $text, $m) === 1 && (int) $m[1] > 0) {
            $ref = (int) $m[1];
        }
        $refCustomer = null;
        if ($ref !== null) {
            $row         = BookingAppointmentModel::forBusiness($this->listingId)->select('customer_id')->where('id', $ref)->first();
            $refCustomer = is_array($row) && $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
        }

        return [
            'text'              => $text,
            'words'             => self::words($text),
            // Four digits before a number is worth looking in phones for.
            'phone'             => $phone !== null && strlen($phone) >= 4 ? $phone : null,
            'reference'         => $ref,
            'referenceCustomer' => $refCustomer,
            'email'             => ! preg_match('/\s/', $text) && preg_match('/[@.]|^[^\d\s]+$/', $text) ? mb_strtolower($text) : null,
        ];
    }

    /** @return list<string> the query words a typo is looked for in */
    private function fuzzyWords(array $q): array
    {
        return array_values(array_filter($q['words'], static fn (string $w): bool => mb_strlen($w) >= 4));
    }

    /**
     * Customers to rank: those a plain match could be ($fuzzy false), or the
     * names sharing a first letter with a long query word ($fuzzy true).
     *
     * @param list<int>|null $scope
     *
     * @return list<array<string,mixed>>
     */
    private function customerRows(array $q, ?array $scope, bool $fuzzy): array
    {
        $c     = 'xs_booking_customers';
        $db    = db_connect();
        $like  = static fn (string $pattern): string => $db->escape($pattern) . " ESCAPE '!'";
        $model = BookingCustomerModel::forBusiness($this->listingId)
            ->select($c . '.id, ' . $c . '.first_name, ' . $c . '.last_name, ' . $c . '.email, ' . $c . '.phone, COALESCE((SELECT MAX(a.start_at) FROM xs_booking_appointments a'
                . ' WHERE a.listing_id = ' . $this->listingId . ' AND a.customer_id = ' . $c . '.id), ' . $c . '.created_at) AS seen', false)
            ->where($c . '.purged_at', null);

        $model->groupStart()->where('1 = 0', null, false);
        if ($fuzzy) {
            foreach (array_unique(array_map(static fn (string $w): string => mb_substr($w, 0, 1), $this->fuzzyWords($q))) as $letter) {
                $model->orWhere(self::NAME_SQL . ' LIKE ' . $like('% ' . $db->escapeLikeString($letter) . '%'), null, false);
            }
        } else {
            if ($q['words'] !== []) {
                // Every word somewhere in the name; PHP then tells a word start from inside a word.
                $model->orGroupStart();
                foreach ($q['words'] as $w) {
                    $model->where(self::NAME_SQL . ' LIKE ' . $like('%' . $db->escapeLikeString($w) . '%'), null, false);
                }
                $model->groupEnd();
                // "vandermerwe" for van der Merwe.
                $model->orWhere("REPLACE(CONCAT_WS('', $c.first_name, $c.last_name), ' ', '') LIKE " . $like('%' . $db->escapeLikeString(implode('', $q['words'])) . '%'), null, false);
            }
            if ($q['email'] !== null) {
                $model->orWhere($c . '.email LIKE ' . $like('%' . $db->escapeLikeString($q['email']) . '%'), null, false);
            }
            if ($q['phone'] !== null) {
                $model->orWhere("REPLACE(REPLACE(REPLACE($c.phone, ' ', ''), '-', ''), '+', '') LIKE " . $like('%' . $q['phone'] . '%'), null, false);
            }
            if ($q['referenceCustomer'] !== null) {
                $model->orWhere($c . '.id', $q['referenceCustomer']);
            }
        }
        $model->groupEnd();

        if ($scope !== null) {
            $model->where($c . '.id IN (SELECT customer_id FROM xs_booking_appointments WHERE listing_id = ' . $this->listingId
                . ' AND customer_id IS NOT NULL AND provider_id IN (' . implode(',', array_map('intval', $scope)) . '))', null, false);
        }

        return $model->orderBy('seen', 'DESC')->orderBy($c . '.id', 'DESC')->findAll($fuzzy ? self::MAX_CANDIDATES : self::MAX_MATCHES);
    }

    /** The best rank of a customer row for the query, or null when it does not match. */
    private function rankCustomer(array $row, array $q, bool $fuzzy): ?int
    {
        $ranks = [];
        if ($q['referenceCustomer'] !== null && $q['referenceCustomer'] === (int) $row['id']) {
            $ranks[] = self::EXACT;
        }
        if ($q['phone'] !== null && (string) ($row['phone'] ?? '') !== '') {
            $theirs = $this->nationalDigits((string) $row['phone']);
            if ($theirs === $q['phone']) {
                $ranks[] = self::EXACT;
            } elseif ($theirs !== null && str_contains($theirs, $q['phone'])) {
                $ranks[] = self::PREFIX;
            }
        }
        if ($q['email'] !== null && (string) ($row['email'] ?? '') !== '') {
            $email = mb_strtolower((string) $row['email']);
            if ($email === $q['email']) {
                $ranks[] = self::EXACT;
            } elseif (str_contains($email, $q['email'])) {
                $ranks[] = self::PREFIX;
            }
        }
        if ($q['words'] !== []) {
            $name = self::words($row['first_name'] . ' ' . ($row['last_name'] ?? ''));
            $hit  = $this->wordsRank($q['words'], $name, $fuzzy);
            if ($hit === null && str_contains(implode('', $name), implode('', $q['words']))) {
                $hit = implode('', $name) === implode('', $q['words']) ? self::EXACT : self::PREFIX;
            }
            if ($hit !== null) {
                $ranks[] = $hit;
            }
        }

        return $ranks === [] ? null : min($ranks);
    }

    /**
     * How well every query word finds a word of the name (or service), each
     * name word used once, so "nal nal" is not Naledi. The rank is the
     * weakest word's: all exact is exact, any typo is fuzzy.
     *
     * @param list<string> $query
     * @param list<string> $target
     */
    private function wordsRank(array $query, array $target, bool $fuzzy): ?int
    {
        $worst = self::EXACT;
        foreach ($query as $w) {
            $best = null;
            foreach ($target as $i => $t) {
                $r = $this->wordRank($w, $t, $fuzzy);
                if ($r !== null && ($best === null || $r < $best[0])) {
                    $best = [$r, $i];
                }
            }
            if ($best === null) {
                return null;
            }
            unset($target[$best[1]]);
            $worst = max($worst, $best[0]);
        }

        return $worst;
    }

    private function wordRank(string $w, string $t, bool $fuzzy): ?int
    {
        if ($w === $t) {
            return self::EXACT;
        }
        if (str_starts_with($t, $w)) {
            return self::PREFIX;
        }
        if (str_contains($t, $w) && mb_strlen($w) >= 3) {
            return self::FUZZY;
        }
        $len = mb_strlen($w);
        if (! $fuzzy || $len < 4 || mb_substr($w, 0, 1) !== mb_substr($t, 0, 1)) {
            return null;
        }
        $max = $len >= 8 ? 2 : 1;
        // A typo in the whole word ("pilay"), or in the part typed so far ("pilai" of Pillay's first five).
        if (levenshtein($w, $t) <= $max || levenshtein($w, mb_substr($t, 0, $len)) <= $max && mb_strlen($t) > $len) {
            return self::FUZZY;
        }
        // Spelled as it sounds: "naidu" for Naidoo, "steyne" for Steyn.
        $sound = metaphone($w);

        return $sound !== '' && $sound === metaphone($t) ? self::FUZZY : null;
    }
}
