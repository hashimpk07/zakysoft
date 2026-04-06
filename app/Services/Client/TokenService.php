<?php

namespace App\Services\Client;

use App\Client;
use App\PartnerAccessToken;
use App\User;
use Exception;

class TokenService
{
    public function getClientApiTokens(Client $client)
    {
        return $client->user->tokens()->select('id', 'name')->get();
    }

    public function generateClientApiToken(User $user, array $validated): string
    {
        $token = $user->createToken($validated['api_key_name'])->accessToken;
        $user->update(['apikey_create_btn' => true]);
        return $token;
    }

    public function getClientWebhooks(Client $client)
    {
        $tokens = $client
            ->partnerAccessTokens()
            ->whereIn('type', [PartnerAccessToken::TYPE_STATUS_UPDATE, PartnerAccessToken::TYPE_CAPTAIN_LOCATION])
            ->select('id', 'type', 'webhooks_url', 'access_token')
            ->get()
            ->keyBy('type');

        return [
            'webhook' => $tokens[PartnerAccessToken::TYPE_STATUS_UPDATE] ?? null,
            'webhook_captain_location' => $tokens[PartnerAccessToken::TYPE_CAPTAIN_LOCATION] ?? null,
        ];
    }

    public function updateOrCreateClientWebhook(Client $client, array $validated)
    {
        // Update or create the partner access token
        $access = $client->partnerAccessTokens()->updateOrCreate(
            ['type' => $validated['type']],
            [
                'webhooks_url' => $validated['webhook_url'] ?? null,
                'access_token' => $validated['webhook_secret_key'] ?? null,
            ],
        );

        // Update corresponding user flag
        $this->updateUserWebhookFlag($client->user, $validated['type']);

        $action = $access->wasRecentlyCreated ? 'added' : 'updated';

        return compact('access', 'action');
    }

    /**
     * Update the relevant webhook flag for the user.
     */
    protected function updateUserWebhookFlag(User $user, string $type): void
    {
        if ($type === PartnerAccessToken::TYPE_STATUS_UPDATE) {
            $user->status_wb_hk = 1;
        } elseif ($type === PartnerAccessToken::TYPE_CAPTAIN_LOCATION) {
            $user->cap_loc_wb_hk = 1;
        }

        $user->save();
    }
}
