<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Answers to a business's own booking questions were kept by position only
 * (field_1 to field_6). When the business renamed a question, an older answer
 * showed under the new wording and was even prefilled for a returning patient
 * (T37 review, 25 Nov 2026). Now each answer keeps the question as asked:
 * xs_booking_appointment_fields.question, and xs_booking_customers
 * .custom_questions (JSON, field key to question) beside custom_fields.
 *
 * Two nullable columns, additive only: safe to run before the code copy.
 * Older rows stay NULL and read as "asked as the question is worded now".
 */
class AddQuestionToBookingAnswers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('booking_appointment_fields', [
            'question' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'after' => 'field_key'],
        ]);
        $this->forge->addColumn('booking_customers', [
            'custom_questions' => ['type' => 'TEXT', 'null' => true, 'after' => 'custom_fields'],
        ]);
    }

    /** Drops only what exists. */
    public function down(): void
    {
        if ($this->db->fieldExists('question', $this->db->prefixTable('booking_appointment_fields'))) {
            $this->forge->dropColumn('booking_appointment_fields', 'question');
        }
        if ($this->db->fieldExists('custom_questions', $this->db->prefixTable('booking_customers'))) {
            $this->forge->dropColumn('booking_customers', 'custom_questions');
        }
    }
}
