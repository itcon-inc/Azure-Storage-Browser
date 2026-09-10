# Azure Storage REST mock

A local stand-in for the Azure Storage REST endpoints that the Drupal
`azure_storage_browser` module talks to: the **Files** service (List Directories
and Files, Get File) and the **Blob** service (List Blobs, Get Blob).

It exists so the module can be developed and tested without an Azure account,
and without deploying to VA prod to discover whether a request signature is right.

## Where this lives

Inside the `azure_storage_browser` module, so it travels with the code it tests
and reaches anyone who installs the module. Paths below are written from the
NESR repo root; from the module root the mock is at `tools/azure-storage-mock`.

The one coupling to be aware of: NESR's `docker-compose.yml` mounts this
directory by path, so the compose service and the module are no longer fully
independent. Moving or renaming the module directory means updating that mount.

## Why not Azurite?

Azurite, Microsoft's official emulator, implements Blob, Queue and Table — but
**not the Files service**, which is exactly the backend this project is moving to
(StorageV2 → FileStorage). This mock covers both services so there is one thing
to run.

## The one rule

**The mock implements the Azure Shared Key spec independently. It must never
import or transliterate the module's own signing code.**

If `lib/sharedkey.js` mirrored `AzureRestClientTrait`, the two would agree even
when both were wrong, and the mock would prove nothing. Keeping them independent
is what makes `test/signing-parity.php` meaningful — it runs the module's *real*
trait against a separately-derived implementation of
[Authorize with Shared Key](https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key).

That independence has already paid for itself once: the first run disagreed, and
the fault was in this mock (it double-counted the account name in the
canonicalized resource), not the module.

## Quick start

The mock is an **opt-in compose profile**, so a plain `docker compose up` does
not start it:

```bash
docker compose --profile mock up -d azure-mock
```

That generates fixtures on first run and serves both services on
`nesr-network`, where Drupal addresses it by service name:

```
Files service  → http://azure-mock:10001/devstoreaccount1/{share}/...
Blob service   → http://azure-mock:10002/devstoreaccount1/{container}/...
```

Ports are also published to the host loopback, so the test suites run from
outside the container:

```bash
cd web/modules/contrib/azure_storage_browser/tools/azure-storage-mock && npm test
```

```bash
docker compose --profile mock down
```

### Running it on the host instead

Useful when iterating on the mock itself:

```bash
cd web/modules/contrib/azure_storage_browser/tools/azure-storage-mock
npm run fixtures
node server.js --host 0.0.0.0
```

`--host 0.0.0.0` is required for the Drupal container to reach it — the default
`127.0.0.1` bind refuses connections arriving over the Docker bridge. Then point
`settings.local.php` at `http://host.docker.internal:10001` / `:10002` instead of
the service name. Note `host.docker.internal` is a Docker Desktop convenience;
on Linux it needs an `extra_hosts` entry, which is one reason the container path
is the default.

## URL shape

URLs are **path-style**, as Azurite's are:

```
http://localhost:10001/{account}/{share}/{path}       Files
http://localhost:10002/{account}/{container}/{blob}   Blob
```

One service per port, because in path-style a download request to either
service is otherwise indistinguishable — real Azure separates them by hostname
(`{account}.file.core.*` vs `{account}.blob.core.*`).

Note that the account appears in the path here but exactly **once** in the
signed canonicalized resource, which is `/{account}/{share}/...` either way.

## Credentials

| | |
|---|---|
| Account | `devstoreaccount1` |
| Key | `Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==` |

That is Azurite's well-known public development key. It is not a secret, and is
used here so it is obvious at a glance that these are test credentials. Override
both with `--account` / `--key`.

## Options

```
--port-file <n>    Files service port                 (default 10001)
--port-blob <n>    Blob service port                  (default 10002)
--fixtures <dir>   Fixture root                       (default ./fixtures)
--account <name>   Expected account name
--key <base64>     Shared Key used to verify signatures
--no-auth          Skip signature verification, to separate a signing
                   failure from a parsing failure
--fault <spec>     Force a status on matching paths, repeatable:
                   --fault "/backups/db.sql=403:AuthorizationFailure"
--verbose          Log every request, and print the expected string-to-sign
                   whenever a signature check fails
```

`--verbose` is the tool to reach for on an `AuthenticationFailed`: it prints the
exact string-to-sign the mock expected, escaped, so it can be diffed against
what the module built.

## Fixtures

Shares and containers are ordinary directory trees:

```
fixtures/shares/<share-name>/...
fixtures/containers/<container-name>/...
```

`npm run fixtures` builds a tree that deliberately includes the cases where
per-segment `rawurlencode()` tends to break — spaces, `+`, `&`, `#`, `%`,
apostrophes, `=`, and non-ASCII names — plus a zero-byte file, an empty
directory, nested directories four levels deep, and an 8 MB file to prove
downloads stream rather than buffer.

Fixtures are generated, not committed. Regenerate them at any time — but note
that `make-fixtures.js` **deletes the whole fixture tree first**, so running it
without `--bulk-blobs` removes the bulk container and `npm run test:pagination`
will then fail with `ContainerNotFound`. Use `npm run fixtures:bulk` to rebuild
both.

```bash
npm run fixtures                        # standard tree
npm run fixtures:bulk                   # adds a 5500-blob container
node make-fixtures.js --large-mb 64     # bigger streaming test
```

## Tests

### `npm test` — signing parity

Loads the module's real `AzureRestClientTrait` (it has no Drupal dependencies,
so it runs standalone) and drives it against the mock: directory listings, marker
continuation, downloads of every awkward filename, large-file streaming, and the
error paths through `extractAzureError()`.

### `npm run test:pagination` — List Blobs continuation

Asserts the marker-following loop returns a complete 5500-blob container, and
reports how much a single `maxresults=5000` request would still have missed.
`AzureBlobStorageService` originally ignored `NextMarker` and silently dropped
those 500 blobs; this keeps the cost of regressing it visible.

Requires the bulk fixtures: `npm run fixtures:bulk`.

## Pointing Drupal at the mock

The module hardcodes `https://{account}.file.core.usgovcloudapi.net`, so it needs
the endpoint-override config before it can reach localhost. In `settings.local.php`:

```php
$config['azure_storage_browser.settings']['azure_account_name'] = 'devstoreaccount1';
$config['azure_storage_browser.settings']['azure_account_key'] = 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==';
$config['azure_storage_browser.settings']['azure_share_name'] = 'va-prd-nesr-db';
$config['azure_storage_browser.settings']['azure_container_name'] = 'va-prd-nesr-db';
$config['azure_storage_browser.settings']['azure_file_endpoint'] = 'http://azure-mock:10001';
$config['azure_storage_browser.settings']['azure_blob_endpoint'] = 'http://azure-mock:10002';
```

`settings.local.php` already carries this block, with the real VA prod account
commented out beneath it as a second profile — switching between mock and prod
is a matter of moving the comments.

Never set the endpoint overrides outside local development — unset, the module
builds host-style production URLs. `test/production-url-parity.php` guards that.

## Fidelity — what this is not

Faithful enough to test the module's request signing, XML parsing, pagination and
streaming. It is **not** a general Azure emulator:

- GET only; no writes, deletes, properties, metadata or leases
- No SAS tokens, no Entra ID / OAuth — Shared Key only
- No `Range` requests, snapshots, or conditional headers
- Blob listing ignores `delimiter`, so it is always a flat recursive listing
- No `FileStorage` vs `StorageV2` distinction — that is an account-kind concept
  with no data-plane expression, which is precisely why the module's file-share
  code needs no changes for the migration

A green run here means the module's REST layer is correct. It does not
substitute for the one end-to-end check against the real account.
