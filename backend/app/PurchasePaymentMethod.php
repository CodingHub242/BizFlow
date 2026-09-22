<?php

namespace App;

enum PurchasePaymentMethod: string
{
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case MOBILE_MONEY = 'mobile_money';
    case CARD = 'card';
    case OTHER = 'other';
}