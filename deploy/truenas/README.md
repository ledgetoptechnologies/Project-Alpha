# TrueNAS catalog preparation

The app directory under `ix-dev/community/project-alpha` is prepared for a future contribution to `truenas/apps`. It is not an installed catalog, public submission or deployment. It does not replace the repository's canonical Compose file. The release workflow and release gates are described in `docs/admin/truenas-catalog-release.md`.

## Files

- `app.yaml`: catalog metadata; upstream app version `0.0.0` deliberately marks an unpublished candidate.
- `ix_values.yaml`: three separate image repositories with the same semantic-version tag.
- `questions.yaml`: install/edit fields for required database passwords, optional backup encryption, proxy/passkey settings, five storage locations, networking, labels and per-container resource limits.
- `templates/docker-compose.yaml`: upstream library-based renderer for database → migration → web → worker/scheduler.
- `templates/test_values`: synthetic ixVolume and host-path cases.
- `renovate-rule.json`: proposed upstream grouping rule; it is not active. Both grouping and the template's same-release guard are needed because registry tag updates are not atomic.
- `validate.py`: runs the real upstream render library and checks deployment invariants without pulling images, modifying TrueNAS or starting containers.

No arbitrary environment-variable editor is exposed in the first catalog version. That prevents overriding credential, storage or security values independently of the template. Optional service integrations remain configured through Project Alpha.

## Validation

The prepared files were checked against upstream commit `ddfe89364d176e001c65b60b5ca35c5efe78b046` and library `2.3.18`, whose published library hash is `ed9839f681342ae8ca5f8c4c860ae22d0845a0919f472ba780d6edbc838f2633`. Use an isolated Python environment containing PyYAML, Jinja2, bcrypt and docker:

```sh
python deploy/truenas/validate.py --upstream /path/to/truenas-apps --output /tmp/pa-rendered
docker compose -f /tmp/pa-rendered/basic-values.json config --quiet
docker compose -f /tmp/pa-rendered/hostpath-values.json config --quiet
```

The questions schema and app metadata JSON schema also passed using the upstream
`truenas/apps_validation` package at commit
`ec5b790df227372b10c02dcaa6496b76e32049be`. Install that package in the isolated
validation environment and add `--schema` to run those checks. This is not the
full upstream CI entry point: middleware-dependent checks, generated metadata,
catalog-wide port uniqueness and live installation remain separate.

Windows validation supplies the equivalent of `os.uname` solely to select the upstream library's non-TrueNAS mode. It is not evidence of TrueNAS middleware admission, actual dataset permissions or Linux process behavior. The renderer rejects mismatched versions, non-version tags, short/shared passwords, missing keyring storage, overlapping/nested storage and invalid ports. Digest-pinned tags with the same version are accepted.

Eleven renderer scenarios and both Docker Compose configuration checks passed. Full upstream metadata/question-schema validation remains pending: the upstream `apps_validation:slim` image was not available locally, and its stalled pull was stopped. No validation container was started. Do not treat the custom renderer checks as full catalog acceptance.

## Before public submission

1. Promote a complete tested source revision into all three public repositories and replace `0.0.0` together. Verify anonymous image pulls and supported `linux/amd64` manifests.
2. Complete the remaining middleware-dependent and generated-metadata checks, catalog-wide port validation, and a real disposable install, reboot, upgrade and restore. The standalone questions and metadata JSON schema checks already passed. Port `31234` is a candidate and must pass the full upstream catalog's uniqueness check immediately before submission.
3. Have the TrueNAS reviewer host approved icon/screenshots; the current icon points to the project source asset.
4. Copy only the app directory into a fork of `truenas/apps`, regenerate metadata/library data using upstream tools, and include the reviewed Renovate grouping rule.
5. Preserve MIT licensing from the reviewed source release.

Web/cron/MySQL entrypoints currently need root for initialization. The worker runs as `33:33`; MySQL drops to `999:999` (verified against the existing PA database image `sha256:b605db7b914c35774c2eb77ebf59efbf7e825db2a7183754f540e14911f87424`). The template drops all capabilities then adds those needed by existing entrypoints. These capability sets still require real-container install/shutdown/upgrade verification; renderer success is not that proof. No privileged mode, host network, Docker socket or TrueNAS management access is requested.

The worker and cron probes verify process liveness only. The web probe checks `/health/ready`; database readiness uses the image's built-in probe. Verify actual queue and scheduler execution during the disposable integration tests.

Never rename an existing installation or replace its storage paths to test this package. Existing installations require an explicitly reviewed migration plan.
