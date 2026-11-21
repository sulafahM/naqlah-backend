# Student Skills API

Laravel runs the project's Python PhraseMatcher locally. CV text and uploaded CVs follow the same extraction path once the file has been read. The Python component uses the shared `naqlah-skills-v0.1.0` dictionary. Laravel owns source records, candidate decisions and matching eligibility.

## Setup

1. Install Python 3.13 and create a virtual environment with `python -m venv .venv`.
2. From the virtual environment, run `python -m pip install -r nlp/requirements.txt`. Set `SKILL_EXTRACTION_PYTHON` in Laravel's `.env` to that interpreter, or leave the default if the interpreter is already on Laravel's PATH. On Windows the virtual environment interpreter is `.venv/Scripts/python.exe`, and on Linux it is `.venv/bin/python`.
3. Run `php artisan migrate`. To accept the full upload limit, set PHP's `upload_max_filesize` to at least `5M` and `post_max_size` to at least `6M`.

Use an existing student account. `GET /api/session` supplies the session cookie and `csrf_token`. Send that cookie and the token as `X-CSRF-TOKEN` to `POST /api/session` with JSON `email` and `password`. Use the regenerated cookie and token from the login response for later requests. `GET /api/session` returns the current student's profile. `DELETE /api/session` signs out. Send `Accept: application/json`.

## Extraction and source records

| Request                                         | Input                                                                                                              |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `POST /api/skill-extraction/cv`                 | JSON `text`, JSON `sections` with one or more of `technical_skills`, `projects`, `experience`, or multipart `file` |
| `POST /api/skill-extraction/practical-work`     | JSON `title`, `work_type`, `description`, or `source_id` for a saved practical work                                |
| `POST /api/skill-extraction/course`             | JSON `title` and `concepts`, or `source_id` for a saved external course                                            |
| `GET /api/skill-extraction/{source}/{sourceId}` | `source` is `cv`, `practical_work`, or `external_course`. Returns a source owned by the current student.           |

The CV upload accepts text-based PDF, DOCX and UTF-8 TXT up to 5 MiB. PDF files must be unencrypted and at most 100 pages. Scans without a text layer return a validation error. Source text is limited to 100,000 characters. Free-form CV input is split on recognized headings. If there are no recognized headings, the input is treated as a technical-skills excerpt. For practical work, the matcher reads the description. For courses, it reads the concepts. Titles and work types are saved as metadata.

A successful response contains the saved source and its candidates. A valid input with no matching skills returns an empty `candidates` array. Repeating the same source submission returns the existing candidates and preserves prior decisions. Extracting from a saved activity after its text changes refreshes its pending candidates.

```json
{
    "data": {
        "source_type": "practical_work",
        "source_id": 12,
        "dictionary_version": "naqlah-skills-v0.1.0",
        "candidates": [
            {
                "candidate_id": 27,
                "skill_id": 5,
                "skill_name": "sql",
                "category": "Technical",
                "source_type": "practical_work",
                "source_id": 12,
                "status": "pending",
                "state": "extracted"
            }
        ]
    }
}
```

## Student skills and review

| Request                                                     | Result                                                                                                                                      |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /api/student-skills`                                   | `data.extracted` contains pending candidates, `data.confirmed` contains matching skills, and `data.course_covered` contains course evidence |
| `GET /api/student-skills/candidates`                        | `data` contains all candidate rows, including rejected candidates and source references                                                     |
| `GET /api/student-skills/confirmed`                         | `data` contains unique matching skills with their `confirmed_at` timestamps                                                                 |
| `POST /api/student-skills/candidates/{candidateId}/confirm` | Confirms an eligible extracted candidate and adds its canonical skill to matching                                                           |
| `POST /api/student-skills/candidates/{candidateId}/reject`  | Marks an extracted candidate as rejected                                                                                                    |

Both decisions return the updated candidate. Repeating a recorded decision is safe. An attempt to reverse a decision returns `409`. Course evidence always has the `course_covered` state. If that skill is awaiting confirmation for matching, submit and confirm the practical-work candidate. A skill already independently confirmed stays in matching when new sources are added.

The API returns `401` for unauthenticated requests, `404` for unknown or foreign source and candidate IDs, `422` for invalid inputs or course review actions, and `503` when Python is unavailable. Extractor failures preserve prior records and decisions.

Run the feature tests with `php artisan test --filter=StudentSkillsTest`. The success cases run the actual Python component.

The [Postman verification notes](postman/verification.md) link to the runnable collection, environment template, synthetic CV files and recorded HTTP results.
