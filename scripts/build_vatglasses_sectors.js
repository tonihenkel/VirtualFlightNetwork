#!/usr/bin/env node
/*
 * Compile VATGlasses' three-dimensional sector definitions into a compact,
 * random-access NDJSON store. The generated dataset remains licensed under
 * CC BY-NC-SA 4.0; see htdocs/data/atc/README.md.
 *
 * Usage:
 *   node scripts/build_vatglasses_sectors.js <vatglasses-data-dir>
 */
'use strict';

const fs = require('fs');
const path = require('path');

const projectRoot = process.env.VFN_BUILD_ROOT
    ? path.resolve(process.env.VFN_BUILD_ROOT)
    : path.resolve(__dirname, '..');
const sourceRoot = path.resolve(process.argv[2] || '');
const sourceData = path.join(sourceRoot, 'data');
const outputDir = path.join(projectRoot, 'htdocs', 'data', 'atc');
const outputData = path.join(outputDir, 'sector-boundaries.ndjson');
const outputIndex = path.join(outputDir, 'sector-boundaries.index.json');

if (!sourceRoot || !fs.existsSync(sourceData)) {
    console.error('Usage: node scripts/build_vatglasses_sectors.js <vatglasses-data-dir>');
    process.exit(2);
}

function dmsToDecimal(value, latitude) {
    const raw = String(value).trim();
    const negative = raw.startsWith('-');
    const digits = raw.replace(/^[+-]/, '').padStart(latitude ? 6 : 7, '0');
    const degreeDigits = latitude ? 2 : 3;
    const degrees = Number(digits.slice(0, degreeDigits));
    const minutes = Number(digits.slice(degreeDigits, degreeDigits + 2));
    const seconds = Number(digits.slice(degreeDigits + 2, degreeDigits + 4));
    if (![degrees, minutes, seconds].every(Number.isFinite)
        || minutes >= 60 || seconds >= 60
        || degrees > (latitude ? 90 : 180)) return null;
    const result = degrees + minutes / 60 + seconds / 3600;
    return negative ? -result : result;
}

function normalizeRing(points) {
    const ring = [];
    for (const point of Array.isArray(points) ? points : []) {
        if (!Array.isArray(point) || point.length < 2) continue;
        const lat = dmsToDecimal(point[0], true);
        const lon = dmsToDecimal(point[1], false);
        if (lat === null || lon === null) continue;
        const normalized = [Number(lon.toFixed(6)), Number(lat.toFixed(6))];
        const previous = ring[ring.length - 1];
        if (!previous || previous[0] !== normalized[0] || previous[1] !== normalized[1]) {
            ring.push(normalized);
        }
    }
    if (ring.length < 3) return null;
    const first = ring[0];
    const last = ring[ring.length - 1];
    if (first[0] !== last[0] || first[1] !== last[1]) ring.push([...first]);
    return ring.length >= 4 ? ring : null;
}

function stationCodes(positionKey, position) {
    const key = String(positionKey).trim().toUpperCase();
    const prefixes = Array.isArray(position.pre) ? position.pre : [];
    const codes = [];
    for (const rawPrefix of prefixes) {
        const prefix = String(rawPrefix).trim().toUpperCase();
        if (!prefix || prefix.includes('/')) continue;
        codes.push(key.startsWith(prefix + '_') || key.startsWith(prefix + '-')
            ? key.replace(/-/g, '_')
            : `${prefix}_${key.replace(/-/g, '_')}`);
    }
    if (!codes.length && /^[A-Z0-9]{3,}(?:[_-][A-Z0-9]+)*$/.test(key)) {
        codes.push(key.replace(/-/g, '_'));
    }
    return [...new Set(codes)];
}

const records = new Map();
const sourceFiles = fs.readdirSync(sourceData).filter(file => file.endsWith('.json')).sort();
let airspaceCount = 0;
let segmentCount = 0;
let invalidRings = 0;

for (const filename of sourceFiles) {
    const fullPath = path.join(sourceData, filename);
    const document = JSON.parse(fs.readFileSync(fullPath, 'utf8').replace(/^\uFEFF/, ''));
    const positions = document.positions && typeof document.positions === 'object'
        ? document.positions : {};
    for (const airspace of Array.isArray(document.airspace) ? document.airspace : []) {
        const primaryOwner = Array.isArray(airspace.owner) ? String(airspace.owner[0] || '') : '';
        const localOwner = primaryOwner.includes('/') ? '' : primaryOwner;
        const position = positions[localOwner];
        if (!position || !['CTR', 'FSS'].includes(String(position.type || '').toUpperCase())) continue;
        const codes = stationCodes(localOwner, position);
        if (!codes.length) continue;
        airspaceCount++;
        for (const sector of Array.isArray(airspace.sectors) ? airspace.sectors : []) {
            const ring = normalizeRing(sector.points);
            if (!ring) {
                invalidRings++;
                continue;
            }
            segmentCount++;
            for (const code of codes) {
                if (!records.has(code)) {
                    records.set(code, {
                        station_code: code,
                        position_key: localOwner,
                        type: String(position.type || 'CTR').toUpperCase(),
                        frequency: String(position.frequency || ''),
                        callsign: String(position.callsign || ''),
                        group: String(airspace.group || ''),
                        source_file: filename,
                        features: []
                    });
                }
                records.get(code).features.push({
                    type: 'Feature',
                    properties: {
                        id: String(airspace.id || ''),
                        group: String(airspace.group || ''),
                        owner: Array.isArray(airspace.owner) ? airspace.owner : [],
                        min_fl: Number.isFinite(Number(sector.min)) ? Number(sector.min) : null,
                        max_fl: Number.isFinite(Number(sector.max)) ? Number(sector.max) : null
                    },
                    geometry: {type: 'Polygon', coordinates: [ring]}
                });
            }
        }
    }
}

fs.mkdirSync(outputDir, {recursive: true});
const fd = fs.openSync(outputData, 'w');
const index = {
    generated_at: new Date().toISOString(),
    source: 'https://github.com/lennycolton/vatglasses-data',
    source_commit: '',
    license: 'CC-BY-NC-SA-4.0',
    files: sourceFiles.length,
    stations: {}
};
try {
    try {
        index.source_commit = fs.readFileSync(path.join(sourceRoot, '.git', 'refs', 'heads', 'master'), 'utf8').trim();
    } catch (_) {
        try { index.source_commit = fs.readFileSync(path.join(sourceRoot, '.git', 'refs', 'heads', 'main'), 'utf8').trim(); } catch (_) {
            try { index.source_commit = fs.readFileSync(path.join(sourceRoot, '.git', 'shallow'), 'utf8').trim().split(/\s+/)[0]; } catch (_) {}
        }
    }
    let offset = 0;
    for (const code of [...records.keys()].sort()) {
        const record = records.get(code);
        const line = JSON.stringify(record) + '\n';
        const bytes = Buffer.byteLength(line);
        fs.writeSync(fd, line, null, 'utf8');
        index.stations[code] = {
            offset,
            length: bytes,
            features: record.features.length,
            frequency: record.frequency,
            callsign: record.callsign,
            group: record.group,
            position_key: record.position_key
        };
        offset += bytes;
    }
} finally {
    fs.closeSync(fd);
}
fs.writeFileSync(outputIndex, JSON.stringify(index, null, 2) + '\n');

console.log(JSON.stringify({
    source_files: sourceFiles.length,
    stations: records.size,
    airspaces: airspaceCount,
    altitude_segments: segmentCount,
    invalid_rings: invalidRings,
    output_bytes: fs.statSync(outputData).size
}, null, 2));
