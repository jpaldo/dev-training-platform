const Module = require('module');
const path = require('path');

const allowedPublicHosts = (process.env.FRONTEND_ALLOWED_HOSTS || 'localhost,127.0.0.1')
  .split(',')
  .map(host => host.trim())
  .filter(Boolean);

const originalLoad = Module._load;
const webpackDevServerConfigPath = require.resolve('react-scripts/config/webpackDevServer.config.js');

Module._load = function load(request, parent, isMain) {
  const loaded = originalLoad.apply(this, arguments);
  let resolvedRequest;
  try {
    resolvedRequest = Module._resolveFilename(request, parent, isMain);
  } catch (_) {
    resolvedRequest = request;
  }

  if (path.resolve(resolvedRequest) !== path.resolve(webpackDevServerConfigPath)) {
    return loaded;
  }

  return function configuredDevServer(proxy, allowedHost) {
    const configuration = loaded(proxy, allowedHost);

    if (configuration.allowedHosts !== 'all') {
      const hosts = Array.isArray(configuration.allowedHosts)
        ? configuration.allowedHosts.filter(Boolean)
        : [];

      for (const host of allowedPublicHosts) {
        if (!hosts.includes(host)) hosts.push(host);
      }

      configuration.allowedHosts = hosts;
    }

    return configuration;
  };
};

require('react-scripts/scripts/start');
