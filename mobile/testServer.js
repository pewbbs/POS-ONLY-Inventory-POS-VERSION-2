const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '10.0.2.2'];

function validateTestLanHost(value) {
  if (value === undefined || value === null || value === '') { return null; }
  if (typeof value !== 'string' || !/^(?:0|[1-9]\d{0,2})(?:\.(?:0|[1-9]\d{0,2})){3}$/.test(value)) {
    throw new Error('Use a canonical RFC1918 IPv4 address for the isolated LAN host.');
  }
  const parts = value.split('.').map(Number);
  if (parts.some(part => part > 255)
    || !(parts[0] === 10 || (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31) || (parts[0] === 192 && parts[1] === 168))) {
    throw new Error('Use a canonical RFC1918 IPv4 address for the isolated LAN host.');
  }
  return value;
}

function validateIsolatedApiUrl(value, allowedLanHost) {
  const lanHost = validateTestLanHost(allowedLanHost);
  let target;
  try { target = new URL(value); } catch {
    throw new Error('Use a credential-free isolated API URL on port 8092 with /api/v1.');
  }
  if (typeof value !== 'string' || target.username || target.password || target.search || target.hash
    || !['http:', 'https:'].includes(target.protocol) || target.port !== '8092'
    || !['/api/v1', '/api/v1/'].includes(target.pathname)
    || ![`${target.origin}/api/v1`, `${target.origin}/api/v1/`].includes(value)) {
    throw new Error('Use a credential-free isolated API URL on port 8092 with /api/v1.');
  }
  if (!LOOPBACK_HOSTS.includes(target.hostname) && target.hostname !== lanHost) {
    throw new Error('Isolated builds may only use the local test backend and their explicitly allowed LAN host.');
  }
  return `${target.origin}/api/v1`;
}

function validateIsolatedBrowserApiUrl(value, origin) {
  let target;
  try { target = new URL(value); } catch {
    throw new Error('The isolated browser preview must use its own localhost API proxy.');
  }
  if (!['http:', 'https:'].includes(target.protocol) || !['127.0.0.1', 'localhost'].includes(target.hostname)
    || target.username || target.password || target.search || target.hash || target.pathname !== '/api/v1'
    || target.origin !== origin || value !== `${origin}/api/v1`) {
    throw new Error('The isolated browser preview must use its own localhost API proxy.');
  }
  return value;
}

module.exports = { validateTestLanHost, validateIsolatedApiUrl, validateIsolatedBrowserApiUrl };
