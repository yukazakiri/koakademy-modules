## Module registry change

### Checklist

- [ ] The module repository is public and has a `vX.Y.Z` tag matching
      `module.json`.
- [ ] Module tests and CI pass.
- [ ] `php scripts/update-module.php` generated both index changes.
- [ ] `php scripts/validate-registry.php` passes locally.
- [ ] Compatibility, migrations, permissions, and rollback impact are
      described below.
- [ ] No private signing key or sensitive data is included.

### Release

- Module repository:
- Release tag:
- Composer package:
- Compatibility/core requirements:
- Migration or rollback notes:

The maintainer signs `registry.json` after review. Contributors should leave
the generated catalog unsigned.
