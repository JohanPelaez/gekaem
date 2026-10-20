<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderPaymentController extends Controller
{
    /**
     * Store a newly created payment/advance for the order.
     */
    public function store(Request $request, Order $order): RedirectResponse
    {
        $maxAmount = max(0.01, (float) $order->pending_balance);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', "max:{$maxAmount}"],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'string', 'in:cash,transfer,card,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'voucher' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'amount.max' => "El abono no puede superar el saldo pendiente ($ " . number_format($maxAmount, 2) . ").",
        ]);

        $voucherPath = null;
        if ($request->hasFile('voucher')) {
            $voucherPath = $request->file('voucher')->store('vouchers', 'public');
        }

        DB::transaction(function () use ($validated, $order, $voucherPath): void {
            OrderPayment::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'voucher_path' => $voucherPath,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });

        return redirect()->back()->with('success', 'Abono registrado exitosamente.');
    }

    /**
     * Delete/void a payment and clean up its physical voucher.
     */
    public function destroy(OrderPayment $payment): RedirectResponse
    {
        abort_if(auth()->user()?->currentRole() === 'worker', 403);

        DB::transaction(function () use ($payment): void {
            if ($payment->voucher_path && Storage::disk('public')->exists($payment->voucher_path)) {
                Storage::disk('public')->delete($payment->voucher_path);
            }

            $payment->delete();
        });

        return redirect()->back()->with('success', 'Abono anulado exitosamente.');
    }
}
