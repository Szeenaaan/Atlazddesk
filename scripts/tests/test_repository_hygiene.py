"""Read-only repository checks: never open credentials or modify the Git index."""
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]

class RepositoryHygieneTests(unittest.TestCase):
    def test_local_secrets_archives_and_builds_are_ignored_but_examples_remain_available(self):
        cases = {
            'notes/local/archive/old-guide.md': True,
            'notes/local/release-evidence/report.txt': True,
            'installation/staging/access.json': True,
            'installation/staging/redis.env': True,
            'backend-overlay/.env': True,
            'frontend/.env.local': True,
            'app.env': True,
            'access.json': True,
            'frontend/dist/assets/app.js': True,
            'frontend/node_modules/package/index.js': True,
            'backend-overlay/vendor/package/library.php': True,
            'backend-overlay/storage/logs/laravel.log': True,
            'backend-overlay/bootstrap/cache/config.php': True,
            'frontend/tsconfig.tsbuildinfo': True,
            'notes/README.md': False,
            'docs/README.md': False,
            'docs/deployment/automatic-updates.md': False,
            'backend-overlay/.env.example': False,
            'backend-overlay/.env.production.example': False,
            'frontend/.env.example': False,
            '.env.example': False,
            'frontend/package-lock.json': False,
            'backend-overlay/composer.lock': False,
            'scripts/tests/test_auto_update_containers.py': False,
            'infra/systemd/supportdesk-updates.env.example': False,
        }
        for path, expected in cases.items():
            with self.subTest(path=path):
                result = subprocess.run(['git', 'check-ignore', '--no-index', '-q', path], cwd=ROOT)
                self.assertIn(result.returncode, [0, 1])
                self.assertEqual(result.returncode == 0, expected)

    def test_private_runtime_and_generated_files_are_not_tracked(self):
        paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
        for name in filter(None, paths):
            with self.subTest(path=name):
                self.assertFalse(name.startswith(('notes/local/', 'installation/', 'release-evidence/', 'frontend/dist/', 'frontend/node_modules/', 'backend-overlay/vendor/')))
                filename = Path(name).name
                self.assertFalse(filename in {'access.json', 'app.env', 'redis.env', 'database.env', 'compose.env', 'updates.env'})
                self.assertFalse(filename.endswith(('.pem', '.key', '.tsbuildinfo')))
                self.assertFalse('.backup' in filename or '.bak-' in filename)
                self.assertFalse(filename.startswith('.env') and not filename.endswith('.example'))

    def test_current_documentation_links_resolve(self):
        docs = [ROOT / name for name in ['README.md', 'AGENTS.md', 'ROLES.md', 'TESTING.md', 'notes/README.md']]
        docs += list((ROOT / 'docs').rglob('*.md'))
        for source in docs:
            text = source.read_text()
            for target in re.findall(r'\]\(([^\s)]+)\)', text):
                if re.match(r'^[a-zA-Z][\w+.-]*:', target) or target.startswith('#'):
                    continue
                target = target.split('#')[0]
                with self.subTest(source=str(source.relative_to(ROOT)), target=target):
                    self.assertTrue((source.parent / target).exists())

if __name__ == '__main__':
    unittest.main()
