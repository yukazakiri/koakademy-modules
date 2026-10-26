## Module registry change

### Checklist

- [ ] The module repository is public and has a `vX.Y.Z` tag matching
      `module.json`.
- [ ] Module tests and CI pass.
- [ ] A compatible Koakademy host integration run passes.
- [ ] `php scripts/update-module.php` generated both index changes.
- [ ] `php scripts/validate-registry.php` passes locally.
- [ ] Compatibility, migrations, permissions, and rollback impact are
      described below.
- [ ] No private signing key or sensitive data is included.

### Release

- Module repository:
- Release tag:
- Immutable source commit (`source_ref`):
- Composer package:
- Module and host CI evidence:
- Compatibility/core requirements:
- New permissions/capabilities and frontend dependencies:
- Migration or rollback notes:

The maintainer signs `registry.json` after review. Contributors should leave
the generated catalog unsigned.
