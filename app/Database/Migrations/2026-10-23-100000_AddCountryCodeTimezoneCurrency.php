<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Groundwork for serving other countries on local.webscheduler.app.
 *
 *  - country_code: ISO 3166-1 alpha-2 beside every row that has an address.
 *    It decides which site a row belongs to (ZA on co.za, the rest on .app),
 *    so it is indexed. NULL until directory:countries:backfill fills it, and
 *    NULL reads as ZA everywhere, the same harmless default isLocal() uses.
 *  - timezone: the IANA zone a listing or branch keeps its hours in. NULL
 *    means "derive from the country", which for every row today is
 *    Africa/Johannesburg, so nothing changes until a foreign row sets it.
 *  - currency: ISO 4217 beside every stored amount. Everything charged so far
 *    went through PayFast in rand, so the default is ZAR.
 *
 * Additive and nullable, so it runs before the code copy.
 */
class AddCountryCodeTimezoneCurrency extends Migration
{
    /** Tables that hold an address. */
    private const ADDRESS_TABLES = [
        'directory_listings', 'directory_practice_locations', 'directory_venues',
        'directory_job_posts', 'directory_career_roles', 'directory_referrals',
    ];

    /** Tables that hold timezone sensitive hours. */
    private const HOURS_TABLES = ['directory_listings', 'directory_practice_locations'];

    /** Tables that hold an amount of money, and the column it sits after. */
    private const MONEY_TABLES = [
        'directory_verifications'               => 'amount',
        'directory_verification_events'         => 'amount_gross',
        'directory_verification_itn_rejections' => 'amount_gross',
        'directory_partner_commissions'         => 'amount',
        'directory_partner_payouts'             => 'total',
        'directory_profile_setup_orders'        => 'amount',
        'directory_job_posts'                   => 'price_cents',
    ];

    public function up(): void
    {
        foreach (self::ADDRESS_TABLES as $table) {
            $this->forge->addColumn($table, [
                'country_code' => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            ]);
            $this->db->query('CREATE INDEX idx_country_code ON ' . $this->db->prefixTable($table) . ' (country_code)');
        }

        foreach (self::HOURS_TABLES as $table) {
            $this->forge->addColumn($table, [
                'timezone' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            ]);
        }

        foreach (self::MONEY_TABLES as $table => $after) {
            $this->forge->addColumn($table, [
                'currency' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true, 'default' => 'ZAR', 'after' => $after],
            ]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::MONEY_TABLES) as $table) {
            $this->forge->dropColumn($table, 'currency');
        }

        foreach (self::HOURS_TABLES as $table) {
            $this->forge->dropColumn($table, 'timezone');
        }

        foreach (self::ADDRESS_TABLES as $table) {
            $this->db->query('DROP INDEX idx_country_code ON ' . $this->db->prefixTable($table));
            $this->forge->dropColumn($table, 'country_code');
        }
    }
}
