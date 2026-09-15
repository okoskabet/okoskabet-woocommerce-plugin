const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const path = require('path');
const sveltePreprocess = require('svelte-preprocess');

function getPath(...pathParts) {
  return path.resolve(__dirname, ...pathParts);
}

const entry = {};
[{ path: 'plugin-admin' }, { path: 'plugin-public', ext: 'ts' }, { path: 'plugin-settings' }, { path: 'checkout-helpers' }].forEach(
  (script) =>
  (entry[script.path] = path.resolve(
    process.cwd(),
    `assets/src/${script.path}.${script.ext || 'js'}`
  ))
);

// The checkout's own files get their content in their name. Page caches on
// merchants' sites (Hummingbird, for one) strip the ?ver= WordPress adds, so a
// fixed name kept serving customers the previous release's script for as long
// as their browser cared to keep it. PHP finds the current name in the build
// folder (oko_build_asset_url).
const HASHED = ['plugin-public', 'checkout-helpers'];
const hashedName = (ext) => (pathData) =>
  HASHED.includes(pathData.chunk && pathData.chunk.name)
    ? `[name].[contenthash:8].${ext}`
    : `[name].${ext}`;

const plugins = defaultConfig.plugins.map((plugin) => {
  if (plugin.constructor && plugin.constructor.name === 'MiniCssExtractPlugin') {
    plugin.options.filename = hashedName('css');
  }
  return plugin;
});

module.exports = {
  ...defaultConfig,
  plugins,
  entry,
  resolve: {
    conditionNames: ['require', 'node', 'svelte'],
    alias: {
      svelte: getPath('node_modules', 'svelte/src/runtime'),
      src: getPath('assets/src')
    },
    extensions: ['.ts', '.mjs', '.js', '.css', '.svelte'],
    mainFields: ['svelte', 'browser', 'module', 'main']
  },
  output: {
    path: path.join(__dirname, './assets/build'),
    filename: hashedName('js'),
  },
  module: {
    rules: [
      ...defaultConfig.module.rules,
      {
        test: /\.svelte$/,
        use: {
          loader: 'svelte-loader',
          options: {
            emitCss: true,
            preprocess: sveltePreprocess()
          }
        }
      },
    ]
  },
  externals: {
    react: 'React',
    'react-dom': 'ReactDOM',
  },
};
