#!/usr/bin/env python3
# Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev) — MIT License
"""
Validates generated catalog response fixtures against the OFFICIAL UCP JSON Schemas\n(catalog_search.json#search_response / catalog_lookup.json#lookup_response).

Usage:
    validate.py <spec-source-dir> <fixtures-dir>

where <spec-source-dir> is the `source/` directory of a checkout of
https://github.com/Universal-Commerce-Protocol/ucp at the pinned tag.
Exits non-zero if any fixture fails validation.
"""
import json
import os
import sys
from urllib.parse import urlparse

from jsonschema import Draft202012Validator
from referencing import Registry, Resource
from referencing.jsonschema import DRAFT202012


def build_registry(src: str):
    resources = {}
    for root, _, files in os.walk(src):
        for fname in files:
            if not fname.endswith(".json"):
                continue
            path = os.path.join(root, fname)
            with open(path) as fh:
                try:
                    schema = json.load(fh)
                except json.JSONDecodeError:
                    continue
            res = Resource.from_contents(schema, default_specification=DRAFT202012)
            if isinstance(schema, dict) and "$id" in schema:
                resources[schema["$id"]] = res
            resources[os.path.relpath(path, src)] = res

    def retrieve(uri: str):
        p = urlparse(uri).path.lstrip("/")
        base = os.path.basename(p)
        # Prefer full path-suffix matches, fall back to basename matches.
        for key in resources:
            if key.endswith(base) and (key.endswith(p) or p.endswith(key)):
                return resources[key]
        for key in resources:
            if key.endswith(base):
                return resources[key]
        raise LookupError(uri)

    registry = Registry(retrieve=retrieve)
    for uri, res in resources.items():
        registry = registry.with_resource(uri, res)
    return registry


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__, file=sys.stderr)
        return 2

    src, fixtures_dir = sys.argv[1], sys.argv[2]

    registry = build_registry(src)
    with open(os.path.join(src, "discovery", "profile_schema.json")) as fh:
        profile_schema = json.load(fh)

    def make(schema_file, def_name):
        with open(os.path.join(src, "schemas", "shopping", schema_file)) as fh:
            sid = json.load(fh)["$id"]
        return Draft202012Validator({"$ref": sid + "#/$defs/" + def_name}, registry=registry)

    validators = {
        "search_response": make("catalog_search.json", "search_response"),
        "lookup_response": make("catalog_lookup.json", "lookup_response"),
    }

    failures = 0
    fixtures = sorted(
        f for f in os.listdir(fixtures_dir) if f.endswith(".json")
    )
    if not fixtures:
        print(f"No fixtures found in {fixtures_dir}", file=sys.stderr)
        return 2

    for name in fixtures:
        with open(os.path.join(fixtures_dir, name)) as fh:
            doc = json.load(fh)
        validator = next(
            (v for prefix, v in validators.items() if name.startswith(prefix)),
            None,
        )
        if validator is None:
            print(f"SKIP {name}: no validator for this fixture prefix")
            continue
        errors = sorted(validator.iter_errors(doc), key=lambda e: e.json_path)
        if errors:
            failures += 1
            print(f"FAIL {name}: {len(errors)} schema error(s)")
            for err in errors[:10]:
                print(f"    {err.json_path}: {err.message[:160]}")
        else:
            print(f"OK   {name}: valid against official schema")

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
