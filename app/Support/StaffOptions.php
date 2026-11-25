<?php

namespace App\Support;

use App\Models\User;

/**
 * The people a "Staff" filter offers: everybody who can post a document.
 *
 * One list, so the dashboard and the document lists it links to name the
 * same people the same way. Every document records who posted it in
 * `posted_by`, and that is what the filter narrows on.
 */
class StaffOptions
{
    /**
     * @return list<array{value:int,label:string}>
     */
    public static function all(): array
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'username'])
            ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name.' (@'.$user->username.')'])
            ->all();
    }
}
