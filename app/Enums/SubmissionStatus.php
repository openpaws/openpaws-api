<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
    case Hidden = 'hidden';
    case Removed = 'removed';
}
