<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProposeOpeningBalanceRequest;
use App\Http\Requests\ReviewOpeningBalanceRequest;
use App\Models\CashAccount;
use App\Models\OpeningBalanceRequest;
use App\Services\OpeningBalanceService;
use Illuminate\Http\JsonResponse;

class OpeningBalanceController extends Controller
{
    public function store(ProposeOpeningBalanceRequest $request, OpeningBalanceService $openingBalanceService): JsonResponse
    {
        $validated = $request->validated();
        $openingBalanceRequest = $openingBalanceService->propose(
            CashAccount::query()->findOrFail($validated['cash_account_id']),
            $validated['amount'],
            new \DateTimeImmutable($validated['effective_at']),
            $validated['reason'],
            $request->user(),
        );

        return response()->json(['data' => $openingBalanceRequest], 201);
    }

    public function review(ReviewOpeningBalanceRequest $request, OpeningBalanceRequest $openingBalanceRequest, OpeningBalanceService $openingBalanceService): JsonResponse
    {
        $validated = $request->validated();
        $openingBalanceService->review($openingBalanceRequest, $request->user(), $validated['decision'], $validated['review_notes'] ?? null);

        return response()->json(['data' => $openingBalanceRequest->fresh()]);
    }
}
