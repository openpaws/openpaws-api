<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\SubmissionResource;
use App\Http\Resources\UserResource;
use App\Models\Folder;
use App\Models\Profile;
use App\Models\Submission;
use App\Models\User;
use App\Support\FolderCounts;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function show(string $username)
    {
        $user = $this->find($username);
        $profile = Profile::find($user->id) ?? new Profile(['links' => []]);

        return response()->json([
            'user' => (new UserResource($user))->resolve(request()),
            'profile' => (new ProfileResource($profile))->resolve(request()),
        ]);
    }

    public function folders(Request $request, string $username)
    {
        $user = $this->find($username);
        $folders = Folder::where('user_id', $user->id)
            ->orderByRaw("kind = 'custom'")->orderBy('position')->orderBy('id')->get();

        return FolderResource::collection(FolderCounts::attach($folders, $request->user('sanctum')));
    }

    public function submissions(Request $request, string $username)
    {
        $user = $this->find($username);
        $key = (string) $request->query('folder', 'gallery');
        $query = Folder::where('user_id', $user->id);
        $folder = in_array($key, ['gallery', 'scraps'], true)
            ? $query->where('kind', $key)->first()
            : $query->where('public_id', $key)->firstOrFail();

        $perPage = max(1, min((int) $request->query('per_page', config('openpaws.pagination.default')), config('openpaws.pagination.max')));

        if ($folder === null) { // gallery/scraps not created yet
            return response()->json(['data' => [], 'meta' => ['next_cursor' => null]]);
        }

        // Ordered by the pivot position; cursorPaginate reads the cursor values from the model
        // attributes `position`, `published_at` and `id`, so the pivot position is selected as `position`.
        $page = Submission::query()
            ->listedFor($request->user('sanctum'))
            ->join('folder_submission as fs', 'fs.submission_id', '=', 'submissions.id')
            ->where('fs.folder_id', $folder->id)
            ->select('submissions.*', 'fs.position')
            ->with(['user', 'tags', 'assets', 'folders'])
            ->orderBy('fs.position')->orderByDesc('submissions.published_at')->orderByDesc('submissions.id')
            ->cursorPaginate($perPage);

        return response()->json([
            'data' => SubmissionResource::collection($page->getCollection())->resolve(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    private function find(string $username): User
    {
        return User::where('username', strtolower($username))->firstOrFail();
    }
}
