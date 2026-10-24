#!/usr/bin/env python3
"""Non-destructive AMC Platform v10.x delivery audit. Never prints credentials.

Default behavior: code/config inspections and read-only Artisan/dependency commands.
Tests may reset *only* the explicit --test-db database and run only if --run-tests is given.
"""
import argparse
from datetime import datetime, timezone
import os
from pathlib import Path
import re
import subprocess
import sys

parser = argparse.ArgumentParser(description='AMC Platform: non-destructive release evidence')
parser.add_argument('--root', required=True, help='Path to AMC Platform project root')
parser.add_argument('--run-tests', action='store_true', help='Run destructive-to-test-db Laravel tests')
parser.add_argument('--test-db', help='Explicit disposable database ending _test (required for --run-tests)')
parser.add_argument('--run-build', action='store_true', help='Run npm run build')
args = parser.parse_args()
root = Path(args.root).expanduser().resolve()
backend = root / 'backend-overlay'
frontend = root / 'frontend'
if not (backend / 'artisan').is_file() or not (frontend / 'package.json').is_file():
    sys.exit('BLOCKED: This does not look like a full AMC Platform project with Laravel artisan and React package.json.')
if args.run_tests and (not args.test_db or not re.fullmatch(r'[A-Za-z0-9_]+_test', args.test_db)):
    sys.exit('BLOCKED: --run-tests needs --test-db=<disposable_database_ending_in__test>. Never use your live business database.')

stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
private_notes = root / 'notes' / 'local'
private_notes.mkdir(parents=True, exist_ok=True, mode=0o700)
private_notes.chmod(0o700)
output = private_notes / 'release-evidence' / stamp
output.mkdir(parents=True, exist_ok=True, mode=0o700)
results = []

def record(label, state, explanation):
    results.append((label, state, explanation))
    print(f'[{state}] {label}: {explanation}')

def run(label, cmd, cwd, expected=None, timeout=200, env=None):
    destination = output / (re.sub('[^a-z0-9-]+', '-', label.lower()).strip('-') + '.txt')
    try:
        p = subprocess.run(cmd, cwd=cwd, env=env, capture_output=True, text=True, timeout=timeout, check=False)
        body = (p.stdout or '') + '\n' + (p.stderr or '')
        destination.write_text(body, encoding='utf-8')
        valid = p.returncode == 0 and (expected is None or expected(body))
        record(label, 'PASS' if valid else 'FAIL', f'exit={p.returncode}; evidence={destination.relative_to(root)}')
        return valid, body
    except (OSError, subprocess.TimeoutExpired) as exc:
        destination.write_text(f'Could not complete: {type(exc).__name__}', encoding='utf-8')
        record(label, 'REVIEW', f'Could not finish automatically; evidence={destination.relative_to(root)}')
        return False, ''

def env_map(file):
    out = {}
    if not file.is_file():
        return out
    for line in file.read_text(errors='replace').splitlines():
        line=line.strip()
        if not line or line.startswith('#') or '=' not in line: continue
        k,v=line.split('=',1)
        out[k.strip()]=v.strip().strip('"').strip("'")
    return out

print(f'AMC Platform release audit | {stamp} | evidence: {output}')
run('Composer vulnerability advisories', ['composer','audit','--no-interaction'], backend, timeout=120)
run('NPM production dependency vulnerabilities', ['npm','audit','--omit=dev'], frontend, timeout=120)
run('Migration status', ['php','artisan','migrate:status'], backend, lambda s: 'Pending' not in s and 'Ran' in s, timeout=60)
run('Scheduler registration', ['php','artisan','schedule:list'], backend, lambda s: all(x in s for x in ('CheckSlaDeadlines','CheckAmcExpiry','GenerateMaintenanceVisits','amc.scheduler.heartbeat','amc.telemetry.prune')), timeout=60)
run('API route registration', ['php','artisan','route:list','--path=api/v2'], backend, lambda s: 'api/v2' in s, timeout=60)

seeder = backend / 'database/seeders/AmcPlatformSeeder.php'
if seeder.exists():
    source = seeder.read_text(errors='replace')
    guard = source.find("if (! app()->environment(['local', 'testing']) || ! env('AMC_SEED_DEMO_USERS', false)) return;")
    demo = source.find("Customer::updateOrCreate(['customer_code'=>'AMC-0001']")
    record('Safe default seeding', 'PASS' if guard >= 0 and demo >= 0 and guard < demo else 'FAIL',
           'Demo-only guard appears before first fixture' if guard >= 0 and demo >= 0 and guard < demo else 'Review AmcPlatformSeeder.php: demo data may be seeded before guard')
else:
    record('Safe default seeding', 'REVIEW', 'Seeder source not found')

client = frontend / 'src/api/client.ts'
if client.is_file():
    code=client.read_text(errors='replace')
    state='PASS' if 'import.meta.env.DEV && import.meta.env.VITE_DEMO_MODE' in code else 'REVIEW'
    record('Production demo mode guard', state, 'Source guard inspected; inspect all UI screens manually too')
else:
    record('Production demo mode guard', 'REVIEW', 'No API client source found')

config = env_map(backend/'.env')
if not config:
    record('Runtime environment controls', 'REVIEW', 'No readable .env; check VPS configuration manually')
else:
    is_prod = config.get('APP_ENV') == 'production'
    checks = {
      'APP_DEBUG disabled': config.get('APP_DEBUG','').lower() in ('false','0'),
      'APP_KEY exists': bool(config.get('APP_KEY')),
      'secure cookies': config.get('SESSION_SECURE_COOKIE','').lower() in ('true','1'),
      'non-sync background queue': config.get('QUEUE_CONNECTION','') in ('redis','database','sqs'),
      'persistent cache': config.get('CACHE_STORE','') in ('redis','database'),
      'persistent sessions': config.get('SESSION_DRIVER','') in ('redis','database'),
    }
    if not is_prod:
        record('Runtime environment controls', 'REVIEW', 'Current .env is not a production VPS environment; do not use it as a production readiness result')
    else:
        failures=[k for k,v in checks.items() if not v]
        record('Runtime environment controls', 'PASS' if not failures else 'FAIL', 'Production safety keys present' if not failures else 'Missing/unsafe flags: '+', '.join(failures))

if args.run_tests:
    custom_env=dict(os.environ)
    custom_env.update({'APP_ENV':'testing','DB_CONNECTION':'mysql','DB_DATABASE':args.test_db})
    run('Full Laravel test suite', ['php','artisan','test'], backend, lambda s: 'Tests:' in s and 'FAILED' not in s, timeout=600, env=custom_env)
else:
    record('Full Laravel test suite', 'REVIEW', 'Not re-run; user previously reported 61 tests and 302 assertions passed')
if args.run_build:
    run('React production build', ['npm','run','build'], frontend, lambda s: 'built in' in s, timeout=400)
else:
    record('React production build', 'REVIEW', 'Not re-run; user previously reported successful v10.2 build')

manual = [
('1. Clean production data','Inspect a fresh staging database after db:seed; no demo users/customers/contracts/assets or default passwords'),
('2. Secure asset credentials','Confirm asset secrets are not plaintext; vault/references, restricted retrieval and audit tested'),
('3. Customer and role isolation','Browser and direct API tests using all roles, two clients and unrelated technicians'),
('4. MFA and session security','Real TOTP enrollment, verification, recovery, expired sessions, disabled account tested'),
('5. Backups and recovery','Restore full DB, encrypted app key and uploads on separate machine; record elapsed time'),
('6. VPS/HTTPS hardening','On actual VPS: TLS, APP_DEBUG=false, PHP-FPM, file ownership, firewall, secure cookies'),
('7. Ticket end-to-end flow','Customer ticket -> Admin assignment -> notification -> technician report -> review and resolution'),
('8. Real email delivery','Default and client-specific provider tests, actual inbox delivery, OTP retry and failures'),
('9. Queue/scheduler runtime','System services running, heartbeat freshness, queued job processed, failure alert delivered'),
('10. Asset forms and history','Create asset with IP, hostname etc; assigned client correct; records and permissions verified'),
('11. Error screens and toasts','Success/error toasts, 401/403/404/422/429/500, offline/timeout and validation tested'),
('12. No fake or unfinished UI','No sample numbers, fake controls, demos, debugging elements or broken navigation'),
('13. Correct role interfaces','Super Admin technical only; Admin business; Customer Admin own company; User self; Technician assigned'),
('14. Responsive/accessibility','Test 360px, 768px, 1440px; keyboard, focus, contrast, modal overflow'),
('15. Super Admin diagnostics','Health, incidents, metrics, trace, profiling, rate limits and 403 cross-role'),
('16. Fresh staging install','Fresh VPS config and migrations, safe account bootstrapping, deployment upgrade/rollback'),
('17. Meeting rehearsal','Run entire presentation on frozen staging build without manual DB edits'),
]
for name, requirement in manual:
    record(name, 'MANUAL', requirement)

report = output / 'RELEASE-RESULTS.md'
lines=[f'# AMC Platform release evidence — {stamp}', '',
'Only explicit PASS results are verified. A PASS on route registration or scheduler listing does not prove runtime operation.', '',
'| Check | Status | Evidence or next action |', '|---|---|---|']
for label,state,detail in results:
    detail=detail.replace('|','/').replace('\n',' ')
    lines.append(f'| {label} | {state} | {detail} |')
lines.extend(['', '## Release decision',
'Production handover is BLOCKED until every critical safety and recovery item has evidence from the exact VPS build.',
'A controlled staging demo can be considered after a full role-based rehearsal and no visible critical defects.',
'No secrets or connection strings are intentionally included in this report. Check underlying tool logs before sharing.'])
report.write_text('\n'.join(lines)+'\n',encoding='utf-8')
print(f'\nReport: {report}')
print('Manual tests have NOT been performed by this script; do not treat MANUAL as PASS.')
