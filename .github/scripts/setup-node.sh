#!/usr/bin/env bash
set -euo pipefail

node_version="$(tr -d '\n\r' < .nvmrc)"
test "$(node --version)" = "v${node_version}"
npm_version="$(node --input-type=module -e '
import fs from "node:fs";
const { packageManager } = JSON.parse(fs.readFileSync("package.json", "utf8"));
if (!/^npm@\d+\.\d+\.\d+$/.test(packageManager)) {
    throw new Error("packageManager must pin an exact npm version");
}
console.log(packageManager.slice(4));
')"
npm install --global "npm@${npm_version}" --ignore-scripts
test "$(npm --version)" = "${npm_version}"
test "$(npm config get ignore-scripts)" = true
test "$(npm config get audit)" = true
node --version
npm --version
