<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingAppointmentModel;
use App\Libraries\ListingText;
use App\Models\Bookings\BookingCustomerModel;
use Config\Bookings;
use DateTimeImmutable;

/**
 * A business's customers: the only writer of xs_booking_customers. Ported
 * from Bookings' CustomerService (upsert), CustomerAppointmentService
 * (history, stats, scope) and CustomerDeletionService.
 *
 * - Email is unique per business, compared lower case. A customer needs a
 *   first name and an email or a phone (settings.md: we need a way to reach
 *   them).
 * - `findOrCreate()` is the inline create from the appointment form and the
 *   public flow: the same email is the same customer, and only empty fields
 *   are filled in (Bookings' preserveNames), so a typo in a booking never
 *   renames someone.
 * - Scope: a provider or reception member sees customers with an
 *   appointment for one of their people (permissions.md, Bookings'
 *   CustomerAppointmentService rule); admin sees all.
 * - Delete: Bookings refused while any appointment existed, so a customer
 *   could never be removed. Here the impact is shown first; a customer with
 *   an upcoming appointment cannot be deleted (cancel it first), and past
 *   appointments stay in the calendar without the person (the foreign key
 *   sets null; the service and time snapshots remain).
 * - Custom fields 1 to 6 follow `booking.custom_field_*`; answers are kept
 *   in `custom_fields` JSON by field key (`field_1` ...).
 */
class CustomerService
{
    /** A first name or surname that is not letters (nameProblem()). */
    public const NAME_PROBLEM = 'Please use only letters in the name, no symbols or numbers.';

    public const PAGE_SIZE = 25;

    private BookingAudit $audit;

    public function __construct(private int $listingId, ?BookingAudit $audit = null)
    {
        $this->audit = $audit ?? new BookingAudit();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = BookingCustomerModel::forBusiness($this->listingId)->find($id);

        return is_array($row) ? $this->decode($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByHash(string $hash): ?array
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return null;
        }
        $row = BookingCustomerModel::forBusiness($this->listingId)->where('hash', $hash)->first();

        return is_array($row) ? $this->decode($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $email = self::normalEmail($email);
        if ($email === null) {
            return null;
        }
        $row = BookingCustomerModel::forBusiness($this->listingId)->where('email', $email)->first();

        return is_array($row) ? $this->decode($row) : null;
    }

    /**
     * Whether a member with this provider scope may see the customer.
     *
     * @param list<int>|null $scope
     */
    public function visible(int $customerId, ?array $scope): bool
    {
        if ($scope === null) {
            return $this->find($customerId) !== null;
        }

        return $scope !== [] && BookingAppointmentModel::forBusiness($this->listingId)
            ->where('customer_id', $customerId)->whereIn('provider_id', $scope)->countAllResults() > 0;
    }

    /**
     * Every customer newest first, or those matching a name, email, phone or
     * reference ("Find a customer", BookingSearch) best first, limited to the
     * member's scope.
     *
     * @param list<int>|null $scope
     *
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function list(string $query, ?array $scope, int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        if ($scope === []) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }
        $query = trim($query);
        if ($query !== '') {
            $ids   = array_column((new BookingSearch($this->listingId))->customers($query, $scope), 'id');
            $total = count($ids);
            $pages = max(1, (int) ceil($total / $perPage));
            $page  = min(max(1, $page), $pages);
            $ids   = array_slice($ids, ($page - 1) * $perPage, $perPage);
            $rows  = $ids === [] ? [] : array_column(BookingCustomerModel::forBusiness($this->listingId)->whereIn('id', $ids)->findAll(), null, 'id');

            return ['rows' => array_map(fn (int $id): array => $this->decode($rows[$id]), array_values(array_filter($ids, static fn (int $id): bool => isset($rows[$id])))),
                'total' => $total, 'page' => $page, 'pages' => $pages];
        }
        $c     = 'xs_booking_customers';
        $model = BookingCustomerModel::forBusiness($this->listingId)->select($c . '.*');
        // Customers past the keeping period (Privacy\Retention) are kept only as counts.
        $model->where($c . '.purged_at', null);
        if ($scope !== null) {
            $ids = array_map('intval', $scope);
            $model->where($c . '.id IN (SELECT customer_id FROM xs_booking_appointments WHERE listing_id = ' . $this->listingId
                . ' AND customer_id IS NOT NULL AND provider_id IN (' . implode(',', $ids) . '))', null, false);
        }

        $total = $model->countAllResults(false);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, $page), $pages);
        $rows  = $model->orderBy($c . '.created_at', 'DESC')->orderBy($c . '.id', 'DESC')->findAll($perPage, ($page - 1) * $perPage);

        return ['rows' => array_map(fn (array $r): array => $this->decode($r), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Create or edit a customer from a form.
     *
     * @param array<string,mixed> $input first_name, last_name, email, phone, address, notes, field_1 ... field_6
     *
     * @return array{id:?int,errors:array<string,string>}
     */
    public function save(?int $id, array $input, ?int $accountId): array
    {
        $existing = $id !== null ? $this->find($id) : null;
        if ($id !== null && $existing === null) {
            return ['id' => null, 'errors' => ['customer' => 'That customer was not found.']];
        }
        [$data, $errors] = $this->clean($input);
        if ($data['email'] !== null) {
            $other = $this->findByEmail($data['email']);
            if ($other !== null && (int) $other['id'] !== $id) {
                $errors['email'] = 'Another customer already has this email.';
            }
        }
        if ($errors !== []) {
            return ['id' => null, 'errors' => $errors];
        }

        $data['custom_fields'] = json_encode(array_merge($existing['custom_fields'] ?? [], $data['custom_fields']), JSON_UNESCAPED_UNICODE);
        if ($existing === null) {
            $id = (int) BookingCustomerModel::forBusiness($this->listingId)->insert($data + ['hash' => bin2hex(random_bytes(32))]);
            $this->audit->record($this->listingId, $accountId, 'customer.created', 'customer', $id);
        } else {
            BookingCustomerModel::forBusiness($this->listingId)->update($id, $data);
            $this->audit->record($this->listingId, $accountId, 'customer.updated', 'customer', $id, null, array_keys(array_filter(
                $data,
                static fn ($v, $k) => $k !== 'custom_fields' && (string) $v !== (string) ($existing[$k] ?? ''),
                ARRAY_FILTER_USE_BOTH,
            )));
        }

        return ['id' => $id, 'errors' => []];
    }

    /**
     * The customer for a booking: the one with this email, filling in only
     * what they had left empty, or a new one.
     *
     * @param array<string,mixed> $input
     *
     * @return array{id:?int,created:bool,errors:array<string,string>}
     */
    public function findOrCreate(array $input, ?int $accountId): array
    {
        [$data, $errors] = $this->clean($input);
        if ($errors !== []) {
            return ['id' => null, 'created' => false, 'errors' => $errors];
        }
        $existing = $data['email'] !== null ? $this->findByEmail($data['email']) : null;
        if ($existing === null) {
            $saved = $this->save(null, $input, $accountId);

            return ['id' => $saved['id'], 'created' => $saved['id'] !== null, 'errors' => $saved['errors']];
        }

        $patch = [];
        foreach (['first_name', 'last_name', 'phone', 'address'] as $field) {
            if ($data[$field] !== null && trim((string) ($existing[$field] ?? '')) === '') {
                $patch[$field] = $data[$field];
            }
        }
        $answers = array_merge($data['custom_fields'], $existing['custom_fields']);
        if ($answers !== $existing['custom_fields']) {
            $patch['custom_fields'] = json_encode($answers, JSON_UNESCAPED_UNICODE);
        }
        if ($patch !== []) {
            BookingCustomerModel::forBusiness($this->listingId)->update((int) $existing['id'], $patch);
        }

        return ['id' => (int) $existing['id'], 'created' => false, 'errors' => []];
    }

    /**
     * Appointments of one customer, newest first, within the member's scope.
     *
     * @param list<int>|null $scope
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $customerId, ?array $scope, int $limit = 100): array
    {
        if ($scope === []) {
            return [];
        }
        $model = BookingAppointmentModel::forBusiness($this->listingId)->where('customer_id', $customerId);
        if ($scope !== null) {
            $model->whereIn('provider_id', $scope);
        }

        return $model->orderBy('start_at', 'DESC')->findAll($limit);
    }

    /**
     * Counts for the customer page (Bookings' getStats()).
     *
     * @return array{total:int,upcoming:int,completed:int,cancelled:int,no_show:int}
     */
    public function stats(int $customerId, ?DateTimeImmutable $now = null): array
    {
        $now  = gmdate('Y-m-d H:i:s', ($now ?? new DateTimeImmutable())->getTimestamp());
        $rows = BookingAppointmentModel::forBusiness($this->listingId)->select('status, start_at')->where('customer_id', $customerId)->findAll();
        $out  = ['total' => count($rows), 'upcoming' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
        foreach ($rows as $r) {
            if (in_array($r['status'], AppointmentStatus::UPCOMING, true) && $r['start_at'] >= $now) {
                $out['upcoming']++;
            } elseif (isset($out[$r['status']])) {
                $out[$r['status']]++;
            }
        }

        return $out;
    }

    /**
     * What deleting a customer would do.
     *
     * @return array{upcoming:int,past:int,can_delete:bool}
     */
    public function deletionImpact(int $customerId, ?DateTimeImmutable $now = null): array
    {
        $now      = gmdate('Y-m-d H:i:s', ($now ?? new DateTimeImmutable())->getTimestamp());
        $upcoming = BookingAppointmentModel::forBusiness($this->listingId)->where('customer_id', $customerId)
            ->whereIn('status', AppointmentStatus::UPCOMING)->where('end_at >', $now)->countAllResults();
        $total    = BookingAppointmentModel::forBusiness($this->listingId)->where('customer_id', $customerId)->countAllResults();

        return ['upcoming' => $upcoming, 'past' => $total - $upcoming, 'can_delete' => $upcoming === 0];
    }

    /** Delete a customer; refused (null) while an upcoming appointment exists. */
    public function delete(int $customerId, ?int $accountId, ?DateTimeImmutable $now = null): ?string
    {
        if ($this->find($customerId) === null) {
            return 'That customer was not found.';
        }
        $impact = $this->deletionImpact($customerId, $now);
        if (! $impact['can_delete']) {
            return 'Cancel or move their upcoming appointments first.';
        }
        BookingCustomerModel::forBusiness($this->listingId)->delete($customerId);
        $this->audit->record($this->listingId, $accountId, 'customer.deleted', 'customer', $customerId, ['past_appointments' => $impact['past']]);

        return null;
    }

    /**
     * The questions this business asks, in order.
     *
     * @return list<array{key:string,title:string,type:string,required:bool,sensitive:bool,options:list<string>,min:int,max:int}>
     */
    public function customFields(): array
    {
        $settings = BookingSettings::for($this->listingId);
        $out      = [];
        for ($n = 1; $n <= Bookings::CUSTOM_FIELDS; $n++) {
            $p     = 'booking.custom_field_' . $n . '_';
            $title   = trim($settings->get($p . 'title'));
            $type    = $settings->get($p . 'type');
            $options = self::options($settings->get($p . 'options'));
            // A list with nothing to choose is not asked.
            if ($settings->get($p . 'enabled') === '1' && $title !== '' && ($type !== 'select' || $options !== [])) {
                $sized = in_array($type, Bookings::CUSTOM_FIELD_SIZED, true);
                $out[] = [
                    'key'       => 'field_' . $n,
                    'title'     => $title,
                    'type'      => $type,
                    'required'  => $settings->get($p . 'required') === '1',
                    // An ID number never goes into a message (POPIA).
                    'sensitive' => $type === 'za_id' || $settings->get($p . 'sensitive') === '1',
                    'options'   => $type === 'select' ? $options : [],
                    'min'       => $type === 'za_id' ? 13 : ($sized ? $settings->int($p . 'min') : 0),
                    'max'       => $type === 'za_id' ? 13 : ($sized ? $settings->int($p . 'max') : 0),
                ];
            }
        }

        return $out;
    }

    /**
     * Validate and normalise form input.
     *
     * @param array<string,mixed> $input
     *
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public function clean(array $input): array
    {
        $text = static function (mixed $v, int $max): ?string {
            $v = trim(strip_tags((string) ($v ?? '')));

            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $errors = [];
        $data   = [
            'first_name' => $text($input['first_name'] ?? null, 80),
            'last_name'  => $text($input['last_name'] ?? null, 80),
            'email'      => null,
            'phone'      => null,
            'address'    => $text($input['address'] ?? null, 255),
            'notes'      => $text($input['notes'] ?? null, 5000),
        ];
        if ($data['first_name'] === null) {
            $errors['first_name'] = 'Add a first name.';
        }
        foreach (['first_name', 'last_name'] as $key) {
            if ($data[$key] !== null && ($problem = self::nameProblem($data[$key])) !== null) {
                $errors[$key] = $problem;
            }
        }
        $rawEmail = trim((string) ($input['email'] ?? ''));
        if ($rawEmail !== '') {
            $data['email'] = self::normalEmail($rawEmail);
            if ($data['email'] === null) {
                $errors['email'] = 'Check the email address.';
            }
        }
        $rawPhone = trim((string) ($input['phone'] ?? ''));
        if ($rawPhone !== '') {
            $data['phone'] = self::normalPhone($rawPhone);
            if ($data['phone'] === null) {
                $errors['phone'] = 'Check the phone number.';
            }
        }
        if ($rawEmail === '' && $rawPhone === '') {
            $errors['email'] = 'Add an email or a phone number so you can reach them.';
        }

        // Required questions are checked when booking (AppointmentService),
        // not on the customer's own record; an answer given must still fit.
        $data['custom_fields'] = $this->answers($input);
        $errors += $this->answerFormatErrors($data['custom_fields']);

        return [$data, $errors];
    }

    /**
     * The answers to the business's questions in some input, trimmed.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,string> field key => answer
     */
    public function answers(array $input): array
    {
        $out = [];
        foreach ($this->customFields() as $field) {
            $value = trim(strip_tags((string) ($input[$field['key']] ?? '')));
            if ($field['type'] === 'checkbox') {
                // Ticked is "Yes"; not ticked is no answer.
                $value = in_array(strtolower($value), ['1', 'yes', 'on', 'true'], true) ? 'Yes' : '';
            } elseif ($field['type'] === 'select' && ! in_array($value, $field['options'], true)) {
                $value = '';
            } elseif ($field['type'] === 'number' || $field['type'] === 'za_id') {
                // Spaces and dashes people type between groups are dropped.
                $value = (string) preg_replace('/[\s\-]+/u', '', $value);
            } elseif ($field['type'] === 'code') {
                $value = mb_strtoupper((string) preg_replace('/[\s\-]+/u', '', $value));
            }
            if ($value !== '') {
                $out[$field['key']] = mb_substr($value, 0, Bookings::CUSTOM_FIELD_VALUE_MAX);
            }
        }

        return $out;
    }

    /**
     * The choices of a select question, one per line in the setting.
     *
     * @return list<string>
     */
    public static function options(string $stored): array
    {
        $out = [];
        foreach (preg_split('/\R/', $stored) ?: [] as $line) {
            $line = mb_substr(trim(strip_tags($line)), 0, Bookings::CUSTOM_FIELD_OPTION_LENGTH);
            if ($line !== '' && ! in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return array_slice($out, 0, Bookings::CUSTOM_FIELD_OPTIONS_MAX);
    }

    /**
     * Required questions left empty.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     */
    public function answerErrors(array $input): array
    {
        $answers = $this->answers($input);
        $errors  = $this->answerFormatErrors($answers);
        foreach ($this->customFields() as $field) {
            if ($field['required'] && ! isset($answers[$field['key']]) && ! isset($errors[$field['key']])) {
                $errors[$field['key']] = match ($field['type']) {
                    'checkbox' => 'Please tick this.',
                    'select'   => 'Please choose one.',
                    default    => 'Please answer this.',
                };
            }
        }

        return $errors;
    }

    /**
     * Answers given that do not fit their question: a number with letters, an
     * ID number that is not one, an answer too short or too long.
     *
     * @param array<string,string> $answers from answers()
     *
     * @return array<string,string>
     */
    public function answerFormatErrors(array $answers): array
    {
        $errors = [];
        foreach ($this->customFields() as $field) {
            $value = $answers[$field['key']] ?? null;
            if ($value !== null && ($problem = self::answerProblem($field, $value)) !== null) {
                $errors[$field['key']] = $problem;
            }
        }

        return $errors;
    }

    /**
     * What is wrong with one answer, or null.
     *
     * @param array{type:string,min:int,max:int} $field
     */
    public static function answerProblem(array $field, string $value): ?string
    {
        switch ($field['type']) {
            case 'za_id':
                if (preg_match('/^\d{13}$/', $value) !== 1) {
                    return 'Please check the ID number: it has 13 digits.';
                }

                return self::validSouthAfricanId($value) ? null : 'That is not a valid ID number. Please check it.';

            case 'number':
                if (preg_match('/^\d+$/', $value) !== 1) {
                    return 'Please use numbers only.';
                }
                break;

            case 'code':
                if (preg_match('/^[\p{L}\d]+$/u', $value) !== 1) {
                    return 'Please use only letters and numbers.';
                }
                break;

            default:
                return null;
        }
        $length = mb_strlen($value);
        $min    = $field['min'];
        $max    = $field['max'];
        if (($min > 0 && $length < $min) || ($max > 0 && $length > $max)) {
            $unit = $field['type'] === 'number' ? 'digits' : 'characters';

            return 'Please check this: it should be ' . self::lengthWords($min, $max, $unit) . '.';
        }

        return null;
    }

    /** "13 digits", "5 to 15 digits", "at least 5 digits", "at most 15 digits", or "" for any length. */
    public static function lengthWords(int $min, int $max, string $unit): string
    {
        return match (true) {
            $min > 0 && $min === $max => $min . ' ' . $unit,
            $min > 0 && $max > 0      => $min . ' to ' . $max . ' ' . $unit,
            $min > 0                  => 'at least ' . $min . ' ' . $unit,
            $max > 0                  => 'at most ' . $max . ' ' . $unit,
            default                   => '',
        };
    }

    /**
     * A South African ID number: 13 digits, YYMMDD a real date, citizenship
     * digit 0, 1 or 2, and the Luhn check digit last.
     */
    public static function validSouthAfricanId(string $id): bool
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})\d{4}([012])\d\d$/', $id, $m) !== 1) {
            return false;
        }
        // The century is not in the number; 29 February needs a leap year in
        // one of the two it could be.
        if (! checkdate((int) $m[2], (int) $m[3], 2000 + (int) $m[1]) && ! checkdate((int) $m[2], (int) $m[3], 1900 + (int) $m[1])) {
            return false;
        }
        $sum = 0;
        foreach (str_split(strrev($id)) as $i => $digit) {
            $d = (int) $digit;
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    /**
     * What is wrong with a customer's first name or surname, or null: letters
     * in any script, spaces, apostrophes, hyphens and full stops only, at
     * least two letters (the contact person rule, ListingText::PERSON_ALLOWED).
     * Written after WSB-BOOK-000001 (12 Nov 2026) was booked as
     * "dfhgfhfghjfghfghRTYRTYRT#$^%45645645645". Keyboard mashing made of
     * letters is deliberately not judged: it would refuse real names.
     * PublicBookingService words it for the customer ("your name").
     */
    public static function nameProblem(string $name): ?string
    {
        if (preg_match(ListingText::PERSON_ALLOWED, $name) !== 1 || preg_match_all('/\p{L}/u', $name) < 2) {
            return self::NAME_PROBLEM;
        }

        return null;
    }

    /**
     * Keep a booking's answers on the customer for next time.
     *
     * @param array<string,string> $answers
     */
    public function rememberAnswers(int $customerId, array $answers): void
    {
        $customer = $this->find($customerId);
        if ($customer === null || $answers === []) {
            return;
        }
        BookingCustomerModel::forBusiness($this->listingId)->update($customerId, [
            'custom_fields' => json_encode(array_merge($customer['custom_fields'], $answers), JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function normalEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        return mb_strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Digits with an optional leading +, 7 to 15 digits (E.164 length); spaces and dashes dropped. */
    public static function normalPhone(string $phone): ?string
    {
        $clean = preg_replace('/[\s().-]+/', '', trim($phone));

        return preg_match('/^\+?\d{7,15}$/', (string) $clean) ? $clean : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decode(array $row): array
    {
        $row['id']            = (int) $row['id'];
        $row['custom_fields'] = json_decode((string) ($row['custom_fields'] ?? ''), true) ?: [];
        $row['name']          = trim($row['first_name'] . ' ' . ($row['last_name'] ?? ''));

        return $row;
    }
}
