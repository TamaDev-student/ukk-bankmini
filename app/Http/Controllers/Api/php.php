public function submitDailyReport(Request $request)
{
    $request->validate([
        'date' => ['required', 'date'],
    ]);

    $teller = $request->user();

    $service = new \App\Services\DailyReportService();

    $report = $service->submit(
        $teller,
        \Carbon\Carbon::parse($request->date)
    );

    return response()->json([
        'message' => 'Laporan harian berhasil dikirim.',
        'data' => $report,
    ], 201);
}