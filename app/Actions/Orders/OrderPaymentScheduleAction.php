<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderPaymentSchedule;
use Illuminate\Support\Facades\DB;
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

        return [
            'schedule' => $schedule->fresh(),
            'payment_schedules' => $order->paymentSchedules()->get(),
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

        $freshOrder = $order->fresh(['payments.creator', 'paymentSchedules']);

        return [
            'payment' => $payment->load('creator'),
            'payments' => $freshOrder->payments,
            'payment_schedules' => $freshOrder->paymentSchedules,
            'paid_amount' => (float) $freshOrder->paid_amount,
            'total_paid' => (float) $freshOrder->paid_amount,
            'pending_balance' => (float) $freshOrder->pending_balance,
        ];
    }

    /**
     * Delete a pending scheduled payment from an order.
     *
     * @return array<string, mixed>
     */
    public function destroy(Order $order, OrderPaymentSchedule $schedule): array
    {
        if ((int) $schedule->order_id !== (int) $order->id || (int) $schedule->tenant_id !== (int) $order->tenant_id) {
            abort(404, 'Cobro programado no pertenece a este pedido.');
        }

        if ($schedule->status !== 'pending') {
            throw ValidationException::withMessages([
                'schedule' => 'Solo se pueden eliminar cobros programados en estado pendiente.',
            ]);
        }

        DB::transaction(function () use ($schedule): void {
            $schedule->delete();
        });

        return [
            'payment_schedules' => $order->paymentSchedules()->get(),
        ];
    }
}

