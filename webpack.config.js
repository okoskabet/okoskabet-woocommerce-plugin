const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

const entry = {};
[{ path: 'plugin-admin' }, { path: 'plugin-public', ext: 'ts' }, { path: 'plugin-settings' }].forEach(
  (script) =>
  (entry[script.path] = path.resolve(
    process.cwd(),
    `assets/src/${script.path}.${script.ext || 'js'}`
  ))
);

module.exports = {
  ...defaultConfig,
  entry,
  output: {
    path: path.join(__dirname, './assets/build'),
  },
  externals: {
    react: 'React',
    'react-dom': 'ReactDOM',
  },
};
