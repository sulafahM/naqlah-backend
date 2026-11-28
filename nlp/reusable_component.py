
"""Reusable NAQLAH skill extraction wrapper built on Wad's PhraseMatcher baseline.

Inputs are prepared text fields, not PDF/DOCX files.
No Laravel calls, student confirmation, or competency verification are performed.
"""

from functools import lru_cache
from .extractor import MAX_TEXT_CHARS, SkillExtractor


# Approved input fields
CV_SECTIONS = ("technical_skills", "projects", "experience")
WORK_FIELDS = ("work_title", "work_type", "technologies_used")


# --------------------------------------------------
# 1. Reuse Wad's existing SkillExtractor
# --------------------------------------------------

@lru_cache(maxsize=1)
def get_extractor():
    """Reuse one loaded baseline dictionary across calls."""
    return SkillExtractor()


# --------------------------------------------------
# 2. Validate student input structure
# --------------------------------------------------

def validate_student_inputs(cv_sections, practical_works):
    """Return readable validation errors for malformed inputs."""

    errors = []

    # Validate CV sections
    if not isinstance(cv_sections, dict):
        errors.append("CV sections must be a dictionary.")

    else:
        for section in CV_SECTIONS:

            if section not in cv_sections:
                errors.append(f"Missing CV section: {section}")

            elif not isinstance(cv_sections[section], str):
                errors.append(
                    f"CV section '{section}' must be a string."
                )

            elif len(cv_sections[section]) > MAX_TEXT_CHARS:
                errors.append(
                    f"CV section '{section}' exceeds "
                    f"{MAX_TEXT_CHARS} characters."
                )

    # Validate Practical Work entries
    if not isinstance(practical_works, list):
        errors.append("Practical works must be a list.")

    else:
        for index, work in enumerate(practical_works, start=1):

            if not isinstance(work, dict):
                errors.append(f"Work {index} must be a dictionary.")
                continue

            for field in WORK_FIELDS:

                if field not in work:
                    errors.append(
                        f"Work {index}: Missing {field}"
                    )

                elif not isinstance(work[field], str):
                    errors.append(
                        f"Work {index}: {field} must be a string."
                    )

                elif (
                    field == "technologies_used"
                    and len(work[field]) > MAX_TEXT_CHARS
                ):
                    errors.append(
                        f"Work {index}: technologies_used exceeds "
                        f"{MAX_TEXT_CHARS} characters."
                    )

    return errors


# --------------------------------------------------
# 3. Extract skills from Practical Work
# --------------------------------------------------

def extract_practical_work_skills(
    work_title,
    work_type,
    technologies_used,
    extractor=None
):
    """Analyze Technologies Used only and preserve work metadata."""

    # Reuse Wad's existing extractor
    extractor = (
        extractor if extractor is not None
        else get_extractor()
    )

    return {
        "source": "practical_work",

        "metadata": {
            "work_title": work_title,
            "work_type": work_type
        },

        "extraction": extractor.extract(technologies_used)
    }


# --------------------------------------------------
# 4. Extract skills from approved CV sections
# --------------------------------------------------

def extract_cv_skills(
    technical_skills,
    projects,
    experience,
    extractor=None
):
    """Extract skills separately from the three approved CV sections."""

    # Reuse Wad's existing extractor
    extractor = (
        extractor if extractor is not None
        else get_extractor()
    )

    # Only approved CV sections are analyzed
    cv_sections = {
        "technical_skills": technical_skills,
        "projects": projects,
        "experience": experience
    }

    # Store extraction results for each section
    section_results = {}

    for section_name, section_text in cv_sections.items():

        # Wad's PhraseMatcher performs the actual extraction
        section_results[section_name] = extractor.extract(
            section_text
        )

    return {
        "source": "cv",
        "sections": section_results
    }


# --------------------------------------------------
# 5. Merge skills from CV and Practical Works
# --------------------------------------------------

def merge_cv_and_practical_works(cv_result, practical_results):
    """Deduplicate skills across sources while preserving provenance."""

    combined_skills = {}

    # Collect skills from CV sections
    for section_name, section_result in cv_result["sections"].items():

        for skill in section_result["candidate_skills"]:

            source = f"cv:{section_name}"

            sources = combined_skills.setdefault(skill, [])

            if source not in sources:
                sources.append(source)

    # Collect skills from multiple Practical Works
    # Work numbers are temporary positions, not database IDs
    for work_number, work_result in enumerate(
        practical_results,
        start=1
    ):

        source = (
            f"practical_work:{work_number}:technologies_used"
        )

        for skill in work_result["extraction"]["candidate_skills"]:

            sources = combined_skills.setdefault(skill, [])

            if source not in sources:
                sources.append(source)

    return combined_skills


# --------------------------------------------------
# 6. Standardize the extracted skill output
# --------------------------------------------------

def standardize_skill_output(merged_skills):
    """Represent each canonical skill with all its sources."""

    skills = [
        {
            "canonical_skill": skill,
            "sources": list(sources)
        }
        for skill, sources in merged_skills.items()
    ]

    return {
        "total_skills": len(skills),
        "skills": skills
    }


# --------------------------------------------------
# 7. Unified student skill extraction function
# --------------------------------------------------

def extract_student_skills(
    cv_sections,
    practical_works,
    extractor=None
):
    """Validate, extract, merge, and return a structured offline result."""

    # Reuse Wad's existing extractor
    extractor = (
        extractor if extractor is not None
        else get_extractor()
    )

    version = extractor.version

    # Step 1: Validate input structure
    errors = validate_student_inputs(
        cv_sections,
        practical_works
    )

    if errors:
        return {
            "dictionary_version": version,
            "status": "invalid_input",
            "total_skills": 0,
            "skills": [],
            "errors": errors,
            "work_metadata": []
        }

    # Step 2: Check if all extraction text is empty
    cv_is_empty = all(
        not cv_sections[name].strip()
        for name in CV_SECTIONS
    )

    works_are_empty = all(
        not work["technologies_used"].strip()
        for work in practical_works
    )

    if cv_is_empty and works_are_empty:
        return {
            "dictionary_version": version,
            "status": "empty_input",
            "total_skills": 0,
            "skills": [],
            "errors": [],
            "work_metadata": []
        }

    # Step 3: Extract skills from approved CV sections
    cv_result = extract_cv_skills(
        technical_skills=cv_sections["technical_skills"],
        projects=cv_sections["projects"],
        experience=cv_sections["experience"],
        extractor=extractor
    )

    # Step 4: Extract skills from Practical Works
    practical_results = [
        extract_practical_work_skills(
            work_title=work["work_title"],
            work_type=work["work_type"],
            technologies_used=work["technologies_used"],
            extractor=extractor
        )
        for work in practical_works
    ]

    # Step 5: Collect any underlying extraction errors
    extraction_errors = []
    extraction_statuses = []

    # Check CV extraction results
    for name, result in cv_result["sections"].items():

        if result["status"] not in ("ok", "empty_input"):

            extraction_statuses.append(result["status"])

            extraction_errors.extend(
                f"CV {name}: {error}"
                for error in result["errors"]
            )

    # Check Practical Work extraction results
    for index, result in enumerate(practical_results, start=1):

        extraction = result["extraction"]

        if extraction["status"] not in ("ok", "empty_input"):

            extraction_statuses.append(
                extraction["status"]
            )

            extraction_errors.extend(
                f"Work {index}: {error}"
                for error in extraction["errors"]
            )

    # Return extraction errors if any occurred
    if extraction_statuses:

        status = (
            "invalid_input"
            if "invalid_input" in extraction_statuses
            else "processing_error"
        )

        return {
            "dictionary_version": version,
            "status": status,
            "total_skills": 0,
            "skills": [],
            "errors": extraction_errors,
            "work_metadata": []
        }

    # Step 6: Merge skills across sources
    merged = merge_cv_and_practical_works(
        cv_result,
        practical_results
    )

    # Step 7: Standardize output
    output = standardize_skill_output(merged)

    # Step 8: Add metadata and extraction status
    output.update({
        "dictionary_version": version,
        "status": "ok",
        "errors": [],

        # Temporary work numbers are not persistent IDs
        "work_metadata": [
            {
                "work_number": index,
                **result["metadata"]
            }
            for index, result in enumerate(
                practical_results,
                start=1
            )
        ]
    })

    return output
