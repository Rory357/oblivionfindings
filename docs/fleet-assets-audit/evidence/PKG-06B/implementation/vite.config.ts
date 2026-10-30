import base from '../../../../../vite.config';
import type { PluginOption } from 'vite';
const keep = (plugin: PluginOption): PluginOption => Array.isArray(plugin) ? plugin.map(keep) : plugin && typeof plugin === 'object' && 'name' in plugin && plugin.name === '@laravel/vite-plugin-wayfinder' ? false : plugin;
// Routes are generated explicitly from this worktree under the isolated test configuration.
export default { ...base, plugins: base.plugins?.map(keep), server: { https: false as never, host: '127.0.0.1', port: 8910, strictPort: true, cors: true, origin: 'http://127.0.0.1:8910' } };
