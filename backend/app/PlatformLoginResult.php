<?php

namespace App;

enum PlatformLoginResult: string
{
    case SUCCESS = 'success';
    case INVALID = 'invalid';
    case LOCKED = 'locked';
}