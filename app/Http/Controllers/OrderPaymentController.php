<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderPaymentController extends Controller
{
    /**
     * Store a newly created payment/advance for the order.
     */
    public function store(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['nullable', 'date'],
            'payment_method' => ['required', 'string', 'in:cash,transfer,card,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'voucher' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $effectiveTotal = $order->resolveEffectiveTotal();
        $totalPaid = round((float) $order->payments()->sum('amount'), 2);
        $remainingBalance = round(max(0.0, $effectiveTotal - $totalPaid), 2);
        $amount = round((float) $validated['amount'], 2);

        if ($amount <= 0 || $amount > $remainingBalance + 0.01) {
            $formattedAmount = number_format($amount, 0, ',', '.');
            $formattedRemaining = number_format($remainingBalance, 0, ',', '.');
            $errorMsg = "El valor del abono (\${$formattedAmount}) supera el saldo pendiente del pedido (\${$formattedRemaining}) después de descuentos.";

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errorMsg,
                    'errors' => ['amount' => [$errorMsg]],
                ], 422);
            }

            return redirect()->back()->withErrors(['amount' => $errorMsg]);
        }

        $voucherPath = null;
        if ($request->hasFile('voucher')) {
            $voucherPath = $request->file('voucher')->store('vouchers', 'public');
        }

        $payment = DB::transaction(function () use ($validated, $order, $voucherPath, $amount): OrderPayment {
            return OrderPayment::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'amount' => $amount,
                'payment_date' => !empty($validated['payment_date']) ? $validated['payment_date'] : now()->toDateString(),
                'payment_method' => $validated['payment_method'],
                'voucher_path' => $voucherPath,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });

        if ($request->wantsJson()) {
            $freshOrder = $order->fresh(['payments.creator', 'paymentSchedules']);
            return response()->json([
                'success' => true,
                'message' => 'Abono registrado exitosamente.',
                'payment' => $payment->load('creator'),
                'payments' => $freshOrder->payments,
                'payment_schedules' => $freshOrder->paymentSchedules,
                'paid_amount' => (float) $freshOrder->paid_amount,
                'total_paid' => (float) $freshOrder->paid_amount,
                'pending_balance' => (float) $freshOrder->pending_balance,
            ]);
        }

        return redirect()->back()->with('success', 'Abono registrado exitosamente.');
    }

    /**
     * Delete/void a payment and clean up its physical voucher.
     */
    public function destroy(OrderPayment $payment): RedirectResponse|JsonResponse
    {
        abort_if(auth()->user()?->currentRole() === 'worker', 403);

        $order = $payment->order;

        DB::transaction(function () use ($payment): void {
            if ($payment->voucher_path && Storage::disk('public')->exists($payment->voucher_path)) {
                Storage::disk('public')->delete($payment->voucher_path);
            }

            $payment->delete();
        });

        if (request()->wantsJson()) {
            $freshOrder = $order?->fresh(['payments.creator', 'paymentSchedules']);
            return response()->json([
                'success' => true,
                'message' => 'Abono anulado exitosamente.',
                'payments' => $freshOrder?->payments ?? [],
                'payment_schedules' => $freshOrder?->paymentSchedules ?? [],
                'paid_amount' => (float) ($freshOrder?->paid_amount ?? 0),
                'total_paid' => (float) ($freshOrder?->paid_amount ?? 0),
                'pending_balance' => (float) ($freshOrder?->pending_balance ?? 0),
            ]);
        }

        return redirect()->back()->with('success', 'Abono anulado exitosamente.');
    }
}
