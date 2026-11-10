#!/usr/bin/env python3
"""Prepare or explicitly promote a tested main commit; never deploy an application."""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path

REPOSITORY = "ledgetoptechnologies/Project-Alpha"
REGISTRY = "ghcr.io/ledgetoptechnologies"
WORKFLOWS = ("ci.yml", "docker-publish.yml", "gitleaks.yml", "codeql.yml")
ROLES = {"web": "sha-", "cron": "cron-sha-", "db": "db-sha-"}


class ReleaseError(RuntimeError):
    pass


def run(args: list[str], *, allow_missing: bool = False) -> str | None:
    """Never invoke a shell or echo potentially sensitive subprocess diagnostics."""
    try:
        result = subprocess.run(args, capture_output=True, text=True, timeout=180, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise ReleaseError(f"{args[0]} command unavailable or timed out") from exc
    if result.returncode:
        error = result.stderr.lower().strip()
        # Only an explicit missing manifest permits creating a new tag. Authentication,
        # transport failures and an inaccessible package must stop the promotion.
        missing = "manifest unknown" in error or error.endswith(": not found")
        ambiguous = any(word in error for word in ("unauthorized", "denied", "authorize", "timeout", "connection"))
        if allow_missing and missing and not ambiguous:
            return None
        raise ReleaseError(f"{args[0]} command failed (exit {result.returncode}); no further publication attempted")
    if len(result.stdout) > 8 * 1024 * 1024:
        raise ReleaseError("Command response exceeded the safety limit")
    return result.stdout


def read_json(args: list[str], *, allow_missing: bool = False):
    raw = run(args, allow_missing=allow_missing)
    if raw is None:
        return None
    try:
        return json.loads(raw)
    except (ValueError, TypeError) as exc:
        raise ReleaseError("Command returned invalid JSON") from exc


def validate_inputs(version: str, sha: str) -> None:
    if not re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", version):
        raise ReleaseError("Version must be a stable SemVer such as 1.2.3, without a v prefix")
    if version == "0.0.0":
        raise ReleaseError("0.0.0 is reserved for the unpublished catalog template")
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ReleaseError("Source must be a full lowercase 40-character commit SHA")


def verify_license(sha: str) -> None:
    """Check the source being released, not merely the workflow's checkout."""
    license_text = run(["git", "show", f"{sha}:LICENSE.md"])
    composer = read_json(["git", "show", f"{sha}:composer.json"])
    if composer.get("license") != "MIT" or not license_text.lstrip("# \r\n").startswith("MIT License"):
        raise ReleaseError("Public release is blocked until source license and package metadata consistently declare the approved MIT license")


def verify_main(sha: str) -> None:
    if os.environ.get("GITHUB_ACTIONS") == "true":
        if (os.environ.get("GITHUB_REF") != "refs/heads/main"
                or os.environ.get("GITHUB_REPOSITORY") != REPOSITORY
                or os.environ.get("GITHUB_EVENT_NAME") != "workflow_dispatch"):
            raise ReleaseError("This workflow is restricted to the canonical repository's main branch")
    run(["git", "fetch", "--no-tags", "origin", "main"])
    run(["git", "merge-base", "--is-ancestor", sha, "origin/main"])
    # Verify the remote identity too: local git state alone must not authorize another repository.
    commit = read_json(["gh", "api", f"repos/{REPOSITORY}/commits/{sha}"])
    if commit.get("sha") != sha:
        raise ReleaseError("Canonical source commit could not be verified")


def verify_workflows(sha: str) -> list[dict]:
    evidence = []
    for workflow in WORKFLOWS:
        data = read_json(["gh", "api", f"repos/{REPOSITORY}/actions/workflows/{workflow}/runs?head_sha={sha}&branch=main&event=push&per_page=100"])
        matching = [r for r in data.get("workflow_runs", [])
                    if r.get("head_sha") == sha and r.get("head_branch") == "main" and r.get("event") == "push"]
        if not matching:
            raise ReleaseError(f"No main push validation found for {workflow}")
        latest = max(matching, key=lambda r: (r.get("id", 0), r.get("run_attempt", 0)))
        if latest.get("status") != "completed" or latest.get("conclusion") != "success":
            raise ReleaseError(f"Latest {workflow} run for the source commit is not successful")
        evidence.append({"workflow": workflow, "run_id": latest["id"], "run_attempt": latest.get("run_attempt", 1)})
    return evidence


def digest(ref: str, *, allow_missing: bool = False) -> str | None:
    manifest = read_json(["docker", "buildx", "imagetools", "inspect", ref, "--format", "{{json .Manifest}}"], allow_missing=allow_missing)
    if manifest is None:
        return None
    value = manifest.get("digest", "")
    if not re.fullmatch(r"sha256:[0-9a-f]{64}", value):
        raise ReleaseError("Registry returned an invalid manifest digest")
    return value


def verify_image(ref: str, sha: str) -> None:
    image = read_json(["docker", "buildx", "imagetools", "inspect", ref, "--format", "{{json .Image}}"])
    configs = [image] if "config" in image else list(image.values())
    if not configs:
        raise ReleaseError("Image has no runnable platform configuration")
    for config in configs:
        labels = config.get("config", {}).get("Labels", {})
        if config.get("os") != "linux" or config.get("architecture") != "amd64":
            raise ReleaseError("Only verified linux/amd64 images are currently supported")
        if labels.get("org.opencontainers.image.revision") != sha:
            raise ReleaseError("Image revision does not match the validated source commit")
        if labels.get("org.opencontainers.image.source") != f"https://github.com/{REPOSITORY}":
            raise ReleaseError("Image source does not match the canonical repository")


def prepare(version: str, sha: str) -> dict:
    validate_inputs(version, sha)
    verify_main(sha)
    verify_license(sha)
    checks = verify_workflows(sha)
    images = []
    for role, prefix in ROLES.items():
        source_tag = f"{REGISTRY}/project-alpha:{prefix}{sha}"
        source_digest = digest(source_tag)
        source = f"{REGISTRY}/project-alpha@{source_digest}"
        verify_image(source, sha)
        target = f"{REGISTRY}/project-alpha-{role}:{version}"
        existing = digest(target, allow_missing=True)
        if existing is not None and existing != source_digest:
            raise ReleaseError(f"Refusing to overwrite an existing {role} release tag")
        images.append({"role": role, "source": source, "target": target, "digest": source_digest, "already_published": existing is not None})
    return {"schema": 1, "version": version, "source_sha": sha, "repository": REPOSITORY,
            "validation": checks, "images": images, "status": "prepared", "attempted_roles": [], "published_roles": []}


def publish(receipt: dict, write_receipt) -> None:
    # Recheck every destination before the first write. Tags are immutable by policy;
    # external registry actors must also respect this policy (there is no registry CAS).
    for image in receipt["images"]:
        existing = digest(image["target"], allow_missing=True)
        if existing is not None and existing != image["digest"]:
            raise ReleaseError("Release destination changed since preflight")
    receipt["status"] = "publishing"
    write_receipt(receipt)
    for image in receipt["images"]:
        existing = digest(image["target"], allow_missing=True)
        if existing is not None and existing != image["digest"]:
            raise ReleaseError("Refusing a conflicting tag discovered during publication")
        if existing is None:
            receipt["attempted_roles"].append(image["role"])
            write_receipt(receipt)
            run(["docker", "buildx", "imagetools", "create", "--prefer-index=false", "--tag", image["target"], image["source"]])
        if digest(image["target"]) != image["digest"]:
            raise ReleaseError("Published image digest did not match the prepared source")
        receipt["published_roles"].append(image["role"])
        write_receipt(receipt)
    receipt["status"] = "published"
    write_receipt(receipt)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", required=True)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--receipt", type=Path, required=True)
    parser.add_argument("--publish", action="store_true", help="Explicitly publish the prepared image tags; default is read-only")
    args = parser.parse_args(argv)
    receipt = None
    def save(value):
        args.receipt.parent.mkdir(parents=True, exist_ok=True)
        args.receipt.write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")
    try:
        receipt = prepare(args.version, args.source_sha)
        save(receipt)
        if args.publish:
            publish(receipt, save)
        print(f"Release {args.version}: {receipt['status']}. Receipt: {args.receipt}")
        return 0
    except ReleaseError as error:
        if receipt is not None:
            receipt["status"] = "incomplete" if receipt["attempted_roles"] or receipt["published_roles"] else "failed"
            save(receipt)
        print(f"Release stopped: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
