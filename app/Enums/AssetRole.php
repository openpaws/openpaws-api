<?php

namespace App\Enums;

enum AssetRole: string
{
    case Original = 'original';
    case ThumbSm = 'thumb_sm';
    case ThumbMd = 'thumb_md';
    case ThumbLg = 'thumb_lg';
    case Preview = 'preview';
    case Transcode = 'transcode';
    case Cover = 'cover';
    case Waveform = 'waveform';
    case Avatar = 'avatar';
    case Banner = 'banner';
    case Document = 'document';
}
