# DBLayer 6.1.3 audit and release plan

Engineering policy: `vendor/infocyph/phpforge/resources/engineering-principles.md` and its companion `AGENTS.md`. All repository changes stay in DBLayer; other libraries and shared tooling are outside this task.

## Release decision

Target **6.1.3**, following 6.1.2. The dependency updates, archive correction and internal consolidations preserve public behavior and do not require a minor release. Merge, tagging and publication remain user-owned.

## Completed changes

- [x] Require ArrayKit `^5.3.2`, CacheLayer `^4.0.2`, InterMix `^11.2.1` and development Runwire `^3.0`. Keep Runwire and InterMix optional for production consumers. The development lockfile remains intentionally ignored.
- [x] Align current dependency documentation and workflow metadata. Preserve the explicitly historical 6.0 migration guide.
- [x] Exclude local, generated and development payloads from Composer and Git archives. The original Composer ZIP included `vendor`, generated graphs and ignored local files despite `.gitignore`; archive exclusions address distribution independently of Git tracking.
- [x] Centralize completion of all six counted repository mutations inside the existing lifecycle owner. Preserve before/after hooks for zero-row operations, defer commit callbacks only for affected rows, and preserve exception and rollback behavior.
- [x] Reuse relation key encoding, mapping-key projections, row normalization and aggregate numeric/ordering helpers. Preserve the plain relation loader's first binding representation, first/last match policies, hidden mapping columns and through-relation behavior. No production type was added.
- [x] Add adversarial regression coverage for zero matches, rollback, after-hook failure and equivalent identifiers with different PHP types. Keep the examples-to-integration-test coverage map complete.
- [x] Extend the representative host workload to exercise repository mutation hooks and batched relation projections. Workload v2 rejects mismatched v1 evidence. Its connection-bound fixture owns only benchmark state and is reused per worker; no new production abstraction or lifecycle is introduced. Existing performance budgets remain unchanged.
- [x] Apply and review PHPForge normalization, Rector, Pint and PHPCBF. Preserve all detector scopes and thresholds.

## Local validation

| Gate | Result |
| --- | --- |
| Full release guard | Passed on PHP 8.5.4: **904 tests / 4,697 assertions**, live dependency audit, syntax, references, duplicate gate, comments, formatting, PHPCS, architecture, PHPStan, Psalm and Rector. |
| Release harness analysis | Additional explicit PHPStan analysis of `.automation/scripts/ci` passed, including the new workload fixture. |
| Duplication review | 36 groups / 1,604 lines / 3.95% reduced to 34 groups / 1,478 lines / 3.64%. Remaining structural matches are not automatically shared behavior; do not merge unrelated lifecycles or SQL policies. |
| Database coverage | Temporary MySQL 9.7.2, MariaDB 12.3.3, PostgreSQL 18 and host SQLite. SQL Server and actual replica/availability-group topologies require the configured hosted matrix. |
| Dependency security | Zero known advisories. `doctrine/annotations` remains an informational, abandoned development dependency through PHPBench. The isolated production install has no advisory or abandoned-package findings. |
| Distribution | Original ZIP: 54,877,809 bytes. Final ZIP: **289,808 bytes**, 174 files, all 168 tracked runtime source files and no unexpected payloads. |
| Production consumers | Final archive installed with `--no-dev --prefer-lowest`, plugins/scripts disabled. Bound values, rollback, caching, streaming and leases passed on PHP 8.4.26 and PHP 8.5.4 without optional integrations. |
| Runwire consumer | Final archive plus published Runwire 3.0 and InterMix 11.2.1 installed without development dependencies. Context borrowing/restoration and completed-request rejection passed on PHP 8.4.26 and PHP 8.5.4. |
| Documentation/workflows | Sphinx warnings-as-errors and actionlint passed, including the final workload documentation. |
| Matched optional-change workload | All seven alternating pairs at concurrency 1, 2 and 4 passed the existing comparator. Median paired throughput regressions were 1.135%, 1.632% and -0.333%; latency and native-memory budgets passed. Variable local samples do not establish a production speed improvement. |
| Final sustained Runwire soak | Passed 300.004 seconds / 434,460 operations: 429,982 successes, 4,478 expected cancellations, zero unexpected errors, zero leaked leases and zero socket growth. All 17 checks passed; RSS growth was 6,258,688 bytes within the unchanged 32 MiB budget. |

Final archive SHA-256: `70eef9072102a03242a3e85f928f4b9fb85077076c8471b44610f93c10fe12c7`.

The optional-change comparison uses isolated DBLayer snapshots: baseline `f1f09bae9f3aae2b97e50225e7c903d77cdb620a`, candidate `ccf16ee0a7cace8c460b5c56e0f3de01aa460056`. They are temporary validation commits, not commits or tags in the user's repository. Both snapshots use the same installed dependency set. These local results supplement exact-final hosted evidence.

Full local evidence: [baseline host](evidence/release-6.1.3-baseline-host.json), [candidate host](evidence/release-6.1.3-candidate-host.json), [matched comparison](evidence/release-6.1.3-host-comparison.json), and [final soak](evidence/release-6.1.3-runwire-soak.json). The final soak identifies package snapshot `50302c07737a6e2cb7bbaa450b39cfab512fbae4`, whose runtime files match the working tree byte-for-byte.

## Release acceptance

Before release, the final committed candidate must pass the configured Security & Standards PHP 8.4/8.5 stable/lowest matrix, database/topology coverage, Documentation, Downstream Smoke and Release Performance workflows. The performance workflow requires its alternating matched baseline/candidate budget checks and full five-minute Runwire soak on that same revision. Historical green runs and temporary package snapshots do not certify a different final commit.

No merge, release tag or publication is authorized by this plan. Repository changes and review/validation state are reported separately from release ownership.
