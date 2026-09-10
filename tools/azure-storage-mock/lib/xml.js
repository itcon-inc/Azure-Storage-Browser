'use strict';

/** Escapes text for inclusion in an XML text node or attribute value. */
function esc(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

const DECL = '<?xml version="1.0" encoding="utf-8"?>';

/**
 * "List Directories and Files" response.
 *
 * Shape per
 * https://learn.microsoft.com/en-us/rest/api/storageservices/list-directories-and-files
 * Size and dates live under <Properties> for service versions >= 2017-04-17,
 * which is what the module's parseListFilesXml() reads.
 */
function listFilesXml({ endpoint, share, directoryPath, marker, maxResults, files, directories, nextMarker }) {
  const fileEntries = files
    .map(
      (f) =>
        `    <File>\n` +
        `      <Name>${esc(f.name)}</Name>\n` +
        `      <Properties>\n` +
        `        <Content-Length>${f.size}</Content-Length>\n` +
        `        <Last-Modified>${esc(f.lastModified)}</Last-Modified>\n` +
        `      </Properties>\n` +
        `    </File>`
    )
    .join('\n');

  const dirEntries = directories
    .map((name) => `    <Directory>\n      <Name>${esc(name)}</Name>\n    </Directory>`)
    .join('\n');

  const entries = [dirEntries, fileEntries].filter(Boolean).join('\n');

  return (
    `${DECL}\n` +
    `<EnumerationResults ServiceEndpoint="${esc(endpoint)}" ShareName="${esc(share)}" DirectoryPath="${esc(directoryPath)}">\n` +
    `  <Marker>${esc(marker || '')}</Marker>\n` +
    `  <MaxResults>${maxResults}</MaxResults>\n` +
    `  <Entries>\n${entries}${entries ? '\n' : ''}  </Entries>\n` +
    `  <NextMarker>${esc(nextMarker || '')}</NextMarker>\n` +
    `</EnumerationResults>\n`
  );
}

/**
 * "List Blobs" response.
 *
 * https://learn.microsoft.com/en-us/rest/api/storageservices/list-blobs
 */
function listBlobsXml({ endpoint, container, prefix, marker, maxResults, blobs, nextMarker }) {
  const blobEntries = blobs
    .map(
      (b) =>
        `    <Blob>\n` +
        `      <Name>${esc(b.name)}</Name>\n` +
        `      <Properties>\n` +
        `        <Last-Modified>${esc(b.lastModified)}</Last-Modified>\n` +
        `        <Content-Length>${b.size}</Content-Length>\n` +
        `        <Content-Type>${esc(b.contentType)}</Content-Type>\n` +
        `        <BlobType>BlockBlob</BlobType>\n` +
        `      </Properties>\n` +
        `    </Blob>`
    )
    .join('\n');

  return (
    `${DECL}\n` +
    `<EnumerationResults ServiceEndpoint="${esc(endpoint)}" ContainerName="${esc(container)}">\n` +
    `  <Prefix>${esc(prefix || '')}</Prefix>\n` +
    `  <Marker>${esc(marker || '')}</Marker>\n` +
    `  <MaxResults>${maxResults}</MaxResults>\n` +
    `  <Blobs>\n${blobEntries}${blobEntries ? '\n' : ''}  </Blobs>\n` +
    `  <NextMarker>${esc(nextMarker || '')}</NextMarker>\n` +
    `</EnumerationResults>\n`
  );
}

/**
 * Azure-shaped error body. The module's extractAzureError() reads Code and
 * Message from this, so the shape matters for the error-path tests.
 */
function errorXml(code, message) {
  return `${DECL}\n<Error>\n  <Code>${esc(code)}</Code>\n  <Message>${esc(message)}</Message>\n</Error>\n`;
}

module.exports = { esc, listFilesXml, listBlobsXml, errorXml };
