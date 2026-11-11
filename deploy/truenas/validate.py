#!/usr/bin/env python3
"""Render the prepared catalog with an explicitly supplied upstream library.

Read-only validation: no images are pulled, no app is deployed, and no files in
the upstream checkout are modified. Requires PyYAML, Jinja2, bcrypt and docker.
"""
import argparse
import copy
import importlib
import importlib.util
import json
import os
import platform
import sys
from pathlib import Path
from types import SimpleNamespace

import jinja2
import yaml

ROOT = Path(__file__).resolve().parent
APP = ROOT / "ix-dev/community/project-alpha"


def load_yaml(path):
    return yaml.safe_load(path.read_text(encoding="utf-8"))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--upstream", type=Path, required=True)
    parser.add_argument("--output", type=Path, help="Write synthetic rendered samples here")
    parser.add_argument("--schema", action="store_true", help="Also validate questions and metadata using the installed upstream apps_validation package")
    args = parser.parse_args()
    metadata = load_yaml(APP / "app.yaml")
    if args.schema:
        from apps_validation.validate_questions import validate_questions_yaml
        from apps_validation.json_schema_utils import APP_METADATA_JSON_SCHEMA
        from jsonschema import validate
        validate_questions_yaml(str(APP / "questions.yaml"), "questions")
        validate(metadata, APP_METADATA_JSON_SCHEMA)
        print("PASS upstream questions and app metadata JSON schemas")
    base = args.upstream.resolve() / "library" / metadata["lib_version"]
    if not (base / "render.py").is_file():
        raise SystemExit("The pinned upstream library is missing")
    # The upstream helper calls os.uname on import. Windows has equivalent
    # platform information but no os.uname; this only selects non-TrueNAS mode.
    if not hasattr(os, "uname"):
        host_info = platform.uname()
        os.uname = lambda: host_info
    spec = importlib.util.spec_from_file_location("pa_catalog_lib", base / "__init__.py", submodule_search_locations=[str(base)])
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    render = importlib.import_module("pa_catalog_lib.render")
    environment = jinja2.Environment(loader=jinja2.FileSystemLoader(APP / "templates"), undefined=jinja2.StrictUndefined, extensions=["jinja2.ext.do"])
    template = environment.get_template("docker-compose.yaml")
    static = load_yaml(APP / "ix_values.yaml")

    def generate(values):
        merged = copy.deepcopy(static)
        merged.update(copy.deepcopy(values))
        merged["ix_context"] = {"app_metadata": metadata}
        return json.loads(template.render(values=merged, ix_lib=SimpleNamespace(base=SimpleNamespace(render=render))))

    count = 0
    for sample in sorted((APP / "templates/test_values").glob("*.yaml")):
        values = load_yaml(sample)
        result = generate(values)
        services = result["services"]
        assert set(services) == {"db", "migrate", "web", "worker", "cron"}
        assert services["migrate"]["depends_on"]["db"]["condition"] == "service_healthy"
        assert services["migrate"]["restart"] == "no"
        for name in ("web", "worker", "cron"):
            assert services[name]["depends_on"]["migrate"]["condition"] == "service_completed_successfully"
            assert len(services[name]["volumes"]) == 3
            assert "MYSQL_ROOT_PASSWORD" not in services[name]["environment"]
        assert len(services["db"]["volumes"]) == 2
        assert services["worker"]["user"] == "33:33"
        assert services["web"]["environment"]["AUTOPAY_BETA_ENABLED"] == "false"
        assert services["web"]["environment"]["APP_AUTH_DISABLED"] == "false"
        assert "PORTAL_INTEGRATION_HMAC_SECRETS_JSON" not in services["web"]["environment"]
        assert services["web"]["ports"][0]["target"] == 80
        for name, service in services.items():
            assert service["cap_drop"] == ["ALL"]
            assert not service["privileged"]
            assert not service.get("network_mode")
            assert not any("docker.sock" in str(v) for v in service.get("volumes", []))
        if args.output:
            args.output.mkdir(parents=True, exist_ok=True)
            (args.output / (sample.stem + ".json")).write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        count += 1
        print(f"PASS {sample.name}: dependencies, storage, identity, ports and safe defaults")

    basic = load_yaml(APP / "templates/test_values/basic-values.yaml")
    mutations = {
        "mismatched release": lambda v: v.update(images={**copy.deepcopy(static["images"]), "cron_image": {"repository": static["images"]["cron_image"]["repository"], "tag": "0.0.1"}}),
        "non-version tag": lambda v: v.update(images={k: {**x, "tag": "latest"} for k, x in static["images"].items()}),
        "short password": lambda v: v["project_alpha"].update(db_password="bad"),
        "shared database password": lambda v: v["project_alpha"].update(db_password=v["project_alpha"]["db_root_password"]),
        "overlapping storage": lambda v: v["ix_volumes"].update(mysql_keyring=v["ix_volumes"]["database"]),
        "nested storage": lambda v: v["ix_volumes"].update(mysql_keyring=v["ix_volumes"]["database"] + "/keyring"),
        "missing keyring storage": lambda v: v["storage"].pop("mysql_keyring"),
        "invalid port": lambda v: v["network"]["web_port"].update(port_number=70000),
    }
    for label, mutate in mutations.items():
        values = copy.deepcopy(basic)
        mutate(values)
        try:
            generate(values)
        except Exception:
            print(f"PASS rejects {label}")
            count += 1
        else:
            raise AssertionError(f"Accepted invalid input: {label}")
    # Digest pinning must not be treated as a version mismatch.
    values = copy.deepcopy(basic)
    values["images"] = {k: {**v, "tag": v["tag"] + "@sha256:" + "a" * 64} for k, v in static["images"].items()}
    generate(values)
    print("PASS same-version digest pins")
    count += 1
    print(f"Validated {count} catalog scenarios with upstream library {metadata['lib_version']}; no deployment performed")


if __name__ == "__main__":
    main()
