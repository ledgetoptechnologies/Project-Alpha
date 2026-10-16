# Bound client contact profiles

`POST /api/v2/directory/clients/{publicId}/contact-profile/commands` accepts the
same four-field command and allowlisted business fields as the existing client
profile endpoint. It requires the existing `api.capabilities.read` and
`directory.clients.write` scopes. No additional environment variable is needed.
The endpoint is advertised only when client writes are enabled and migration
0109 is installed. Consumers must require that exact endpoint descriptor before
sending contact-only changes.

The target must have a current, active binding owned by the caller's API
application, with the exact revision and projection hash. The first successful
command records the client's existing portal principal and login email, or a
locked absence of portal identity. The contact profile, policy, address, resource
revision, and command receipt commit together. Legacy and contact-only commands
have separate request-hash namespaces and cannot cross-replay.

Changing a business contact email does not transfer portal login access. The
existing principal, identity binding, entitlements, workspace IDs, and application
binding remain in place. Subsequent cron reconciliation and browser profile
changes cannot create, reactivate, relink, or grant defaults to a protected client.
Existing manual, root, and client-inactive revocations still take effect. Restoring
a root does not reactivate a previously revoked protected identity. Login changes
or identity enrollment require a separate, explicit authority-management flow;
contact edits are never such an authorization.

Clients that have not explicitly used this endpoint retain legacy behavior.
Duplicate login checks use a protected client's saved login email rather than its
editable contact email, so editing one contact cannot make another client's login
appear duplicated.

No identity policy is backfilled during installation. The legacy `/profile/commands`
endpoint remains compatible. Existing financial documents, projects, public links,
source public IDs, client types, organization relationships, and private fields are
outside the contact command's editable fields.
