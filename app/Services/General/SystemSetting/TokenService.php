<?php
namespace App\Services\General\SystemSetting;

use App\Interfaces\General\SystemSettingInterface;
use Illuminate\Support\Facades\Auth;



class TokenService
{
    public function __construct(private readonly SystemSettingInterface $systemSettingInterface)
    {
    }

    public function getTokenList(int $perPage = 10)
    {
        $user = Auth::user();
        return [
            'tokens' => $this->systemSettingInterface->getUserActiveTokens($user, $perPage),
            'scopes' => $this->systemSettingInterface->getAllScopes()
        ];
    }

    public function removeToken($id): bool
    {
        return $this->systemSettingInterface->removeToken($id);
    }

    public function createToken(array $data): string
    {
        $user = Auth::user();
        $scopes = $data['scopes'] ?? [];
        return $this->systemSettingInterface->createToken(
            $user,
            $data['name'],
            $scopes
        );
    }
}