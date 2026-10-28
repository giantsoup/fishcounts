#!/usr/bin/env python3
"""Export every locked instance and check the approved migration target set."""
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

BASELINE = 'aed4c40924870e0bb20cae82d00c83838638bda4'


def snapshot(baseline=False):
    def read(name):
        return subprocess.check_output(['git', 'show', f'{BASELINE}:{name}']) if baseline else Path(name).read_bytes()

    files = {name: read(name) for name in ['composer.json', 'composer.lock', 'package.json', 'package-lock.json']}
    composer = json.loads(files['composer.lock'])
    npm = json.loads(files['package-lock.json'])
    packages = {}
    for group in ['packages', 'packages-dev']:
        for package in composer[group]:
            packages['composer:' + package['name']] = {
                key: package[key] for key in ['name', 'version', 'source', 'dist', 'require', 'conflict', 'provide', 'replace'] if key in package
            } | {'dev': group == 'packages-dev'}
    for location, package in npm['packages'].items():
        if location:
            packages['npm:' + location] = package
    return {
        'hashes': {name: hashlib.sha256(data).hexdigest() for name, data in files.items()},
        'counts': {'composer': len(composer['packages']) + len(composer['packages-dev']), 'npm': len(npm['packages']) - 1},
        'manifests': {name: json.loads(files[name]) for name in ['composer.json', 'package.json']},
        'npm_root': npm['packages'][''],
        'packages': packages,
    }


def package_name(identity):
    return identity.removeprefix('composer:') if identity.startswith('composer:') else identity.rsplit('node_modules/', 1)[1]


def reference(package):
    return package.get('integrity') or package.get('source', {}).get('reference') or package.get('dist', {}).get('reference') or package.get('resolved', '')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', default='docs/dependency-migration-inventory.json')
    parser.add_argument('--checker-directory', type=Path)
    args = parser.parse_args()
    before, after = snapshot(True), snapshot()
    rows = re.findall(r'^\| (D\d{2}) \| (Composer|npm) `([^`]+)` \| ([^|]+) \| ([^|]+) \|', Path('docs/dependency-upgrade-plan.md').read_text(), re.M)
    assert len(rows) == 69, f'Expected 69 candidate rows, got {len(rows)}'
    targets = {}
    candidates = []
    bundles = json.loads(Path('docs/dependency-migration-bundles.json').read_text())
    for label, data in [('before', before), ('after', after)]:
        locked = data['packages']['npm:node_modules/@tailwindcss/oxide-wasm32-wasi']
        assert locked['version'] == bundles[label]['version'] and locked['integrity'] == bundles[label]['integrity'], 'Bundled inventory must match the reviewed tarball'
    bundle_versions = {name: package['version'] for name, package in bundles['after']['packages'].items() if name != 'tslib'}
    blockers = {
        'ansi-styles': 'wrap-ansi 9.0.2 requires ^6.2.1; cliui requires wrap-ansi ^9.0.0.',
        'chalk': 'concurrently 10.0.6 pins chalk exactly 5.6.2.',
        'emoji-regex': 'string-width 7.2.0 requires emoji-regex ^10.3.0.',
        'magic-string': '@tailwindcss/node 4.3.3 requires ^0.30.21.',
        'nanoid': 'postcss 8.5.32 requires ^3.3.20; Vite retains PostCSS.',
        'string-width': 'cliui and yargs require ^7.2.0; wrap-ansi requires ^7.0.0.',
        'supports-color': 'concurrently 10.0.6 pins exactly 10.2.2.',
        'picomatch': 'vite-plugin-full-reload 1.2.0 requires ^2.3.1.',
        'wrap-ansi': 'cliui requires ^9.0.0.',
        'yargs': 'concurrently 10.0.6 pins exactly 18.0.0.',
        '@oxc-project/types': 'rolldown 1.2.15 pins exactly 0.155.0; it remains the latest stable parent inspected.',
    }
    for candidate_id, manager, instance, old, target in rows:
        manager = manager.lower()
        location = instance if instance.startswith('node_modules/') or manager == 'composer' else 'node_modules/' + instance
        identity = manager + ':' + location
        name = package_name(identity)
        targets.setdefault(manager + ':' + name, set()).add(target.strip().lstrip('v'))
        actual = after['packages'].get(identity)
        remaining = [{'identity': key, 'version': value['version']} for key, value in after['packages'].items() if package_name(key) == name]
        if candidate_id in ['D60', 'D61']:
            status, reason = 'superseded', 'Validated final major target replaces this alternative.'
        elif name in bundle_versions and manager == 'npm':
            status = 'blocked'
            reason = f'Oxide 4.3.3 bundles {name} {bundle_versions[name]}; the tarball owns this version. '
            reason += 'Oxide requires WASI Threads ^1.2.2 and bundled Core pins 1.2.2.' if name.endswith('wasi-threads') else 'A newer parent tarball must update its bundled copy.'
        elif actual and actual['version'].lstrip('v') == target.strip().lstrip('v'):
            status, reason = 'installed', 'Selected through the supported parent graph.'
        elif not actual:
            status, reason = 'removed', 'Obsolete Tailwind 3 instance removed with its parent.'
            if remaining:
                reason += ' Other supported instances are listed separately.'
        else:
            assert name in blockers, f'Unexplained candidate {candidate_id}: {name}'
            status, reason = 'blocked', blockers[name]
        candidates.append({'id': candidate_id, 'manager': manager, 'identity': identity, 'name': name, 'baseline': old.strip(), 'candidate': target.strip(), 'actual': actual.get('version') if actual else None, 'remaining_instances': remaining, 'bundled_version': bundle_versions.get(name) if manager == 'npm' else None, 'status': status, 'reason': reason})
    changes = []
    for identity in sorted(before['packages'].keys() | after['packages'].keys()):
        old, new = before['packages'].get(identity), after['packages'].get(identity)
        if old == new:
            continue
        change = 'added' if old is None else 'removed' if new is None else 'updated' if old['version'] != new['version'] else 'metadata'
        if old and new:
            old_version, new_version = old['version'].lstrip('v'), new['version'].lstrip('v')
            if old_version == new_version:
                assert reference(old) == reference(new), f'Unreviewed source change: {identity}'
                assert all(old.get(key) == new.get(key) for key in ['resolved', 'source', 'dist']), f'Unreviewed source URL change: {identity}'
            if old_version.split('.')[0] != new_version.split('.')[0] or old_version.startswith('0.') and old_version.split('.')[1] != new_version.split('.')[1]:
                assert new_version in targets.get(identity.split(':')[0] + ':' + package_name(identity), set()), f'Unplanned major or 0.x minor: {identity}'
        changes.append({'identity': identity, 'change': change, 'before': old, 'after': new})
    result = {'baseline_commit': BASELINE, 'before': before, 'after': after, 'candidates': candidates, 'changes': changes, 'bundled_packages': bundles}
    Path(args.output).write_text(json.dumps(result, indent=2, sort_keys=True) + '\n')
    if args.checker_directory:
        args.checker_directory.mkdir(parents=True, exist_ok=True)
        for label, data in [('before', before), ('after', after)]:
            normalized = {'manifests': {name: data['hashes'][name] for name in ['composer.json', 'package.json']}, 'packages': {identity: {'version': package['version'], 'reference': reference(package), 'pinned': False} for identity, package in data['packages'].items()}}
            (args.checker_directory / f'{label}.json').write_text(json.dumps(normalized, indent=2) + '\n')
    print(json.dumps({'before': before['counts'], 'after': after['counts'], 'changes': len(changes), 'candidates': {status: sum(c['status'] == status for c in candidates) for status in ['installed', 'removed', 'superseded', 'blocked']}}))


if __name__ == '__main__':
    main()
