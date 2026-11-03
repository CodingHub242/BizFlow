<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use BackedEnum;

class EmployeeItemRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status;

        if ($status instanceof BackedEnum) {
            $status = $status->value;
        }

        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'status' => $status,
            'purpose' => $this->purpose,
            'notes' => $this->notes,

            'branch' => $this->whenLoaded(
                'branch',
                fn () => $this->branch ? [
                    'id' => $this->branch->id,
                    'name' => $this->branch->name,
                ] : null
            ),

            'employee' => $this->whenLoaded(
                'employee',
                fn () => $this->employee ? [
                    'id' => $this->employee->id,
                    'employee_number' => $this->employee->employee_number,
                    'job_title' => $this->employee->job_title,
                    'user' => $this->employee->user ? [
                        'id' => $this->employee->user->id,
                        'name' => $this->employee->user->name,
                        'email' => $this->employee->user->email,
                    ] : null,
                ] : null
            ),

            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn ($item) => [
                    'id' => $item->id,
                    'catalog_item_id' => $item->catalog_item_id,
                    'catalog_item' => $item->catalogItem ? [
                        'id' => $item->catalogItem->id,
                        'name' => $item->catalogItem->name,
                        'sku' => $item->catalogItem->sku,
                        'type' => $item->catalogItem->type,
                    ] : null,
                    'quantity_requested' => $item->quantity_requested,
                    'quantity_approved' => $item->quantity_approved,
                    'notes' => $item->notes,
                ])->values()
            ),

            'approved_by' => $this->whenLoaded(
                'approvedBy',
                fn () => $this->approvedBy ? [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                    'email' => $this->approvedBy->email,
                ] : null
            ),

            'approved_at' => $this->approved_at,

            'rejected_by' => $this->whenLoaded(
                'rejectedBy',
                fn () => $this->rejectedBy ? [
                    'id' => $this->rejectedBy->id,
                    'name' => $this->rejectedBy->name,
                    'email' => $this->rejectedBy->email,
                ] : null
            ),

            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,

            'cancelled_by' => $this->whenLoaded(
                'cancelledBy',
                fn () => $this->cancelledBy ? [
                    'id' => $this->cancelledBy->id,
                    'name' => $this->cancelledBy->name,
                    'email' => $this->cancelledBy->email,
                ] : null
            ),

            'cancelled_at' => $this->cancelled_at,

            'issues' => $this->whenLoaded(
                'issues',
                fn () => $this->issues->map(fn ($issue) => [
                    'id' => $issue->id,
                    'issue_number' => $issue->issue_number,
                    'branch_id' => $issue->branch_id,
                    'issued_at' => $issue->issued_at,
                    'notes' => $issue->notes,
                    'issued_by' => $issue->issuedBy ? [
                        'id' => $issue->issuedBy->id,
                        'name' => $issue->issuedBy->name,
                    ] : null,
                    'items' => $issue->items->map(fn ($issueItem) => [
                        'id' => $issueItem->id,
                        'employee_item_request_item_id' =>
                            $issueItem->employee_item_request_item_id,
                        'catalog_item_id' => $issueItem->catalog_item_id,
                        'catalog_item' => $issueItem->catalogItem ? [
                            'id' => $issueItem->catalogItem->id,
                            'name' => $issueItem->catalogItem->name,
                            'sku' => $issueItem->catalogItem->sku,
                        ] : null,
                        'quantity_issued' => $issueItem->quantity_issued,
                        'tracks_inventory' => $issueItem->tracks_inventory,
                        'notes' => $issueItem->notes,
                    ])->values(),
                ])->values()
            ),

            'returns' => $this->whenLoaded(
                'returns',
                fn () => $this->returns->map(fn ($return) => [
                    'id' => $return->id,
                    'return_number' => $return->return_number,
                    'returned_at' => $return->returned_at,
                    'notes' => $return->notes,
                    'received_by' => $return->receivedBy ? [
                        'id' => $return->receivedBy->id,
                        'name' => $return->receivedBy->name,
                    ] : null,
                    'items' => $return->items->map(fn ($returnItem) => [
                        'id' => $returnItem->id,
                        'employee_item_request_issue_item_id' =>
                            $returnItem->employee_item_request_issue_item_id,
                        'inventory_batch_id' => $returnItem->inventory_batch_id,
                        'quantity_returned' => $returnItem->quantity_returned,
                        'return_condition' => $returnItem->return_condition,
                        'notes' => $returnItem->notes,
                    ])->values(),
                ])->values()
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
