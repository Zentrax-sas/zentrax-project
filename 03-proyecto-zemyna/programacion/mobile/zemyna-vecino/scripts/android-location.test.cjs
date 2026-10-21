const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const root = join(__dirname, '..');

test('GPS WebView declara ubicación aproximada y precisa sin background', () => {
  const manifest = readFileSync(join(root, 'android/app/src/main/AndroidManifest.xml'), 'utf8');
  for (const permission of ['ACCESS_COARSE_LOCATION', 'ACCESS_FINE_LOCATION']) {
    assert.equal(manifest.match(new RegExp(`android.permission.${permission}`, 'g'))?.length, 1);
  }
  assert.ok(!manifest.includes('ACCESS_BACKGROUND_LOCATION'));
  const bridge = readFileSync(join(root, 'node_modules/@capacitor/android/capacitor/src/main/java/com/getcapacitor/BridgeWebChromeClient.java'), 'utf8');
  assert.match(bridge, /onGeolocationPermissionsShowPrompt/);
  assert.match(bridge, /permissionLauncher.launch\(geoPermissions\)/);
});
