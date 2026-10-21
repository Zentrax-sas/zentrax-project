// Run once on the demo PC; private keys stay in ignored tmp/, never in the APK.
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const dir = path.join(root, 'tmp/android-https');
const openssl = process.env.OPENSSL_EXE || 'C:/xampp/apache/bin/openssl.exe';
const raw = path.join(root, 'android/app/src/debug/res/raw');
fs.mkdirSync(dir, { recursive: true });
fs.mkdirSync(raw, { recursive: true });
function run(args) {
  execFileSync(openssl, args, { cwd: dir, windowsHide: true, stdio: 'pipe' });
}
if (!fs.existsSync(path.join(dir, 'server.key'))) {
  // Refuse to overwrite existing trust material after an interrupted preparation.
  if (fs.existsSync(path.join(dir, 'ca.key'))) throw new Error('Existing CA: inspect tmp/android-https before regenerating.');
  fs.writeFileSync(path.join(dir, 'openssl.cnf'), `[req]
distinguished_name=dn
[dn]
[ca]
basicConstraints=critical,CA:TRUE,pathlen:0
keyUsage=critical,keyCertSign,cRLSign
subjectKeyIdentifier=hash
[server]
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=IP:172.28.57.181
`);
  run(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '365',
    '-config', 'openssl.cnf', '-extensions', 'ca', '-subj', '/CN=Zemyna local demo CA',
    '-keyout', 'ca.key', '-out', 'ca.crt']);
  run(['req', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-config', 'openssl.cnf',
    '-subj', '/CN=172.28.57.181', '-keyout', 'server.key', '-out', 'server.csr']);
  run(['x509', '-req', '-in', 'server.csr', '-CA', 'ca.crt', '-CAkey', 'ca.key',
    '-CAcreateserial', '-days', '90', '-sha256', '-extfile', 'openssl.cnf',
    '-extensions', 'server', '-out', 'server.crt']);
}
run(['verify', '-CAfile', 'ca.crt', '-verify_ip', '172.28.57.181', 'server.crt']);
fs.copyFileSync(path.join(dir, 'ca.crt'), path.join(raw, 'zemyna_demo_ca.crt'));
console.log('TLS verified. Only the public CA was copied to Android debug resources.');
