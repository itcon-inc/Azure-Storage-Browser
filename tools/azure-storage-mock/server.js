#!/usr/bin/env node
'use strict';

/**
 * A local mock of the Azure Storage REST endpoints used by the Drupal
 * azure_storage_browser module: the Files service (List Directories and
 * Files, Get File) and the Blob service (List Blobs, Get Blob).
 *
 * Azurite, Microsoft's official emulator, covers Blob/Queue/Table but not the
 * Files service, which is the backend this project is migrating to — hence a
 * purpose-built mock.
 *
 * URLs are path-style, as Azurite's are:
 *
 *   http://localhost:10001/{account}/{share}/{path}      (Files, default port)
 *   http://localhost:10002/{account}/{container}/{blob}  (Blob,  default port)
 *
 * One service per port, because in path-style form a download request to
 * either service is otherwise indistinguishable. This mirrors how real Azure
 * separates them by hostname.
 */

const http = require('node:http');
const path = require('node:path');
const { Store, paginate } = require('./lib/store');
const { listFilesXml, listBlobsXml, errorXml } = require('./lib/xml');
const sharedkey = require('./lib/sharedkey');

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

const DEFAULTS = {
  host: '127.0.0.1',
  portFile: 10001,
  portBlob: 10002,
  fixtures: path.join(__dirname, 'fixtures'),
  account: 'devstoreaccount1',
  // Azurite's well-known public development key. Not a secret; chosen so it is
  // obvious at a glance that this is a test credential.
  key: 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==',
  auth: true,
  verbose: false,
  faults: [],
};

function parseArgs(argv) {
  const options = { ...DEFAULTS, faults: [] };

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    const next = () => argv[++i];

    switch (arg) {
      case '--host': options.host = next(); break;
      case '--port-file': options.portFile = Number(next()); break;
      case '--port-blob': options.portBlob = Number(next()); break;
      case '--fixtures': options.fixtures = path.resolve(next()); break;
      case '--account': options.account = next(); break;
      case '--key': options.key = next(); break;
      case '--no-auth': options.auth = false; break;
      case '--verbose': options.verbose = true; break;
      case '--fault': {
        // --fault <path-substring>=<status>[:<AzureErrorCode>]
        const spec = next();
        const eq = spec.lastIndexOf('=');
        if (eq === -1) {
          throw new Error(`--fault expects <path-substring>=<status>[:<Code>], got "${spec}"`);
        }
        const match = String(spec.slice(eq + 1)).split(':');
        options.faults.push({
          match: spec.slice(0, eq),
          status: Number(match[0]),
          code: match[1] || 'MockInjectedFault',
        });
        break;
      }
      case '--help':
      case '-h':
        printUsage();
        process.exit(0);
        break;
      default:
        throw new Error(`Unknown argument: ${arg}`);
    }
  }

  return options;
}

function printUsage() {
  process.stdout.write(`
Azure Storage REST mock (Files + Blob)

Usage: node server.js [options]

  --host <addr>      Interface to bind            (default ${DEFAULTS.host})
                     Use 0.0.0.0 when Drupal runs in Docker and reaches the
                     mock via host.docker.internal.
  --port-file <n>    Files service port           (default ${DEFAULTS.portFile})
  --port-blob <n>    Blob service port            (default ${DEFAULTS.portBlob})
  --fixtures <dir>   Fixture root                 (default ./fixtures)
  --account <name>   Expected account name        (default ${DEFAULTS.account})
  --key <base64>     Shared Key used to verify signatures
  --no-auth          Skip signature verification, to separate a signing
                     failure from a parsing failure
  --fault <spec>     Force a status on matching paths, repeatable.
                     e.g. --fault "/backups/db.sql=403:AuthorizationFailure"
  --verbose          Log every request, and the expected string-to-sign
                     whenever a signature check fails

Fixtures are ordinary directories:
  <fixtures>/shares/<share-name>/...
  <fixtures>/containers/<container-name>/...
`);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Azure Storage **data plane** service versions.
 *
 * Deliberately exhaustive, because getting this wrong is a real and easy bug:
 * ARM (management plane) versions look similar — Microsoft.Storage/
 * storageAccounts@2022-04-01 — but the data plane rejects them with
 * InvalidHeaderValue. Note the gap between 2021-12-02 and 2022-11-02, which is
 * exactly where the plausible-looking 2022-04-01 falls.
 *
 * https://learn.microsoft.com/en-us/rest/api/storageservices/versioning-for-the-azure-storage-services
 */
const SERVICE_VERSIONS = new Set([
  '2019-02-02', '2019-07-07', '2019-12-12',
  '2020-02-10', '2020-04-08', '2020-06-12', '2020-08-04', '2020-10-02', '2020-12-06',
  '2021-02-12', '2021-04-10', '2021-06-08', '2021-08-06', '2021-10-04', '2021-12-02',
  '2022-11-02',
  '2023-01-03', '2023-05-03', '2023-08-03', '2023-11-03',
  '2024-05-04', '2024-08-04', '2024-11-04',
  '2025-01-05', '2025-05-05', '2025-07-05', '2025-11-05',
]);

const DEFAULT_VERSION = '2020-10-02';

const CONTENT_TYPES = {
  '.txt': 'text/plain',
  '.sql': 'application/sql',
  '.json': 'application/json',
  '.xml': 'application/xml',
  '.gz': 'application/gzip',
  '.zip': 'application/zip',
  '.tar': 'application/x-tar',
  '.bak': 'application/octet-stream',
  '.pdf': 'application/pdf',
};

function contentTypeFor(name) {
  return CONTENT_TYPES[path.extname(name).toLowerCase()] || 'application/octet-stream';
}

function sendXml(res, status, body) {
  const buffer = Buffer.from(body, 'utf8');
  res.writeHead(status, {
    'Content-Type': 'application/xml',
    'Content-Length': buffer.length,
    'x-ms-version': DEFAULT_VERSION,
  });
  res.end(buffer);
}

function sendError(res, status, code, message) {
  sendXml(res, status, errorXml(code, message));
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

function createHandler(service, options, store) {
  const kind = service === 'file' ? 'shares' : 'containers';

  return async function handle(req, res) {
    const url = new URL(req.url, `http://localhost`);
    const { pathname, searchParams } = url;

    if (options.verbose) {
      process.stderr.write(`[${service}] ${req.method} ${req.url}\n`);
    }

    if (req.method !== 'GET' && req.method !== 'HEAD') {
      return sendError(res, 405, 'UnsupportedHttpVerb', `This mock only implements GET; got ${req.method}.`);
    }

    // Injected faults take precedence over everything, so error paths can be
    // exercised on requests that would otherwise succeed.
    for (const fault of options.faults) {
      if (decodeURIComponent(pathname).includes(fault.match)) {
        return sendError(res, fault.status, fault.code, `Injected fault for path matching "${fault.match}".`);
      }
    }

    // Reject bogus service versions the way the real data plane does. Without
    // this the mock happily accepts an ARM version that Azure refuses, which is
    // how a broken x-ms-version reached production once already.
    const version = req.headers['x-ms-version'];
    if (version !== undefined && !SERVICE_VERSIONS.has(String(version).trim())) {
      if (options.verbose) {
        process.stderr.write(`[${service}] rejecting x-ms-version: ${version}\n`);
      }
      return sendError(
        res,
        400,
        'InvalidHeaderValue',
        `The value for one of the HTTP headers is not in the correct format. ` +
          `"${version}" is not an Azure Storage data plane service version — ` +
          `check it is not an ARM (management plane) API version.`
      );
    }

    // Path-style: /{account}/{shareOrContainer}/{rest...}
    const segments = pathname.split('/').filter((s) => s !== '');
    if (segments.length < 2) {
      return sendError(
        res,
        400,
        'InvalidUri',
        `Expected a path-style URI of /{account}/{${service === 'file' ? 'share' : 'container'}}/...`
      );
    }

    const [rawAccount, rawContainer, ...rest] = segments.map((s) => decodeURIComponent(s));

    if (rawAccount !== options.account) {
      return sendError(
        res,
        400,
        'InvalidUri',
        `Unknown account "${rawAccount}"; this mock is serving "${options.account}".`
      );
    }

    // In path-style URLs the account is a path segment, but the spec's
    // CanonicalizedResource names the account exactly once, followed by the
    // resource path as it would appear in host-style form (/{share}/...).
    // Strip the account segment here rather than double-counting it. The raw,
    // still-encoded segments are kept so canonicalizedResource() can decode.
    const rawSegments = pathname.split('/').filter((s) => s !== '');
    const resourcePathname = '/' + rawSegments.slice(1).join('/');

    if (options.auth) {
      const result = sharedkey.verify({
        method: req.method,
        headers: req.headers,
        account: options.account,
        accountKey: options.key,
        pathname: resourcePathname,
        searchParams,
      });

      if (!result.ok) {
        if (options.verbose && result.stringToSign !== undefined) {
          process.stderr.write(
            `[${service}] signature mismatch\n` +
              `  expected signature: ${result.expected}\n` +
              `  string-to-sign (escaped):\n    ${JSON.stringify(result.stringToSign)}\n`
          );
        }
        return sendError(res, 403, result.code, result.message);
      }
    }

    const relativePath = rest.join('/');
    const isList = searchParams.get('comp') === 'list';

    if (service === 'file') {
      return isList
        ? handleListFiles(res, store, kind, rawContainer, relativePath, searchParams, options)
        : handleGetObject(req, res, store, kind, rawContainer, relativePath);
    }

    return isList
      ? handleListBlobs(res, store, kind, rawContainer, searchParams, options)
      : handleGetObject(req, res, store, kind, rawContainer, relativePath);
  };
}

/** Files: List Directories and Files — immediate children only. */
async function handleListFiles(res, store, kind, share, directoryPath, searchParams, options) {
  if (searchParams.get('restype') !== 'directory') {
    return sendError(res, 400, 'InvalidQueryParameterValue', 'Listing the Files service requires restype=directory.');
  }

  const absolute = store.resolve(kind, share, directoryPath);
  if (absolute === null) {
    return sendError(res, 400, 'InvalidUri', 'The request URI is invalid.');
  }

  const listing = await store.listDirectory(absolute);
  if (listing === null) {
    return sendError(
      res,
      404,
      'ResourceNotFound',
      `The specified resource does not exist: share "${share}", directory "${directoryPath}".`
    );
  }

  // Files and directories page through one combined, ordered entry list.
  const entries = [
    ...listing.directories.map((name) => ({ type: 'dir', name })),
    ...listing.files.map((f) => ({ type: 'file', ...f })),
  ].sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));

  const maxResults = Number(searchParams.get('maxresults')) || 5000;
  const marker = searchParams.get('marker') || '';
  const { page, nextMarker } = paginate(entries, marker, maxResults);

  return sendXml(
    res,
    200,
    listFilesXml({
      endpoint: `http://localhost:${options.portFile}/${options.account}/`,
      share,
      directoryPath,
      marker,
      maxResults,
      files: page.filter((e) => e.type === 'file'),
      directories: page.filter((e) => e.type === 'dir').map((e) => e.name),
      nextMarker,
    })
  );
}

/** Blob: List Blobs — flat, recursive, prefix-filtered. */
async function handleListBlobs(res, store, kind, container, searchParams, options) {
  if (searchParams.get('restype') !== 'container') {
    return sendError(res, 400, 'InvalidQueryParameterValue', 'Listing the Blob service requires restype=container.');
  }

  const absolute = store.resolve(kind, container);
  if (absolute === null) {
    return sendError(res, 400, 'InvalidUri', 'The request URI is invalid.');
  }

  const stats = await store.stat(absolute);
  if (stats === null || !stats.isDirectory()) {
    return sendError(res, 404, 'ContainerNotFound', `The specified container "${container}" does not exist.`);
  }

  const prefix = searchParams.get('prefix') || '';
  const all = await store.listRecursive(absolute, prefix);

  const maxResults = Number(searchParams.get('maxresults')) || 5000;
  const marker = searchParams.get('marker') || '';
  const { page, nextMarker } = paginate(all, marker, maxResults);

  return sendXml(
    res,
    200,
    listBlobsXml({
      endpoint: `http://localhost:${options.portBlob}/${options.account}/`,
      container,
      prefix,
      marker,
      maxResults,
      blobs: page.map((b) => ({ ...b, contentType: contentTypeFor(b.name) })),
      nextMarker,
    })
  );
}

/** Files: Get File / Blob: Get Blob — identical from the client's side. */
async function handleGetObject(req, res, store, kind, container, relativePath) {
  if (relativePath === '') {
    return sendError(res, 400, 'InvalidUri', 'No file path was supplied.');
  }

  const absolute = store.resolve(kind, container, relativePath);
  if (absolute === null) {
    return sendError(res, 400, 'InvalidUri', 'The request URI is invalid.');
  }

  const stats = await store.stat(absolute);
  if (stats === null || !stats.isFile()) {
    return sendError(
      res,
      404,
      kind === 'shares' ? 'ResourceNotFound' : 'BlobNotFound',
      `The specified resource does not exist: "${relativePath}".`
    );
  }

  res.writeHead(200, {
    'Content-Type': contentTypeFor(relativePath),
    'Content-Length': stats.size,
    'Last-Modified': stats.mtime.toUTCString(),
    'x-ms-version': DEFAULT_VERSION,
    'x-ms-type': kind === 'shares' ? 'File' : 'BlockBlob',
    ETag: `"${stats.mtimeMs.toString(16)}-${stats.size.toString(16)}"`,
  });

  if (req.method === 'HEAD') {
    return res.end();
  }

  const stream = store.createReadStream(absolute);
  stream.on('error', () => res.destroy());
  stream.pipe(res);
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

function main() {
  let options;
  try {
    options = parseArgs(process.argv.slice(2));
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    printUsage();
    process.exit(2);
  }

  const store = new Store(options.fixtures);

  const wrap = (service) => {
    const handler = createHandler(service, options, store);
    return (req, res) => {
      handler(req, res).catch((error) => {
        process.stderr.write(`[${service}] unhandled error: ${error.stack}\n`);
        if (!res.headersSent) {
          sendError(res, 500, 'InternalError', String(error.message));
        } else {
          res.destroy();
        }
      });
    };
  };

  const fileServer = http.createServer(wrap('file'));
  const blobServer = http.createServer(wrap('blob'));

  fileServer.listen(options.portFile, options.host, () => {
    process.stdout.write(`Files service  → http://${options.host}:${options.portFile}/${options.account}/{share}/...\n`);
  });
  blobServer.listen(options.portBlob, options.host, () => {
    process.stdout.write(`Blob service   → http://${options.host}:${options.portBlob}/${options.account}/{container}/...\n`);
  });

  process.stdout.write(`Fixtures       → ${options.fixtures}\n`);
  process.stdout.write(`Account        → ${options.account}\n`);
  process.stdout.write(`Auth           → ${options.auth ? 'Shared Key verification ON' : 'DISABLED (--no-auth)'}\n`);
  if (options.faults.length) {
    for (const f of options.faults) {
      process.stdout.write(`Fault          → "${f.match}" ⇒ ${f.status} ${f.code}\n`);
    }
  }

  const shutdown = () => {
    fileServer.close();
    blobServer.close();
    process.exit(0);
  };
  process.on('SIGINT', shutdown);
  process.on('SIGTERM', shutdown);
}

if (require.main === module) {
  main();
}

module.exports = { createHandler, parseArgs };
