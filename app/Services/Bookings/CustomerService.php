<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingAppointmentModel;
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
     * Find customers by name, email or phone ("Find a customer"), newest
     * first, limited to the member's scope.
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
        $c     = 'xs_booking_customers';
        $model = BookingCustomerModel::forBusiness($this->listingId)->select($c . '.*');
        $query = trim($query);
        if ($query !== '') {
            $model->groupStart()
                ->like($c . '.first_name', $query)
                ->orLike($c . '.last_name', $query)
                ->orLike($c . '.email', $query)
                ->orLike($c . '.phone', preg_replace('/\s+/', '', $query));
            $words = preg_split('/\s+/', $query, 2);
            if (count($words) === 2) {
                $model->orGroupStart()->like($c . '.first_name', $words[0], 'after')->like($c . '.last_name', $words[1], 'after')->groupEnd();
            }
            $model->groupEnd();
        }
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
     * @return list<array{key:string,title:string,type:string,required:bool,sensitive:bool,options:list<string>}>
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
                $out[] = [
                    'key'       => 'field_' . $n,
                    'title'     => $title,
                    'type'      => $settings->get($p . 'type'),
                    'required'  => $settings->get($p . 'required') === '1',
                    'sensitive' => $settings->get($p . 'sensitive') === '1',
                    'options'   => $type === 'select' ? $options : [],
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
        // not on the customer's own record.
        $data['custom_fields'] = $this->answers($input);

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
        $errors  = [];
        foreach ($this->customFields() as $field) {
            if ($field['required'] && ! isset($answers[$field['key']])) {
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
