<?php

namespace App\Services;

use App\Enums\SkillSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\Process\Exception\RuntimeException;

class PythonSkillExtractor
{
    /**
     * @param  array<string, string>|null  $sections
     * @return array{skills: list<string>, dictionary_version: string, text: string}
     */
    public function extract(SkillSource $source, string $text, ?UploadedFile $file = null, ?array $sections = null): array
    {
        $payload = ['source_type' => $source->value, 'text' => $text, 'sections' => $sections];
        if ($file !== null) {
            $payload['file'] = [
                'path' => $file->getPathname(),
                'extension' => strtolower($file->getClientOriginalExtension()),
            ];
        }

        try {
            $result = Process::path(base_path())
                ->timeout(20)
                ->input(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE))
                ->run([config()->string('skill-extraction.python'), '-m', 'nlp.laravel_bridge']);

            if ($result->failed()) {
                $this->unavailable('process failed', $result->exitCode());
            }

            $output = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (RuntimeException|JsonException $exception) {
            $this->unavailable($exception::class);
        }

        if ($file !== null && ($output['status'] ?? null) === 'invalid_file') {
            throw ValidationException::withMessages(['file' => 'The CV file must be readable, contain text, and meet the supported format limits.']);
        }

        if (! in_array($output['status'] ?? null, ['ok', 'empty_input'], true)
            || ! is_string($output['dictionary_version'] ?? null)
            || ! is_array($output['candidate_skills'] ?? null)
            || ! array_is_list($output['candidate_skills'])
            || ($file !== null && (! is_string($output['extracted_text'] ?? null) || trim($output['extracted_text']) === ''))) {
            $this->unavailable('unexpected extractor response');
        }

        $skills = [];
        foreach ($output['candidate_skills'] as $name) {
            if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
                $this->unavailable('invalid canonical skill');
            }
            $skills[] = $name;
        }

        return [
            'skills' => array_values(array_unique($skills)),
            'dictionary_version' => $output['dictionary_version'],
            'text' => $file === null ? $text : $output['extracted_text'],
        ];
    }

    private function unavailable(string $reason, ?int $exitCode = null): never
    {
        Log::warning('Skill extraction unavailable.', ['reason' => $reason, 'exit_code' => $exitCode]);

        abort(503, 'Skill extraction is temporarily unavailable.');
    }
}
