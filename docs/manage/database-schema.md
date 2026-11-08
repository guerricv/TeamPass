# Database schema conformity

Health System → Database → **Check structure** compares every table belonging to
the configured TeamPass prefix with the schema shipped for the installed version.
The table-size list below it is still limited to the largest tables; it does not
limit the structure check.

The check reads table, column and index metadata only. It never repairs a schema,
reads item contents, decrypts secrets or runs `CHECK TABLE` / `ALTER TABLE`.
The result lives in the current browser session and is refreshed only on request.

- Red identifies potentially incompatible differences: missing tables/columns,
  changed column definitions, missing primary/unique keys, additional uniqueness,
  required additional columns without defaults, or different storage engines.
- Orange identifies differences to review: additional tables/nullable columns,
  ordinary indexes and collations. Historical upgrades and local customizations
  can explain these differences; a finding is not evidence of corruption.
- Gray means no reliable result is available (for example missing metadata
  permissions or a missing reference for this release). Missing objects may also
  be hidden by database permissions; check those before assuming data was lost.

The report includes the expected and observed definitions. Integer display widths
are normalized (except `ZEROFILL`); index names may differ, but index column order,
uniqueness, type and indexed prefix lengths must match. Column order, comments,
row counts, index cardinality and the current auto-increment counter are ignored.
Physical corruption, row consistency, foreign-key/check constraints and triggers
are outside this first version's checks.

## Maintaining the reference

`app/config/database_schema.json` is a data-only snapshot of a fresh installation,
with logical table names and no instance data. Do not build it from an installed
customer or test database: that would legitimize that database's existing drift.
The generator extracts only constant `CREATE TABLE` / additive `ALTER TABLE`
definitions from the installer and calls the two shared DDL helpers. It does not
include or evaluate the installer, its configuration, or upgrade scripts.

Use a dedicated, **empty**, disposable MySQL/MariaDB database. Its name must start
with `tp_schema_reference_`; the generator refuses a nonempty database and never
creates or drops a database. Example environment variables (use a dedicated local
database account, and supply its password through your development environment):

```sh
export TP_SCHEMA_DATABASE=tp_schema_reference_dev
export TP_SCHEMA_HOST=localhost
export TP_SCHEMA_USER=schema_builder
php app/scripts/build-schema-reference.php
php _tools/phpunit.phar tests/Unit/DatabaseSchemaTest.php
```

Review the JSON diff along with the installation and migration changes. The
PHPUnit suite fingerprints installer, shared DDL, version and upgrade sources,
so an upgrade-only change also requires an explicit review and regeneration.
The reference represents the supported release's fresh schema: intentional
legacy differences remain visible rather than being silently accepted.

`tests/Integration/database_schema.php` checks the reference against a disposable
fresh schema on a real database and exercises deliberately changed definitions.
Run it with the same environment variables in another empty disposable database.
Test both supported database families before publishing a schema change.
The runtime reference also works after the installation directory is removed.

The tool does not run historical upgrades. Comparing representative upgraded
databases remains necessary to decide whether a reported difference is a
legitimate legacy variation or a migration defect. Schema conformity is separate
from the recorded version/migration level and does not replace the upgrade wizard.
