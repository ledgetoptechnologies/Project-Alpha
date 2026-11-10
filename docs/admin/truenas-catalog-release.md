# TrueNAS catalog preparation and release

This work prepares a public Community Edition catalog entry. It does not deploy
PA, publish container tags, enable AutoPay, or submit anything to TrueNAS.

## Two separate release systems

The existing main-branch Docker workflow continues to build the current PA
images and their source-SHA tags. The new `catalog-release.yml` workflow is a
manual, main-only promotion step. It defaults to read-only preflight and checks
the exact source commit's latest main-push CI, Docker, Gitleaks and CodeQL runs.
It also requires the source's MIT license to match Composer metadata and checks
the revision, source and linux/amd64 platform of all three container images.

The promotion plan maps those existing immutable image digests into:

| Role | Catalog repository | Version |
| --- | --- | --- |
| Web, worker, migrator | `ghcr.io/ledgetoptechnologies/project-alpha-web` | Same stable SemVer |
| Cron | `ghcr.io/ledgetoptechnologies/project-alpha-cron` | Same stable SemVer |
| Encrypted MySQL | `ghcr.io/ledgetoptechnologies/project-alpha-db` | Same stable SemVer |

Only an explicit `publish=true` invocation writes tags. No `latest` tag or
existing deployment is changed. Existing version tags are not overwritten with
different digests. Retries accept the same already-published digest.

## First public release gates

1. Merge reviewed preparation changes to main. The old restricted license must
   not be used as the source of a public MIT release.
2. Select the merged source SHA after all four required workflows pass. Complete
   the catalog's clean-install, restart, upgrade and restore acceptance on
   disposable data. The source CI alone is not TrueNAS host acceptance.
3. Verify target GHCR package permissions and public visibility. The workflow
   uses the repository `GITHUB_TOKEN`; no new PAT is required by the code. If a
   new package is reported as denied/private rather than an explicit missing
   manifest, preflight deliberately stops. An operator must resolve package
   ownership/access and any initial package provisioning before retrying. Never
   weaken this check to interpret arbitrary registry failures as "missing".
4. Run the workflow with `publish=false`, exact source SHA and a chosen stable
   version. Inspect its receipt before approving a publishing invocation.
5. After explicitly authorized publication, independently verify anonymous pulls
   of all three public tags and their recorded digests.
6. Replace catalog version `0.0.0` with that verified release and generate the
   upstream metadata. `0.0.0` is an unpublished template value, not a release.
7. Review the upstream Renovate grouping change with TrueNAS, attach final icon
   and screenshots, and submit the initial app addition. Do not claim a listing
   until maintainers accept and publish it.

Example **read-only** invocation, after the workflow exists on main:

```sh
gh workflow run catalog-release.yml --ref main \
  -f version=1.0.0 -f source_sha=<full-tested-main-sha> -F publish=false
```

The version shown is an example, not an assigned release. This workflow is not
triggered by a feature branch, tag, pull request or new Docker build.

## Ongoing catalog updates

Once accepted, TrueNAS's own Renovate workflow detects stable image releases,
updates the catalog entry and proposes a PR. The proposed grouping configuration
and template checks keep web/cron/database versions together. Do not run a second
bot that creates duplicate upstream PRs.

Registry publication cannot atomically update three repositories. An interrupted
promotion records attempted and completed roles and must be retried using the
same source and version. Do not publish another version to hide the partial
state. The catalog template must reject mixed versions, and the grouping rule
must be accepted before relying on unattended update proposals.

Changes to installer fields, storage or startup behavior still require deliberate
catalog changes and, when appropriate, migrations of saved installation values.
TrueNAS's catalog publishing and user installation are separate from our image
publication. We do not control upstream review timing or users' update choices.

## Compatibility and cleanup boundaries

The original `docker-compose.yml` and existing deployments remain unchanged.
The catalog provides its own platform-specific template using the same five
service roles and persistent data paths. It excludes company-specific Operations
configuration and floating images. Never delete old storage names or encryption
keys as a cosmetic cleanup. Existing Custom App installations require an explicit
data-mapping migration plan before conversion to a catalog installation.

Sources checked November 10, 2026:

- https://github.com/truenas/apps/blob/master/CONTRIBUTIONS.md
- https://github.com/truenas/apps/blob/master/.github/renovate-config.js
- https://github.com/truenas/apps/blob/master/.github/workflows/renovate.yaml

See `deploy/truenas` for the candidate package and its local validation procedure.

## Preparation validation (November 10, 2026)

- 13 synthetic release-tool tests pass without network requests or publication.
- 11 catalog cases pass with upstream rendering library 2.3.18, including both
  storage modes and invalid input rejection. Both rendered configurations pass
  Docker Compose validation.
- Installer questions and metadata JSON schemas pass using the upstream
  validation package; this is narrower than the complete upstream CI command.
- Main-only workflow policy tests pass (2 tests, 40 assertions).
- Python compilation and Git whitespace checks pass.

No release workflow was dispatched, stable image tag published, catalog PR
submitted, or application deployed. Full upstream checks and a disposable
install/restart/upgrade/restore remain required before public submission. The
Dockerfile license-copy changes have not been built into release images here.
