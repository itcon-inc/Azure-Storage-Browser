#!/usr/bin/env node
'use strict';

/**
 * Generates the fixture tree the mock serves.
 *
 * The awkward names are the point: per-segment rawurlencode() in the module is
 * where signing and download bugs hide, so the tree deliberately contains
 * spaces, '+', '&', '#', non-ASCII characters, a zero-byte file, and a file
 * large enough to prove downloads stream rather than buffer.
 */

const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');

const SHARE = 'va-prd-nesr-db';
const CONTAINER = 'va-prd-nesr-db';

function parseArgs(argv) {
  const options = {
    out: path.join(__dirname, 'fixtures'),
    bulkBlobs: 0,
    largeMb: 8,
  };

  for (let i = 0; i < argv.length; i++) {
    switch (argv[i]) {
      case '--out': options.out = path.resolve(argv[++i]); break;
      case '--bulk-blobs': options.bulkBlobs = Number(argv[++i]); break;
      case '--large-mb': options.largeMb = Number(argv[++i]); break;
      case '--help':
      case '-h':
        process.stdout.write(`
Usage: node make-fixtures.js [options]

  --out <dir>         Output directory        (default ./fixtures)
  --bulk-blobs <n>    Also generate a "bulk" container with n blobs, to prove
                      the >5000 List Blobs continuation path
  --large-mb <n>      Size of the streaming-test file in MB (default 8)
`);
        process.exit(0);
        break;
      default:
        throw new Error(`Unknown argument: ${argv[i]}`);
    }
  }

  return options;
}

/** Files whose names exercise URL-encoding edge cases. */
const AWKWARD_NAMES = [
  'file with spaces.sql',
  'plus+sign.sql',
  'amp&ersand.sql',
  'hash#symbol.sql',
  'percent%25encoded.sql',
  "apostrophe's.sql",
  'unicode-café-日本語.sql',
  'equals=sign.sql',
];

async function writeFile(absolutePath, contents) {
  await fsp.mkdir(path.dirname(absolutePath), { recursive: true });
  await fsp.writeFile(absolutePath, contents);
}

async function buildTree(root, label) {
  await writeFile(path.join(root, 'readme.txt'), `Fixture tree for ${label}.\n`);

  // Nested directories, to exercise recursive listing and subdirectory paths.
  await writeFile(
    path.join(root, 'backups', 'daily', 'nesr-2026-09-08.sql.gz'),
    '-- daily backup 2026-09-08\n'.repeat(40)
  );
  await writeFile(
    path.join(root, 'backups', 'daily', 'nesr-2026-09-07.sql.gz'),
    '-- daily backup 2026-09-07\n'.repeat(40)
  );
  await writeFile(
    path.join(root, 'backups', 'weekly', 'nesr-2026-W36.sql.gz'),
    '-- weekly backup 2026-W36\n'.repeat(80)
  );

  // Three levels deep, to prove recursion goes past the trivial case.
  await writeFile(
    path.join(root, 'backups', 'archive', '2025', 'q4', 'nesr-2025-12-31.sql.gz'),
    '-- archived backup\n'.repeat(20)
  );

  for (const name of AWKWARD_NAMES) {
    await writeFile(path.join(root, 'awkward', name), `-- ${name}\n`);
  }

  // Zero-byte file: size formatting and empty-stream handling.
  await writeFile(path.join(root, 'awkward', 'empty.sql'), '');

  // A file with an extension outside a typical allowed-extensions list, to
  // check the filter actually excludes it.
  await writeFile(path.join(root, 'notes.md'), '# Not a backup\n');

  // An empty directory: listing must not crash, and it must not appear as a file.
  await fsp.mkdir(path.join(root, 'empty-directory'), { recursive: true });
}

async function writeLargeFile(absolutePath, megabytes) {
  await fsp.mkdir(path.dirname(absolutePath), { recursive: true });
  const chunk = Buffer.alloc(1024 * 1024, 'A');
  const handle = await fsp.open(absolutePath, 'w');
  try {
    for (let i = 0; i < megabytes; i++) {
      await handle.write(chunk);
    }
  } finally {
    await handle.close();
  }
}

async function main() {
  const options = parseArgs(process.argv.slice(2));

  const shareRoot = path.join(options.out, 'shares', SHARE);
  const containerRoot = path.join(options.out, 'containers', CONTAINER);

  await fsp.rm(options.out, { recursive: true, force: true });

  await buildTree(shareRoot, `Azure Files share "${SHARE}"`);
  await buildTree(containerRoot, `Azure Blob container "${CONTAINER}"`);

  await writeLargeFile(path.join(shareRoot, 'large', 'streaming-test.bin'), options.largeMb);
  await writeLargeFile(path.join(containerRoot, 'large', 'streaming-test.bin'), options.largeMb);

  if (options.bulkBlobs > 0) {
    const bulkRoot = path.join(options.out, 'containers', 'bulk');
    const width = String(options.bulkBlobs).length;
    for (let i = 0; i < options.bulkBlobs; i++) {
      const name = `blob-${String(i).padStart(width, '0')}.txt`;
      // Spread across subdirectories so the flattening path is exercised too.
      const bucket = `part-${String(Math.floor(i / 1000)).padStart(2, '0')}`;
      await writeFile(path.join(bulkRoot, bucket, name), `${i}\n`);
    }
    process.stdout.write(`Generated bulk container with ${options.bulkBlobs} blobs.\n`);
  }

  process.stdout.write(`Fixtures written to ${options.out}\n`);
  process.stdout.write(`  shares/${SHARE}\n  containers/${CONTAINER}\n`);
}

main().catch((error) => {
  process.stderr.write(`${error.stack}\n`);
  process.exit(1);
});
