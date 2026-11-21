<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
    Storage::fake('local');
});

test('typed cv uses the approved sections and confirms or rejects one candidate at a time', function () {
    $student = User::factory()->create();
    $this->actingAs($student);
    $payload = ['text' => "Education: Docker\nTechnical Skills: Python, python, SQL\nProjects: K8s\nExperience: Kubernetes"];

    $response = $this->postJson('/api/skill-extraction/cv', $payload)
        ->assertOk()->assertJsonPath('data.source_type', 'cv')->assertJsonCount(3, 'data.candidates');
    $sourceId = $response->json('data.source_id');
    $candidates = collect($response->json('data.candidates'))->keyBy('skill_name');
    expect($candidates->keys()->sort()->values()->all())->toBe(['kubernetes', 'python', 'sql']);
    $python = $candidates->get('python')['candidate_id'];
    $sql = $candidates->get('sql')['candidate_id'];

    $this->assertDatabaseHas('cv_uploads', ['cv_id' => $sourceId, 'user_id' => $student->getKey(), 'file_path' => null]);
    $this->getJson('/api/student-skills')->assertJsonCount(3, 'data.extracted')->assertJsonCount(0, 'data.confirmed');
    $this->postJson("/api/student-skills/candidates/$python/confirm")->assertOk()->assertJsonPath('data.state', 'confirmed');
    $this->postJson("/api/student-skills/candidates/$python/confirm")->assertOk();
    $this->postJson("/api/student-skills/candidates/$python/reject")->assertConflict();
    $this->postJson("/api/student-skills/candidates/$sql/reject")->assertOk()->assertJsonPath('data.state', 'rejected');
    $this->postJson("/api/student-skills/candidates/$sql/reject")->assertOk();
    $this->postJson("/api/student-skills/candidates/$sql/confirm")->assertConflict();

    $this->postJson('/api/skill-extraction/cv', $payload)
        ->assertOk()->assertJsonPath('data.source_id', $sourceId)
        ->assertJsonFragment(['candidate_id' => $sql, 'state' => 'rejected']);
    $this->assertDatabaseCount('candidate_skills', 3)->assertDatabaseCount('cv_uploads', 1);
    $this->getJson('/api/student-skills')->assertJsonCount(1, 'data.extracted')->assertJsonCount(1, 'data.confirmed');
    $this->getJson('/api/student-skills/confirmed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.skill_name', 'python');
});

test('structured cv input supports all three sections and no match is successful', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson('/api/skill-extraction/cv', [
        'sections' => ['technical_skills' => 'Python', 'projects' => 'Docker', 'experience' => 'SQL'],
    ])->assertOk()->assertJsonCount(3, 'data.candidates');

    $this->postJson('/api/skill-extraction/cv', ['text' => 'The cat slept on the windowsill.'])
        ->assertOk()->assertJsonPath('data.candidates', []);
    $this->getJson('/api/student-skills/confirmed')->assertJsonPath('data', []);
});

test('course coverage only enters matching through confirmed practical evidence', function () {
    $this->actingAs(User::factory()->create());
    $course = ['title' => 'Databases using Python', 'concepts' => 'SQL, sql'];
    $response = $this->postJson('/api/skill-extraction/course', $course)
        ->assertOk()->assertJsonCount(1, 'data.candidates')
        ->assertJsonPath('data.candidates.0.state', 'course_covered')
        ->assertJsonPath('data.candidates.0.skill_name', 'sql');
    $courseCandidate = $response->json('data.candidates.0.candidate_id');
    $sourceId = $response->json('data.source_id');
    $this->postJson('/api/skill-extraction/course', $course)->assertJsonPath('data.source_id', $sourceId);
    $this->postJson("/api/student-skills/candidates/$courseCandidate/confirm")->assertUnprocessable();

    $cvCandidate = $this->postJson('/api/skill-extraction/cv', ['text' => 'SQL'])
        ->assertOk()->json('data.candidates.0.candidate_id');
    $this->postJson("/api/student-skills/candidates/$cvCandidate/confirm")->assertUnprocessable();
    $this->postJson("/api/student-skills/candidates/$cvCandidate/reject")->assertOk();

    $work = ['title' => 'Python database lab', 'work_type' => 'lab', 'description' => 'Created tables using SQL.'];
    $workResponse = $this->postJson('/api/skill-extraction/practical-work', $work)
        ->assertOk()->assertJsonCount(1, 'data.candidates')
        ->assertJsonPath('data.candidates.0.state', 'extracted')
        ->assertJsonPath('data.candidates.0.skill_name', 'sql');
    $workCandidate = $workResponse->json('data.candidates.0.candidate_id');
    $this->postJson('/api/skill-extraction/practical-work', ['source_id' => $workResponse->json('data.source_id')])
        ->assertOk()->assertJsonPath('data.candidates.0.candidate_id', $workCandidate);
    $this->getJson('/api/student-skills/confirmed')->assertJsonPath('data', []);
    $this->postJson("/api/student-skills/candidates/$workCandidate/confirm")->assertOk();
    $this->getJson('/api/student-skills')->assertJsonPath('data.course_covered.0.state', 'course_covered')
        ->assertJsonPath('data.confirmed.0.skill_name', 'sql');
    $this->assertDatabaseCount('external_courses', 1)->assertDatabaseCount('student_skills', 1);
});

test('refreshing edited practical work replaces stale pending candidates and preserves decisions', function () {
    $this->actingAs(User::factory()->create());
    $response = $this->postJson('/api/skill-extraction/practical-work', [
        'work_type' => 'lab', 'title' => 'Database Lab', 'description' => 'Python and SQL',
    ])->assertOk()->assertJsonCount(2, 'data.candidates');
    $sourceId = $response->json('data.source_id');
    $python = collect($response->json('data.candidates'))->firstWhere('skill_name', 'python')['candidate_id'];
    $this->postJson("/api/student-skills/candidates/$python/confirm")->assertOk();

    DB::table('practical_works')->where('work_id', $sourceId)->update(['description' => 'Docker']);
    $this->postJson('/api/skill-extraction/practical-work', ['source_id' => $sourceId])
        ->assertOk()->assertJsonCount(1, 'data.candidates')->assertJsonPath('data.candidates.0.skill_name', 'docker');
    $this->getJson('/api/student-skills')->assertJsonCount(1, 'data.extracted')->assertJsonPath('data.extracted.0.skill_name', 'docker')
        ->assertJsonCount(1, 'data.confirmed')->assertJsonPath('data.confirmed.0.skill_name', 'python');
    $this->assertDatabaseCount('candidate_skills', 2);
});

test('valid cv files flow through the real extractor and are saved privately', function (string $extension) {
    $student = User::factory()->create();
    $this->actingAs($student);
    $file = UploadedFile::fake()->createWithContent("example.$extension", match ($extension) {
        'txt' => "Technical Skills\nPython, SQL",
        'pdf' => skillsTestPdf(),
        'docx' => skillsTestDocx(),
    });

    $response = $this->post('/api/skill-extraction/cv', ['file' => $file], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonCount(2, 'data.candidates')->assertJsonPath('data.candidates.0.state', 'extracted');
    $cvId = $response->json('data.source_id');
    $cv = DB::table('cv_uploads')->where('cv_id', $cvId)->first();

    Storage::disk('local')->assertExists($cv->file_path);
    $this->post('/api/skill-extraction/cv', ['file' => $file], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.source_id', $cvId);
    $this->getJson("/api/skill-extraction/cv/$cvId")->assertOk()
        ->assertJsonPath('data.extracted_text', $cv->extracted_text)->assertJsonMissingPath('data.file_path');
    $this->assertDatabaseCount('candidate_skills', 2)->assertDatabaseCount('student_skills', 0);
})->with(['pdf', 'docx', 'txt']);

test('invalid cv files leave storage and skills untouched', function (string $filename, string $content) {
    $this->actingAs(User::factory()->create());
    $this->post('/api/skill-extraction/cv', [
        'file' => UploadedFile::fake()->createWithContent($filename, $content),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->assertDatabaseCount('cv_uploads', 0)->assertDatabaseCount('candidate_skills', 0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    'empty' => ['empty.txt', ''],
    'blank' => ['blank.txt', " \n "],
    'unsupported' => ['example.csv', 'Python'],
    'corrupted pdf' => ['broken.pdf', '%PDF-1.7 fake content'],
    'corrupted docx' => ['broken.docx', 'fake content'],
    'binary masquerading as text' => ['broken.txt', "\0\xFF"],
]);

test('rejects missing, ambiguous or oversized input and activity uploads', function () {
    $this->actingAs(User::factory()->create());
    $this->postJson('/api/skill-extraction/cv', ['text' => '   '])->assertUnprocessable();
    $this->postJson('/api/skill-extraction/cv', ['text' => 'SQL', 'sections' => ['projects' => 'Python']])->assertUnprocessable();
    $this->post('/api/skill-extraction/cv', ['file' => UploadedFile::fake()->create('large.txt', 5121)])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->post('/api/skill-extraction/practical-work', [
        'title' => 'My Project', 'work_type' => 'project', 'description' => 'Python',
        'file' => UploadedFile::fake()->createWithContent('details.txt', 'Python'),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->post('/api/skill-extraction/course', [
        'title' => 'Databases', 'concepts' => 'SQL',
        'file' => UploadedFile::fake()->createWithContent('concepts.txt', 'SQL'),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->assertDatabaseCount('candidate_skills', 0);
});

test('process failures and malformed responses preserve all existing rows', function () {
    $student = User::factory()->create();
    $this->actingAs($student);
    $confirmedId = $this->postJson('/api/skill-extraction/cv', ['text' => 'Python'])
        ->assertOk()->json('data.candidates.0.candidate_id');
    $this->postJson("/api/student-skills/candidates/$confirmedId/confirm")->assertOk();
    $this->postJson('/api/skill-extraction/practical-work', ['title' => 'A Lab', 'work_type' => 'lab', 'description' => 'SQL'])
        ->assertOk()->assertJsonPath('data.candidates.0.state', 'extracted');

    Process::fake(['*' => Process::sequence()
        ->push(Process::result(errorOutput: 'secret traceback', exitCode: 1))
        ->push(Process::result(output: '{"status":"ok","dictionary_version":"test","candidate_skills":[123]}'))
        ->push(Process::result(output: '{"status":"processing_error"}')),
    ]);

    $this->postJson('/api/skill-extraction/cv', ['text' => 'Docker'])->assertServiceUnavailable()
        ->assertJsonPath('message', 'Skill extraction is temporarily unavailable.');
    $this->postJson('/api/skill-extraction/course', ['title' => 'Databases', 'concepts' => 'SQL'])->assertServiceUnavailable();
    $this->post('/api/skill-extraction/cv', ['file' => UploadedFile::fake()->createWithContent('cv.txt', 'Python')])
        ->assertServiceUnavailable();
    $this->getJson('/api/student-skills')->assertJsonCount(1, 'data.extracted')
        ->assertJsonPath('data.confirmed.0.skill_name', 'python');
    $this->assertDatabaseCount('cv_uploads', 1)->assertDatabaseCount('external_courses', 0)->assertDatabaseCount('candidate_skills', 2);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('real session login isolates profiles, sources, candidates and matching across two accounts', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $this->get('/api/student-skills')->assertUnauthorized();
    $this->getJson('/api/session')->assertOk()->assertJsonPath('data', null);
    $this->postJson('/api/session', ['email' => $alice->email, 'password' => 'wrong'])->assertUnauthorized();
    $this->postJson('/api/session', ['email' => $alice->email, 'password' => 'password'])
        ->assertOk()->assertJsonPath('data.user_id', $alice->getKey())->assertJsonMissingPath('data.password_hash');
    $cv = $this->postJson('/api/skill-extraction/cv', ['text' => 'Python, SQL', 'user_id' => $bob->getKey()])
        ->assertOk()->json('data');
    $workId = $this->postJson('/api/skill-extraction/practical-work', ['title' => 'My Lab', 'work_type' => 'lab', 'description' => 'Docker'])
        ->assertOk()->json('data.source_id');
    $courseId = $this->postJson('/api/skill-extraction/course', ['title' => 'Containers', 'concepts' => 'Kubernetes'])
        ->assertOk()->json('data.source_id');
    [$one, $two] = array_column($cv['candidates'], 'candidate_id');
    $this->postJson("/api/student-skills/candidates/$one/confirm")->assertOk();
    $this->postJson("/api/student-skills/candidates/$two/reject")->assertOk();
    $this->deleteJson('/api/session')->assertNoContent();

    $this->postJson('/api/session', ['email' => $bob->email, 'password' => 'password'])->assertOk();
    $this->getJson('/api/session?user_id='.$alice->getKey())->assertJsonPath('data.user_id', $bob->getKey());
    $this->getJson('/api/student-skills/candidates?user_id='.$alice->getKey())->assertJsonPath('data', []);
    $this->getJson('/api/student-skills/confirmed')->assertJsonPath('data', []);
    $this->getJson('/api/skill-extraction/cv/'.$cv['source_id'])->assertNotFound();
    $this->getJson("/api/skill-extraction/practical_work/$workId")->assertNotFound();
    $this->getJson("/api/skill-extraction/external_course/$courseId")->assertNotFound();
    $this->postJson('/api/skill-extraction/practical-work', ['source_id' => $workId])->assertNotFound();
    $this->postJson('/api/skill-extraction/course', ['source_id' => $courseId])->assertNotFound();
    $this->postJson("/api/student-skills/candidates/$one/reject")->assertNotFound();
    $this->postJson("/api/student-skills/candidates/$two/confirm")->assertNotFound();
    $this->deleteJson('/api/session')->assertNoContent();

    $this->postJson('/api/session', ['email' => $alice->email, 'password' => 'password'])->assertOk();
    $this->getJson('/api/skill-extraction/cv/'.$cv['source_id'])->assertJsonPath('data.extracted_text', 'Python, SQL');
    $this->getJson('/api/student-skills/confirmed')->assertJsonCount(1, 'data');
    $this->getJson('/api/student-skills/candidates')->assertJsonFragment(['candidate_id' => $two, 'state' => 'rejected']);
});

function skillsTestPdf(): string
{
    $content = 'BT /F1 12 Tf 72 720 Td (Technical Skills: Python, SQL) Tj ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length '.strlen($content)." >>\nstream\n$content\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $xref = "0000000000 65535 f \n";
    foreach ($objects as $index => $object) {
        $xref .= sprintf("%010d 00000 n \n", strlen($pdf));
        $pdf .= ($index + 1)." 0 obj\n$object\nendobj\n";
    }

    return $pdf."xref\n0 6\n".$xref."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".strlen($pdf)."\n%%EOF\n";
}

function skillsTestDocx(): string
{
    $path = tempnam(sys_get_temp_dir(), 'skills-docx');

    try {
        $document = new ZipArchive;
        $document->open($path, ZipArchive::OVERWRITE);
        $document->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $document->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Technical Skills</w:t><w:br/><w:t>Python</w:t><w:tab/><w:t>SQL</w:t></w:r></w:p></w:body></w:document>');
        $document->close();

        return file_get_contents($path);
    } finally {
        unlink($path);
    }
}
