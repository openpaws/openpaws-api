<?php

namespace App\Jobs;

use App\Enums\UploadKind;
use App\Media\FileSignature;
use App\Media\InvalidProfileImage;
use App\Media\Processors\ImageProcessor;
use App\Models\UploadSession;
use App\Models\User;
use App\Storage\ObjectStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Turns a consumed avatar/banner upload into a cropped WebP under public/users/{id}/ and
 * points the user at it. The previous object and the staged upload are deleted afterwards.
 * Content that is not a real image leaves the user unchanged.
 */
class ProcessProfileImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public User $user, public UploadKind $kind, public string $uploadSessionId)
    {
        $this->onQueue('media');
    }

    public function handle(ObjectStorage $storage): void
    {
        $session = UploadSession::find($this->uploadSessionId);
        if ($session === null) {
            return;
        }
        $dir = sys_get_temp_dir().'/openpaws-job-'.Str::random(20);
        File::ensureDirectoryExists($dir, 0700);

        try {
            $local = $storage->downloadToTemp($session->path);
            try {
                $this->publish($storage, $local, $dir);
            } finally {
                File::delete($local);
            }
        } catch (InvalidProfileImage $e) {
            Log::warning('Profile image rejected', ['user_id' => $this->user->id, 'kind' => $this->kind->value, 'reason' => $e->getMessage()]);
        } finally {
            File::deleteDirectory($dir);
        }

        $storage->delete($session->path);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Profile image processing failed', ['user_id' => $this->user->id, 'kind' => $this->kind->value, 'exception' => $e?->getMessage()]);
        $session = UploadSession::find($this->uploadSessionId);
        if ($session !== null) {
            try {
                app(ObjectStorage::class)->delete($session->path);
            } catch (Throwable) {
            }
        }
    }

    private function publish(ObjectStorage $storage, string $local, string $dir): void
    {
        if (FileSignature::canonicalMime($local, $this->kind) === null) {
            throw new InvalidProfileImage('The upload is not a supported image.');
        }

        ImageProcessor::applyResourceLimits();
        [$w, $h] = $this->kind === UploadKind::Avatar ? [256, 256] : [1600, 400];
        try {
            $image = ImageManager::usingDriver(Driver::class, autoOrientation: true, strip: true)
                ->decodePath($local)
                ->removeAnimation(0)
                ->cover($w, $h);
            $out = "{$dir}/out.webp";
            $image->encode(new WebpEncoder(quality: 85, strip: true))->save($out);
        } catch (Throwable $e) {
            throw new InvalidProfileImage('The image could not be processed: '.$e->getMessage(), previous: $e);
        }

        $column = $this->kind->value.'_path';
        $path = "public/users/{$this->user->id}/{$this->kind->value}-".bin2hex(random_bytes(8)).'.webp';
        $storage->putFile($path, $out, 'image/webp');

        $fresh = User::find($this->user->id);
        if ($fresh === null) {
            $storage->delete($path);

            return;
        }
        $old = $fresh->{$column};
        $fresh->forceFill([$column => $path])->save();
        if ($old !== null && $old !== $path) {
            $storage->delete($old);
        }
    }
}
