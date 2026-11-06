<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Consumed = 'consumed';
    case Expired = 'expired';
}
