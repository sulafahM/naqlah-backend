<?php

namespace App\Services;

use App\Enums\SkillSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;
use Throwable;

class StudentSkills
{
    /**
     * @param  array<string, scalar|null>  $attributes
     * @param  list<string>  $skills
     * @return array{source_id: int, candidates: Collection<int, array<string, mixed>>}
     */
    public function store(
        int $userId,
        SkillSource $source,
        array $attributes,
        array $skills,
        ?UploadedFile $file = null,
        ?int $sourceId = null,
    ): array {
        $filePath = null;
        $uploadKey = null;
        if ($file !== null) {
            $hash = hash_file('sha256', $file->getPathname());
            abort_if($hash === false, 503, 'CV storage is temporarily unavailable.');
            $uploadKey = $hash.'.'.strtolower($file->getClientOriginalExtension());
        }

        try {
            return DB::transaction(function () use ($userId, $source, $attributes, $skills, $file, $uploadKey, $sourceId, &$filePath): array {
                $this->lockStudent($userId);
                $refreshExistingSource = $sourceId !== null;

                $sources = $this->sources($userId, $source);
                if ($uploadKey !== null) {
                    $sources->where('file_path', 'cv/'.$userId.'/'.$uploadKey);
                } else {
                    $sources->where($attributes);
                    if ($source === SkillSource::Cv) {
                        $sources->whereNull('file_path');
                    }
                }

                if ($sourceId !== null) {
                    $current = $sources->where($source->key(), $sourceId)->lockForUpdate()->first();
                    abort_if($current === null, 409, 'The source changed during extraction. Retry with its latest text.');
                } else {
                    $sourceId = $sources->orderBy($source->key())->value($source->key());
                    if ($sourceId === null) {
                        if ($file !== null) {
                            $path = $file->storeAs('cv/'.$userId, $uploadKey, 'local');
                            abort_if($path === false, 503, 'CV storage is temporarily unavailable.');
                            $attributes['file_path'] = $filePath = $path;
                        }

                        $sourceId = DB::table($source->table())->insertGetId(['user_id' => $userId] + $attributes, $source->key());
                    }
                }

                $candidates = $this->candidateQuery($userId)
                    ->where('candidate_skills.source_type', $source->value)
                    ->where('candidate_skills.source_id', $sourceId);

                $matchedSkillIds = collect();
                if ($skills !== []) {
                    sort($skills);
                    DB::table('skills')->insertOrIgnore(array_map(
                        fn (string $name): array => ['skill_name' => $name, 'category' => 'Technical'],
                        $skills,
                    ));

                    $matchedSkillIds = DB::table('skills')->whereIn('skill_name', $skills)->pluck('skill_id');
                    $existing = (clone $candidates)->pluck('candidate_skills.skill_id');
                    $rows = $matchedSkillIds->diff($existing)->map(fn ($skillId): array => [
                        'user_id' => $userId,
                        'skill_id' => $skillId,
                        'source_type' => $source->value,
                        'source_id' => $sourceId,
                        'status' => 'pending',
                    ])->values()->all();

                    if ($rows !== []) {
                        DB::table('candidate_skills')->insert($rows);
                    }
                }

                if ($refreshExistingSource) {
                    DB::table('candidate_skills')
                        ->where('user_id', $userId)
                        ->where('source_type', $source->value)
                        ->where('source_id', $sourceId)
                        ->where('status', 'pending')
                        ->whereNotIn('skill_id', $matchedSkillIds)
                        ->delete();
                }

                return [
                    'source_id' => (int) $sourceId,
                    'candidates' => $this->formatCandidates($candidates->whereIn('skills.skill_name', $skills)->get()),
                ];
            });
        } catch (Throwable $exception) {
            if ($filePath !== null) {
                Storage::disk('local')->delete($filePath);
            }

            throw $exception;
        }
    }

    public function source(int $userId, SkillSource $source, int $sourceId): stdClass
    {
        $record = $this->sources($userId, $source)->where($source->key(), $sourceId)->first();
        abort_if($record === null, 404, 'Source not found.');

        unset($record->user_id, $record->file_path);

        return $record;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function candidates(int $userId): Collection
    {
        return $this->formatCandidates($this->candidateQuery($userId)->get());
    }

    /** @return Collection<int, stdClass> */
    public function confirmed(int $userId): Collection
    {
        return DB::table('student_skills')
            ->join('skills', 'skills.skill_id', '=', 'student_skills.skill_id')
            ->where('student_skills.user_id', $userId)
            ->orderBy('skills.skill_name')
            ->get(['skills.skill_id', 'skills.skill_name', 'skills.category', 'student_skills.confirmed_at'])
            ->each(fn (stdClass $skill) => $skill->state = 'confirmed');
    }

    /** @return array<string, mixed> */
    public function decide(int $userId, int $candidateId, string $decision): array
    {
        return DB::transaction(function () use ($userId, $candidateId, $decision): array {
            $this->lockStudent($userId);
            $candidate = $this->candidateQuery($userId)->where('candidate_id', $candidateId)->first();
            abort_if($candidate === null, 404, 'Candidate skill not found.');
            abort_if(SkillSource::isCourse($candidate->source_type), 422, 'Course-covered skills require practical-work evidence and explicit confirmation.');

            if ($candidate->status === $decision) {
                return $this->formatCandidate($candidate);
            }

            abort_unless($candidate->status === 'pending', 409, 'A different decision has already been recorded for this candidate.');
            if ($decision === 'confirmed') {
                $needsPracticalEvidence = $candidate->source_type === SkillSource::Cv->value
                    && DB::table('candidate_skills')
                        ->where('user_id', $userId)
                        ->where('skill_id', $candidate->skill_id)
                        ->whereIn('source_type', [SkillSource::ExternalCourse->value, 'university_course'])
                        ->exists()
                    && ! DB::table('student_skills')->where('user_id', $userId)->where('skill_id', $candidate->skill_id)->exists();
                abort_if($needsPracticalEvidence, 422, 'Confirm the practical-work candidate for this course-covered skill.');

                DB::table('student_skills')->insertOrIgnore([
                    'user_id' => $userId,
                    'skill_id' => $candidate->skill_id,
                    'confirmed_at' => now(),
                ]);
            }

            DB::table('candidate_skills')
                ->where('user_id', $userId)
                ->where('candidate_id', $candidateId)
                ->update(['status' => $decision]);
            $candidate->status = $decision;

            return $this->formatCandidate($candidate);
        });
    }

    private function lockStudent(int $userId): void
    {
        $user = DB::table('users')->where('user_id', $userId);
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite ignores SELECT FOR UPDATE. Acquire its write lock before reading skills.
            abort_if($user->update(['user_id' => $userId]) === 0, 404);

            return;
        }

        $user->lockForUpdate()->firstOrFail();
    }

    private function sources(int $userId, SkillSource $source): Builder
    {
        return DB::table($source->table())->where('user_id', $userId);
    }

    private function candidateQuery(int $userId): Builder
    {
        return DB::table('candidate_skills')
            ->join('skills', 'skills.skill_id', '=', 'candidate_skills.skill_id')
            ->where('candidate_skills.user_id', $userId)
            ->orderBy('candidate_skills.candidate_id')
            ->select(['candidate_id', 'candidate_skills.skill_id', 'skill_name', 'category', 'source_type', 'source_id', 'status']);
    }

    /**
     * @param  Collection<int, stdClass>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function formatCandidates(Collection $candidates): Collection
    {
        return $candidates->map($this->formatCandidate(...));
    }

    /** @return array<string, mixed> */
    private function formatCandidate(stdClass $candidate): array
    {
        $state = match (true) {
            SkillSource::isCourse($candidate->source_type) => 'course_covered',
            $candidate->status === 'pending' => 'extracted',
            default => $candidate->status,
        };

        return (array) $candidate + ['state' => $state];
    }
}
