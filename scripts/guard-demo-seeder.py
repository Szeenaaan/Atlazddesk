#!/usr/bin/env python3
"""Guarded, narrow production seeder correction. No database interaction."""
import argparse
from pathlib import Path
from datetime import datetime
import shutil
import sys

parser = argparse.ArgumentParser(description='Preview or apply safe demo seeder ordering fix')
parser.add_argument('--root', required=True, help='AMC Platform project root')
parser.add_argument('--apply', action='store_true', help='Apply only if the source exactly matches the known v10.2 form')
args = parser.parse_args()
file = Path(args.root).expanduser().resolve() / 'backend-overlay/database/seeders/AmcPlatformSeeder.php'
if not file.is_file():
    sys.exit(f'BLOCKED: Cannot locate {file}')
source = file.read_text()
fixture = "        $customer = Customer::updateOrCreate(['customer_code'=>'AMC-0001'], ["
old = "        // Local fixtures only. NEVER create predictable demo passwords in production.\n        if (! app()->environment(['local', 'testing']) || ! env('AMC_SEED_DEMO_USERS', false)) return;\n\n"
new = "        // Business fixtures and demo users are strictly opt-in and never seeded in production.\n        if (! app()->environment(['local', 'testing']) || ! env('AMC_SEED_DEMO_USERS', false)) return;\n\n"
if source.count(fixture) != 1:
    sys.exit('BLOCKED: Expected demo customer fixture not uniquely present; review manually')
if new in source and source.index(new) < source.index(fixture) and old not in source:
    print('PASS: Seeder already guarded before business fixture creation. No change needed.')
    sys.exit(0)
if source.count(old) != 1 or source.index(old) < source.index(fixture):
    sys.exit('BLOCKED: Seeder differs from known source; refusing to overwrite. Share the actual seeder for a manual review.')
updated = source.replace(old, '        // The demo-user credentials below share the opt-in guard above.\n\n', 1)
updated = updated.replace(fixture, new + fixture, 1)
assert updated.index(new) < updated.index(fixture)
if not args.apply:
    print('DRY RUN PASS: Will move the existing demo-only guard before customer, contract, asset and user fixtures.')
    print('No source or database records have been changed. Re-run with --apply after review.')
else:
    stamp = datetime.now().strftime('%Y%m%d-%H%M%S')
    backup = file.with_name(file.name + f'.backup-{stamp}')
    shutil.copy2(file, backup)
    file.write_text(updated)
    print(f'APPLIED: {file}')
    print(f'Original preserved: {backup}')
    print('Next: php -l database/seeders/AmcPlatformSeeder.php, then run tests ONLY on *_test DB.')
