<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Store the magic-link tokens hashed instead of in plaintext.
 *
 * verify_token and manage_token are bearer credentials: the first publishes a
 * listing, the second grants edit access to it. Held in plaintext, anyone who
 * can read the table — a leaked backup, a read-only injection, a support
 * export — holds working credentials for every outstanding link. Hashed, a
 * database disclosure yields nothing usable.
 *
 * Deliberately a plain SHA-256, not password_hash(). A KDF earns its cost on
 * low-entropy, human-chosen secrets; these are 256-bit CSPRNG values that are
 * not brute-forcible, so the slow hash would buy nothing and would cost a table
 * scan on every redemption — password_verify() cannot be used in a WHERE.
 * SHA-256 keeps the lookup a single equality match on the existing index.
 *
 * The backfill hashes in place rather than clearing, so no outstanding link
 * breaks. That is possible precisely because the plaintext is still here to be
 * read: the app will hash the token arriving from the URL and compare it to the
 * hash of the same token stored here. MySQL's SHA2(x, 256) is byte-identical to
 * PHP's hash('sha256', $x), so the two sides agree. This matters more than it
 * looks — there is no self-service way to re-request a *verification* email, so
 * clearing verify_token would strand every unverified listing in 'pending'
 * permanently.
 *
 * No column change: SHA-256 hex is exactly 64 characters and both columns are
 * already VARCHAR(64). No MySQL/MariaDB guard either, unlike the spatial
 * migration next door — SHA2() has been in both since 5.5.
 */
class HashListingTokens extends Migration
{
    public function up(): void
    {
        $table = $this->db->prefixTable('directory_listings');

        $this->db->query(
            "UPDATE `{$table}` SET `verify_token` = SHA2(`verify_token`, 256) WHERE `verify_token` IS NOT NULL"
        );
        $this->db->query(
            "UPDATE `{$table}` SET `manage_token` = SHA2(`manage_token`, 256) WHERE `manage_token` IS NOT NULL"
        );
    }

    /**
     * A hash cannot be turned back into the token it came from, so this cannot
     * restore the previous state and does not pretend to. Clearing the columns
     * is the honest inverse: it leaves no value that the application would
     * treat as a valid credential.
     *
     * Rolling back therefore invalidates every outstanding verification and
     * manage link. Manage links are self-service (request another at /manage);
     * verification links are not, so any listing still 'pending' at that point
     * needs an admin to publish it by hand.
     */
    public function down(): void
    {
        $table = $this->db->prefixTable('directory_listings');

        $this->db->query(
            "UPDATE `{$table}` SET `verify_token` = NULL, `verify_expires` = NULL WHERE `verify_token` IS NOT NULL"
        );
        $this->db->query(
            "UPDATE `{$table}` SET `manage_token` = NULL, `manage_expires` = NULL WHERE `manage_token` IS NOT NULL"
        );
    }
}
