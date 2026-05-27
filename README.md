# Azure Storage  Browser

A Drupal 11 / PHP 8.4 module that lists files in an Azure Blob Storage container
as download links. Downloads are served via time-limited **Shared Access 
Signature (SAS)** URLs — the storage account key is never exposed to end-users 
or embedded in links.

---

## Features

- Lists blobs from a configurable Azure container with optional prefix filtering
- Filters by file extension (e.g. show only `.bak`, `.sql`, `.zip`)
- Generates per-click, time-limited SAS download URLs (configurable expiry)
- Two granular permissions: *view list* and *administer settings*
- Pure REST API implementation — **no external PHP SDK required**
- Configurable display columns (file size, last modified)

---

## Requirements

| Requirement | Version |
|---|---|
| Drupal | ^11 |
| PHP | ≥ 8.4 |
| PHP extensions | `curl`, `openssl`, `simplexml` |

---

## Installation

```bash
# Copy the module into your Drupal installation
cp -r azure_storage_browser web/modules/custom/

# Enable the module
drush en azure_storage_browser -y

# (optional) clear caches
drush cr
```

---

## Configuration

Navigate to **Administration → Configuration → Services → Azure Storage Browser**
(`/admin/config/azure-storage-browser`).

### Required settings

| Setting | Description |
|---|---|
| **Storage Account Name** | Your Azure storage account name (e.g. `mystorageaccount`) |
| **Storage Account Key** | Primary or secondary access key (base64-encoded, 88 chars) |
| **Container Name** | The blob container to list (e.g. `db-backups`) |

### Optional settings

| Setting | Default | Description |
|---|---|---|
| Blob Prefix | *(empty)* | Virtual directory filter, e.g. `backups/prod/` |
| Allowed Extensions | `bak,sql,zip,gz,tar` | Leave blank to show all blobs |
| SAS Token Expiry | `60` minutes | How long download links remain valid |
| Show File Size | ✓ | Display a Size column |
| Show Last Modified | ✓ | Display a Last Modified column |

### Storing the key securely (recommended for production)

Override the config in `settings.php` so the key is never stored in the
Drupal database:

```php
// web/sites/default/settings.php
$config['azure_storage_browser.settings']['azure_account_name'] = 'mystorageaccount';
$config['azure_storage_browser.settings']['azure_account_key']  = 'BASE64_KEY_HERE==';
$config['azure_storage_browser.settings']['azure_container_name'] = 'db-backups';
```

---

## Permissions

| Permission | Machine name | Purpose |
|---|---|---|
| Access Azure Storage Browser | `access azure storage browser` | View the file list and generate download links |
| Administer Azure Storage Browser | `administer azure storage browser` | Change credentials and settings |

Grant these at **Administration → People → Permissions** (`/admin/people/permissions`).

---

## How downloads work

1. User clicks **Download** next to a file.
2. Drupal generates a SAS URL signed with HMAC-SHA256 using the account key.
3. The user is 302-redirected to the SAS URL on Azure's CDN/origin.
4. Azure validates the signature and streams the file directly to the browser.
5. The SAS URL expires after the configured number of minutes.

The storage account key is **never** included in the URL or sent to the browser.

---

## Azure Blob Service REST API

This module talks directly to the
[Azure Blob Service REST API](https://learn.microsoft.com/en-us/rest/api/storageservices/blob-service-rest-api)
using **Shared Key** authentication and **Service SAS** tokens.
No Azure SDK or Composer package is required.

API version used: `2020-10-02`

---

## Troubleshooting

### "Azure Blob Storage returned an error: AuthenticationFailed"
- Double-check the account name and key in settings.
- Ensure the key is the raw base64 value (copy directly from the Azure Portal → Access Keys).

### "AuthorizationResourceTypeMismatch"
- Verify the container name is correct and the container exists.

### No files listed
- Check the blob prefix — if set, it must match the actual blob path prefix exactly.
- Verify the allowed extensions list includes the extensions of your blobs.

### Downloads fail immediately after clicking
- The SAS URL may have already expired. Increase the expiry minutes in settings.
- Ensure the server clock is accurate (NTP sync). Azure rejects SAS tokens
  with a start time more than 15 minutes in the future.
