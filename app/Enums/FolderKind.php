<?php

namespace App\Enums;

enum FolderKind: string
{
    case Gallery = 'gallery';
    case Scraps = 'scraps';
    case Custom = 'custom';
}
