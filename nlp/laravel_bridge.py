"""Read one Laravel extraction request from stdin and return one JSON result."""

import json
import re
import sys
from pathlib import Path
from xml.etree import ElementTree
from zipfile import BadZipFile, ZipFile
from zlib import error as DecompressionError

from pypdf import PdfReader
from pypdf.errors import PdfReadError

from .extractor import MAX_TEXT_CHARS
from .reusable_component import CV_SECTIONS, extract_student_skills, get_extractor

CV_HEADINGS = {
    "technical_skills": r"technical skills|skills|technologies",
    "projects": r"projects|technical projects|personal projects|academic projects",
    "experience": r"experience|work experience|professional experience|employment",
}
OTHER_HEADINGS = (
    r"education|summary|professional summary|profile|objective|contact(?: information)?|"
    r"certifications|languages|interests|references|awards|publications|courses|training|"
    r"volunteering|achievements|hobbies|about me"
)
HEADING = re.compile(
    r"^\s*(?:" + "|".join(f"(?P<{key}>{value})" for key, value in CV_HEADINGS.items())
    + r"|(?P<other>" + OTHER_HEADINGS + r"))(?:\s*:\s*(?P<body>.*)|\s*)$",
    re.IGNORECASE,
)
WORD_NAMESPACE = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
WORD_BREAKS = {WORD_NAMESPACE + "tab": "\t", WORD_NAMESPACE + "br": "\n", WORD_NAMESPACE + "cr": "\n"}


class InvalidCv(ValueError):
    pass


def read_cv(path, extension):
    path = Path(path)
    try:
        if extension == "txt":
            text = path.read_text(encoding="utf-8-sig")
        elif extension == "pdf":
            with path.open("rb") as stream:
                pdf = PdfReader(stream, strict=True, xref_stream_maximum_entries=50_000)
                if pdf.is_encrypted or len(pdf.pages) > 100:
                    raise InvalidCv("The PDF must be unencrypted and have at most 100 pages.")
                pages = []
                length = 0
                for page in pdf.pages:
                    page_text = page.extract_text()
                    length += len(page_text) + 1
                    if length > MAX_TEXT_CHARS + 1:
                        raise InvalidCv(f"CV text exceeds {MAX_TEXT_CHARS} characters.")
                    pages.append(page_text)
                text = "\n".join(pages)
        elif extension == "docx":
            with ZipFile(path) as document:
                if "[Content_Types].xml" not in document.namelist():
                    raise InvalidCv("The uploaded file is not a DOCX document.")
                with document.open("word/document.xml") as xml:
                    content = xml.read(5 * 1024 * 1024 + 1)
            if len(content) > 5 * 1024 * 1024:
                raise InvalidCv("The DOCX document exceeds the supported limits.")
            encoding = "utf-16" if content.startswith((b"\xff\xfe", b"\xfe\xff")) else "utf-8-sig"
            xml_text = content.decode(encoding)
            if re.search(r"<!DOCTYPE|<!ENTITY", xml_text, re.I):
                raise InvalidCv("The DOCX document uses unsupported XML declarations.")
            root = ElementTree.fromstring(xml_text)
            if root.tag != WORD_NAMESPACE + "document":
                raise InvalidCv("The uploaded file is not a DOCX document.")
            text = "\n".join(
                "".join(
                    (node.text or "") if node.tag == WORD_NAMESPACE + "t" else WORD_BREAKS.get(node.tag, "")
                    for node in paragraph.iter()
                )
                for paragraph in root.iter(WORD_NAMESPACE + "p")
            )
        else:
            raise InvalidCv("Unsupported CV file format.")
    except (BadZipFile, DecompressionError, ElementTree.ParseError, KeyError, PdfReadError, RuntimeError, UnicodeError) as exc:
        raise InvalidCv("The CV file is corrupted or unreadable.") from exc

    if not text.strip() or re.search(r"[\x00-\x08\x0b\x0e-\x1f\x7f]", text):
        raise InvalidCv("The CV file must contain readable text.")
    if len(text) > MAX_TEXT_CHARS:
        raise InvalidCv(f"CV text exceeds {MAX_TEXT_CHARS} characters.")
    return text.strip()


def extract_cv_sections(text):
    sections = {key: [] for key in CV_SECTIONS}
    section = None
    found_heading = False
    for line in text.splitlines():
        heading = HEADING.fullmatch(line)
        if heading:
            found_heading = True
            section = next((key for key in CV_SECTIONS if heading.group(key)), None)
            line = heading.group("body") or ""
        if section:
            sections[section].append(line)
    if not found_heading:
        sections["technical_skills"] = [text]
    return {key: "\n".join(lines) for key, lines in sections.items()}


def extract(payload):
    source_type = payload["source_type"]
    text = payload.get("text", "")
    file = payload.get("file")
    if file:
        try:
            text = read_cv(file["path"], file["extension"])
        except InvalidCv as exc:
            return {"status": "invalid_file", "message": str(exc)}

    if source_type == "external_course":
        # The upstream wrapper has no course method. Use its canonical matcher.
        result = get_extractor().extract(text)
    else:
        cv_sections = {key: "" for key in CV_SECTIONS}
        works = []
        if source_type == "cv":
            sections = payload.get("sections")
            cv_sections.update(sections if sections else extract_cv_sections(text))
        elif source_type == "practical_work":
            works = [{"work_title": "", "work_type": "", "technologies_used": text}]
        else:
            raise ValueError("Unsupported extraction source")
        result = extract_student_skills(cv_sections, works)
        result["candidate_skills"] = [skill["canonical_skill"] for skill in result["skills"]]

    return {
        "status": result["status"],
        "dictionary_version": result["dictionary_version"],
        "candidate_skills": result["candidate_skills"],
        "extracted_text": text if file else None,
    }


if __name__ == "__main__":
    request = json.loads(sys.stdin.buffer.read().decode("utf-8"))
    print(json.dumps(extract(request), ensure_ascii=True))
