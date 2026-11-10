/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * Stamps every compiled bundle, and every minified third-party asset this plugin ships, with a
 * pointer to its own human-readable source.
 *
 * WordPress.org guideline 4 requires that source to be findable. It ships inside this plugin
 * (assets/backend/app/src/ is in both ZIPs) and readme.txt documents it -- but that is not enough on
 * its own, because the reviewers' scanner quotes ONE LINE out of each minified file and decides from
 * that excerpt alone. Where that line is has bitten this plugin family twice, two different ways:
 *
 *  - plugixa-team was flagged with the scanner quoting LINE 1, which is why the block banner below
 *    exists.
 *  - plugixa-recipe 1.0.2 was flagged anyway, with the block banner already on all 72 bundles,
 *    because the scanner walks past a leading comment block and quotes the first line long enough to
 *    look minified. Measured against that review: exactly the files with a line over 2000
 *    characters, which was this plugin's shape too -- a five-line banner, then 19,401 characters of
 *    code on line 6 that named nothing.
 *
 * So the block banner stays for a human opening the file, and a single-line marker is glued to the
 * FRONT OF THE FIRST LINE OF CODE, with no newline after it. That is the line the scanner quotes, so
 * its own evidence carries the answer.
 *
 * This runs after `vite build` rather than as a bundler plugin because neither `output.banner` nor
 * rolldown's plugin-level `banner` addon hook is honoured under Vite 8, and a `generateBundle`
 * prepend cannot see the entry chunk's `__vite__mapDeps` preamble, which Vite injects later.
 *
 * Usage:  node <path>/stamp-source-banner.mjs <backend|vendor>
 *
 * `backend` stamps whichever output tree the build it follows actually wrote, by the same rule
 * vite.config.js uses: PLUGIXA_TARGET=free, OR a tree with no src/pro/ (the free ZIP), is the base
 * bundle; anything else is the premium overlay. Deciding on the variable alone would send the
 * documented `npm run build` in the free ZIP looking for a pro/ output directory.
 *
 * `vendor` stamps the minified assets of libraries shipped verbatim, which sit outside every build
 * directory. IT STAMPS THE TREE THIS SCRIPT SITS IN, and in this plugin that is meant to be a release
 * STAGE, never the working copy: vendor/ is not committed here -- Composer owns it -- so a stamp
 * written into the checkout would be undone by the next `composer install` and nothing would say so.
 * bin/release.ps1 runs the staged copy of this script, which resolves PLUGIN_ROOT to the stage, and
 * then refuses to package any minified file whose quoted line still names no source.
 */
import { readdirSync, readFileSync, writeFileSync, existsSync, statSync } from 'node:fs';
import { join, resolve, relative, sep } from 'node:path';

const MARKER = 'Plugixa Inventory -- compiled bundle';
const VENDOR_MARKER = 'Plugixa Inventory -- bundled third-party library';
const PLUGIN_ROOT = resolve(import.meta.dirname, '..', '..');

const TARGETS = {
  backend: {
    appDir: 'assets/backend/app',
    // Mirrors vite.config.js's `isFreeBuild` -- see the note at the top of this file.
    outDir: () =>
      process.env.PLUGIXA_TARGET === 'free' ||
      !existsSync(resolve(PLUGIN_ROOT, 'assets/backend/app/src/pro/index.tsx'))
        ? '../build'
        : '../../../pro/assets/backend/build',
    source: 'assets/backend/app/src/',
    what: 'React admin application (TypeScript, MUI)',
    rebuild: 'cd assets/backend/app && npm install && npm run build',
    node: 'Node.js 20.19+ or 22.12+',
  },
};

/**
 * Third-party libraries shipped verbatim, outside any build directory.
 *
 * Guideline 4 covers "developer libraries you've included", not only first-party code. A DIRECTORY,
 * never a hand-written list of files: the Freemius SDK ships 17 js/css assets and 10 of them carry a
 * long minified line, and listing them one by one is how plugixa-recipe first shipped nine unstamped
 * admin stylesheets -- everybody remembers freemius-pricing.js and nobody remembers assets/css/admin/.
 * A directory also survives an SDK bump that adds a file.
 *
 * The version and licence are read from composer.lock rather than written here, so the banner cannot
 * go on naming the SDK version before last.
 */
const VENDOR_DIRS = [
  {
    dir: 'vendor/freemius/wordpress-sdk/assets',
    package: 'freemius/wordpress-sdk',
    lib: 'Freemius WordPress SDK',
    vendored: 'installed by Composer; see composer.json at the plugin root',
  },
];

function blockBannerFor(target) {
  return (
    `/*! ${MARKER}. Do not edit; regenerated on every build.\n` +
    ` * Human-readable source: ${target.source} -- ${target.what}\n` +
    ` * Rebuild: ${target.rebuild}   (${target.node})\n` +
    ` * License: GPLv2 or later -- https://www.gnu.org/licenses/gpl-2.0.html\n` +
    ` */\n`
  );
}

function inlineMarkerFor(target) {
  return `/*! ${MARKER} -- source: ${target.source} | rebuild: ${target.rebuild} | GPLv2 or later */`;
}

function vendorBlockBannerFor(entry) {
  return (
    `/*! ${VENDOR_MARKER}.\n` +
    ` * ${entry.lib} ${entry.version} -- ${entry.license} -- ${entry.upstream}\n` +
    ` * This file is NOT built from this plugin's source. Its human-readable source is the upstream\n` +
    ` * project above, at the version pinned in composer.lock. The file is upstream's, unchanged\n` +
    ` * except for this comment and a one-line marker before its first line of code.\n` +
    ` * Vendored by: ${entry.vendored}\n` +
    ` */\n`
  );
}

function vendorInlineMarkerFor(entry) {
  return `/*! ${VENDOR_MARKER}: ${entry.lib} ${entry.version} (${entry.license}) -- source: ${entry.upstream} */`;
}

/**
 * Prepend the block banner, and glue the inline marker onto the first line of code.
 *
 * ONLY that line. Marking every long line was tried on plugixa-recipe and is WRONG: a bundle carrying
 * a multi-line template literal minifies to several long lines, and the later ones are CONTINUATIONS
 * of a string, not statements. Prepending a comment there writes the comment text into the string --
 * measured on recipe's dashboard bundle, where the marker landed inside an SVG path. The file still
 * parses, so `node --check` and the build both stay green while the chart renders corrupted.
 *
 * "First line of code" means the first line after the leading comment/blank region -- not simply
 * line 1, because freemius-pricing.js opens with a one-line licence comment and its code is line 2.
 *
 * Returns null when the file is already stamped, so the caller can count skips.
 */
function stamp(code, blockBanner, inlineMarker) {
  if (code.startsWith('/*!') && code.slice(0, 400).includes(MARKER)) return null;
  if (code.startsWith('/*!') && code.slice(0, 400).includes(VENDOR_MARKER)) return null;

  const lines = code.split('\n');
  let i = 0;
  let inComment = false;

  // Walk only the LEADING comment/blank region. This never enters code, so it cannot mistake a `/*`
  // inside a string literal for a comment opener.
  for (; i < lines.length; i += 1) {
    const line = lines[i].trim();
    if (inComment) {
      if (line.includes('*/')) {
        inComment = false;
        // Trailing code on the same line as the comment close: stamp here.
        if (line.slice(line.indexOf('*/') + 2).trim() !== '') break;
      }
      continue;
    }
    if (line === '') continue;
    if (line.startsWith('//')) continue;
    if (line.startsWith('/*')) {
      const end = line.indexOf('*/');
      if (end === -1) inComment = true;
      else if (line.slice(end + 2).trim() !== '') break;
      continue;
    }
    break;
  }

  if (i < lines.length) lines[i] = inlineMarker + lines[i];

  return blockBanner + lines.join('\n');
}

function readmeFor(target) {
  return (
    `Plugixa Inventory -- compiled output\n` +
    `====================================\n\n` +
    `Every file in this directory is GENERATED. Do not edit it by hand; the next\n` +
    `build overwrites it.\n\n` +
    `Human-readable source\n` +
    `---------------------\n` +
    `  ${target.source}${' '.repeat(Math.max(1, 30 - target.source.length))}${target.what}\n\n` +
    `Rebuild\n` +
    `-------\n` +
    `  ${target.rebuild}\n` +
    `  (requires ${target.node})\n\n` +
    `Exact dependency versions are pinned in the package-lock.json next to that\n` +
    `source. Any .js.map alongside a bundle here also embeds the original source\n` +
    `verbatim in its "sourcesContent" field.\n\n` +
    `License: GPLv2 or later -- https://www.gnu.org/licenses/gpl-2.0.html\n`
  );
}

function walk(dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) out.push(...walk(full));
    else out.push(full);
  }
  return out;
}

/**
 * The version, licence and upstream repository Composer actually installed.
 *
 * Fails rather than guessing: a banner that names the wrong version, or no licence, is a claim about
 * somebody else's code that this plugin cannot back up.
 */
function lockedPackage(name) {
  const lockFile = resolve(PLUGIN_ROOT, 'composer.lock');
  if (!existsSync(lockFile)) {
    console.error(`stamp-source-banner: composer.lock not found at ${lockFile}; cannot name ${name}'s version.`);
    process.exit(1);
  }

  const lock = JSON.parse(readFileSync(lockFile, 'utf8'));
  const found = [...(lock.packages ?? []), ...(lock['packages-dev'] ?? [])].find((p) => p.name === name);
  if (!found) {
    console.error(`stamp-source-banner: ${name} is not in composer.lock.`);
    process.exit(1);
  }

  return {
    version: found.version,
    license: (found.license ?? []).join(' OR ') || 'see upstream',
    upstream: String(found.source?.url ?? `https://github.com/${name}`).replace(/\.git$/, ''),
  };
}

function runVendor() {
  let stamped = 0;
  let skipped = 0;
  const missing = [];

  for (const vendor of VENDOR_DIRS) {
    const dir = resolve(PLUGIN_ROOT, vendor.dir);
    if (!existsSync(dir)) {
      missing.push(vendor.dir);
      continue;
    }

    const entry = { ...vendor, ...lockedPackage(vendor.package) };
    const blockBanner = vendorBlockBannerFor(entry);
    const inlineMarker = vendorInlineMarkerFor(entry);

    for (const path of walk(dir).filter((f) => f.endsWith('.js') || f.endsWith('.css'))) {
      const next = stamp(readFileSync(path, 'utf8'), blockBanner, inlineMarker);

      if (next === null) {
        skipped += 1;
        continue;
      }

      writeFileSync(path, next, 'utf8');
      stamped += 1;
    }
  }

  if (missing.length) {
    console.error(
      `stamp-source-banner: vendor director(y/ies) not found, so nothing names their source:\n  ` +
      missing.join('\n  ')
    );
    process.exit(1);
  }

  console.log(
    `stamp-source-banner: vendor -- stamped ${stamped} file(s)` +
    `${skipped ? `, skipped ${skipped} already stamped` : ''}`
  );
}

function runBuildTarget(target) {
  const outDir = resolve(PLUGIN_ROOT, target.appDir, target.outDir());
  if (!existsSync(outDir)) {
    console.error(`stamp-source-banner: output directory not found: ${outDir}`);
    process.exit(1);
  }

  const blockBanner = blockBannerFor(target);
  const inlineMarker = inlineMarkerFor(target);

  // A prepend of whole lines at offset 0 shifts every mapping down by exactly this many lines; in
  // the VLQ `mappings` format one line break is one `;`. The inline marker adds no newline, so it
  // does not change this count -- it shifts the columns of the one line it is glued to, which no
  // sourcemap consumer minds for a generated file.
  const bannerLines = blockBanner.split('\n').length - 1;

  let stamped = 0;
  let shifted = 0;
  let skipped = 0;

  for (const file of walk(outDir)) {
    if (!file.endsWith('.js') && !file.endsWith('.css')) continue;

    const next = stamp(readFileSync(file, 'utf8'), blockBanner, inlineMarker);
    if (next === null) {
      skipped += 1;
      continue;
    }

    writeFileSync(file, next, 'utf8');
    stamped += 1;

    const mapFile = `${file}.map`;
    if (existsSync(mapFile)) {
      const map = JSON.parse(readFileSync(mapFile, 'utf8'));
      map.mappings = ';'.repeat(bannerLines) + map.mappings;
      writeFileSync(mapFile, JSON.stringify(map), 'utf8');
      shifted += 1;
    }
  }

  writeFileSync(join(outDir, 'SOURCE.txt'), readmeFor(target), 'utf8');

  const rel = relative(PLUGIN_ROOT, outDir).split(sep).join('/');
  console.log(
    `stamp-source-banner: ${rel} -- stamped ${stamped} file(s), ` +
    `shifted ${shifted} sourcemap(s)${skipped ? `, skipped ${skipped} already stamped` : ''}, wrote SOURCE.txt`
  );
}

const name = process.argv[2];

if (name === 'vendor') {
  runVendor();
} else if (TARGETS[name]) {
  runBuildTarget(TARGETS[name]);
} else {
  console.error(
    `stamp-source-banner: unknown target "${name}". Expected one of: ${Object.keys(TARGETS).join(', ')}, vendor`
  );
  process.exit(1);
}
