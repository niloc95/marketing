<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\Api\ApiKeys as Keys;

/**
 * Settings, API keys tab (/business/settings/api, Phase 9). Admins only
 * (api_keys.manage). A new key is shown once, on the page after it is made,
 * and never again.
 */
class ApiKeys extends BaseController
{
    private const BACK = 'business/settings/api';

    /** @return array<string,mixed> */
    public static function viewData(int $listingId): array
    {
        return [
            'keys'   => (new Keys($listingId))->list(),
            'scopes' => Keys::SCOPES,
            'newKey' => flash('api_key_new'),
            'docs'   => base_url('developers/bookings'),
        ];
    }

    public function create()
    {
        $context = service('businessContext');
        $expires = (string) $this->request->getPost('expires');
        $result  = (new Keys($context->listingId()))->create(
            (int) $context->member()['id'],
            (string) $this->request->getPost('name'),
            array_map('strval', (array) ($this->request->getPost('scopes') ?? [])),
            $expires === '' ? null : (int) $expires,
            $context->accountId(),
        );
        if ($result['errors'] !== []) {
            return redirect()->to(base_url(self::BACK))->with('error', implode(' ', $result['errors']));
        }

        return redirect()->to(base_url(self::BACK . '#new-key'))->with('api_key_new', $result['key']);
    }

    public function revoke(int $id)
    {
        $context = service('businessContext');
        $ok      = (new Keys($context->listingId()))->revoke($id, $context->accountId());

        return redirect()->to(base_url(self::BACK))->with($ok ? 'success' : 'error', $ok ? 'Revoked. The key stops working now.' : 'That key was not found.');
    }
}
