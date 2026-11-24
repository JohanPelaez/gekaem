<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Orders\OrderPaymentScheduleAction;
use App\Models\Order;
use App\Models\OrderPaymentSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderPaymentScheduleController extends Controller
{
    public function __construct(
        protected OrderPaymentScheduleAction $scheduleAction
    ) {}

    /**
     * Store a newly scheduled payment for the given order.
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        abort_if($request->user()?->currentRole() === 'worker', 403);

        $validated = $request->validate([
            'scheduled_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->scheduleAction->schedule($order, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Cobro programado registrado exitosamente.',
            ...$result,
        ], 201);
    }

    /**
     * Update a pending scheduled payment for the given order.
     */
    public function update(Request $request, Order $order, OrderPaymentSchedule $schedule): JsonResponse
    {
        abort_if($request->user()?->currentRole() === 'worker', 403);

        $validated = $request->validate([
            'scheduled_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->scheduleAction->update($order, $schedule, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Cobro programado actualizado correctamente.',
            ...$result,
        ]);
    }

    /**
     * Mark a pending scheduled payment as paid and convert it into a real OrderPayment.
     */
    public function markPaid(Request $request, Order $order, OrderPaymentSchedule $schedule): JsonResponse
    {
        abort_if($request->user()?->currentRole() === 'worker', 403);

        $validated = $request->validate([
            'payment_method' => ['nullable', 'string', 'in:cash,transfer,card,other'],
        ]);

        $result = $this->scheduleAction->markPaid($order, $schedule, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Cobro programado marcado como cobrado y registrado como abono.',
            ...$result,
        ]);
    }

    /**
     * Revert a paid scheduled payment back to pending and remove its linked payment.
     */
    public function unmarkPaid(Request $request, Order $order, OrderPaymentSchedule $schedule): JsonResponse
    {
        abort_if($request->user()?->currentRole() === 'worker', 403);

        $result = $this->scheduleAction->unmarkPaid($order, $schedule);

        return response()->json([
            'success' => true,
            'message' => 'Cobro revertido a pendiente.',
            ...$result,
        ]);
    }

    /**
     * Delete a scheduled payment (and its linked payment if already paid).
     */
    public function destroy(Request $request, Order $order, OrderPaymentSchedule $schedule): JsonResponse
    {
        abort_if($request->user()?->currentRole() === 'worker', 403);

        $result = $this->scheduleAction->destroy($order, $schedule);

        return response()->json([
            'success' => true,
            'message' => 'Cobro programado eliminado correctamente.',
            ...$result,
        ]);
    }
}

