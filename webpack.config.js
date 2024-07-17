const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const path = require('path');
const sveltePreprocess = require('svelte-preprocess');

function getPath(...pathParts) {
  return path.resolve(__dirname, ...pathParts);
}

const entry = {};
[{ path: 'plugin-admin' }, { path: 'plugin-public', ext: 'ts' }, { path: 'plugin-settings' }, { path: 'delivery_options', ext: 'svelte' }].forEach(
  (script) =>
  (entry[script.path] = path.resolve(
    process.cwd(),
    `assets/src/${script.path}.${script.ext || 'js'}`
  ))
);

module.exports = {
  ...defaultConfig,
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
