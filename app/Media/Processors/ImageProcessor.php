<?php

namespace App\Media\Processors;

use App\Enums\AssetRole;
use App\Enums\UploadKind;
use App\Media\AssetDraft;
use App\Media\FileSignature;
use App\Media\MediaProcessingFailed;
use App\Models\Submission;
use Imagick;
use ImagickException;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\Drivers\Imagick\Modifiers\StripMetaModifier;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Images: EXIF-stripped original, WebP preview (animation kept) and thumbnails.
 * Also turns cover images (uploaded or video posters) into a cover plus thumbnails.
 */
class ImageProcessor implements MediaProcessor
{
    public const MAX_EDGE = 16384;

    public const PREVIEW_EDGE = 2000;

    public const THUMBNAILS = ['thumb_sm' => 200, 'thumb_md' => 400, 'thumb_lg' => 800];

    private const WEBP_QUALITY = 85;

    private ImageManager $images;

    public function __construct()
    {
        self::applyResourceLimits();
        $this->images = ImageManager::usingDriver(Driver::class, autoOrientation: true, strip: true);
    }

    /**
     * Process-wide ImageMagick limits so decompression bombs fail instead of exhausting the server.
     */
    public static function applyResourceLimits(): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, 128_000_000);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, 1024 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_WIDTH, self::MAX_EDGE);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, self::MAX_EDGE);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_LISTLENGTH, 2000);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 300);
    }

    public function process(Submission $s, ?string $originalPath, ?string $coverPath): array
    {
        if ($originalPath === null) {
            throw new MediaProcessingFailed('An image submission needs an upload.', permanent: true);
        }
        $mime = FileSignature::canonicalMime($originalPath, UploadKind::Image)
            ?? throw MediaProcessingFailed::typeMismatch('image', FileSignature::detect($originalPath));
        $dir = dirname($originalPath);

        $image = $this->decode($originalPath);

        $preview = $this->encodeWebp(
            (clone $image)->scaleDown(self::PREVIEW_EDGE, self::PREVIEW_EDGE),
            AssetRole::Preview,
            $dir,
        );
        $still = (clone $image)->removeAnimation(0);
        $thumbs = $this->thumbnails($still, $dir);

        return [$this->stripOriginal($image, $mime, $dir), $preview, ...$thumbs];
    }

    /**
     * A cover image (first frame only) as WebP, max 2000px, plus thumbnails made from it.
     *
     * @return list<AssetDraft>
     */
    public function coverDrafts(string $coverPath): array
    {
        if (! FileSignature::matches($coverPath, UploadKind::Cover)) {
            throw MediaProcessingFailed::typeMismatch('cover', FileSignature::detect($coverPath));
        }
        $dir = dirname($coverPath);

        $cover = $this->decode($coverPath)->removeAnimation(0)->scaleDown(self::PREVIEW_EDGE, self::PREVIEW_EDGE);

        return [$this->encodeWebp(clone $cover, AssetRole::Cover, $dir), ...$this->thumbnails($cover, $dir)];
    }

    /** @return list<AssetDraft> */
    private function thumbnails(ImageInterface $still, string $dir): array
    {
        $drafts = [];
        foreach (self::THUMBNAILS as $role => $edge) {
            $drafts[] = $this->encodeWebp((clone $still)->scaleDown($edge, $edge), AssetRole::from($role), $dir);
        }

        return $drafts;
    }

    private function decode(string $path): ImageInterface
    {
        try {
            $ping = new Imagick;
            $ping->pingImage($path);
            [$w, $h] = [$ping->getImageWidth(), $ping->getImageHeight()];
            $ping->clear();
        } catch (ImagickException $e) {
            throw new MediaProcessingFailed('The image could not be read: '.$e->getMessage(), permanent: true, previous: $e);
        }
        if ($w > self::MAX_EDGE || $h > self::MAX_EDGE) {
            throw new MediaProcessingFailed("The image is {$w}x{$h}; the limit is ".self::MAX_EDGE.'px per side.', permanent: true);
        }

        try {
            return $this->images->decodePath($path);
        } catch (Throwable $e) {
            throw new MediaProcessingFailed('The image could not be decoded: '.$e->getMessage(), previous: $e);
        }
    }

    private function encodeWebp(ImageInterface $image, AssetRole $role, string $dir): AssetDraft
    {
        $path = "{$dir}/{$role->value}.webp";
        $this->save($image, new WebpEncoder(quality: self::WEBP_QUALITY, strip: true), $path);

        return new AssetDraft($role, $path, 'image/webp', $image->width(), $image->height());
    }

    /** Re-encodes the original in its own format without metadata (orientation applied, ICC kept). */
    private function stripOriginal(ImageInterface $image, string $mime, string $dir): AssetDraft
    {
        $image->modify(new StripMetaModifier);
        $encoder = match ($mime) {
            'image/jpeg' => new JpegEncoder(quality: 92, strip: true),
            'image/png' => new PngEncoder(strip: true),
            'image/gif' => new GifEncoder,
            'image/webp' => new WebpEncoder(quality: 92, strip: true),
        };
        $path = "{$dir}/original.".FileSignature::extension($mime);
        $this->save($image, $encoder, $path);

        return new AssetDraft(AssetRole::Original, $path, $mime, $image->width(), $image->height());
    }

    private function save(ImageInterface $image, EncoderInterface $encoder, string $path): void
    {
        try {
            $image->encode($encoder)->save($path);
        } catch (Throwable $e) {
            throw new MediaProcessingFailed('The image could not be encoded: '.$e->getMessage(), previous: $e);
        }
    }
}
