<?php

namespace App\Services\Bookings;

/**
 * Who is signed in to /business and which business they are working in, for
 * the current request. Filled by App\Filters\BusinessAuth after it has checked
 * the session against the database; read by controllers through
 * service('businessContext'). Never filled from request input.
 */
class BusinessContext
{
    /** @var array<string,mixed>|null */
    private ?array $account = null;

    /** @var array<string,mixed>|null */
    private ?array $member = null;

    /** @var array<string,mixed>|null */
    private ?array $listing = null;

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $member
     * @param array<string,mixed> $listing
     */
    public function set(array $account, array $member, array $listing): void
    {
        $this->account = $account;
        $this->member  = $member;
        $this->listing = $listing;
    }

    /**
     * Only the account: pages that work before a business is chosen (the
     * business picker, the account page).
     *
     * @param array<string,mixed> $account
     */
    public function setAccount(array $account): void
    {
        $this->account = $account;
    }

    public function hasAccount(): bool
    {
        return $this->account !== null;
    }

    public function isSet(): bool
    {
        return $this->member !== null;
    }

    /** @return array<string,mixed> */
    public function account(): array
    {
        return $this->account ?? throw new \LogicException('No business session.');
    }

    /** @return array<string,mixed> */
    public function member(): array
    {
        return $this->member ?? throw new \LogicException('No business session.');
    }

    /** @return array<string,mixed> */
    public function listing(): array
    {
        return $this->listing ?? throw new \LogicException('No business session.');
    }

    public function listingId(): int
    {
        return (int) $this->listing()['id'];
    }

    public function accountId(): int
    {
        return (int) $this->account()['id'];
    }

    /** The business's own time zone (the profile's), never the server's. */
    public function timezone(): string
    {
        $tz = (string) ($this->listing()['timezone'] ?? '');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Johannesburg';
    }

    /**
     * Provider ids this member's rows are limited to: null for all (admin),
     * otherwise the provider they are (provider) or look after (reception).
     *
     * @return list<int>|null
     */
    public function providerScope(): ?array
    {
        return (new BookingAuthorization())->providerScope($this->member());
    }

    public function can(string $permission): bool
    {
        return $this->isSet() && (new BookingAuthorization())->can($this->member(), $permission);
    }
}
