import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadPrivateDocument } from '../src/utils/privateDocuments.ts';

test('private document bytes pass through unchanged', async () => {
  const pdf = new Blob(['%PDF-1.4\n'], { type: 'application/pdf' });
  assert.equal(await loadPrivateDocument(async () => ({ data: pdf })), pdf);
});

test('JSON error blobs produce an inline message instead of a downloaded error page', async () => {
  await assert.rejects(loadPrivateDocument(async () => {
    throw { response: { status: 404, data: new Blob([JSON.stringify({ message: 'The document was not found.' })]) } };
  }), /The document was not found\./);
});

test('expired sessions explain how to recover', async () => {
  await assert.rejects(loadPrivateDocument(async () => {
    throw { response: { status: 401, data: new Blob(['{}']) } };
  }), /Sign in again/);
});

test('unexpected server output is not exposed', async () => {
  await assert.rejects(loadPrivateDocument(async () => {
    throw { response: { status: 500, data: new Blob(['<html>private server trace</html>']) } };
  }), /^Error: Could not open the document\. Please try again\.$/);
});
