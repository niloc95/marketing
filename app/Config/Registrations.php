<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Which statutory register a category's professionals are on, and how to
 * show it. Read through App\Libraries\ProfessionalRegistration only.
 *
 * Only regulated professions are here. A registration box on a plumber's or a
 * salon's form is noise, and an empty one would make an unregulated business
 * look as if something were missing. A category not listed gets no box at all.
 *
 * A category resolves by slug first, then by group, so 'nurse' can answer
 * SANC inside a group that otherwise answers HPCSA. A slug mapped to null opts
 * out of its group's default: a hospital or a clinic is a facility, not a
 * registered person, and a nutritionist is not a statutory title.
 *
 * Regulators:
 *   short       what the profile and the JSON-LD propertyID call it
 *   name        the full name, for the hint and the register link's title
 *   pattern     what a number must look like, matched against the normalised
 *               (upper cased, single spaced) value; null = only the generic check
 *   example     shown as the placeholder
 *   register    the regulator's public lookup, linked from the profile so a
 *               visitor can check the number themselves. Each one returned 200
 *               on 28 Oct 2026 except SANC, whose site refuses scripted
 *               requests, so it links the home page.
 *   research    whether ORCID and ResearchGate are offered beside the number
 */
class Registrations extends BaseConfig
{
    /** @var array<string,array{short:string,name:string,pattern:?string,example:string,register:string,research:bool}> */
    public array $regulators = [
        'hpcsa' => [
            'short'    => 'HPCSA',
            'name'     => 'Health Professions Council of South Africa',
            // The register prefix (MP, DP, PS, PT, OD, …) and its digits.
            'pattern'  => '/^[A-Z]{2,4} ?\d{4,8}$/',
            'example'  => 'MP0123456',
            'register' => 'https://hpcsaonline.custhelp.com/app/i_reg_form',
            'research' => true,
        ],
        'sanc' => [
            'short'    => 'SANC',
            'name'     => 'South African Nursing Council',
            'pattern'  => null,
            'example'  => '12345678',
            'register' => 'https://www.sanc.co.za/',
            'research' => true,
        ],
        'sapc' => [
            'short'    => 'SAPC',
            'name'     => 'South African Pharmacy Council',
            'pattern'  => null,
            'example'  => 'P12345678',
            'register' => 'https://www.sapc.za.org/registered-persons',
            'research' => true,
        ],
        'ahpcsa' => [
            'short'    => 'AHPCSA',
            'name'     => 'Allied Health Professions Council of South Africa',
            'pattern'  => null,
            'example'  => 'A12345',
            'register' => 'https://ahpcsa.co.za/practitioners/',
            'research' => true,
        ],
        'sacssp' => [
            'short'    => 'SACSSP',
            'name'     => 'South African Council for Social Service Professions',
            'pattern'  => null,
            'example'  => '10-12345',
            'register' => 'https://www.sacssp.co.za/directory/find-professional',
            'research' => false,
        ],
        'lpc' => [
            'short'    => 'LPC',
            'name'     => 'Legal Practice Council',
            'pattern'  => null,
            'example'  => '12345',
            'register' => 'https://lpc.org.za/members-of-the-public/list-of-legal-practitioners/',
            'research' => false,
        ],
        'sacap' => [
            'short'    => 'SACAP',
            'name'     => 'South African Council for the Architectural Profession',
            'pattern'  => null,
            'example'  => '12345678',
            'register' => 'https://search.mymembership.co.za/Search/?Id=4f3f0fde-d5dc-4af0-97cd-0a192a56830e',
            'research' => false,
        ],
        'ecsa' => [
            'short'    => 'ECSA',
            'name'     => 'Engineering Council of South Africa',
            'pattern'  => null,
            'example'  => '20123456',
            'register' => 'https://findregisteredpersonprod.powerappsportals.com/Who-is-Registered/',
            'research' => true,
        ],
    ];

    /** @var array<string,string> directory_categories.group_name => regulator */
    public array $byGroup = [
        'Health & Medical' => 'hpcsa',
    ];

    /** @var array<string,?string> category slug => regulator, or null to opt out of the group's */
    public array $bySlug = [
        'nurse'                => 'sanc',
        'midwife'              => 'sanc',
        'pharmacist'           => 'sapc',
        'chiropractor'         => 'ahpcsa',
        'social-worker'        => 'sacssp',
        'nutritionist'         => null,
        'pharmacy'             => null,
        'medical-clinic'       => null,
        'hospital'             => null,
        'pathology-laboratory' => null,
        'radiology-practice'   => null,

        'attorney'    => 'lpc',
        'conveyancer' => 'lpc',
        'notary'      => 'lpc',

        'architect' => 'sacap',
        'engineer'  => 'ecsa',

        // Phase 2, on the owner's go ahead: each needs its regulator above.
        // 'accountant'        => 'saica',
        // 'auditor'           => 'irba',
        // 'financial-adviser' => 'fsca',
        // 'estate-agent'      => 'ppra',
        // 'land-surveyor'     => 'sagc',
        // 'property-valuer'   => 'sacpvp',
    ];
}
