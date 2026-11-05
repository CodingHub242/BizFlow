export type EmployeeItemRequestStatus =
  | 'PENDING'
  | 'APPROVED'
  | 'PARTIALLY_APPROVED'
  | 'REJECTED'
  | 'PARTIALLY_ISSUED'
  | 'ISSUED'
  | 'CANCELLED'
  | 'pending'
  | 'approved'
  | 'partially_approved'
  | 'rejected'
  | 'partially_issued'
  | 'issued'
  | 'cancelled';

export interface EmployeeItemRequestEmployee {
  id: number;
  employee_number: string | null;
  job_title: string | null;
  user: {
    id: number;
    name: string;
    email: string;
  } | null;
}

export interface EmployeeItemRequestBranch {
  id: number;
  name: string;
}

export interface EmployeeItemRequestItem {
  id: number;
  catalog_item_id: number;
  catalog_item: {
    id: number;
    name: string;
    sku: string | null;
    type: string | null;
  } | null;
  quantity_requested: number;
  quantity_approved: number;
  notes: string | null;
}

export interface EmployeeItemRequestCatalogItem {
  id: number;
  name: string;
  sku?: string | null;
  type?: string;
  unit?: string | null;
}



export interface EmployeeItemRequest {
  id: number;
  request_number: string;
  status: EmployeeItemRequestStatus;
  purpose: string | null;
  notes: string | null;
  branch: EmployeeItemRequestBranch | null;
  employee: EmployeeItemRequestEmployee | null;
  items: EmployeeItemRequestItem[];
  created_at: string;
  updated_at: string;
}

export interface EmployeeItemRequestPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}



export interface EmployeeItemRequestListResponse {
  success: boolean;
  data: EmployeeItemRequest[];
  meta: EmployeeItemRequestPagination;
}


export interface EmployeeItemRequestResponse {
  success: boolean;
  message?: string;
  data: EmployeeItemRequest;
}

export interface CreateEmployeeItemRequestItem {
  catalog_item_id: number;
  quantity_requested: number;
  notes?: string | null;
}

export interface CreateEmployeeItemRequest {
  branch_id?: number | null;
  purpose?: string | null;
  notes?: string | null;
  items: CreateEmployeeItemRequestItem[];
}

export interface ApproveEmployeeItemRequest {
  approved_quantities?: Record<number, number>;
}

export interface RejectEmployeeItemRequest {
  rejection_reason: string;
}


export type EmployeeItemReturnCondition =
  | 'restockable'
  | 'damaged'
  | 'unusable';

export interface IssueEmployeeItemRequestPayload {
  // Key = employee item request item ID
  // Value = quantity to issue
  items: Record<number, number>;
  branch_id?: number | null;
  notes?: string | null;
}

export interface ReturnEmployeeItemRequestPayload {
  items: {
    issue_item_id: number;
    inventory_batch_id: number;
    quantity_returned: number;
    return_condition: EmployeeItemReturnCondition;
    notes?: string | null;
  }[];
  notes?: string | null;
}