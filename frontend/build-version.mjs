import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export function appVersion() {
  const configured = process.env.APP_VERSION?.trim();
  if (configured) return configured.replace(/-\d+-g[0-9a-f]+(?:-dirty)?$/i, '').replace(/-dirty$/, '');
  try {
    return execFileSync('git', ['describe', '--tags', '--abbrev=0'], {
      cwd: fileURLToPath(new URL('..', import.meta.url)), encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'],
    }).trim();
  } catch {
    return 'Unversioned build';
  }
}
