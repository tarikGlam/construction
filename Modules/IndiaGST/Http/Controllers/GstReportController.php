<?php

namespace Modules\IndiaGST\Http\Controllers;

use App\Services\PermissionService;
use App\Services\WarehouseAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\IndiaGST\Services\IndiaGstReportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GstReportController extends Controller
{
    public function __construct(
        private IndiaGstReportService $reportService,
        private PermissionService $permissions,
        private WarehouseAccessService $warehouseAccess
    ) {
    }

    public function index(Request $request)
    {
        $this->authorizeReport();
        $filters = $this->filters($request);
        $report = $this->reportService->report($filters);
        $options = $this->reportService->filterOptions();

        return view('indiagst::reports.gst_report', array_merge($report, $options, [
            'startingDate' => $filters['starting_date'],
            'endingDate' => $filters['ending_date'],
            'warehouseId' => $filters['warehouse_id'],
            'gstRegistrationId' => $filters['gst_registration_id'],
            'customerId' => $filters['customer_id'],
            'supplierId' => $filters['supplier_id'],
            'stateCode' => $filters['state_code'],
        ]));
    }

    public function export(Request $request)
    {
        $this->authorizeReport();
        $request->merge(['format' => $request->input('format', $request->input('type', 'csv'))]);
        $validated = $request->validate([
            'format' => ['required', 'in:csv,excel,pdf'],
            'tab' => ['nullable', 'in:all,output,input,expense'],
        ]);
        $filters = $this->filters($request);
        $report = $this->reportService->report($filters);
        $sections = $this->reportService->sections($report, $validated['tab'] ?? 'all');
        $filename = 'india_gst_operational_report_'.now()->format('Ymd_His');

        return match ($validated['format']) {
            'excel' => $this->excel($report, $sections, $filename),
            'pdf' => Pdf::loadView('indiagst::reports.gst_report_export', compact('report', 'sections', 'filters'))
                ->setPaper('a4', 'landscape')
                ->download($filename.'.pdf'),
            default => $this->csv($report, $sections, $filename),
        };
    }

    public function printView(Request $request)
    {
        $this->authorizeReport();
        $request->validate(['tab' => ['nullable', 'in:all,output,input,expense']]);
        $filters = $this->filters($request);
        $report = $this->reportService->report($filters);
        $sections = $this->reportService->sections($report, $request->input('tab', 'all'));

        return view('indiagst::reports.gst_report_export', compact('report', 'sections', 'filters'))
            ->with('printMode', true);
    }

    private function authorizeReport(): void
    {
        $user = auth()->user();
        abort_unless($user, 401);

        $classification = $this->warehouseAccess->classification($user);
        abort_unless(in_array($classification, [
            WarehouseAccessService::GLOBAL_OPERATIONAL,
            WarehouseAccessService::WAREHOUSE_OPERATIONAL,
        ], true), 403);

        if ((int) $user->role_id > 2) {
            abort_unless($this->permissions->userHasExplicitPermission($user, 'tax-report'), 403);
        }
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'starting_date' => ['nullable', 'date'],
            'ending_date' => ['nullable', 'date', 'after_or_equal:starting_date'],
            'warehouse_id' => ['nullable', 'integer', 'min:0'],
            'gst_registration_id' => ['nullable', 'integer', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'min:0'],
            'state_code' => ['nullable', 'regex:/^[0-9]{2}$/'],
        ]);

        $warehouseId = (int) ($validated['warehouse_id'] ?? 0);
        $user = auth()->user();
        if ($user && (int) $user->role_id > 2) {
            $allowedWarehouseId = $this->warehouseAccess->warehouseId($user);
            abort_unless($allowedWarehouseId && (!$warehouseId || $warehouseId === $allowedWarehouseId), 403);
            $warehouseId = $allowedWarehouseId;
        }

        return [
            'starting_date' => $validated['starting_date'] ?? now()->startOfMonth()->toDateString(),
            'ending_date' => $validated['ending_date'] ?? now()->toDateString(),
            'warehouse_id' => $warehouseId,
            'gst_registration_id' => (int) ($validated['gst_registration_id'] ?? 0),
            'customer_id' => (int) ($validated['customer_id'] ?? 0),
            'supplier_id' => (int) ($validated['supplier_id'] ?? 0),
            'state_code' => (string) ($validated['state_code'] ?? ''),
        ];
    }

    private function csv(array $report, array $sections, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report, $sections) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['INDIA GST OPERATIONAL REPORT SUMMARY']);
            foreach ($this->summaryRows($report['summary']) as $row) {
                fputcsv($handle, $row);
            }
            foreach ($sections as $section) {
                fputcsv($handle, []);
                fputcsv($handle, [strtoupper($section['title']).' REGISTER']);
                fputcsv($handle, $section['headings']);
                foreach ($section['rows'] as $row) {
                    fputcsv($handle, $row);
                }
                fputcsv($handle, $section['totals']);
            }
            fclose($handle);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function excel(array $report, array $sections, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Summary');
        $summarySheet->fromArray([['India GST Operational Report'], ['Metric', 'Amount']], null, 'A1');
        $summarySheet->fromArray($this->summaryRows($report['summary']), null, 'A3');

        foreach ($sections as $section) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle(substr($section['title'], 0, 31));
            $sheet->fromArray([$section['headings']], null, 'A1');
            if ($section['rows']) {
                $sheet->fromArray($section['rows'], null, 'A2');
            }
            $sheet->fromArray([$section['totals']], null, 'A'.(count($section['rows']) + 2));
            $sheet->freezePane('A2');
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function summaryRows(array $summary): array
    {
        return [
            ['Gross Output GST', $summary['gross_output_tax']],
            ['Seller Credit Note Adjustments', $summary['credit_note_output_tax']],
            ['Net Output GST', $summary['net_output_tax']],
            ['Net Purchase ITC', $summary['net_purchase_itc']],
            ['Expense Eligible ITC', $summary['expense_eligible_itc']],
            ['RCM Liability', $summary['rcm_liability']],
            ['RCM Eligible ITC', $summary['rcm_eligible_itc']],
            ['Blocked / Ineligible ITC (informational)', $summary['total_ineligible_itc']],
            ['Total Available ITC', $summary['total_available_itc']],
            ['Indicative Net GST Position', $summary['indicative_net_position']],
        ];
    }
}
