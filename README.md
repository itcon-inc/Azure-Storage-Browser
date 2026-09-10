# Azure Storage Browser

A Drupal 11 module that lists files in Azure storage as download links — for
example nightly database backups — with configurable credentials, filtering and
permissions.

It talks to **either** of two Azure services, selected by configuration:

| Backend | Azure service | Account kind |
|---|---|---|
| `blob` | Blob Storage | StorageV2 (general purpose v2) |
| `file_share` | Azure Files | FileStorage (premium files), or Files on StorageV2 |

A storage account is only ever one kind — a FileStorage account has no blob
endpoint at all — so one site talks to one backend. Set `storage_backend` to say
which, typically overridden per environment in `settings.php`.

Pure REST implementation using **Shared Key** authentication. No Azure SDK or
Composer package required.

---

## Requirements

| Requirement | Version |
|---|---|
| Drupal | ^11 |
| PHP | ≥ 8.4 |
| PHP extensions | `curl`, `openssl`, `simplexml` |

The storage account must permit Shared Key access. If `allowSharedKeyAccess` is
disabled — increasingly common in hardened environments — this module cannot
authenticate, and would need Entra ID / OAuth support instead.

An Azure Files share must be **SMB**. NFS 4.1 shares have no REST data plane and
cannot be browsed this way.

---

## Configuration

**Administration → Configuration → Services → Azure Storage Settings**
(`/admin/config/azure-storage-browser`).

| Setting | Applies to | Description |
|---|---|---|
| **Storage backend** | both | `blob` or `file_share` |
| **Storage Account Name** | both | e.g. `mystorageaccount` |
| **Storage Account Key** | both | Primary or secondary access key (base64) |
| Container Name | blob | The blob container to list |
| Blob Name Prefix | blob | Optional filter, e.g. `backups/prod/` |
| File Share Name | file_share | The file share to list |
| Directory Path | file_share | Optional subdirectory; listed recursively |
| Allowed Extensions | both | e.g. `bak,sql,zip,gz`. Blank shows everything |
| Page Title | both | Heading on the listing page |
| Show File Size / Last Modified | both | Optional table columns |

The form shows only the group belonging to the selected backend.

### Production configuration

Override in `settings.php` so credentials never live in the database or in
exported config:

```php
$config['azure_storage_browser.settings']['storage_backend'] = 'file_share';
$config['azure_storage_browser.settings']['azure_account_name'] = 'mystorageaccount';
$config['azure_storage_browser.settings']['azure_account_key'] = 'BASE64_KEY_HERE==';
$config['azure_storage_browser.settings']['azure_share_name'] = 'db-backups';
```

Anything set this way is shown in the settings form as read-only, with a notice
saying it comes from `settings.php`.

---

## Permissions

| Permission | Machine name |
|---|---|
| Access Azure Storage Browser | `access azure storage browser` |
| Administer Azure Storage Browser | `administer azure storage browser` |

Both are marked restricted. Grant at `/admin/people/permissions`.

---

## How downloads work

Downloads are **proxied through the Drupal server**, streamed rather than
buffered:

1. The user clicks Download.
2. Drupal requests the object from Azure, signing with the account key.
3. The bytes are streamed straight through to the browser.

The account key is never exposed to the browser, and no SAS URL is generated.
This matters when the storage account's firewall is restricted to specific IP
ranges or a VNet: only the Drupal server needs network access, not the end
user's browser.

The extension filter is re-checked server-side on download, so it is a real
control rather than a display filter.

---

## Local development

`tools/azure-storage-mock` is a dependency-free Node mock of both Azure REST
services, with test suites that validate this module's request signing against
an independently spec-derived implementation. Azurite does not implement the
Files service, which is why it exists. See its README.

Point the module at it with the endpoint overrides — local development only:

```php
//Set these if you have the mock server running.
$config['azure_storage_browser.settings']['azure_file_endpoint'] = 'http://azure-mock:10001';
$config['azure_storage_browser.settings']['azure_blob_endpoint'] = 'http://azure-mock:10002';
```


Unset, the module builds production Government Cloud URLs
(`{account}.file.core.usgovcloudapi.net`).

---

## Azure REST APIs used

[Blob Service](https://learn.microsoft.com/en-us/rest/api/storageservices/blob-service-rest-api)
and [File Service](https://learn.microsoft.com/en-us/rest/api/storageservices/file-service-rest-api),
with [Shared Key](https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key)
authorization. API version `2020-10-02`.

> `2022-04-01` looks like a plausible version but is an **ARM management-plane**
> API version, not a data-plane one — the storage services reject it with
> `InvalidHeaderValue`. There is no valid data-plane version between
> `2021-12-02` and `2022-11-02`.

Both listings follow `NextMarker` continuation, so containers and directories
larger than one page list completely.

---

## Troubleshooting

**`AuthenticationFailed`**
Check the account name and key; the key must be the raw base64 value from the
Azure Portal. Also check the server clock — large skew invalidates the signed
`x-ms-date`. Run the mock with `--verbose` to see the exact expected
string-to-sign.

**`AuthorizationFailure` / connection timeouts**
The storage account firewall is likely blocking the Drupal server. Allow-list
its outbound IPs or put both on the same VNet.

**`ContainerNotFound` / `ResourceNotFound`, or an empty listing**
Usually the wrong backend for the account kind — a FileStorage account has no
blob endpoint. Check `storage_backend`, then the container/share name, the
prefix or directory path, and the allowed extensions list.

**Listing is slow on a file share**
Azure Files is hierarchical and is walked recursively, one request per
directory. Set a Directory Path to bound the traversal.
