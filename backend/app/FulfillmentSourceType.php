<?php

namespace App;

enum FulfillmentSourceType: string
{
    case BRANCH = 'branch';
    case SUPPLIER = 'supplier';
    case EXTERNAL = 'external';
}