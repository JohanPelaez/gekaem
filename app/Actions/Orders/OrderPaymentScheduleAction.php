<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderPaymentSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OrderPaymentScheduleAction
{
    /**
     * Create a new scheduled payment for an order after verifying the discounted cap.
     *
     * @param array{scheduled_date: string, amount: float|int|string, notes?: ?string} $data
     * @return array<string, mixed>
     */
    public function schedule(Order $order, array $data): array
    {
        $amount = round((float) $data['amount'], 2);
        $totalOrder = $order->resolveEffectiveTotal();
        $totalPaid = round((float) $order->payments()->sum('amount'), 2);
        $totalPendingScheduled = round(
            (float) $order->paymentSchedules()->where('status', 'pending')->sum('amount'),
            2
        );
        $availableToSchedule = round(max(0.0, $totalOrder - $totalPaid - $totalPendingScheduled), 2);

        if ($amount <= 0 || $amount > $availableToSchedule + 0.01) {
            $formattedAvailable = number_format($availableToSchedule, 0, ',', '.');
            throw ValidationException::withMessages([
                'amount' => "El cobro programado supera el valor total del pedido (disponible por programar tras descuentos y abonos: \${$formattedAvailable}).",
            ]);
        }

        $schedule = DB::transaction(function () use ($order, $data, $amount): OrderPaymentSchedule {
            return OrderPaymentSchedule::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'scheduled_date' => $data['scheduled_date'],
                'amount' => $amount,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                    ? trim((string) $data['notes'])
                    : null,
                'status' => 'pending',
            ]);
        });

        $freshOrder = $order->fresh([
            'payments.creator',
            'paymentSchedules' => fn ($q) => $q->orderBy('scheduled_date')->orderBy('id'),
        ]);

        return [
            'schedule' => $schedule->fresh(),
            'payments' => $freshOrder->payments,
            'payment_schedules' => $freshOrder->paymentSchedules,
            'total_amount' => (float) $freshOrder->total_amount,
            'paid_amount' => (float) $freshOrder->paid_amount,
            'total_paid' => (float) $freshOrder->paid_amount,
            'pending_balance' => (float) $freshOrder->pending_balance,
        ];
    }

    /**
     * Mark a pending scheduled payment as paid and create the corresponding real OrderPayment.
     *
     * @param array{payment_method?: ?string} $data
     * @return array<string, mixed>
     */
    public function markPaid(Order $order, OrderPaymentSchedule $schedule, array $data = []): array
    {
        if ((int) $schedule->order_id !== (int) $order->id || (int) $schedule->tenant_id !== (int) $order->tenant_id) {
            abort(404, 'Cobro programado no pertenece a este pedido.');
        }

        if ($schedule->status !== 'pending') {
            throw ValidationException::withMessages([
                'schedule' => 'Este cobro programado ya no se encuentra pendiente.',
            ]);
        }

        $totalOrder = $order->resolveEffectiveTotal();
        $totalPaid = round((float) $order->payments()->sum('amount'), 2);
        $remainingBalance = round(max(0.0, $totalOrder - $totalPaid), 2);
        $amount = round((float) $schedule->amount, 2);

        if ($amount > $remainingBalance + 0.01) {
            $formattedAmount = number_format($amount, 0, ',', '.');
            $formattedRemaining = number_format($remainingBalance, 0, ',', '.');
            throw ValidationException::withMessages([
                'amount' => "El valor del abono (\${$formattedAmount}) supera el saldo pendiente del pedido (\${$formattedRemaining}) después de descuentos.",
            ]);
        }

        $payment = DB::transaction(function () use ($order, $schedule, $data, $amount): OrderPayment {
            $dateStr = $schedule->scheduled_date?->format('Y-m-d') ?? now()->toDateString();
            $note = "Cobro programado ({$dateStr})" . ($schedule->notes ? ' - ' . $schedule->notes : '');

            $createdPayment = OrderPayment::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'amount' => $amount,
                'payment_date' => now()->toDateString(),
                'payment_method' => $data['payment_method'] ?? 'transfer',
                'notes' => mb_substr($note, 0, 1000),
                'created_by' => auth()->id(),
            ]);

            $schedule->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_id' => $createdPayment->id,
            ]);

            return $createdPayment;
        });

        $freshOrder = $order->fresh([
            'payments.creator',
            'paymentSchedules' => fn ($q) => $q->orderBy('scheduled_date')->orderBy('id'),
        ]);

        return [
            'payment' => $payment->load('creator'),
            'payments' => $freshOrder->payments,
            'payment_schedules' => $freshOrder->paymentSchedules,
            'total_amount' => (float) $freshOrder->total_amount,
            'paid_amount' => (float) $freshOrder->paid_amount,
            'total_paid' => (float) $freshOrder->paid_amount,
            'pending_balance' => (float) $freshOrder->pending_balance,
        ];
    }

    /**
     * Update a pending scheduled payment after verifying the discounted cap excluding its previous amount.
     *
     * @param array{scheduled_date: string, amount: float|int|string, notes?: ?string} $data
     * @return array<string, mixed>
     */
    public function update(Order $order, OrderPaymentSchedule $schedule, array $data): array
    {
        if ((int) $schedule->order_id !== (int) $order->id || (int) $schedule->tenant_id !== (int) $order->tenant_id) {
            abort(404, 'Cobro programado no pertenece a este pedido.');
        }

        if ($schedule->status !== 'pending') {
            throw ValidationException::withMessages([
                'schedule' => 'Primero deshaz el cobro para poder editar este registro programado.',
            ]);
        }

        $amount = round((float) $data['amount'], 2);
        $totalOrder = $order->resolveEffectiveTotal();
        $totalPaid = round((float) $order->payments()->sum('amount'), 2);
        $otherPendingScheduled = round(
            (float) $order->paymentSchedules()
                ->where('status', 'pending')
                ->where('id', '!=', $schedule->id)
                ->sum('amount'),
            2
        );
        $maxAllowedForThisSchedule = round(max(0.0, $totalOrder - $totalPaid - $otherPendingScheduled), 2);

        if ($amount <= 0 || $amount > $maxAllowedForThisSchedule + 0.01) {
            $formattedMax = number_format($maxAllowedForThisSchedule, 0, ',', '.');
            throw ValidationException::withMessages([
                'amount' => "El monto supera el disponible por programar tras descuentos y abonos (\${$formattedMax}).",
            ]);
        }

        DB::transaction(function () use ($schedule, $data, $amount): void {
            $schedule->update([
                'scheduled_date' => $data['scheduled_date'],
                'amount' => $amount,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                    ? trim((string) $data['notes'])
                    : null,
            ]);
        });

        $freshOrder = $order->fresh([
            'payments.creator',
            'paymentSchedules' => fn ($q) => $q->orderBy('scheduled_date')->orderBy('id'),
        ]);

        return [
            'schedule' => $schedule->fresh(),
            'payments' => $freshOrder->payments,
            'payment_schedules' => $freshOrder->paymentSchedules,
            'total_amount' => (float) $freshOrder->total_amount,
            'paid_amount' => (float) $freshOrder->paid_amount,
            'total_paid' => (float) $freshOrder->paid_amount,
            'pending_balance' => (float) $freshOrder->pending_balance,
        ];
    }

    /**
     * Revert a paid scheduled payment back to pending and remove its linked OrderPayment.
     *
     * @return array<string, mixed>
     */
    public function unmarkPaid(Order $order, OrderPaymentSchedule $schedule): array
    {
        if ((int) $schedule->order_id !== (int) $order->id || (int) $schedule->tenant_id !== (int) $order->tenant_id) {
            abort(404, 'Cobro programado no pertenece a este pedido.');
        }

        if ($schedule->status !== 'paid') {
            throw ValidationException::withMessages([
                'schedule' => 'Este cobro programado no se encuentra en estado cobrado.',
            ]);
        }

        DB::transaction(function () use ($order, $schedule): void {
            if ($schedule->payment_id) {
                $linkedPayment = OrderPayment::where('id', $schedule->payment_id)
                    ->where('order_id', $order->id)
                    ->where('tenant_id', $order->tenant_id)
                    ->first();

                if ($linkedPayment) {
                    if ($linkedPayment->voucher_path && Storage::disk('public')->exists($linkedPayment->voucher_path)) {
                        Storage::disk('public')->delete($linkedPayment->voucher_path);
                    }
                    $linkedPayment->delete();
                }
            }

            $schedule->update([
                'status' => 'pending',
                'paid_at' => null,
                'payment_id' => null,
            ]);
        });

        $freshOrder = $order->fresh([
            'payments.creator',
            'paymentSchedules' => fn ($q) => $q->orderBy('scheduled_date')->orderBy('id'),
        ]);

        return [
            'schedule' => $schedule->fresh(),
            'payments' => $freshOrder->payments,
            'payment_schedules' => $freshOrder->paymentSchedules,
            'total_amount' => (float) $freshOrder->total_amount,
            'paid_amount' => (float) $freshOrder->paid_amount,
            'total_paid' => (float) $freshOrder->paid_amount,
            'pending_balance' => (float) $freshOrder->pending_balance,
        ];
    }

    /**
     * Delete a scheduled payment (pending or paid) from an order, removing any linked payment if paid.
     *
     * @return array<string, mixed>
     */
    public function destroy(Order $order, OrderPaymentSchedule $schedule): array
    {
        if ((int) $schedule->order_id !== (int) $order->id || (int) $schedule->tenant_id !== (int) $order->tenant_id) {
            abort(404, 'Cobro programado no pertenece a este pedido.');
        }

        DB::transaction(function () use ($order, $schedule): void {
            if ($schedule->status === 'paid' && $schedule->payment_id) {
                $linkedPayment = OrderPayment::where('id', $schedule->payment_id)
                    ->where('order_id', $order->id)
                    ->where('tenant_id', $order->tenant_id)
                    ->first();

                if ($linkedPayment) {
                    if ($linkedPayment->voucher_path && Storage::disk('public')->exists($linkedPayment->voucher_path)) {
                        Storage::disk('public')->delete($linkedPayment->voucher_path);
                    }
                    $linkedPayment->delete();
                }
            }

            $schedule->delete();
        });

        $freshOrder = $order->fresh([
            'payments.creator',
            'paymentSchedules' => fn ($q) => $q->orderBy('scheduled_date')->orderBy('id'),
        ]);

        return [
            'payments' => $freshOrder->payments,
            'payment_schedules' => $freshOrder->paymentSchedules,
            'total_amount' => (float) $freshOrder->total_amount,
            'paid_amount' => (float) $freshOrder->paid_amount,
            'total_paid' => (float) $freshOrder->paid_amount,
            'pending_balance' => (float) $freshOrder->pending_balance,
        ];
    }
}

