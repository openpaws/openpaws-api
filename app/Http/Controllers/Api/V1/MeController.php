<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Rating;
use App\Enums\UploadKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Jobs\ProcessProfileImage;
use App\Uploads\LocksUploadSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    use LocksUploadSession;

    public function show(Request $request)
    {
        return new UserResource($request->user());
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'min:1', 'max:50'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->fill($data);
        $emailChanged = $user->isDirty('email');
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();
        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return new UserResource($user);
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }
        $user->password = $data['password'];
        $user->save();

        return response()->noContent();
    }

    public function settings(Request $request)
    {
        $data = $request->validate([
            'max_rating' => ['required', Rule::enum(Rating::class)],
        ]);
        $user = $request->user();
        if ($data['max_rating'] !== Rating::General->value && $user->adult_confirmed_at === null) {
            throw ValidationException::withMessages(['max_rating' => ['Confirm you are 18+ before allowing mature or adult content.']]);
        }
        $user->max_rating = $data['max_rating'];
        $user->save();

        return new UserResource($user);
    }

    public function adultConfirmation(Request $request)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            throw new AuthorizationException('Verify your email before confirming you are 18+.');
        }
        $user->adult_confirmed_at ??= now();
        $user->save();

        return new UserResource($user);
    }

    public function avatar(Request $request)
    {
        return $this->setProfileImage($request, UploadKind::Avatar);
    }

    public function banner(Request $request)
    {
        return $this->setProfileImage($request, UploadKind::Banner);
    }

    /** Consumes the upload and queues the resize; the URL changes once the job is done. */
    private function setProfileImage(Request $request, UploadKind $kind)
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            throw new AuthorizationException('Verify your email to change your profile images.');
        }
        $data = $request->validate(['upload_session_id' => ['required', 'uuid']]);

        DB::transaction(function () use ($user, $kind, $data) {
            $session = $this->lockSession($user, 'upload_session_id', $data['upload_session_id'], $kind);
            $session->consume();
            ProcessProfileImage::dispatch($user, $kind, $session->id)->afterCommit();
        });

        return (new UserResource($user->refresh()))->response()->setStatusCode(202);
    }
}
