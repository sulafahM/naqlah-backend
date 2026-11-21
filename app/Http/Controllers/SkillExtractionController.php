<?php

namespace App\Http\Controllers;

use App\Enums\SkillSource;
use App\Services\PythonSkillExtractor;
use App\Services\StudentSkills;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class SkillExtractionController extends Controller
{
    public function __construct(
        private readonly PythonSkillExtractor $extractor,
        private readonly StudentSkills $skills,
    ) {}

    public function cv(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required_without_all:file,sections', 'prohibits:file,sections', 'string', 'max:100000'],
            'sections' => ['required_without_all:text,file', 'prohibits:text,file', 'array:technical_skills,projects,experience', 'min:1'],
            'sections.*' => ['required', 'string', 'max:100000'],
            'file' => ['required_without_all:text,sections', 'prohibits:text,sections', 'file', 'extensions:pdf,docx,txt', 'max:5120'],
        ]);

        $text = $data['text'] ?? '';
        $sections = $data['sections'] ?? null;
        if ($sections !== null) {
            $headings = [
                'technical_skills' => 'Technical Skills',
                'projects' => 'Projects',
                'experience' => 'Experience',
            ];
            $blocks = [];
            foreach ($headings as $key => $heading) {
                if (isset($sections[$key])) {
                    $blocks[] = $heading.":\n".$sections[$key];
                }
            }
            $text = implode("\n\n", $blocks);
            if (mb_strlen($text) > 100000) {
                throw ValidationException::withMessages(['sections' => 'The combined CV text must be at most 100,000 characters.']);
            }
        }

        return $this->extract($request, SkillSource::Cv, $text, [], $data['file'] ?? null, $sections);
    }

    public function practicalWork(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id' => ['sometimes', 'required', 'integer', 'min:1', 'prohibits:title,work_type,description'],
            'title' => ['required_without:source_id', 'string', 'max:255'],
            'work_type' => ['required_without:source_id', 'string', 'max:255'],
            'description' => ['required_without:source_id', 'string', 'max:100000'],
            'file' => ['missing'],
        ]);

        return $this->extractActivity($request, SkillSource::PracticalWork, $data);
    }

    public function course(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_id' => ['sometimes', 'required', 'integer', 'min:1', 'prohibits:title,concepts'],
            'title' => ['required_without:source_id', 'string', 'max:255'],
            'concepts' => ['required_without:source_id', 'string', 'max:100000'],
            'file' => ['missing'],
        ]);

        if (isset($data['concepts'])) {
            $data['description'] = $data['concepts'];
        }

        return $this->extractActivity($request, SkillSource::ExternalCourse, $data);
    }

    public function show(Request $request, SkillSource $source, int $sourceId): JsonResponse
    {
        return response()->json([
            'data' => $this->skills->source((int) $request->user()?->getAuthIdentifier(), $source, $sourceId),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function extractActivity(Request $request, SkillSource $source, array $data): JsonResponse
    {
        $sourceId = isset($data['source_id']) ? (int) $data['source_id'] : null;
        if ($sourceId !== null) {
            $data = (array) $this->skills->source((int) $request->user()?->getAuthIdentifier(), $source, $sourceId);
        }

        $text = $data['description'] ?? '';
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > 100000) {
            throw ValidationException::withMessages(['source_id' => 'The stored activity must contain up to 100,000 characters of text.']);
        }

        $attributes = ['title' => $data['title']];
        if ($source === SkillSource::PracticalWork) {
            $attributes['work_type'] = $data['work_type'];
        }

        return $this->extract($request, $source, $text, $attributes, sourceId: $sourceId);
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     * @param  array<string, string>|null  $sections
     */
    private function extract(
        Request $request,
        SkillSource $source,
        string $text,
        array $attributes,
        ?UploadedFile $file = null,
        ?array $sections = null,
        ?int $sourceId = null,
    ): JsonResponse {
        $result = $this->extractor->extract($source, $text, $file, $sections);
        $attributes[$source->textColumn()] = $result['text'];
        $saved = $this->skills->store(
            (int) $request->user()?->getAuthIdentifier(), $source, $attributes, $result['skills'], $file, $sourceId,
        );

        return response()->json(['data' => [
            'source_type' => $source->value,
            'dictionary_version' => $result['dictionary_version'],
        ] + $saved]);
    }
}
