<?php

namespace App;

enum MigrationSessionStatus: string
{
    case PENDING = 'pending';
    case UPLOADED = 'uploaded';
    case ANALYZING = 'analyzing';
    case MAPPING = 'mapping';
    case VALIDATING = 'validating';
    case IMPORTING = 'importing';
    case COMPLETED = 'completed';
}