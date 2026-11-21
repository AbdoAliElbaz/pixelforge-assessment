<?php

namespace App\Http\Controllers;

use App\Services\AdminReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminReportController extends Controller
{
    public function index(Request $request, AdminReportService $reports)
    {
        abort_unless($request->user()->is_admin, 403);

        $report = $reports->build();

        return Inertia::render('Admin/Report', $report);
    }
}
