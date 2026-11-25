<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\AuditService;
use App\Services\ReportBook;
use App\Support\ReportFilters;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The summaries.
 *
 * Every figure comes from `InventoryMetrics`, the same service the
 * dashboard reads, so a number shown on both screens means one thing.
 *
 * Read-only, with no write routes. Nothing is stored: a report is a
 * question asked of the documents, answered fresh each time. Phase 34 added
 * the Cashier and Payment method filters, the printed page (saved as PDF
 * from the print dialog) and the spreadsheet — all three read
 * `ReportFilters` and `ReportBook`, so the file covers exactly what the
 * screen showed.
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportBook $book,
        protected AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewReports', Sale::class);

        $filters = ReportFilters::fromRequest($request);
        $user = $request->user();

        return Inertia::render('Reports/Index', [
            'title' => 'Reports',
            'range' => [
                'from' => $filters->from->toDateString(),
                'to' => $filters->to->toDateString(),
                'label' => $filters->periodLabel(),
            ],
            'filters' => [
                'staff_id' => $filters->staffId,
                'payment_method_id' => $filters->paymentMethodId,
                'staff_label' => $filters->staffLabel(),
                'payment_label' => $filters->paymentLabel(),
            ],
            'options' => [
                'staff' => ReportFilters::staffOptions($user),
                'methods' => ReportFilters::methodOptions(),
            ],
            'export' => [
                'available' => Spreadsheet::available(),
                'query' => $filters->query(),
            ],
            ...$this->book->blocks($user, $filters),
        ]);
    }

    /**
     * The same report as a spreadsheet: a summary sheet, every document
     * behind it, and every row of each breakdown.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewReports', Sale::class);

        $filters = ReportFilters::fromRequest($request);

        abort_unless(Spreadsheet::available(), 503, 'The server\'s PHP has no zip extension, so a spreadsheet cannot be made.');

        $path = $this->book->workbook($request->user(), $filters);

        $this->audit->logView(
            'Reports',
            'Exported the report as a spreadsheet: '.$filters->periodLabel()
                .'; cashier: '.$filters->staffLabel()
                .'; payment method: '.$filters->paymentLabel().'.',
        );

        return response()
            ->download($path, $this->book->filename($filters), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
