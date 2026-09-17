"""Check answers of the Vereine API against docs/openapi.json.

Covers the part of OpenAPI 3.0 the description uses: $ref, type, nullable, enum,
pattern, minimum, maximum, required, properties, additionalProperties: false and
items. A keyword outside that list fails loudly, so the description cannot rely on
something this check ignores. Standard library only.
"""

from __future__ import annotations

import json
import re
from pathlib import Path

VALIDATING = {"$ref", "type", "nullable", "enum", "pattern", "minimum", "maximum", "required", "properties",
              "additionalProperties", "items"}
ANNOTATING = {"description", "example"}
TYPES = {
    "object": lambda value: isinstance(value, dict),
    "array": lambda value: isinstance(value, list),
    "string": lambda value: isinstance(value, str),
    "integer": lambda value: isinstance(value, int) and not isinstance(value, bool),
    "number": lambda value: isinstance(value, (int, float)) and not isinstance(value, bool),
    "boolean": lambda value: isinstance(value, bool),
}


class OpenApi:
    """The API description and what the runtime checks have compared with it."""

    def __init__(self, path: Path) -> None:
        self.spec = json.loads(path.read_text(encoding="utf-8"))
        self.base = "/api/index.php"
        self.checked: set[tuple[str, str, str]] = set()

    def operations(self) -> set[tuple[str, str, str]]:
        """Every documented (method, path, status)."""
        return {(method.upper(), path, status)
                for path, item in self.spec["paths"].items()
                for method, operation in item.items()
                for status in operation["responses"]}

    def template(self, path: str) -> str | None:
        """The documented path a concrete request path belongs to, such as /vereine/members/{id}/summary."""
        concrete = "/" + path.split("?", 1)[0].strip("/")
        for candidate in self.spec["paths"]:
            pattern = "^" + re.sub(r"\\\{[^}]+\\\}", "[^/]+", re.escape(candidate)) + "$"
            if re.match(pattern, concrete):
                return candidate
        return None

    def check(self, method: str, path: str, status: int, body: object) -> list[str]:
        """Problems of one answer; empty when it matches the description."""
        template = self.template(path)
        if template is None:
            return [f"{method} {path} is not in docs/openapi.json"]
        operation = self.spec["paths"][template].get(method.lower())
        if operation is None:
            return [f"{method} {template} is not in docs/openapi.json"]
        response = operation["responses"].get(str(status))
        if response is None:
            return [f"{method} {template} answered HTTP {status}, which docs/openapi.json does not list"]
        self.checked.add((method.upper(), template, str(status)))
        response = self.resolve(response)
        schema = response.get("content", {}).get("application/json", {}).get("schema")
        if schema is None:
            return []
        return self.validate(schema, body, f"{method} {template} HTTP {status}")

    def resolve(self, node: dict) -> dict:
        while "$ref" in node:
            target: object = self.spec
            for part in node["$ref"].lstrip("#/").split("/"):
                target = target[part]  # type: ignore[index]
            node = target  # type: ignore[assignment]
        return node

    def validate(self, schema: dict, value: object, where: str) -> list[str]:
        schema = self.resolve(schema)
        unknown = set(schema) - VALIDATING - ANNOTATING
        if unknown:
            raise ValueError(f"docs/openapi.json uses {sorted(unknown)} at {where}, which the check does not support")
        if value is None:
            return [] if schema.get("nullable") else [f"{where}: null is not allowed"]
        kind = schema.get("type")
        if kind and not TYPES[kind](value):
            return [f"{where}: expected {kind}, got {type(value).__name__} {value!r}"[:300]]
        problems = []
        if "enum" in schema and value not in schema["enum"]:
            problems.append(f"{where}: {value!r} is not one of {schema['enum']}")
        if "pattern" in schema and isinstance(value, str) and not re.search(schema["pattern"], value):
            problems.append(f"{where}: {value!r} does not match {schema['pattern']}")
        if "minimum" in schema and isinstance(value, (int, float)) and value < schema["minimum"]:
            problems.append(f"{where}: {value} is below {schema['minimum']}")
        if "maximum" in schema and isinstance(value, (int, float)) and value > schema["maximum"]:
            problems.append(f"{where}: {value} is above {schema['maximum']}")
        if isinstance(value, dict):
            properties = schema.get("properties", {})
            for name in schema.get("required", []):
                if name not in value:
                    problems.append(f"{where}: field {name} is missing")
            for name, item in value.items():
                if name in properties:
                    problems += self.validate(properties[name], item, f"{where}.{name}")
                elif schema.get("additionalProperties") is False:
                    problems.append(f"{where}: field {name} is not in docs/openapi.json")
        if isinstance(value, list) and "items" in schema:
            for index, item in enumerate(value):
                problems += self.validate(schema["items"], item, f"{where}[{index}]")
        return problems
