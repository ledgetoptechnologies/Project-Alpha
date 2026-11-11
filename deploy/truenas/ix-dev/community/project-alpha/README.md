# Project Alpha

Project Alpha provides self-hosted project, contract, invoice and payment management.

This prepared catalog definition uses five services: the web application, maintenance worker, scheduler, encrypted MySQL database and a one-time schema migrator. The database and encryption keyring have separate persistent storage. Configuration, uploads and database backups are also retained across updates.

Complete the first-administrator setup in Project Alpha after installation. Configure the public Application Domain and HTTPS before using client links or payment webhooks. Email, payment providers and optional integrations are configured inside Project Alpha. AutoPay remains disabled in this candidate.

Back up all five storage locations together. An encrypted database without its original MySQL keyring cannot be recovered. TrueNAS application rollback does not restore data in mounted host paths; restore those datasets from an appropriate backup if a schema change must be reversed.

**Preparation only:** version `0.0.0` is an unpublished placeholder, not a release. The catalog submission requires released images, final artwork and live installation/upgrade acceptance. Do not install this candidate against existing data.
