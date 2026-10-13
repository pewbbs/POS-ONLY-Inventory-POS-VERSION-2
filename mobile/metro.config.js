const http = require('node:http');
const { getDefaultConfig } = require('expo/metro-config');

const config = getDefaultConfig(__dirname);
if (process.env.POSONLY_BUILD_ENV === 'isolated') {
  config.server.enhanceMiddleware = middleware => (request, response, next) => {
    if (!request.url?.startsWith('/api/v1/')) { return middleware(request, response, next); }
    const target = new URL(process.env.POSONLY_TEST_API_URL || 'http://127.0.0.1:8092/api/v1');
    if (!['127.0.0.1', 'localhost'].includes(target.hostname) || target.port !== '8092') { response.writeHead(503); response.end(); return; }
    const proxy = http.request({ hostname: target.hostname, port: target.port, path: request.url, method: request.method, headers: { ...request.headers, host: target.host } }, upstream => {
      response.writeHead(upstream.statusCode || 502, upstream.headers); upstream.pipe(response);
    });
    proxy.on('error', () => { response.writeHead(502, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); response.end(JSON.stringify({ message: 'The isolated backend is disconnected. Original requests are retained.' })); });
    request.pipe(proxy);
  };
}
module.exports = config;
