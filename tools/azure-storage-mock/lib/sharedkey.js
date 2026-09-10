'use strict';

const crypto = require('node:crypto');

/**
 * Shared Key (not Shared Key Lite) request authorisation.
 *
 * Implemented from the Microsoft specification:
 * https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key
 *
 * This is deliberately written from the spec rather than ported from the
 * Drupal module it exists to test. If this file mirrored the module's own
 * canonicalisation, the two would agree even when both were wrong, and the
 * mock would prove nothing about correctness against real Azure.
 */

// The fixed, order-sensitive header block of the string-to-sign, after VERB.
const SIGNED_HEADERS = [
  'content-encoding',
  'content-language',
  'content-length',
  'content-md5',
  'content-type',
  'date',
  'if-modified-since',
  'if-match',
  'if-none-match',
  'if-unmodified-since',
  'range',
];

/**
 * "Retrieve all headers for the resource that begin with x-ms-, including the
 * x-ms-date header. Convert each HTTP header name to lowercase. Sort the
 * headers lexicographically by header name, in ascending order. Unfold
 * multi-line values, trim whitespace around the colon, and append a newline
 * to each canonicalized header."
 */
function canonicalizedHeaders(headers) {
  const msHeaders = Object.entries(headers)
    .filter(([name]) => name.toLowerCase().startsWith('x-ms-'))
    .map(([name, value]) => [
      name.toLowerCase(),
      String(Array.isArray(value) ? value.join(',') : value)
        .replace(/\r?\n/g, ' ')
        .replace(/\s+/g, ' ')
        .trim(),
    ])
    .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0));

  return msHeaders.map(([name, value]) => `${name}:${value}\n`).join('');
}

/**
 * "Beginning with an empty string, append a forward slash followed by the name
 * of the account, then the resource's URI path (decoded, without query).
 * Then, for each query parameter: lowercase the name, sort by name, URL-decode
 * name and value, and append '\nname:value'. Multiple values for one parameter
 * are sorted and joined with commas."
 */
function canonicalizedResource(account, pathname, searchParams) {
  const decodedPath = pathname
    .split('/')
    .map((segment) => decodeURIComponent(segment))
    .join('/');

  let resource = `/${account}${decodedPath}`;

  const byName = new Map();
  for (const [rawName, value] of searchParams.entries()) {
    const name = rawName.toLowerCase();
    if (!byName.has(name)) {
      byName.set(name, []);
    }
    byName.get(name).push(value);
  }

  for (const name of [...byName.keys()].sort()) {
    const values = byName.get(name).slice().sort();
    resource += `\n${name}:${values.join(',')}`;
  }

  return resource;
}

/**
 * Builds the full string-to-sign for a request.
 *
 * @returns {string}
 */
function buildStringToSign(method, headers, account, pathname, searchParams) {
  const parts = SIGNED_HEADERS.map((name) => {
    const raw = headers[name];
    const value = raw === undefined ? '' : String(raw);

    // Content-Length is signed as an empty string when zero or absent.
    if (name === 'content-length' && (value === '' || value === '0')) {
      return '';
    }
    // When x-ms-date is present, the Date header is signed as empty.
    if (name === 'date' && headers['x-ms-date'] !== undefined) {
      return '';
    }
    return value;
  });

  return (
    [method.toUpperCase(), ...parts].join('\n') +
    '\n' +
    canonicalizedHeaders(headers) +
    canonicalizedResource(account, pathname, searchParams)
  );
}

/** base64(HMAC-SHA256(base64decode(accountKey), stringToSign)) */
function sign(accountKey, stringToSign) {
  return crypto
    .createHmac('sha256', Buffer.from(accountKey, 'base64'))
    .update(stringToSign, 'utf8')
    .digest('base64');
}

/**
 * Verifies an Authorization header of the form "SharedKey <account>:<sig>".
 *
 * @returns {{ok: true} | {ok: false, code: string, message: string, expected?: string, stringToSign?: string}}
 */
function verify({ method, headers, account, accountKey, pathname, searchParams }) {
  const authorization = headers['authorization'];

  if (!authorization) {
    return {
      ok: false,
      code: 'NoAuthenticationInformation',
      message: 'Server failed to authenticate the request. Authorization header is missing.',
    };
  }

  const match = /^SharedKey ([^:]+):(.+)$/.exec(authorization);
  if (!match) {
    return {
      ok: false,
      code: 'InvalidAuthenticationInfo',
      message: `Authorization header is malformed. Expected "SharedKey <account>:<signature>", got "${authorization}".`,
    };
  }

  const [, signedAccount, providedSignature] = match;

  if (signedAccount !== account) {
    return {
      ok: false,
      code: 'AuthenticationFailed',
      message: `Account name in the Authorization header ("${signedAccount}") does not match the account in the request path ("${account}").`,
    };
  }

  const stringToSign = buildStringToSign(method, headers, account, pathname, searchParams);
  const expected = sign(accountKey, stringToSign);

  if (expected !== providedSignature) {
    return {
      ok: false,
      code: 'AuthenticationFailed',
      message:
        'The MAC signature found in the HTTP request is not the same as any computed signature.',
      expected,
      stringToSign,
    };
  }

  return { ok: true };
}

module.exports = { buildStringToSign, canonicalizedHeaders, canonicalizedResource, sign, verify };
