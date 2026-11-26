<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use App\Support\SidebarBadges;
use App\Support\ThemeStyles;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Data shared with every Inertia response.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $settings = app(Settings::class);

        // What waits on this person, read at most once per request, when the
        // sidebar or the bell first asks (see SidebarBadges).
        $cache = null;
        $waiting = function () use (&$cache, $user) {
            return $cache ??= SidebarBadges::waiting($user);
        };

        return array_merge(parent::share($request), [
            // The company name comes from the settings table, not from the
            // environment file, so an admin can change it.
            'appName' => $settings->get('company.name'),
            'company' => $settings->group('company'),
            'theme' => $settings->group('theme'),
            // What the signed-in person sees — their own choice, else the
            // default — so the page can put it on <html> after every visit
            // (sign-in and sign-out are visits, not page loads). Null for a
            // guest: the sign-in pages are always light.
            'appearance' => app(ThemeStyles::class)->appearanceFor($user)?->value,
            // The currency reaches every screen that shows an amount, so no
            // page has to carry a hard-coded peso sign.
            'system' => $settings->group('system'),
            // Whether the business trades under VAT, and at what rate. The
            // entry screens read this; a posted document reads the rate
            // stamped on its own row instead.
            'tax' => $settings->group('tax'),
            // What a printed sale slip carries besides the sale itself.
            // Any screen that can open a sale can print it.
            // How the full-page documents print: paper, margins, the
            // letterhead and the terms (phase 35). Small, and every printed
            // page reads it.
            'printing' => $settings->group('printing'),
            'slip' => [
                'width' => (string) $settings->get('documents.slip_width', '80'),
                'footer' => $settings->get('documents.slip_footer'),
            ],
            // What waits on this person: red counts on the sidebar, keyed by
            // the item's `badge` in navigation.js, and the same list under
            // the bell — each read lazily, and worked out once for both.
            'badges' => fn () => array_column($waiting(), 'count', 'key'),
            'notifications' => fn () => array_values(array_filter($waiting(), fn (array $item) => $item['count'] > 0)),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'initials' => $user->initials(),
                    // Their own light or dark choice; null follows the default.
                    'appearance' => $user->appearance,
                    'roles' => $user->getRoleNames()->values()->all(),
                    // The front end uses this list to hide menu items and
                    // buttons. The server still checks every request.
                    'permissions' => $user->getAllPermissions()
                        ->pluck('name')
                        ->values()
                        ->all(),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
                'sessionExpired' => fn () => $request->session()->get('sessionExpired'),
                // The sale the till just posted, so it can offer its slip.
                'sale' => fn () => $request->session()->get('sale'),
                // A held sale the till just claimed, to put back on the counter.
                'recalled' => fn () => $request->session()->get('recalled'),
                // The order the kiosk just sent to the cashier (CR-2), so it
                // can offer its slip.
                'pending' => fn () => $request->session()->get('pending'),
            ],
            // The cookie wins. The theme setting is the starting point for
            // somebody who has never folded the menu.
            'sidebarCollapsed' => $request->cookie('sidebar_collapsed') !== null
                ? $request->cookie('sidebar_collapsed') === '1'
                : (bool) $settings->get('theme.sidebar_collapsed'),
        ]);
    }
}
