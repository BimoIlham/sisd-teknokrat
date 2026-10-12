<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

class LegacySupervisorCredentialInspector
{
    public function matchesKnownHistoricalPassword(User $user, #[SensitiveParameter] string $knownPassword): bool
    {
        return $knownPassword !== ''
            && $user->lecturer_id !== null
            && $user->hasRole('supervisor')
            && ! $user->hasRole('superadmin')
            && Hash::check($knownPassword, $user->password);
    }
}
