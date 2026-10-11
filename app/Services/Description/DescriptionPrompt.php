<?php

namespace App\Services\Description;

use App\Libraries\RichText;
use App\Models\DirectoryListingModel;

/**
 * The request behind "Write it with ChatGPT": the owner's details and our
 * writing rules, as plain text the owner can read. DescriptionHelper::prompt()
 * returns it; the browser opens ChatGPT with it in a new tab and copies it to
 * the clipboard. We never call a model ourselves.
 *
 * Written in the owner's voice ("my business"), because the owner is the one
 * sending it, from their own ChatGPT.
 *
 * The rules allow the owner's own claims ("12 years", "dermatologist") and
 * nothing beyond them. A good description needs those details, and only the
 * owner can give them, which is what the four questions on the form are for.
 * Claude Haiku can take over from ChatGPT later with the same prompt.
 */
final class DescriptionPrompt
{
    /** Just inside the field's 3000 cap, leaving room for the owner's own edits. */
    public const TARGET_CHARS = 2850;

    public static function build(array $facts): string
    {
        return "Please write the description for my business profile on WebScheduler Local, a South African local business directory, using the details below.\n\n"
            . "How to write it:\n"
            . "1. Use only what I tell you below. You may use my own words and claims, but do not add services, prices, numbers, years, awards, qualifications, people or contact details that I have not given.\n"
            . "2. The category is a label from the directory, not always a word for my business. Turn it into natural English.\n"
            . "3. Write 2 short paragraphs in warm, confident, plain South African English, using \"we\". If I have said what we believe or what makes us different, open with that. Otherwise open with what we do.\n"
            . "4. Mention the business name and the town naturally.\n"
            . "5. Do not use dashes or hyphens of any kind. Write \"walk ins\", not \"walk-ins\".\n"
            . "6. No headings, lists, emojis, hashtags or links.\n"
            . '7. Keep it under ' . number_format(self::TARGET_CHARS) . ' characters, so it fits the profile (the limit is ' . number_format(RichText::MAX_PLAIN_LENGTH) . ").\n"
            . "Reply with the description only, ready to paste.\n\n"
            . self::details($facts);
    }

    /**
     * One line per detail the owner gave, in the form's order, then their
     * answers to the four questions. Blank details are left out entirely.
     *
     * @param array<string,mixed> $facts
     */
    public static function details(array $facts): string
    {
        $line = static function (string $label, mixed $value): string {
            $value = is_array($value) ? implode(', ', array_filter(array_map('strval', $value))) : trim((string) $value);

            return $value === '' ? '' : $label . ': ' . $value . "\n";
        };

        $where = (string) ($facts['customer_location'] ?? 'visit');

        return trim("My details:\n"
            . $line('Business name', $facts['name'] ?? '')
            . $line('Category', $facts['category'] ?? '')
            . $line('Profile type', DirectoryListingModel::TYPES[(string) ($facts['type'] ?? '')] ?? '')
            . $line('Suburb', $facts['suburb'] ?? '')
            . $line('Town', $facts['city'] ?? '')
            . $line('Services', $facts['services'] ?? [])
            . $line('Areas of focus', $facts['tags'] ?? [])
            . ($where !== 'visit' ? $line('We travel to customers in', $facts['service_areas'] ?? []) : '')
            . $line('Features', $facts['features'] ?? [])
            . $line('Credentials', $facts['credentials'] ?? '')
            . $line('What we believe, or how we work', $facts['approach'] ?? '')
            . $line('The people customers will see, and their qualifications', $facts['people'] ?? '')
            . $line('How long we have been doing this', $facts['years'] ?? '')
            . $line('What makes us different, or what customers love', $facts['different'] ?? ''));
    }
}
