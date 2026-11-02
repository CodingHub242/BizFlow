<?php

namespace App;

enum EmployeeItemRequestStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case PARTIALLY_APPROVED = 'partially_approved';
    case REJECTED = 'rejected';
    case PARTIALLY_ISSUED = 'partially_issued';
    case ISSUED = 'issued';
    case CANCELLED = 'cancelled';
}