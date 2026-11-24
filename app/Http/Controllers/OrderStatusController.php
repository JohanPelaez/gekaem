<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderStatusController extends Controller
{
    /**
     * Store a newly created order status.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['required', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'is_history_status' => ['nullable', 'boolean'],
        ]);

        $tenant = auth()->user()?->currentTenant();
        $nextPos = ((int) (OrderStatus::max('position') ?? 0)) + 1;

        $status = OrderStatus::create([
            'tenant_id' => $tenant->id,
            'name' => Str::title(trim($validated['name'])),
            'color' => $validated['color'],
            'system_key' => 'custom',
            'position' => $nextPos,
            'is_history_status' => (bool) ($validated['is_history_status'] ?? false),
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'status' => $status], 201);
        }

        return redirect()->back()->with('success', 'Estado de pedido creado correctamente.');
    }

    /**
     * Update the specified order status name, color and history behavior.
     */
    public function update(Request $request, OrderStatus $orderStatus): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['required', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'is_history_status' => ['nullable', 'boolean'],
        ]);

        $isHistoryStatus = array_key_exists('is_history_status', $validated)
            ? (bool) $validated['is_history_status']
            : (bool) $orderStatus->is_history_status;

        $orderStatus->update([
            'name' => Str::title(trim($validated['name'])),
            'color' => $validated['color'],
            'is_history_status' => $isHistoryStatus,
        ]);

        if ($isHistoryStatus) {
            Order::where('order_status_id', $orderStatus->id)
                ->update([
                    'derived_status' => 'delivered',
                    'shipped_at' => DB::raw('COALESCE(shipped_at, NOW())'),
                    'delivered_at' => DB::raw('COALESCE(delivered_at, NOW())'),
                ]);
        } else {
            $activeDerived = ($orderStatus->system_key && in_array($orderStatus->system_key, ['pending', 'in_progress', 'blocked', 'ready'], true))
                ? $orderStatus->system_key
                : 'in_progress';

            Order::where('order_status_id', $orderStatus->id)
                ->where(function ($q): void {
                    $q->whereNotNull('shipped_at')->orWhere('derived_status', 'delivered');
                })
                ->update([
                    'shipped_at' => null,
                    'delivered_at' => null,
                    'derived_status' => $activeDerived,
                ]);
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'status' => $orderStatus->fresh()]);
        }

        return redirect()->back()->with('success', 'Estado de pedido actualizado.');
    }

    /**
     * Reorder order statuses positions.
     */
    public function reorder(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'status_ids' => ['required', 'array'],
            'status_ids.*' => ['integer', 'exists:order_statuses,id'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['status_ids'] as $index => $id) {
                OrderStatus::where('id', $id)->update(['position' => $index + 1]);
            }
        });

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Estados reordenados correctamente.']);
        }

        return redirect()->back()->with('success', 'Estados reordenados correctamente.');
    }

    /**
     * Remove the specified order status and reassign orders to first available status.
     */
    public function destroy(OrderStatus $orderStatus): JsonResponse|RedirectResponse
    {
        $fallback = OrderStatus::where('id', '!=', $orderStatus->id)->orderBy('position')->first();
        if ($fallback) {
            Order::where('order_status_id', $orderStatus->id)->update([
                'order_status_id' => $fallback->id,
                'shipped_at' => $fallback->is_history_status ? now() : null,
            ]);
        }

        $orderStatus->delete();

        if (request()->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Estado eliminado.']);
        }

        return redirect()->back()->with('success', 'Estado de pedido eliminado.');
    }
}
