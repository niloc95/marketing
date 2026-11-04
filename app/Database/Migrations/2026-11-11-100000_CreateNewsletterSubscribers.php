<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The newsletter list, replacing Mautic (segments 10 and 11). One row per
 * address for all three signup forms: local.webscheduler.co.za,
 * local.webscheduler.app and the brand site webscheduler.co.za. `source` says
 * which, so an update meant for one audience never reaches the other.
 *
 * Real double opt in: a row is `pending` until the person presses Confirm on
 * the page the emailed link opens (NewsletterService::confirm()). Only then is
 * consent_at stamped and the row `subscribed`.
 *
 * The confirm token is stored only as its hash (TokenHash), like every other
 * emailed bearer token. The unsubscribe token is stored as is, like
 * marketing_token on listings: it has to go into every future email, and the
 * worst it can do is take the address off the list.
 *
 * `unsubscribed` rows are kept, not deleted: they are the do not contact list.
 */
class CreateNewsletterSubscribers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'email'              => ['type' => 'VARCHAR', 'constraint' => 254],
            'source'             => ['type' => 'VARCHAR', 'constraint' => 8],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'],
            'confirm_token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'confirm_expires'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'confirm_sent_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'unsubscribe_token'  => ['type' => 'CHAR', 'constraint' => 64],
            'consent_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'consent_source'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'default' => null],
            'unsubscribed_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->addUniqueKey('unsubscribe_token');
        $this->forge->addKey('confirm_token_hash');
        $this->forge->addKey(['status', 'source']);
        $this->forge->createTable('newsletter_subscribers');
    }

    public function down(): void
    {
        $this->forge->dropTable('newsletter_subscribers', true);
    }
}
