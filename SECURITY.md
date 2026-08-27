# Security policy

## Reporting a vulnerability

Please report vulnerabilities privately through a GitHub Security Advisory for
this repository, or contact the repository maintainers through an established
private channel. Do not open a public issue for vulnerabilities, exposed
signing keys, authentication bypasses, or personal/student data exposure.

Include the affected module and version, impact, reproduction steps, and any
safe mitigation. Please do not include real student records or other
sensitive data in a report.

## Registry signing key

The registry public key is a trust root for KoAkademy applications. Treat the
corresponding private key as production credentials:

- never commit it or place it in a module repository;
- keep it in a protected secret manager or persistent registry volume;
- restrict signing access to maintainers;
- verify the catalog after signing and before publishing;
- rotate it only with a coordinated update of every application’s configured
  public key.

If the private key may have been exposed, stop publishing, preserve the
current public catalog, and coordinate a documented trust-root rotation. Do
not silently generate a new key: existing applications will reject the new
catalog until their public-key configuration is migrated.

## Supported versions

Security fixes should target the latest released module version and the
currently supported KoAkademy core compatibility range. Module maintainers are
responsible for reviewing authorization, validation, file uploads, personal
data retention, and migration safety in their own repositories.
