"""Synthetic tests: no registry access, publication or workflow dispatch."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("catalog_release", Path(__file__).parents[1] / "catalog_release.py")
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)
SHA = "a" * 40
DIGEST = "sha256:" + "b" * 64
OTHER = "sha256:" + "c" * 64


def receipt():
    return {"status": "prepared", "version": "1.2.3", "attempted_roles": [], "published_roles": [], "images": [
        {"role": role, "source": f"source@{DIGEST}", "target": f"target-{role}:1.2.3", "digest": DIGEST}
        for role in release.ROLES]}


class ReleaseTests(unittest.TestCase):
    def test_only_stable_semver_and_exact_sha(self):
        release.validate_inputs("1.20.3", SHA)
        for version in ("0.0.0", "v1.2.3", "01.2.3", "1.2.3-rc1", "1.2.3;echo secret", "1.2.3\n", "latest"):
            with self.subTest(version=version), self.assertRaises(release.ReleaseError):
                release.validate_inputs(version, SHA)
        for sha in ("abc", "A" * 40, SHA + "\n", "$(anything)"):
            with self.assertRaises(release.ReleaseError):
                release.validate_inputs("1.2.3", sha)

    @patch.object(release, "run")
    @patch.object(release, "read_json", return_value={"sha": SHA})
    def test_main_proof_and_injected_remote_sha(self, read, run):
        with patch.dict(os.environ, {"GITHUB_ACTIONS": "true", "GITHUB_REF": "refs/heads/main", "GITHUB_REPOSITORY": release.REPOSITORY, "GITHUB_EVENT_NAME": "workflow_dispatch"}):
            release.verify_main(SHA)
            self.assertIn(unittest.mock.call(["git", "merge-base", "--is-ancestor", SHA, "origin/main"]), run.call_args_list)
            with patch.dict(os.environ, {"GITHUB_REF": "refs/heads/feature"}), self.assertRaises(release.ReleaseError):
                release.verify_main(SHA)
            for event in ("pull_request", "push", "workflow_call"):
                with patch.dict(os.environ, {"GITHUB_EVENT_NAME": event}), self.assertRaises(release.ReleaseError):
                    release.verify_main(SHA)

    @patch.object(release, "run", return_value="restricted source license")
    @patch.object(release, "read_json", return_value={"license": "MIT"})
    def test_source_license_disagreement_blocks_release(self, read, run):
        with self.assertRaises(release.ReleaseError):
            release.verify_license(SHA)
        run.return_value = "# MIT License\n\nPermission is hereby granted"
        release.verify_license(SHA)
        read.return_value = {"license": "proprietary"}
        with self.assertRaises(release.ReleaseError):
            release.verify_license(SHA)

    @patch.object(release, "read_json")
    def test_latest_failed_or_running_workflow_cannot_use_old_success(self, read):
        old = {"id": 1, "head_sha": SHA, "head_branch": "main", "event": "push", "status": "completed", "conclusion": "success"}
        for status, conclusion in (("completed", "failure"), ("in_progress", None), ("completed", "cancelled")):
            read.return_value = {"workflow_runs": [old, {**old, "id": 2, "status": status, "conclusion": conclusion}]}
            with self.assertRaises(release.ReleaseError):
                release.verify_workflows(SHA)
        read.return_value = {"workflow_runs": [old]}
        self.assertEqual(4, len(release.verify_workflows(SHA)))
        read.return_value = {"workflow_runs": [{**old, "head_branch": "feature"}]}
        with self.assertRaises(release.ReleaseError):
            release.verify_workflows(SHA)

    @patch.object(release, "read_json")
    def test_image_source_revision_and_platform_are_verified(self, read):
        good = {"os": "linux", "architecture": "amd64", "config": {"Labels": {
            "org.opencontainers.image.revision": SHA,
            "org.opencontainers.image.source": f"https://github.com/{release.REPOSITORY}"}}}
        read.return_value = good
        release.verify_image("source", SHA)
        read.return_value = {"linux/amd64": good}
        release.verify_image("source", SHA)
        for altered in ({**good, "architecture": "arm64"}, {**good, "config": {"Labels": {}}}, {}):
            read.return_value = altered
            with self.assertRaises(release.ReleaseError):
                release.verify_image("source", SHA)

    @patch.object(release, "verify_main")
    @patch.object(release, "verify_license")
    @patch.object(release, "verify_workflows", return_value=[])
    @patch.object(release, "verify_image")
    @patch.object(release, "digest")
    @patch.object(release, "run")
    def test_prepare_has_no_write_and_preflights_all_targets(self, run, digest, image, checks, license, main):
        digest.side_effect = [DIGEST, None] * 3
        result = release.prepare("1.2.3", SHA)
        self.assertEqual("prepared", result["status"])
        self.assertEqual(3, len(result["images"]))
        self.assertTrue(all(item["source"].endswith("@" + DIGEST) for item in result["images"]))
        run.assert_not_called()
        digest.side_effect = [DIGEST, OTHER]
        with self.assertRaises(release.ReleaseError):
            release.prepare("1.2.3", SHA)
        run.assert_not_called()

    @patch.object(release, "digest", return_value=DIGEST)
    @patch.object(release, "run")
    def test_idempotent_publication_does_not_rewrite(self, run, digest):
        data = receipt()
        release.publish(data, lambda _: None)
        run.assert_not_called()
        self.assertEqual("published", data["status"])
        self.assertEqual(["web", "cron", "db"], data["published_roles"])

    @patch.object(release, "digest")
    @patch.object(release, "run")
    def test_publication_writes_only_verified_digest_and_records_progress(self, run, digest):
        digest.side_effect = [None] * 3 + [None, DIGEST] * 3
        data = receipt()
        snapshots = []
        release.publish(data, lambda value: snapshots.append(json.loads(json.dumps(value))))
        self.assertEqual(3, run.call_count)
        self.assertTrue(all("--prefer-index=false" in call.args[0] for call in run.call_args_list))
        self.assertEqual(["web", "cron", "db"], data["attempted_roles"])
        self.assertEqual("published", snapshots[-1]["status"])

    @patch.object(release, "digest")
    @patch.object(release, "run")
    def test_conflicting_destination_prevents_all_writes(self, run, digest):
        digest.side_effect = [None, OTHER]
        with self.assertRaises(release.ReleaseError):
            release.publish(receipt(), lambda _: None)
        run.assert_not_called()

    @patch.object(release, "digest")
    @patch.object(release, "run")
    def test_postwrite_mismatch_stops_remaining_roles(self, run, digest):
        digest.side_effect = [None] * 4 + [OTHER]
        data = receipt()
        with self.assertRaises(release.ReleaseError):
            release.publish(data, lambda _: None)
        self.assertEqual(1, run.call_count)
        self.assertEqual(["web"], data["attempted_roles"])
        self.assertEqual([], data["published_roles"])

    @patch.object(release.subprocess, "run")
    def test_missing_detection_is_narrow_and_does_not_leak_diagnostics(self, command):
        command.return_value = subprocess.CompletedProcess([], 1, "", "ERROR: manifest unknown")
        self.assertIsNone(release.run(["docker", "inspect"], allow_missing=True))
        for error in ("token-secret unauthorized", "failed to authorize: not found", "connection timeout", "unknown error"):
            command.return_value = subprocess.CompletedProcess([], 1, "", error)
            with self.assertRaises(release.ReleaseError) as raised:
                release.run(["docker", "inspect"], allow_missing=True)
            self.assertNotIn(error, str(raised.exception))

    @patch.object(release, "prepare")
    @patch.object(release, "publish")
    def test_cli_defaults_to_no_publication(self, publish, prepare):
        prepare.return_value = receipt()
        with tempfile.TemporaryDirectory() as directory:
            result = release.main(["--version", "1.2.3", "--source-sha", SHA, "--receipt", str(Path(directory) / "receipt.json")])
        self.assertEqual(0, result)
        publish.assert_not_called()

    @patch.object(release, "prepare")
    @patch.object(release, "publish")
    def test_cli_records_uncertain_partial_publication(self, publish, prepare):
        data = receipt()
        prepare.return_value = data
        def partial(value, save):
            value["attempted_roles"].append("web")
            raise release.ReleaseError("uncertain")
        publish.side_effect = partial
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "receipt.json"
            result = release.main(["--version", "1.2.3", "--source-sha", SHA, "--receipt", str(target), "--publish"])
            self.assertEqual("incomplete", json.loads(target.read_text())["status"])
        self.assertEqual(1, result)


if __name__ == "__main__":
    unittest.main()
