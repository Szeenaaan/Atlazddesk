const [major, minor, patch] = process.versions.node.split('.').map(Number);
if (major !== 24 || minor < 22 || (minor === 22 && patch < 1)) {
  console.error(`SupportDesk requires patched Node.js 24.22.1 or newer in the 24.x LTS line. Current: ${process.versions.node}.`);
  process.exitCode = 1;
} else {
  console.log(`Verified patched Node.js ${process.versions.node}.`);
}
