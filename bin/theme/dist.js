const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const themeName = process.argv[2] || 'freckle-theme';
const srcDir = path.join(process.cwd(), 'themes', themeName);
const distRoot = path.join(process.cwd(), 'dist');
const distDir = path.join(distRoot, themeName);
const zipPath = path.join(distRoot, `${themeName}.zip`);

const EXCLUDES = new Set(['.git', '.gitignore', '.idea', 'phpcs.xml', 'node_modules', '.DS_Store']);

if (!fs.existsSync(srcDir)) {
  console.error(`Theme not found: ${srcDir}`);
  process.exit(1);
}

fs.rmSync(distDir, { recursive: true, force: true });
fs.rmSync(zipPath, { force: true });
fs.mkdirSync(distDir, { recursive: true });

fs.cpSync(srcDir, distDir, {
  recursive: true,
  filter: (source) => !EXCLUDES.has(path.basename(source)),
});

console.log(`Copied ${themeName} -> dist/${themeName}`);

if (process.platform === 'win32') {
  execSync(
    `powershell -NoProfile -Command "Compress-Archive -Path '${distDir}\\*' -DestinationPath '${zipPath}' -Force"`,
    { stdio: 'inherit' }
  );
} else {
  execSync(`cd "${distRoot}" && zip -rq "${themeName}.zip" "${themeName}"`, { stdio: 'inherit' });
}

console.log(`Zipped -> dist/${themeName}.zip`);
