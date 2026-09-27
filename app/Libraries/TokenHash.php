<?php

namespace App\Libraries;

/**
 * Emailed bearer tokens: minted here, stored only as their hash.
 *
 * Shared by listings (DirectoryListingMutationService) and job posts
 * (JobBoardService) so both store tokens the same way. The hash must stay
 * SHA-256 hex: 2026-08-05-100000_HashListingTokens backfilled existing rows
 * with MySQL's SHA2(x, 256), and TokenHashTest pins the two together.
 */
final class TokenHash
{
    /** A fresh raw token, 64 hex characters. Goes in the emailed link only. */
    public static function mint(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** What the database stores and is queried by. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
