<?php

namespace Tests\Support;

/**
 * The fields every signup and owner edit must now carry to be accepted:
 * a real description, complete opening hours, one service, a title and a
 * position (see DirectoryListingMutationService::validate()).
 *
 * `+`, not array_merge: whatever a test sets itself wins, so a test about a
 * missing description passes 'description' => '' and gets exactly that.
 */
trait ValidListingInput
{
    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    protected function withRequiredSections(array $post = []): array
    {
        $hours = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $day) {
            $hours[$day] = ['open' => '08:00', 'close' => '17:00'];
        }
        $hours['sat'] = ['closed' => '1'];
        $hours['sun'] = ['closed' => '1'];

        return $post + [
            'title'            => 'Mr',
            'position'         => 'Owner',
            'description'      => 'We are a friendly local business serving customers across the area since 2010.',
            'hours'            => $hours,
            'services_present' => '1',
            'services'         => [['name' => 'Consultation', 'price_label' => 'R250']],
        ];
    }
}
