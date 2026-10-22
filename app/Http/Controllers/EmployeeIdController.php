<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEmployeeIdRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeIdController extends Controller
{
    /**
     * Show the employee ID prompt to a user who has none; a user who already has one goes to their landing page.
     */
    public function edit(Request $request): View|RedirectResponse
    {
        if (filled($request->user()->employee_id)) {
            return redirect()->route($request->user()->landingRouteName());
        }

        return view('account.employee-id');
    }

    public function update(UpdateEmployeeIdRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return redirect()->route($request->user()->landingRouteName());
    }
}
