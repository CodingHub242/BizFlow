<?php

namespace App;

enum OrderStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case PARTIALLY_FULFILLED = 'partially_fulfilled';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';
}
