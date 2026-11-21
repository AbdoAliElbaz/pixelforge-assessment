<?php

namespace App\Http\Controllers;

use App\Services\CreditService;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function store(Request $request, CreditService $credits)
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'in:'.implode(',', CreditService::AMOUNTS_USD)],
        ]);

        $credits->purchase($request->user(), $data['amount']);

        return back();
    }
}
