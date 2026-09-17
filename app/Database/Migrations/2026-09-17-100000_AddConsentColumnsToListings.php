<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A record of what the owner agreed to: the terms, and — separately — marketing.
 *
 * Until now the signup checkbox only confirmed authority to publish, and nothing
 * about it was stored. POPIA section 69 lets us email marketing only to someone
 * who opted in, and only if we can show when they did, so both the terms
 * acceptance and the marketing choice are kept on the row.
 *
 * Marketing is its own opt-in, never folded into the terms: consent that is a
 * condition of listing is not freely given. Existing rows default to not opted
 * in, and must stay that way until the owner says otherwise.
 *
 * `marketing_token` is a random, stored value rather than an HMAC over the app
 * key: rotating encryption.key would otherwise break every unsubscribe link
 * already sitting in an inbox. It can only ever opt a listing out, so it is
 * stored raw — the campaign sender needs to put it into a URL.
 * MarketingConsentService is the only writer of every column here.
 */
class AddConsentColumnsToListings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'terms_accepted_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'after'   => 'booking_url',
                'comment' => 'When the owner accepted the terms and privacy policy at signup',
            ],
            'terms_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'after'      => 'terms_accepted_at',
                'comment'    => 'Legal::LAST_UPDATED[terms] at the time of acceptance',
            ],
            'marketing_opt_in' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'terms_version',
                'comment'    => 'POPIA s69 opt-in to marketing email; 0 unless the owner ticked it',
            ],
            'marketing_consent_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'after'   => 'marketing_opt_in',
                'comment' => 'Most recent opt-in',
            ],
            'marketing_withdrawn_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'after'   => 'marketing_consent_at',
                'comment' => 'Most recent opt-out',
            ],
            'marketing_consent_source' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'marketing_withdrawn_at',
                'comment'    => 'signup | manage | unsubscribe — where the latest change came from',
            ],
            'marketing_token' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'marketing_consent_source',
                'comment'    => 'Unsubscribe link token; can only opt out',
            ],
        ]);

        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `uq_marketing_token` (`marketing_token`)");
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query("ALTER TABLE `{$table}` DROP INDEX `uq_marketing_token`");
        $this->forge->dropColumn('directory_listings', [
            'terms_accepted_at', 'terms_version', 'marketing_opt_in', 'marketing_consent_at',
            'marketing_withdrawn_at', 'marketing_consent_source', 'marketing_token',
        ]);
    }
}
