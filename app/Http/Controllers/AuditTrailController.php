<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail screen.
 *
 * Read-only by design: this controller has one method, and the policy allows
 * nothing but reading.
 */
class AuditTrailController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditTrail::class);

        $filters = $this->validatedFilters($request);

        $query = AuditTrail::query()
            ->forUser($filters['user_id'])
            ->forModule($filters['module'])
            ->forAction($filters['action'])
            ->betweenDates($filters['from'], $filters['to']);

        $table = TableQuery::for($query, $request)
            ->searchable(['description', 'username', 'user_name', 'ip_address'])
            ->sortable(['created_at', 'module', 'action', 'username'])
            ->defaultSort('created_at', 'desc')
            ->extra($filters);

        $trails = $table->paginate();

        return Inertia::render('Audit/Index', [
            'title' => 'Audit Trail',
            'trails' => $trails->through(fn (AuditTrail $trail) => [
                'id' => $trail->id,
                'user_name' => $trail->user_name,
                'username' => $trail->username,
                'role' => $trail->role,
                'module' => $trail->module,
                'action' => $trail->action->value,
                'action_label' => $trail->action->label(),
                'action_tone' => $trail->action->tone(),
                'description' => $trail->description,
                'properties' => $trail->properties,
                'ip_address' => $trail->ip_address,
                'browser' => $trail->browser,
                'created_at' => $trail->created_at?->format('d M Y, g:i:s A'),
                'created_at_short' => $trail->created_at?->format('d M Y, g:i A'),
            ]),
            'filters' => $table->state(),
            'options' => [
                'users' => $this->userOptions(),
                'modules' => $this->moduleOptions(),
                'actions' => AuditAction::options(),
            ],
        ]);
    }

    /**
     * Read the filters off the query string, refusing anything unexpected.
     *
     * @return array{user_id:?int,module:?string,action:?string,from:?string,to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'module' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', Rule::in(AuditAction::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            'module' => $validated['module'] ?? null,
            'action' => $validated['action'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    /**
     * Only the people who actually appear in the trail, so the list stays
     * short and every choice returns rows.
     *
     * @return list<array{value:int,label:string}>
     */
    protected function userOptions(): array
    {
        return AuditTrail::query()
            ->whereNotNull('user_id')
            ->select('user_id', 'username')
            ->distinct()
            ->orderBy('username')
            ->get()
            ->map(fn (AuditTrail $trail) => [
                'value' => $trail->user_id,
                'label' => $trail->username,
            ])
            ->unique('value')
            ->values()
            ->all();
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    protected function moduleOptions(): array
    {
        return AuditTrail::query()
            ->select('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->map(fn (string $module) => ['value' => $module, 'label' => $module])
            ->all();
    }
}
