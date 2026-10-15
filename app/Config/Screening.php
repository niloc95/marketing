<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Screening questions (App\Libraries\Screening). A role picks a level; each
 * applicant then answers one reading comprehension, one language and one
 * everyday numbers question from that level's templates in
 * App\Libraries\ScreeningBank, each with its own details, on a clock.
 */
class Screening extends BaseConfig
{
    /**
     * Stored key => label shown to whoever posts the role.
     *
     * @var array<string,string>
     */
    public array $levels = [
        'basic'        => 'Basic (Grade 7 to 8)',
        'intermediate' => 'Intermediate (Grade 9 to 10)',
        'advanced'     => 'Advanced (Grade 11 to 12)',
    ];

    /**
     * The time allowed per question, in seconds, by level and kind. Every
     * applicant answers one of each kind (ScreeningBank::KINDS), so a
     * level's limit is the sum of its row. Published numerical reasoning
     * tests allow roughly 45 to 90 seconds a question; a passage needs
     * reading time on top. The limit is soft: answers after it are kept
     * and shown as over time, never thrown away.
     *
     * @var array<string,array<string,int>>
     */
    public array $secondsPerKind = [
        'basic'        => ['comprehension' => 120, 'language' => 45, 'numbers' => 60],
        'intermediate' => ['comprehension' => 150, 'language' => 60, 'numbers' => 75],
        'advanced'     => ['comprehension' => 180, 'language' => 75, 'numbers' => 90],
    ];
}
