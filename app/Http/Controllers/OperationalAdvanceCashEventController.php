<?php

namespace App\Http\Controllers;

use App\Http\Requests\CorrectAdvanceCashEventRequest;
use App\Http\Requests\PayAdvanceReimbursementRequest;
use App\Http\Requests\ReceiveAdvanceReturnRequest;
use App\Http\Requests\RejectAdvanceReturnRequest;
use App\Http\Requests\SubmitAdvanceReturnRequest;
use App\Models\OperationalAdvance;
use App\Models\Transaction;
use App\Services\OperationalAdvanceReconciliationService;
use Illuminate\Http\RedirectResponse;

class OperationalAdvanceCashEventController extends Controller
{
    public function submitReturn(
        SubmitAdvanceReturnRequest $request,
        OperationalAdvance $operationalAdvance,
        OperationalAdvanceReconciliationService $workflow,
    ): RedirectResponse {
        $workflow->submitReturn($operationalAdvance, $request->user(), $request->validated());

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Pengajuan pengembalian sisa bon menunggu verifikasi Keuangan Sarpras.');
    }

    public function receiveReturn(
        ReceiveAdvanceReturnRequest $request,
        OperationalAdvance $operationalAdvance,
        Transaction $transaction,
        OperationalAdvanceReconciliationService $workflow,
    ): RedirectResponse {
        $workflow->receiveReturn($operationalAdvance, $transaction, $request->user(), (int) $request->validated('cash_account_id'));

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Pengembalian bon diverifikasi dan dicatat sebagai kas masuk.');
    }

    public function rejectReturn(
        RejectAdvanceReturnRequest $request,
        OperationalAdvance $operationalAdvance,
        Transaction $transaction,
        OperationalAdvanceReconciliationService $workflow,
    ): RedirectResponse {
        $workflow->rejectReturn($operationalAdvance, $transaction, $request->user(), $request->validated('reason'));

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Pengajuan pengembalian bon ditolak dengan alasan tercatat.');
    }

    public function payReimbursement(
        PayAdvanceReimbursementRequest $request,
        OperationalAdvance $operationalAdvance,
        OperationalAdvanceReconciliationService $workflow,
    ): RedirectResponse {
        $workflow->payReimbursement($operationalAdvance, $request->user(), $request->validated());

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Pembayaran penggantian kepada penerima dicatat sebagai kas keluar.');
    }

    public function correct(
        CorrectAdvanceCashEventRequest $request,
        OperationalAdvance $operationalAdvance,
        Transaction $transaction,
        OperationalAdvanceReconciliationService $workflow,
    ): RedirectResponse {
        $workflow->correctCashEvent($transaction, $request->user(), $request->validated());

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Cash event bon dikoreksi melalui reversal dan transaksi pengganti.');
    }
}
