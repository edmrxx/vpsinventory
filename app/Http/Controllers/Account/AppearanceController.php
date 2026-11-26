<?php

namespace App\Http\Controllers\Account;

use App\Enums\Appearance;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A person's own light or dark screen (phase 35.1, CR-3).
 *
 * It needs no permission: it changes only how the app looks to the person
 * asking, and nothing anybody else sees. It is not in `$fillable`, so the
 * Users form can never set or clear it, and the audit trail treats it as
 * noise, like the remember token — a row for every switch would bury the
 * changes that matter.
 */
class AppearanceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Null puts the person back on the default in Theme Settings.
            'appearance' => ['present', 'nullable', 'string', Rule::enum(Appearance::class)],
        ]);

        $request->user()->forceFill(['appearance' => $data['appearance']])->save();

        return back();
    }
}
