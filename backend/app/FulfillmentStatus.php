<?php

namespace App;

enum FulfillmentStatus: string
{
    case PENDING = 'pending';
    case SOURCING = 'sourcing';
    case PARTIALLY_FULFILLED = 'partially_fulfilled';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';
}