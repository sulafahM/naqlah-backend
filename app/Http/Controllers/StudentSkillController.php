<?php

namespace App\Http\Controllers;

use App\Services\StudentSkills;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentSkillController extends Controller
{
    public function __construct(private readonly StudentSkills $skills) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()?->getAuthIdentifier();
        $candidates = $this->skills->candidates($userId);

        return response()->json(['data' => [
            'extracted' => $candidates->where('state', 'extracted')->values(),
            'confirmed' => $this->skills->confirmed($userId),
            'course_covered' => $candidates->where('state', 'course_covered')->values(),
        ]]);
    }

    public function candidates(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->skills->candidates((int) $request->user()?->getAuthIdentifier()),
        ]);
    }

    public function confirmed(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->skills->confirmed((int) $request->user()?->getAuthIdentifier()),
        ]);
    }

    public function confirm(Request $request, int $candidateId): JsonResponse
    {
        return response()->json([
            'data' => $this->skills->decide((int) $request->user()?->getAuthIdentifier(), $candidateId, 'confirmed'),
        ]);
    }

    public function reject(Request $request, int $candidateId): JsonResponse
    {
        return response()->json([
            'data' => $this->skills->decide((int) $request->user()?->getAuthIdentifier(), $candidateId, 'rejected'),
        ]);
    }
}
