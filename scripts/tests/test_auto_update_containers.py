#!/usr/bin/env python3
"""Exercise updater decisions with fake Docker/curl; never access real Docker or data."""
import json, os, pathlib, subprocess, tempfile, unittest
ROOT = pathlib.Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'scripts/auto-update-containers.sh'
FAKE_DOCKER = r'''#!/usr/bin/env python3
import os,sys,json,pathlib
args=sys.argv[1:]; root=pathlib.Path(os.environ['FIXTURE']); case=os.environ['CASE']
with (root/'calls').open('a') as f:f.write(json.dumps(args)+'\n')
old='sha256:'+'1'*64; new='sha256:'+'2'*64; web='sha256:'+'3'*64
if args[0]=='pull':sys.exit(0)
if args[0]=='compose':
 if 'config' in args:sys.exit(0)
 if 'ps' in args:print('container-'+args[-1]);sys.exit(0)
 if 'up' in args:
  overrides=[args[i+1] for i,x in enumerate(args) if x=='-f']
  rollback='rollback.' in overrides[-1]
  if case=='up-fails' and not rollback:sys.exit(1)
  sys.exit(0)
if args[0]=='inspect':print(old);sys.exit(0)
if args[:2]==['image','inspect']:
 fmt=args[args.index('--format')+1]; image=args[-1]
 if fmt=='{{.Id}}':print(web if '/web:' in image else new);sys.exit(0)
 if 'org.supportdesk.application' in fmt:print('e'*64 if case=='application-changes' and image!=old else 'd'*64)
 elif 'org.supportdesk.schema' in fmt:print('b'*64 if case=='schema-changes' and image!=old else 'a'*64)
 elif 'org.supportdesk.validation' in fmt:print('failed' if case=='unvalidated' else 'passed')
 elif 'org.opencontainers.image.revision' in fmt:print('c'*40)
 elif 'org.supportdesk.release' in fmt:print(('99-1' if case=='older-release' else '97-1') if image==old else ('99-1' if case=='pair-mismatch' and image==web else '98-1'))
 else:sys.exit(2)
 sys.exit(0)
sys.exit(2)
'''
class UpdateTests(unittest.TestCase):
 def run_case(self, case, mode='--apply'):
  with tempfile.TemporaryDirectory() as temp:
   root=pathlib.Path(temp); bindir=root/'bin'; bindir.mkdir()
   for name in ['base.yaml','images.yaml','compose.env']:(root/name).write_text('fixture')
   (bindir/'docker').write_text(FAKE_DOCKER);(bindir/'docker').chmod(0o755)
   (bindir/'curl').write_text('#!/bin/sh\nif [ "$CASE" = unhealthy ]; then exit 22; fi\nprintf \'{"status":"ready"}\'\n');(bindir/'curl').chmod(0o755)
   env={**os.environ,'PATH':str(bindir)+':'+os.environ['PATH'],'FIXTURE':temp,'CASE':case,
    'SUPPORTDESK_PROJECT_DIR':temp,'SUPPORTDESK_COMPOSE_FILE':str(root/'base.yaml'),
    'SUPPORTDESK_COMPOSE_ENV_FILE':str(root/'compose.env'),'SUPPORTDESK_IMAGE_OVERRIDE':str(root/'images.yaml'),
    'SUPPORTDESK_APP_IMAGE':'ghcr.io/owner/repo/app:tested','SUPPORTDESK_WEB_IMAGE':'ghcr.io/owner/repo/web:tested',
    'SUPPORTDESK_ORIGIN':'https://support.example.test','SUPPORTDESK_UPDATE_STATE_DIR':str(root/'state')}
   result=subprocess.run(['bash',str(SCRIPT),mode],env=env,capture_output=True,text=True)
   calls=[json.loads(line) for line in (root/'calls').read_text().splitlines()]
   return result,calls
 def test_dry_run_never_pulls_or_recreates(self):
  result,calls=self.run_case('clean','--check');self.assertEqual(result.returncode,0,result.stderr)
  self.assertEqual(len(calls),1);self.assertIn('config',calls[0])
 def test_unvalidated_mismatched_or_schema_changing_images_never_deploy(self):
  for case in ['unvalidated','pair-mismatch','schema-changes','application-changes','older-release']:
   with self.subTest(case=case):
    result,calls=self.run_case(case);self.assertNotEqual(result.returncode,0)
    self.assertFalse(any('up' in call for call in calls))
 def test_success_uses_immutable_ids_and_only_four_application_services(self):
  result,calls=self.run_case('clean');self.assertEqual(result.returncode,0,result.stderr)
  deploys=[call for call in calls if 'up' in call];self.assertEqual(len(deploys),1)
  self.assertEqual(deploys[0][-4:],['app','worker','scheduler','web'])
  self.assertIn('--no-deps',deploys[0]);self.assertIn('--no-build',deploys[0])
  self.assertFalse(any('prune' in call or 'down' in call or 'exec' in call for call in calls))
 def test_failed_deploy_and_readiness_trigger_rollback(self):
  for case in ['up-fails','unhealthy']:
   with self.subTest(case=case):
    result,calls=self.run_case(case);self.assertNotEqual(result.returncode,0)
    deploys=[call for call in calls if 'up' in call];self.assertEqual(len(deploys),2)
    self.assertTrue(any('rollback.' in arg for arg in deploys[1]))
if __name__=='__main__':unittest.main()
