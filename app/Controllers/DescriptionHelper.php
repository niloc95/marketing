<?php

namespace App\Controllers;

use App\Models\DirectoryCategoryModel;
use App\Services\Description\CategoryInsightsService;
use App\Services\Description\DescriptionPrompt;

/**
 * AJAX backend for the description field of the signup, owner and admin
 * listing forms: the category hints, and the prompt for "Write it with
 * ChatGPT". Public and unauthenticated like AddressSuggest (signup comes
 * before any session), so throttled per IP.
 *
 * Both actions only read and store nothing. Nothing here costs money per
 * call, so the throttle is only there to stop the insights query being used
 * as a load test.
 */
class DescriptionHelper extends BaseController
{
    private const MAX_PER_MINUTE = 30;

    private const NEED_BASICS = 'Choose your category and type your business name first, then try again.';

    /**
     * The four questions under the description (see _form_fields.php), by
     * the name directory.js sends each answer under. Used for the prompt only:
     * the inputs have no name of their own, so the form never saves them.
     */
    private const ANSWERS = ['approach', 'people', 'years', 'different'];

    private const ANSWER_MAX = 400;

    /** The three listing columns the form shows beside the ticked features. */
    private const FLAG_FEATURES = [
        'accepts_card_payments' => 'Card payments accepted',
        'offers_delivery'       => 'Delivery or mobile service',
        'offers_online_booking' => 'Online booking',
    ];

    /**
     * The prompt for "Write it with ChatGPT": the owner's facts and our
     * writing rules as plain text. The browser opens ChatGPT with it in a new
     * tab (ChatGPT will not load in a frame) and copies it to the clipboard.
     * We never call OpenAI ourselves; the owner sends it, from their account.
     */
    public function prompt()
    {
        if (! $this->allow('description-prompt')) {
            return $this->response->setStatusCode(429)->setJSON(['prompt' => '', 'message' => 'Too many tries in a row. Try again in a minute.']);
        }

        $facts = $this->facts();

        return $this->response->setJSON($facts === null
            ? ['prompt' => '', 'message' => self::NEED_BASICS]
            : ['prompt' => DescriptionPrompt::build($facts), 'message' => '']);
    }

    /**
     * The form as sent, cleaned up into the facts DescriptionPrompt reads
     * (the same shape DescriptionWriter takes, plus credentials and the four
     * answers). Null without an active category and a business name.
     *
     * @return array<string,mixed>|null
     */
    private function facts(): ?array
    {
        $category = (new DirectoryCategoryModel())->where('is_active', 1)->find((int) $this->request->getGet('category_id'));
        $name     = trim((string) $this->request->getGet('display_name'));
        if (! is_array($category) || $name === '') {
            return null;
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
        $location = in_array($location, ['visit', 'travel', 'both'], true) ? $location : 'visit';

        $answers = [];
        foreach (self::ANSWERS as $key) {
            $answers[$key] = $this->text('q_' . $key, self::ANSWER_MAX);
        }

        return $answers + [
            'type'              => (string) $this->request->getGet('type'),
            'name'              => mb_substr($name, 0, 200),
            'category'          => (string) $category['name'],
            'group'             => (string) $category['group_name'],
            'city'              => mb_substr(trim((string) $this->request->getGet('city')), 0, 120),
            'suburb'            => mb_substr(trim((string) $this->request->getGet('suburb')), 0, 120),
            'customer_location' => $location,
            'service_areas'     => $areas,
            'services'          => array_slice($services, 0, 20),
            'tags'              => array_slice($tags, 0, 20),
            'features'          => $features,
            'credentials'       => $this->text('credentials', 500),
        ];
    }

    /** One free text field, single spaced and cut to $max characters. */
    private function text(string $key, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $this->request->getGet($key))), 0, $max);
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
