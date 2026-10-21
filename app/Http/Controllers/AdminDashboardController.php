<?php

namespace App\Http\Controllers;

use App\Services\AdminDashboardPageService;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(AdminDashboardPageService $page): View
    {
        return view('admin.dashboard', $page->showData());
    }
}
