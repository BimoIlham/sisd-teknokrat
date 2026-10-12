<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CredentialSessionRevoker
{
    public function revoke(User $user, ?string $preservedSessionId = null): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') === 'database') {
            $sessions = DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id);
            if ($preservedSessionId !== null) {
                $sessions->where('id', '<>', $preservedSessionId);
            }
            $sessions->delete();
        }
    }
}
