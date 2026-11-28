"""Versioned, dictionary-based technical skill extraction baseline for NAQLAH.

Inputs are English text, not uploaded files or verified student competencies.
The component returns candidate skills only; confirmation belongs to application workflow.
"""
from __future__ import annotations

import csv
import re
import unicodedata
from pathlib import Path
from typing import Any

import spacy
from spacy.matcher import PhraseMatcher

DEFAULT_DATA_DIR = Path(__file__).resolve().parent
MAX_TEXT_CHARS = 100_000


def _clean_surface(value: str) -> str:
    value = unicodedata.normalize("NFKC", value)
    value = value.replace("\u00a0", " ").replace("\ufeff", "")
    return re.sub(r"\s+", " ", value).strip().casefold()


class SkillExtractor:
    def __init__(self, data_dir: str | Path = DEFAULT_DATA_DIR):
        self.data_dir = Path(data_dir)
        self.nlp = spacy.blank("en")
        self.matcher = PhraseMatcher(self.nlp.vocab, attr="LOWER")
        self.version = None
        self.name_lookup: dict[str, str] = {}
        self.aliases: dict[str, str] = {}
        dictionary = self.data_dir / "skill_dictionary.csv"
        aliases = self.data_dir / "skill_aliases.csv"
        if not dictionary.is_file() or not aliases.is_file():
            raise FileNotFoundError("skill_dictionary.csv and skill_aliases.csv must be present in nlp/")
        with dictionary.open(encoding="utf-8-sig", newline="") as f:
            for row in csv.DictReader(f):
                version = (row.get("Dictionary_Version") or "").strip()
                if self.version is None:
                    self.version = version
                elif version != self.version:
                    raise ValueError("Mixed dictionary versions are not supported")
                # Only technical terms are candidates for this extraction baseline.
                if (row.get("Category") or "").strip() != "Technical":
                    continue
                canonical = (row.get("Canonical_Skill") or "").strip()
                if canonical:
                    key = _clean_surface(canonical)
                    if key in self.name_lookup and self.name_lookup[key] != canonical:
                        raise ValueError(f"Conflicting canonical names: {key}")
                    self.name_lookup[key] = canonical
        if not self.version:
            raise ValueError("Dictionary version is missing")
        with aliases.open(encoding="utf-8-sig", newline="") as f:
            for row in csv.DictReader(f):
                if (row.get("Dictionary_Version") or "").strip() != self.version:
                    raise ValueError("Alias version differs from dictionary version")
                if (row.get("Status") or "").strip() != "Active (provisional)":
                    continue
                alias = _clean_surface(row.get("Alias") or "")
                target = _clean_surface(row.get("Canonical_Skill") or "")
                if target not in self.name_lookup:
                    raise ValueError(f"Active alias target not in technical dictionary: {target}")
                canonical = self.name_lookup[target]
                if alias in self.name_lookup and self.name_lookup[alias] != canonical:
                    raise ValueError(f"Active alias conflicts with canonical skill: {alias}")
                if alias in self.aliases and self.aliases[alias] != canonical:
                    raise ValueError(f"Conflicting aliases: {alias}")
                if alias:
                    self.aliases[alias] = canonical
        keys = dict(self.name_lookup)
        keys.update(self.aliases)
        patterns = []
        self.phrase_to_skill = {}
        for phrase, canonical in keys.items():
            doc = self.nlp.make_doc(phrase)
            if not doc:
                continue
            patterns.append(doc)
            self.phrase_to_skill[phrase] = canonical
        self.matcher.add("TECHNICAL_SKILL", patterns)

    def extract(self, text: Any) -> dict:
        """Return candidate skills and original-text span offsets; no student confirmation."""
        basic = {"dictionary_version": self.version, "status": "ok", "candidate_skills": [], "mentions": [], "errors": []}
        if not isinstance(text, str):
            basic["status"] = "invalid_input"
            basic["errors"] = ["Input must be a string."]
            return basic
        if not text.strip():
            basic["status"] = "empty_input"
            return basic
        if len(text) > MAX_TEXT_CHARS:
            basic["status"] = "invalid_input"
            basic["errors"] = [f"Input exceeds {MAX_TEXT_CHARS} characters."]
            return basic
        try:
            doc = self.nlp.make_doc(text)
            spans = []
            for _, start, end in self.matcher(doc):
                span = doc[start:end]
                phrase = _clean_surface(span.text)
                skill = self.phrase_to_skill.get(phrase)
                if skill:
                    spans.append({"start": span.start_char, "end": span.end_char,
                                  "matched_text": span.text, "canonical_skill": skill})
            # Prefer the longest matching span at a given region; overlapping candidates
            # are not both returned. Ties are resolved deterministically.
            spans.sort(key=lambda x: (-(x["end"] - x["start"]), x["start"], x["canonical_skill"]))
            retained = []
            for candidate in spans:
                if not any(candidate["start"] < selected["end"] and selected["start"] < candidate["end"]
                           for selected in retained):
                    retained.append(candidate)
            retained.sort(key=lambda x: (x["start"], x["end"]))
            basic["mentions"] = retained
            basic["candidate_skills"] = list(dict.fromkeys(x["canonical_skill"] for x in retained))
        except (ValueError, TypeError) as exc:
            basic["status"] = "processing_error"
            basic["errors"] = [type(exc).__name__]
        return basic


if __name__ == "__main__":
    import json
    extractor = SkillExtractor()
    example = "Created dashboards using Python, SQL, Docker and K8s."
    print(json.dumps(extractor.extract(example), indent=2, ensure_ascii=False))
