<?php

namespace App;

enum InventoryMovementType: string
{
    case OPENING = 'opening';
    case PURCHASE = 'purchase';
    case SALE = 'sale';
    case ADJUSTMENT = 'adjustment';
    case DAMAGE = 'damage';
    case TRANSFER_IN = 'transfer_in';
    case TRANSFER_OUT = 'transfer_out';
    case RETURN = 'return';
}
