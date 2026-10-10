<?php

namespace App\Controllers;

use App\Models\DirectoryCategoryModel;
use App\Services\Description\CategoryInsightsService;

/**
 * AJAX backend for "Help me write this" on the description field of the
 * signup, owner and admin listing forms. Public and unauthenticated like
 * AddressSuggest (signup comes before any session), so throttled per IP.
 *
 * Both actions only read. draft() writes text from the form values it is
 * sent and stores nothing; the owner decides whether to keep it. Nothing here
 * costs money per call, so the throttle is only there to stop the insights
 * query being used as a load test.
 */
class DescriptionHelper extends BaseController
{
    private const MAX_PER_MINUTE = 30;

    /** The three listing columns the form shows beside the ticked features. */
    private const FLAG_FEATURES = [
        'accepts_card_payments' => 'Card payments accepted',
        'offers_delivery'       => 'Delivery or mobile service',
        'offers_online_booking' => 'Online booking',
    ];

    public function draft()
    {
        if (! $this->allow('description-draft')) {
            return $this->response->setStatusCode(429)->setJSON(['html' => '', 'message' => 'Too many drafts in a row. Try again in a minute.']);
        }

        $category = (new DirectoryCategoryModel())->where('is_active', 1)->find((int) $this->request->getGet('category_id'));
        $name     = trim((string) $this->request->getGet('display_name'));
        if (! is_array($category) || $name === '') {
            return $this->response->setJSON([
                'html'    => '',
                'message' => 'Choose your category and type your business name first, then try again.',
            ]);
        }

        $services = array_values(array_filter(array_map(
            static fn ($s): string => trim((string) $s),
            (array) $this->request->getGet('services')
        )));
        $tags  = $this->list((string) $this->request->getGet('tags'), ',');
        $areas = $this->list((string) $this->request->getGet('service_areas'), "\n");

        $labels   = config('ListingAttributes')->forGroup((string) $category['group_name']) + self::FLAG_FEATURES;
        $features = [];
        foreach ((array) $this->request->getGet('features') as $key) {
            if (is_string($key) && isset($labels[$key])) {
                $features[] = $labels[$key];
            }
        }

        $location = (string) $this->request->getGet('customer_location');
        $facts    = [
            'type'              => (string) $this->request->getGet('type'),
            'name'              => mb_substr($name, 0, 200),
            'category'          => (string) $category['name'],
            'group'             => (string) $category['group_name'],
            'city'              => mb_substr(trim((string) $this->request->getGet('city')), 0, 120),
            'suburb'            => mb_substr(trim((string) $this->request->getGet('suburb')), 0, 120),
            'customer_location' => in_array($location, ['visit', 'travel', 'both'], true) ? $location : 'visit',
            'service_areas'     => $areas,
            'services'          => array_slice($services, 0, 20),
            'tags'              => array_slice($tags, 0, 20),
            'features'          => $features,
        ];

        // What would make the next draft better, in the order the form asks.
        $missing = [];
        if ($services === [] && $tags === []) {
            $missing[] = 'your services or areas of focus';
        }
        if ($location !== 'visit' && $areas === []) {
            $missing[] = 'the places you travel to';
        }
        if ($features === []) {
            $missing[] = 'your features';
        }

        return $this->response->setJSON([
            'html'    => service('descriptionWriter')->draft($facts),
            'message' => $missing === []
                ? 'Here is a draft. Make it yours: add what makes you different, then save.'
                : 'Here is a draft. Add ' . $this->sentenceList($missing) . ' further down the form, then press the button again for a fuller one.',
        ]);
    }

    public function insights()
    {
        if (! $this->allow('description-insights')) {
            return $this->response->setJSON(['category' => '', 'source' => 'none', 'hints' => []]);
        }

        $id = (int) $this->request->getGet('category_id');

        return $this->response->setJSON(
            $id > 0
                ? (new CategoryInsightsService())->forCategory($id)
                : ['category' => '', 'source' => 'none', 'hints' => []]
        );
    }

    /** @return list<string> */
    private function list(string $text, string $separator): array
    {
        return array_values(array_filter(array_map('trim', explode($separator, str_replace("\r", '', $text)))));
    }

    /** @param list<string> $items */
    private function sentenceList(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items) . ' and ' . $last;
    }

    /** Per-IP, per-action rate limit. False means "over the limit". */
    private function allow(string $action): bool
    {
        return service('throttler')->check(
            $action . '-' . md5((string) $this->request->getIPAddress()),
            self::MAX_PER_MINUTE,
            MINUTE
        ) !== false;
    }
}
