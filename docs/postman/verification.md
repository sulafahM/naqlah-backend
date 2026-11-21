# Skill Extraction and Student Skills verification

Verified on 21 November 2026 against a local Laravel 13.40.0 server with PHP 8.4.19, Python 3.13.3 and an isolated SQLite database seeded with two disposable accounts. [Postman Documentation & Independent Verification of Extraction and Student Skills Endpoints](https://app.clickup.com/t/z8uq9mb1mk)

The [Postman collection](skill-extraction-student-skills.postman_collection.json) contains saved examples from the actual run. The [environment template](skill-extraction-student-skills.postman_environment.json) ships with empty credentials. The examples omit session cookies and redact CSRF token values. The CVs in [fixtures](fixtures/) contain synthetic text.

## Endpoint status

| Route | Documented | Implemented | Tested | Observed success and error responses |
| --- | --- | --- | --- | --- |
| `POST /api/skill-extraction/cv` | Yes | Yes | Pass | `200` for typed text, explicit CV sections, TXT, PDF, DOCX and valid text with zero matches. `422` for blank text, conflicting inputs and unsupported, empty or corrupted files. `503` with the Python executable unavailable in a separate HTTP check. |
| `POST /api/skill-extraction/practical-work` | Yes | Yes | Pass | `200` for typed `title`, `work_type`, `description` and re-extraction by saved `source_id`. `422` for blank description. `404` for another student's source. |
| `POST /api/skill-extraction/course` | Yes | Yes | Pass | `200` for typed `title`, `concepts` and re-extraction by saved `source_id`. `422` for a non-string `concepts` value. `404` for another student's source. |
| `GET /api/skill-extraction/{source}/{sourceId}` | Yes | Yes | Pass | `200` for an owned `cv`, `practical_work` or `external_course` record. `404` for an unknown source type, a foreign source ID or an ID outside the integer range. |
| `GET /api/student-skills` | Yes | Yes | Pass | `200` with `extracted`, `confirmed` and `course_covered` arrays. `401` without a session. |
| `GET /api/student-skills/candidates` | Yes | Yes | Pass | `200` with candidates, including the rejected Docker candidate and SQL course evidence. `401` without a session. |
| `GET /api/student-skills/confirmed` | Yes | Yes | Pass | `200` with Python and SQL after explicit confirmation. `401` without a session. |
| `POST /api/student-skills/candidates/{candidateId}/confirm` | Yes | Yes | Pass | `200` for eligible CV and practical-work candidates and repeat confirmation. `422` for course evidence or CV-only proof of a course-covered skill. `409` for a rejected candidate. `404` for a foreign or out-of-range ID. |
| `POST /api/student-skills/candidates/{candidateId}/reject` | Yes | Yes | Pass | `200` with `status: rejected` and `state: rejected`. `422` for course evidence. `409` for a confirmed candidate. `404` for a foreign or out-of-range ID. |

The collection uses JSON for practical work and courses. CV extraction is the only multipart request type. The first subfolder starts a Laravel session, and later folders use the session cookie and the current `X-CSRF-TOKEN` for writes. The last request signs out and clears the transient Postman variables.

## Actual runs

- **Python independently** — 14 passing cases against `nlp.laravel_bridge` and `nlp.reusable_component`, using spaCy 3.8.18 and pypdf 7.1.1. Typed CV tests checked section filtering, alias normalization and deduplication. Practical work and course tests checked extraction from description and concepts. Valid TXT, PDF and DOCX yielded canonical skills. Blank text returned `empty_input`, unmatched text returned an empty list, and empty, unsupported and corrupted files returned `invalid_file`. The wrapper also preserved section and work provenance and returned `invalid_input` for malformed sections.
- **Laravel independently** — `php artisan test` passed **19 tests and 194 assertions**. The suite covers persistence, file storage, isolation, validation, review decisions and failure handling.
- **Collection over HTTP** — Newman 6.2.3 ran **57 requests and 136 assertions with zero failures**. It observed 30 responses with `200`, three with `204`, three with `401`, nine with `404`, two with `409` and ten with `422`. Student B could read their own empty overview and received `404` when accessing Student A's records. Student A's decisions survived sign-out and sign-in.
- **Extractor failure over HTTP** — with a nonexistent Python executable, CV extraction returned `503` and `{"message":"Skill extraction is temporarily unavailable."}`. The parsed student-skills overview was identical before and after the failed write.
- **Defect fixed and retested** — before the fix, identifiers outside PHP's integer range returned `500` when reading a source or confirming or rejecting a candidate. All three now return `404`. The regression is covered by the Laravel suite and collection.

## Run the collection

1. Follow the [Laravel and Python setup](../student-skills-api.md). Point Laravel at a fresh disposable database, set a generated `APP_KEY`, migrate, and bind the test server to loopback. Create two fresh users with `App\Models\User::factory()` in `php artisan tinker`. Use two distinct test emails and randomly generated passwords.
2. Import the collection and environment in Postman. Set `base_url` and both pairs of `student_*_email` and `student_*_password` as local environment values. Keep the cookie jar enabled and start without session cookies for the test URL. Set the Postman working directory to the directory that contains `fixtures/`. [Postman working directory](https://learning.postman.com/latest-v-12/docs/getting-started/installation/settings/working-directory)
3. In the Collection Runner, run **Skill Extraction & Student Skills** with the imported environment and retain the displayed order. Use fresh test users for each full run because the workflow persists extraction and review decisions.

To run with Newman, set `$localEnvironmentFile` to a private copy of the environment JSON populated with the disposable credentials. From `docs/postman` run the following command. [Newman command reference](https://learning.postman.com/docs/collections/using-newman-cli/newman-options)

```powershell
newman run skill-extraction-student-skills.postman_collection.json -e $localEnvironmentFile --working-dir (Get-Location).Path --no-insecure-file-read
```

## Shared collection

Published to the **Naqlah** collection in the Moqen workspace, as confirmed by the task requester. The exported workspace copy was compared with the collection executed by Newman. All 57 requests matched in order, including methods, URLs, headers, bodies, fixture paths and test scripts. All 87 saved examples matched by name, status code, response body and original request method and URL. [Skill Extraction & Student Skills](https://wabadhdh-1695993.postman.co/workspace/Moqen~abb79ea4-2bb8-4a98-ac94-0c553288f12b/folder/51847477-cbd9040f-47d7-42a6-ab01-2dfa589a8ae9)

Postman reported that the workspace's Free plan restricts adding teammates. The ClickUp attachment includes the importable collection, environment template and fixtures for teammates who need a local copy.
