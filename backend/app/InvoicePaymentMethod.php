<?php

namespace App;

enum InvoicePaymentMethod: string
{
    case CASH = 'cash';
    case MOBILE_MONEY = 'mobile_money';
    case BANK_TRANSFER = 'bank_transfer';
    case CARD = 'card';
    case CHEQUE = 'cheque';
    case OTHER = 'other';
}