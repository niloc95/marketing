<?php

namespace App\Controllers;

use App\Services\SesNotificationService;

/**
 * POST /hooks/ses: Amazon SNS delivers SES bounce and complaint notices here
 * (SesNotificationService has the rules). CSRF, honeypot and bot trap exempt
 * in Config\Filters, like the payment webhooks. Answers 200 to anything it
 * understood or ignored, so SNS does not retry; 403 to a bad signature; 404
 * while it is switched off.
 */
class SesHook extends BaseController
{
    public function index()
    {
        $result = (new SesNotificationService())->handle((string) $this->request->getBody());

        return $this->response->setStatusCode(match ($result) {
            'off'     => 404,
            'refused' => 403,
            default   => 200,
        })->setBody('');
    }
}
