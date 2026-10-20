const http = require('http');

const port = Number(process.argv[process.argv.indexOf('--port') + 1] || 80);

function targetFor(pathname) {
  return { port: 8080, path: pathname };
}

const server = http.createServer((request, response) => {
  const url = new URL(request.url || '/', 'http://localhost');
  const target = targetFor(url.pathname);
  // Preserve the host and scheme used by a local browser through this optional
  // compatibility proxy.
  const originalHost = request.headers.host || `127.0.0.1:${port}`;
  const forwardedProto = request.headers['x-forwarded-proto'];
  const originalProto = forwardedProto === 'https' || forwardedProto === 'http'
    ? forwardedProto
    : 'http';
  const remoteAddress = (request.socket.remoteAddress || '127.0.0.1').replace(/^::ffff:/, '');
  const headers = {
    ...request.headers,
    host: originalHost,
    'x-forwarded-host': originalHost,
    'x-forwarded-proto': originalProto,
    'x-forwarded-for': request.headers['x-forwarded-for'] || remoteAddress,
    forwarded: `for=${remoteAddress};host=${originalHost};proto=${originalProto}`,
  };
  const proxyRequest = http.request({
    hostname: '127.0.0.1',
    port: target.port,
    method: request.method,
    path: `${target.path}${url.search}`,
    headers,
  }, (proxyResponse) => {
    response.writeHead(proxyResponse.statusCode || 502, proxyResponse.headers);
    proxyResponse.pipe(response);
  });

  proxyRequest.on('error', (error) => {
    if (!response.headersSent) {
      response.writeHead(502, { 'content-type': 'text/plain; charset=utf-8' });
    }
    response.end(`Development proxy target is unavailable: ${error.message}`);
  });

  request.pipe(proxyRequest);
});

server.listen(port, '127.0.0.1', () => {
  console.log(`Development reverse proxy listening on http://127.0.0.1:${port}`);
});

server.on('error', (error) => {
  console.error(`Development reverse proxy failed: ${error.message}`);
  process.exitCode = 1;
});
