'use strict';

const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');

/**
 * Fixture-backed store. Shares and containers are ordinary directory trees:
 *
 *   <fixtures>/shares/<share-name>/...
 *   <fixtures>/containers/<container-name>/...
 *
 * so listings are readdir() and downloads are a file stream.
 */
class Store {
  constructor(fixturesRoot) {
    this.root = path.resolve(fixturesRoot);
  }

  /**
   * Resolves a path inside a share/container, refusing anything that escapes
   * the fixture root via .. or an absolute segment.
   *
   * @returns {string|null} Absolute path, or null if it would escape.
   */
  resolve(kind, name, relativePath = '') {
    const base = path.join(this.root, kind, name);
    const target = path.resolve(base, relativePath);
    const baseResolved = path.resolve(base);

    if (target !== baseResolved && !target.startsWith(baseResolved + path.sep)) {
      return null;
    }
    return target;
  }

  async stat(absolutePath) {
    try {
      return await fsp.stat(absolutePath);
    } catch {
      return null;
    }
  }

  /**
   * Immediate children of a directory, sorted by name — the Azure Files
   * "List Directories and Files" model.
   *
   * @returns {Promise<{files: Array, directories: Array}|null>}
   */
  async listDirectory(absolutePath) {
    let dirents;
    try {
      dirents = await fsp.readdir(absolutePath, { withFileTypes: true });
    } catch {
      return null;
    }

    const files = [];
    const directories = [];

    for (const dirent of dirents.sort((a, b) => (a.name < b.name ? -1 : 1))) {
      if (dirent.name.startsWith('.')) continue;

      if (dirent.isDirectory()) {
        directories.push(dirent.name);
      } else if (dirent.isFile()) {
        const stats = await fsp.stat(path.join(absolutePath, dirent.name));
        files.push({
          name: dirent.name,
          size: stats.size,
          lastModified: stats.mtime.toUTCString(),
        });
      }
    }

    return { files, directories };
  }

  /**
   * Every file under a directory, flattened to share-relative paths — the
   * Azure Blob model, where "directories" are only a naming convention.
   *
   * @returns {Promise<Array<{name: string, size: number, lastModified: string}>>}
   */
  async listRecursive(absoluteRoot, prefix = '') {
    const out = [];

    const walk = async (dir, relative) => {
      let dirents;
      try {
        dirents = await fsp.readdir(dir, { withFileTypes: true });
      } catch {
        return;
      }

      for (const dirent of dirents.sort((a, b) => (a.name < b.name ? -1 : 1))) {
        if (dirent.name.startsWith('.')) continue;

        const childRelative = relative ? `${relative}/${dirent.name}` : dirent.name;
        const childAbsolute = path.join(dir, dirent.name);

        if (dirent.isDirectory()) {
          await walk(childAbsolute, childRelative);
        } else if (dirent.isFile()) {
          const stats = await fsp.stat(childAbsolute);
          out.push({
            name: childRelative,
            size: stats.size,
            lastModified: stats.mtime.toUTCString(),
          });
        }
      }
    };

    await walk(absoluteRoot, '');

    // Blob listings are ordered lexicographically by full blob name.
    out.sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));

    return prefix ? out.filter((b) => b.name.startsWith(prefix)) : out;
  }

  createReadStream(absolutePath) {
    return fs.createReadStream(absolutePath);
  }
}

/**
 * Opaque continuation markers. Azure's markers are opaque to the client, so
 * the exact encoding does not matter — only that the module round-trips it.
 */
function encodeMarker(offset) {
  return Buffer.from(`offset:${offset}`, 'utf8').toString('base64');
}

function decodeMarker(marker) {
  if (!marker) return 0;
  try {
    const decoded = Buffer.from(marker, 'base64').toString('utf8');
    const match = /^offset:(\d+)$/.exec(decoded);
    return match ? Number(match[1]) : 0;
  } catch {
    return 0;
  }
}

/**
 * Applies maxresults/marker paging to an ordered list.
 *
 * @returns {{page: Array, nextMarker: string}}
 */
function paginate(items, marker, maxResults) {
  const offset = decodeMarker(marker);
  const limit = Number.isFinite(maxResults) && maxResults > 0 ? maxResults : 5000;
  const page = items.slice(offset, offset + limit);
  const nextOffset = offset + page.length;
  const nextMarker = nextOffset < items.length ? encodeMarker(nextOffset) : '';
  return { page, nextMarker };
}

module.exports = { Store, paginate, encodeMarker, decodeMarker };
