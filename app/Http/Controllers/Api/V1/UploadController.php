<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UploadKind;
use App\Enums\UploadStatus;
use App\Exceptions\DomainError;
use App\Http\Controllers\Controller;
use App\Http\Resources\UploadSessionResource;
use App\Models\UploadSession;
use App\Storage\ObjectStorage;
use App\Uploads\UploadLimits;
use App\Uploads\UploadQuota;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    public function store(Request $request, ObjectStorage $storage)
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            throw new AuthorizationException('Verify your email to upload files.');
        }

        $data = $request->validate([
            'kind' => ['required', Rule::enum(UploadKind::class)],
            'filename' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ]);
        $kind = UploadKind::from($data['kind']);
        $size = (int) $data['size'];

        if (! in_array($data['mime'], $kind->allowedMimes(), true)) {
            throw ValidationException::withMessages(['mime' => ['This file type is not allowed for this kind.']]);
        }
        if ($size > $kind->maxBytes()) {
            throw ValidationException::withMessages(['size' => ['File exceeds the maximum size for this kind.']]);
        }

        UploadQuota::assertCanUpload($user, $size);

        $path = 'staging/'.Str::uuid().'/'.UploadLimits::sanitiseFilename($data['filename']);
        $multipart = UploadLimits::isMultipart($size);

        $session = new UploadSession([
            'user_id' => $user->id,
            'kind' => $kind,
            'status' => UploadStatus::Pending,
            'filename' => $data['filename'],
            'mime' => $data['mime'],
            'size' => $size,
            'path' => $path,
            'multipart' => $multipart,
            'expires_at' => now()->addHours((int) config('openpaws.uploads.stale_hours')),
        ]);

        $resource = null;
        if ($multipart) {
            $session->multipart_upload_id = $storage->createMultipart($path, $data['mime']);
            $session->part_count = UploadLimits::partCount($size);
            $session->save();
            $resource = new UploadSessionResource($session);
        } else {
            $session->save();
            $resource = (new UploadSessionResource($session))->additional([
                'url' => $storage->presignPut($path, $data['mime'], (int) config('openpaws.presign_ttl')),
            ]);
        }

        return $resource->response()->setStatusCode(201);
    }

    public function parts(Request $request, string $id, ObjectStorage $storage)
    {
        $session = $this->owned($request, $id);
        if (! $session->multipart || $session->status !== UploadStatus::Pending) {
            throw new DomainError('invalid_state', 'This upload does not accept parts.');
        }
        $data = $request->validate([
            'part_numbers' => ['required', 'array', 'min:1'],
            'part_numbers.*' => ['integer', 'min:1', 'max:'.$session->part_count],
        ]);

        $urls = [];
        foreach (array_unique($data['part_numbers']) as $n) {
            $urls[(string) $n] = $storage->presignPart(
                $session->path, $session->multipart_upload_id, (int) $n, (int) config('openpaws.presign_ttl'),
            );
        }

        return response()->json(['urls' => $urls]);
    }

    public function complete(Request $request, string $id, ObjectStorage $storage)
    {
        $session = $this->owned($request, $id);
        if ($session->status !== UploadStatus::Pending) {
            throw new DomainError('invalid_state', 'This upload is not pending.');
        }

        if ($session->multipart) {
            $data = $request->validate([
                'parts' => ['required', 'array', 'min:1'],
                'parts.*.part_number' => ['required', 'integer', 'min:1', 'max:'.$session->part_count],
                'parts.*.etag' => ['required', 'string'],
            ]);
            $parts = collect($data['parts'])->sortBy('part_number')->values()->all();
            try {
                $storage->completeMultipart($session->path, $session->multipart_upload_id, $parts);
            } catch (\Throwable $e) {
                report($e);
                throw new DomainError('upload_not_complete', 'The upload could not be assembled.');
            }
        }

        $actual = $storage->size($session->path);
        if ($actual === null) {
            throw new DomainError('upload_not_complete', 'The uploaded file was not found.');
        }
        if ($actual !== $session->size) {
            throw new DomainError('upload_not_complete', 'The uploaded file size does not match the declared size.');
        }

        $session->status = UploadStatus::Uploaded;
        $session->save();

        return response()->json(['id' => $session->id, 'status' => 'uploaded']);
    }

    private function owned(Request $request, string $id): UploadSession
    {
        return UploadSession::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();
    }
}
