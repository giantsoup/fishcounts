import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { platform, arch } = process;
let suffix;

if (platform === 'linux' && ['x64', 'arm64'].includes(arch)) {
    const libc = process.report.getReport().header.glibcVersionRuntime ? 'gnu' : 'musl';
    suffix = `linux-${arch}-${libc}`;
} else if (platform === 'darwin' && ['x64', 'arm64'].includes(arch)) {
    suffix = `darwin-${arch}`;
} else if (platform === 'win32' && ['x64', 'arm64'].includes(arch)) {
    suffix = `win32-${arch}-msvc`;
} else {
    throw new Error(`Unsupported asset-build platform: ${platform}/${arch}`);
}

for (const name of [
    `@rolldown/binding-${suffix}`,
    `@tailwindcss/oxide-${suffix}`,
    `lightningcss-${suffix}`,
]) {
    require(name);
    console.log(`Loaded ${name}`);
}
