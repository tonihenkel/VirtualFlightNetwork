require('dotenv').config();

const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const net = require('net');
const dns = require('dns').promises;
const crypto = require('crypto');
const { spawn } = require('child_process');
const mysql = require('mysql2/promise');
const WebSocket = require('ws');
const { EdgeTTS } = require('node-edge-tts');

const config = {
  host: process.env.VOICE_HOST || '0.0.0.0',
  port: Number(process.env.VOICE_PORT || 8090),
  tls: {
    cert: process.env.VOICE_TLS_CERT || '',
    key: process.env.VOICE_TLS_KEY || ''
  },
  db: {
    host: process.env.DB_HOST || '127.0.0.1',
    database: process.env.DB_NAME || 'flight_network',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    waitForConnections: true,
    connectionLimit: 10,
    charset: 'utf8mb4'
  },
  unicomFrequency: normalizeFrequency(process.env.UNICOM_FREQUENCY || '122.800'),
  unicomGlobal: String(process.env.UNICOM_GLOBAL || '1') === '1',
  ffmpegPath: process.env.FFMPEG_PATH || 'ffmpeg',
  testAudioDirectory: path.resolve(
    process.env.VOICE_TEST_AUDIO_DIR || path.join(__dirname, 'test-audio')
  ),
  airportFrequencyCsv: path.resolve(
    process.env.AIRPORT_FREQUENCY_CSV || path.join(__dirname, '..', 'htdocs', 'data', 'airports', 'airport-frequencies.csv')
  ),
  runwayCsv: path.resolve(
    process.env.RUNWAY_CSV || path.join(__dirname, '..', 'htdocs', 'data', 'airports', 'runways.csv')
  ),
  autoAtisRangeNm: Number(process.env.AUTO_ATIS_RANGE_NM || 60),
  ranges: {
    gnd: Number(process.env.RANGE_GND_NM || 30),
    twr: Number(process.env.RANGE_TWR_NM || 60),
    app: Number(process.env.RANGE_APP_NM || 150),
    ctr: Number(process.env.RANGE_CTR_NM || 400),
    default: Number(process.env.RANGE_DEFAULT_NM || 200)
  }
};

const pool = mysql.createPool(config.db);
const clients = new Map();
let testBroadcast = null;
const autoAtisBroadcasts = new Map();
let autoAtisReconcileRunning = false;
const airportAtisFrequencies = new Map();
const airportRunways = new Map();
// Match the plugin's 2048-sample capture buffers. Tiny 20 ms frames caused
// older X-Plane clients to spend most of their receive loop synchronously
// opening/playing JSON-wrapped waveOut packets, producing gaps and backlog.
const testBroadcastFrameBytes = 2048 * 2;

fs.mkdirSync(config.testAudioDirectory, { recursive: true });
fs.mkdirSync(path.join(config.testAudioDirectory, 'atis'), { recursive: true });

function parseCsvLine(line) {
  const result = [];
  let value = '';
  let quoted = false;
  for (let index = 0; index < line.length; index += 1) {
    const character = line[index];
    if (character === '"') {
      if (quoted && line[index + 1] === '"') { value += '"'; index += 1; }
      else quoted = !quoted;
    } else if (character === ',' && !quoted) {
      result.push(value); value = '';
    } else value += character;
  }
  result.push(value);
  return result;
}

function loadAtisReferenceData() {
  try {
    const lines = fs.readFileSync(config.airportFrequencyCsv, 'utf8').split(/\r?\n/);
    const header = parseCsvLine(lines.shift() || '');
    const identIndex = header.indexOf('airport_ident');
    const typeIndex = header.indexOf('type');
    const frequencyIndex = header.indexOf('frequency_mhz');
    for (const line of lines) {
      if (!line) continue;
      const row = parseCsvLine(line);
      const station = String(row[identIndex] || '').toUpperCase();
      const type = String(row[typeIndex] || '').toUpperCase();
      const frequency = normalizeFrequency(row[frequencyIndex]);
      if (station && ['ATIS', 'D-ATIS'].includes(type) && frequency &&
          !airportAtisFrequencies.has(station)) airportAtisFrequencies.set(station, frequency);
    }
  } catch (error) { console.error('Unable to load global ATIS frequencies:', error); }
  try {
    const lines = fs.readFileSync(config.runwayCsv, 'utf8').split(/\r?\n/);
    const header = parseCsvLine(lines.shift() || '');
    const column = (name) => header.indexOf(name);
    for (const line of lines) {
      if (!line) continue;
      const row = parseCsvLine(line);
      const station = String(row[column('airport_ident')] || '').toUpperCase();
      if (!station || Number(row[column('closed')] || 0)) continue;
      const entries = [
        {ident: row[column('le_ident')], heading: Number(row[column('le_heading_degT')])},
        {ident: row[column('he_ident')], heading: Number(row[column('he_heading_degT')])}
      ].filter(item => item.ident && Number.isFinite(item.heading));
      if (!entries.length) continue;
      if (!airportRunways.has(station)) airportRunways.set(station, []);
      airportRunways.get(station).push(...entries);
    }
  } catch (error) { console.error('Unable to load global runway data:', error); }
  console.log(`ATIS reference data: ${airportAtisFrequencies.size} airports, ${airportRunways.size} runway sets.`);
}

loadAtisReferenceData();

function normalizeFrequency(value) {
  const match = String(value || '').trim().match(/^(\d{3})[.,]?(\d{0,3})$/);

  if (!match) {
    return null;
  }

  return `${match[1]}.${(match[2] || '').padEnd(3, '0').slice(0, 3)}`;
}

function distanceNm(aLat, aLon, bLat, bLon) {
  if (![aLat, aLon, bLat, bLon].every(Number.isFinite)) {
    return Infinity;
  }

  const toRad = (value) => value * Math.PI / 180;
  const earthRadiusKm = 6371;
  const dLat = toRad(bLat - aLat);
  const dLon = toRad(bLon - aLon);
  const lat1 = toRad(aLat);
  const lat2 = toRad(bLat);

  const a =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(lat1) * Math.cos(lat2) *
    Math.sin(dLon / 2) * Math.sin(dLon / 2);

  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return earthRadiusKm * c * 0.539957;
}

async function authenticate(token) {
  // Use the text protocol here. The prepared-statement binary protocol used by
  // mysql2.execute() can stall against this server while reading result-set
  // metadata. query() still escapes the token through its placeholder binding.
  const [rows] = await pool.query(
    `SELECT
        s.user_id,
        UPPER(s.callsign) AS callsign,
        COALESCE(s.is_invisible, 0) AS is_invisible,
        COALESCE(s.is_spectator, 0) AS is_spectator,
        COALESCE(u.op_permission, 0) AS op_permission
     FROM user_sessions s
     INNER JOIN users u ON u.id = s.user_id
     WHERE s.token = ?
       AND s.is_active = 1
     LIMIT 1`,
    [token]
  );

  return rows[0] || null;
}

function parseJsonMessage(raw) {
  if (typeof raw !== 'string' && !Buffer.isBuffer(raw)) {
    return null;
  }

  try {
    return JSON.parse(raw.toString('utf8'));
  } catch {
    return null;
  }
}

function send(ws, payload) {
  if (ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify(payload));
  }
}

function getFrequencyRangeNm(frequency, station, overrideRangeNm = null) {
  if (frequency === config.unicomFrequency && config.unicomGlobal) {
    return Infinity;
  }

  if (Number.isFinite(Number(overrideRangeNm)) && Number(overrideRangeNm) >= 1 &&
      Number(overrideRangeNm) <= 500) {
    return Number(overrideRangeNm);
  }

  const label = String(station || '').toUpperCase();

  if (label.endsWith('_GND')) return config.ranges.gnd;
  if (label.endsWith('_TWR')) return config.ranges.twr;
  if (label.endsWith('_APP') || label.endsWith('_DEP')) return config.ranges.app;
  if (label.endsWith('_CTR') || label.endsWith('_FSS')) return config.ranges.ctr;

  return config.ranges.default;
}

function updateClientState(client, payload) {
  client.com1 = normalizeFrequency(payload.com1) || client.com1;
  client.com2 = normalizeFrequency(payload.com2) || client.com2;
  client.txCom = Number(payload.txCom || client.txCom) === 2 ? 2 : 1;
  if (typeof payload.ptt === 'boolean') {
    // Starting a transmission must pass the channel-occupancy check below.
    // State packets may release PTT, but must never bypass that arbitration.
    if (client.spectator || payload.ptt === false) client.ptt = false;
  }

  const lat = Number(payload.latitude);
  const lon = Number(payload.longitude);

  if (Number.isFinite(lat)) client.latitude = lat;
  if (Number.isFinite(lon)) client.longitude = lon;

  if (typeof payload.station === 'string') {
    client.station = payload.station.trim().toUpperCase();
  }
}

function canReceive(sender, receiver, frequency) {
  if (sender.id === receiver.id) {
    return false;
  }

  if (receiver.monitor) {
    // A global monitor (currently UNICOM) is global only with regard to
    // distance. It must never bypass the tuned-frequency check.
    if (!receiver.monitorAuthorized || receiver.monitorFrequency !== frequency) {
      return false;
    }
    if (receiver.monitorGlobal) return true;
    return distanceNm(
      sender.latitude,
      sender.longitude,
      receiver.monitorLatitude,
      receiver.monitorLongitude
    ) <= receiver.monitorRangeNm;
  }

  if (receiver.com1 !== frequency && receiver.com2 !== frequency) {
    return false;
  }

  const rangeNm = getFrequencyRangeNm(frequency, sender.station, sender.rangeNm);

  if (rangeNm === Infinity) {
    return true;
  }

  return distanceNm(
    sender.latitude,
    sender.longitude,
    receiver.latitude,
    receiver.longitude
  ) <= rangeNm;
}

function transmittingFrequency(client, requestedFrequency = null) {
  return normalizeFrequency(requestedFrequency) ||
    (client.txCom === 2 ? client.com2 : client.com1);
}

function transmissionRegionsOverlap(first, second, frequency) {
  if (!first || !second) return false;
  if (frequency === config.unicomFrequency && config.unicomGlobal) return true;

  const firstRange = getFrequencyRangeNm(frequency, first.station, first.rangeNm);
  const secondRange = getFrequencyRangeNm(frequency, second.station, second.rangeNm);
  if (firstRange === Infinity || secondRange === Infinity) return true;

  const separation = distanceNm(
    first.latitude, first.longitude, second.latitude, second.longitude
  );
  // Two transmitters share a channel whenever their radio coverage areas
  // overlap. Same frequencies may still be used simultaneously in distant
  // regions, e.g. Berlin and Tokyo.
  return separation <= firstRange + secondRange;
}

function findBlockingTransmitter(client, frequency) {
  for (const other of clients.values()) {
    if (other.id === client.id || !other.authenticated || !other.ptt) continue;
    if (transmittingFrequency(other) !== frequency) continue;
    if (transmissionRegionsOverlap(client, other, frequency)) return other;
  }
  if (testBroadcast && testBroadcast.frequency === frequency &&
      transmissionRegionsOverlap(client, testBroadcast.sender, frequency)) {
    return testBroadcast.sender;
  }
  for (const broadcast of autoAtisBroadcasts.values()) {
    if (broadcast.frequency === frequency &&
        transmissionRegionsOverlap(client, broadcast.sender, frequency)) {
      return broadcast.sender;
    }
  }
  return null;
}

function requestTransmission(client, payload) {
  client.txCom = Number(payload.txCom || client.txCom) === 2 ? 2 : 1;
  const frequency = transmittingFrequency(client, payload.frequency);
  if (client.spectator || !frequency) {
    client.ptt = false;
    send(client.ws, { type: 'tx', active: false, receiveOnly: client.spectator });
    return false;
  }
  const blocker = findBlockingTransmitter(client, frequency);
  if (blocker) {
    client.ptt = false;
    const now = Date.now();
    if (!client.lastBusyNoticeAt || now - client.lastBusyNoticeAt >= 1000) {
      client.lastBusyNoticeAt = now;
      send(client.ws, {
        type: 'tx', active: false, busy: true, frequency,
        from: blocker.callsign || 'RADIO'
      });
    }
    return false;
  }
  client.ptt = true;
  client.lastPttAt = new Date().toISOString();
  send(client.ws, { type: 'tx', active: true, frequency });
  return true;
}

function forwardAudio(sender, payload) {
  const frequency =
    normalizeFrequency(payload.frequency) ||
    (sender.txCom === 2 ? sender.com2 : sender.com1);

  if (!frequency || typeof payload.payload !== 'string' || payload.payload === '') {
    return 0;
  }

  let forwarded = 0;

  for (const receiver of clients.values()) {
    if (!canReceive(sender, receiver, frequency)) {
      continue;
    }

    send(receiver.ws, {
      type: 'audio',
      from: sender.callsign,
      frequency,
      codec: payload.codec || 'opus',
      sampleRate: Number(payload.sampleRate || 0),
      sequence: Number(payload.sequence || 0),
      payload: payload.payload
    });

    send(receiver.ws, {
      type: 'rx',
      active: true,
      frequency,
      from: sender.callsign
    });

    forwarded += 1;
  }

  sender.audioPacketsForwarded += forwarded;
  return forwarded;
}

function broadcastTestStatus(extra = {}) {
  const status = {
    type: 'test_source_status',
    active: Boolean(testBroadcast),
    frequency: testBroadcast ? testBroadcast.frequency : null,
    sourceName: testBroadcast ? testBroadcast.sourceName : null,
    loop: testBroadcast ? testBroadcast.loop : false,
    startedBy: testBroadcast ? testBroadcast.startedBy : null,
    locationName: testBroadcast ? testBroadcast.locationName : null,
    rangeNm: testBroadcast ? testBroadcast.rangeNm : null,
    ...extra
  };

  for (const client of clients.values()) {
    if (client.authenticated && client.opPermission >= 5) {
      send(client.ws, status);
    }
  }
}

function stopTestBroadcast(reason = 'stopped') {
  if (!testBroadcast) {
    broadcastTestStatus({ reason });
    return;
  }
  const current = testBroadcast;
  testBroadcast = null;
  if (current.process && !current.process.killed) current.process.kill();
  broadcastTestStatus({ reason });
}

function isPrivateAddress(address) {
  const normalized = String(address || '').replace(/^::ffff:/, '');
  if (net.isIPv4(normalized)) {
    const parts = normalized.split('.').map(Number);
    return parts[0] === 10 || parts[0] === 127 || parts[0] === 0 ||
      (parts[0] === 169 && parts[1] === 254) ||
      (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31) ||
      (parts[0] === 192 && parts[1] === 168);
  }
  if (net.isIPv6(normalized)) {
    const lower = normalized.toLowerCase();
    return lower === '::1' || lower === '::' || lower.startsWith('fc') ||
      lower.startsWith('fd') || lower.startsWith('fe8') ||
      lower.startsWith('fe9') || lower.startsWith('fea') || lower.startsWith('feb');
  }
  return true;
}

async function validateStreamUrl(value) {
  let parsed;
  try { parsed = new URL(String(value || '')); } catch { throw new Error('Invalid stream URL.'); }
  if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) {
    throw new Error('Only HTTP/HTTPS stream URLs are allowed.');
  }
  const records = await dns.lookup(parsed.hostname, { all: true });
  if (!records.length || records.some((record) => isPrivateAddress(record.address))) {
    throw new Error('Private or local stream addresses are not allowed.');
  }
  return parsed.toString();
}

function resolveUploadedAudio(fileName) {
  const safeName = path.basename(String(fileName || ''));
  if (!/^[a-zA-Z0-9._-]+\.(aac|mp3|flac)$/i.test(safeName)) {
    throw new Error('Invalid audio file.');
  }
  const resolved = path.resolve(config.testAudioDirectory, safeName);
  if (!resolved.startsWith(config.testAudioDirectory + path.sep) || !fs.existsSync(resolved)) {
    throw new Error('Audio file not found.');
  }
  return resolved;
}

async function resolveAirport(icao) {
  const normalized = String(icao || '').trim().toUpperCase();
  if (!/^[A-Z0-9-]{3,12}$/.test(normalized)) {
    throw new Error('Invalid airport ICAO.');
  }
  const [rows] = await pool.query(
    `SELECT ident, name, latitude_deg, longitude_deg
     FROM airports
     WHERE UPPER(ident) = ? OR UPPER(icao_code) = ? OR UPPER(gps_code) = ?
     ORDER BY CASE WHEN UPPER(ident) = ? THEN 0 ELSE 1 END
     LIMIT 1`,
    [normalized, normalized, normalized, normalized]
  );
  const airport = rows[0];
  if (!airport || !Number.isFinite(Number(airport.latitude_deg)) ||
      !Number.isFinite(Number(airport.longitude_deg))) {
    throw new Error('Airport not found.');
  }
  return airport;
}

async function startTestBroadcast(client, payload) {
  if (client.opPermission < 5) throw new Error('No permission for test source.');
  const frequency = normalizeFrequency(payload.frequency);
  if (!frequency) throw new Error('Invalid frequency.');

  let latitude = NaN;
  let longitude = NaN;
  let station = '';
  let rangeNm = null;
  let locationName = 'UNICOM';
  if (!(frequency === config.unicomFrequency && config.unicomGlobal)) {
    if (payload.locationType === 'airport') {
      const airport = await resolveAirport(payload.airportIcao);
      rangeNm = Number(payload.rangeNm);
      if (![5, 10, 25, 50].includes(rangeNm)) throw new Error('Invalid transmitter range.');
      latitude = Number(airport.latitude_deg);
      longitude = Number(airport.longitude_deg);
      station = String(airport.ident || '').toUpperCase();
      locationName = station;
    } else {
      const referenceUserId = Number(payload.referenceUserId || 0);
      const reference = Array.from(clients.values()).find((item) =>
        item.authenticated && item.userId === referenceUserId &&
        Number.isFinite(item.latitude) && Number.isFinite(item.longitude)
      );
      if (!reference) throw new Error('A connected reference pilot with position is required.');
      latitude = reference.latitude;
      longitude = reference.longitude;
      station = reference.station;
      locationName = reference.callsign;
    }
  }

  const sourceType = payload.sourceType === 'upload' ? 'upload' : 'stream';
  const input = sourceType === 'upload'
    ? resolveUploadedAudio(payload.fileName)
    : await validateStreamUrl(payload.streamUrl);
  const loop = payload.loop === true;
  stopTestBroadcast('replaced');

  const args = ['-hide_banner', '-loglevel', 'warning'];
  if (sourceType === 'upload' && loop) args.push('-stream_loop', '-1');
  if (sourceType === 'stream') {
    args.push('-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5');
  }
  args.push('-re', '-i', input, '-vn', '-ac', '1', '-ar', '16000', '-f', 's16le', 'pipe:1');

  const process = spawn(config.ffmpegPath, args, { windowsHide: true });
  const sender = {
    id: `test-source-${Date.now()}`,
    callsign: 'VFN-AUDIO', txCom: 1, com1: frequency, com2: frequency,
    latitude, longitude, station, rangeNm, audioPacketsForwarded: 0
  };
  const blocker = findBlockingTransmitter(sender, frequency);
  if (blocker) {
    throw new Error(`Frequency is currently occupied by ${blocker.callsign || 'another station'}.`);
  }
  testBroadcast = {
    process, sender, frequency, loop,
    sourceName: sourceType === 'upload' ? path.basename(input) : new URL(input).hostname,
    locationName, rangeNm,
    startedBy: client.callsign,
    uploadedPath: sourceType === 'upload' ? input : null,
    sequence: 0,
    buffer: Buffer.alloc(0)
  };
  const current = testBroadcast;
  process.stdout.on('data', (chunk) => {
    if (testBroadcast !== current) return;
    current.buffer = Buffer.concat([current.buffer, chunk]);
    while (current.buffer.length >= testBroadcastFrameBytes) {
      const frame = current.buffer.subarray(0, testBroadcastFrameBytes);
      current.buffer = current.buffer.subarray(testBroadcastFrameBytes);
      forwardAudio(sender, {
        frequency, codec: 'pcm16', sampleRate: 16000,
        sequence: ++current.sequence, payload: frame.toString('base64')
      });
    }
  });
  process.stderr.on('data', (chunk) => console.warn(`FFmpeg test source: ${chunk}`));
  process.on('error', (error) => {
    console.error('FFmpeg test source failed:', error);
    if (testBroadcast === current) {
      testBroadcast = null;
      broadcastTestStatus({ reason: 'error', message: error.message });
    }
    if (current.uploadedPath) fs.unlink(current.uploadedPath, () => {});
  });
  process.on('close', (code) => {
    if (testBroadcast === current) {
      testBroadcast = null;
      broadcastTestStatus({ reason: code === 0 ? 'finished' : 'error' });
    }
    if (current.uploadedPath) fs.unlink(current.uploadedPath, () => {});
  });
  broadcastTestStatus();
}

const atisAlphabet = [
  'Alpha','Bravo','Charlie','Delta','Echo','Foxtrot','Golf','Hotel','India','Juliett',
  'Kilo','Lima','Mike','November','Oscar','Papa','Quebec','Romeo','Sierra','Tango',
  'Uniform','Victor','Whiskey','X-ray','Yankee','Zulu'
];
const phoneticLetters = {
  A:'Alpha',B:'Bravo',C:'Charlie',D:'Delta',E:'Echo',F:'Foxtrot',G:'Golf',H:'Hotel',
  I:'India',J:'Juliett',K:'Kilo',L:'Lima',M:'Mike',N:'November',O:'Oscar',P:'Papa',
  Q:'Quebec',R:'Romeo',S:'Sierra',T:'Tango',U:'Uniform',V:'Victor',W:'Whiskey',
  X:'X-ray',Y:'Yankee',Z:'Zulu'
};
const spokenDigits = ['zero','one','two','three','four','five','six','seven','eight','nine'];

function speakCharacters(value) {
  return String(value || '').toUpperCase().split('').map(character =>
    phoneticLetters[character] || (/[0-9]/.test(character) ? spokenDigits[Number(character)] : character)
  ).join(' ');
}

function speakNumberDigits(value) {
  return String(value || '').split('').map(character =>
    /[0-9]/.test(character) ? spokenDigits[Number(character)] : character
  ).join(' ');
}

function fetchText(url) {
  return new Promise((resolve, reject) => {
    const request = https.get(url, {headers:{'User-Agent':'VFN-Auto-ATIS/1.0'}}, response => {
      if (response.statusCode < 200 || response.statusCode >= 300) {
        response.resume(); reject(new Error(`HTTP ${response.statusCode}`)); return;
      }
      let body = '';
      response.setEncoding('utf8');
      response.on('data', chunk => { body += chunk; });
      response.on('end', () => resolve(body));
    });
    request.setTimeout(10000, () => request.destroy(new Error('METAR timeout')));
    request.on('error', reject);
  });
}

async function fetchAirportMetar(station) {
  const body = await fetchText(
    `https://tgftp.nws.noaa.gov/data/observations/metar/stations/${encodeURIComponent(station)}.TXT`
  );
  const lines = body.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
  if (lines.length < 2) throw new Error(`No METAR for ${station}`);
  return {observedAt: lines[0], raw: lines[1]};
}

function chooseAtisRunway(station, rawMetar) {
  const windMatch = String(rawMetar).match(/\b(\d{3}|VRB)(\d{2,3})(?:G\d{2,3})?KT\b/);
  const runways = airportRunways.get(station) || [];
  if (!windMatch || windMatch[1] === 'VRB' || !runways.length) return '';
  const wind = Number(windMatch[1]);
  return runways.reduce((best, runway) => {
    const difference = Math.abs(((runway.heading - wind + 540) % 360) - 180);
    return !best || difference < best.difference ? {...runway, difference} : best;
  }, null)?.ident || '';
}

function buildAtisSpeech(station, airportName, metar, runway, letter, override = null) {
  const raw = metar.raw.toUpperCase();
  const parts = [
    `${airportName || speakCharacters(station)} information ${letter}.`,
    `This is an automated V F N airport information broadcast.`
  ];
  const time = raw.match(/\b\d{2}(\d{2})(\d{2})Z\b/);
  if (time) parts.push(`Time ${speakNumberDigits(time[1] + time[2])} Zulu.`);
  const wind = raw.match(/\b(\d{3}|VRB)(\d{2,3})(?:G(\d{2,3}))?KT\b/);
  if (wind) {
    const direction = wind[1] === 'VRB' ? 'variable' : `${speakNumberDigits(wind[1])} degrees`;
    parts.push(`Wind ${direction}, ${Number(wind[2])} knots${wind[3] ? `, gusting ${Number(wind[3])} knots` : ''}.`);
  }
  const visibility = raw.match(/\s(\d{4})\s/);
  if (visibility) parts.push(Number(visibility[1]) >= 9999
    ? 'Visibility one zero kilometers or more.'
    : `Visibility ${Number(visibility[1])} meters.`);
  const cloudNames = {FEW:'few',SCT:'scattered',BKN:'broken',OVC:'overcast'};
  const clouds = [...raw.matchAll(/\b(FEW|SCT|BKN|OVC)(\d{3})\b/g)].map(match =>
    `${cloudNames[match[1]]} at ${Number(match[2]) * 100} feet`
  );
  if (clouds.length) parts.push(`Clouds ${clouds.join(', ')}.`);
  else if (/\b(CAVOK|SKC|CLR|NSC)\b/.test(raw)) parts.push('No significant cloud.');
  const temperature = raw.match(/\b(M?\d{2})\/(M?\d{2})\b/);
  if (temperature) {
    const readTemp = value => value.startsWith('M') ? `minus ${Number(value.slice(1))}` : `${Number(value)}`;
    parts.push(`Temperature ${readTemp(temperature[1])}, dew point ${readTemp(temperature[2])}.`);
  }
  const qnh = raw.match(/\bQ(\d{4})\b/);
  if (qnh) parts.push(`Q N H ${speakNumberDigits(qnh[1])}.`);
  const arrivalRunways = String(override?.arrival_runways || '').trim();
  const departureRunways = String(override?.departure_runways || '').trim();
  if (arrivalRunways) parts.push(`Landing runways ${speakCharacters(arrivalRunways)}.`);
  if (departureRunways) parts.push(`Departure runways ${speakCharacters(departureRunways)}.`);
  if (!arrivalRunways && !departureRunways && runway) parts.push(`Runway ${speakCharacters(runway)} in use.`);
  const approachType = String(override?.approach_type || '').trim();
  if (approachType) parts.push(`${approachType}.`);
  const transitionLevel = String(override?.transition_level || '').replace(/^FL\s*/i, '').trim();
  if (transitionLevel) parts.push(`Transition level ${speakNumberDigits(transitionLevel)}.`);
  const transitionAltitude = String(override?.transition_altitude || '').replace(/\s*FT$/i, '').trim();
  if (transitionAltitude) parts.push(`Transition altitude ${speakNumberDigits(transitionAltitude)} feet.`);
  const remarks = String(override?.remarks || '').trim();
  if (remarks) parts.push(remarks.endsWith('.') ? remarks : `${remarks}.`);
  parts.push(`Advise on initial contact you have information ${letter}.`);
  return parts.join(' ');
}

function stopAutoAtis(station, reason = 'stopped') {
  const current = autoAtisBroadcasts.get(station);
  if (!current) return;
  autoAtisBroadcasts.delete(station);
  if (current.process && !current.process.killed) current.process.kill();
  console.log(`Automatic ATIS ${station} stopped: ${reason}`);
}

function scheduleAtisFileRemoval(filePath, attempt = 0) {
  if (!filePath || [...autoAtisBroadcasts.values()].some(item => item.audioPath === filePath)) return;
  try { if (fs.existsSync(filePath)) fs.unlinkSync(filePath); }
  catch (error) {
    if (attempt < 6) setTimeout(() => scheduleAtisFileRemoval(filePath, attempt + 1), 1000 * (attempt + 1));
    else console.warn(`Unable to remove obsolete ATIS audio ${filePath}: ${error.message}`);
  }
}

function cleanupAtisAudioDirectory() {
  const directory = path.join(config.testAudioDirectory, 'atis');
  const active = new Set([...autoAtisBroadcasts.values()].map(item => path.resolve(item.audioPath || '')));
  try {
    for (const name of fs.readdirSync(directory)) {
      if (!name.toLowerCase().endsWith('.mp3')) continue;
      const filePath = path.resolve(directory, name);
      if (active.has(filePath)) continue;
      const age = Date.now() - fs.statSync(filePath).mtimeMs;
      if (age > 60 * 60 * 1000) scheduleAtisFileRemoval(filePath);
    }
  } catch (error) { console.warn(`ATIS audio cleanup failed: ${error.message}`); }
}

async function synthesizeAtis(text, outputPath) {
  const temporaryPath = `${outputPath}.${Date.now()}.mp3`;
  const tts = new EdgeTTS({
    voice:'en-US-AriaNeural', lang:'en-US',
    outputFormat:'audio-24khz-48kbitrate-mono-mp3', rate:'-8%', timeout:20000
  });
  await tts.ttsPromise(text, temporaryPath);
  if (fs.existsSync(outputPath)) fs.unlinkSync(outputPath);
  fs.renameSync(temporaryPath, outputPath);
}

function startAutoAtisAudio(details) {
  const previousAudioPath = autoAtisBroadcasts.get(details.station)?.audioPath || '';
  stopAutoAtis(details.station, 'updated');
  const args = [
    '-hide_banner','-loglevel','warning','-stream_loop','-1','-re','-i',details.audioPath,
    '-vn','-ac','1','-ar','16000','-f','s16le','pipe:1'
  ];
  const process = spawn(config.ffmpegPath, args, {windowsHide:true});
  const sender = {
    id:`auto-atis-${details.station}`, callsign:`${details.station}_ATIS`,
    txCom:1, com1:details.frequency, com2:details.frequency,
    latitude:details.latitude, longitude:details.longitude,
    station:`${details.station}_ATIS`, rangeNm:config.autoAtisRangeNm,
    audioPacketsForwarded:0
  };
  const current = {...details, process, sender, sequence:0, buffer:Buffer.alloc(0)};
  autoAtisBroadcasts.set(details.station, current);
  if (previousAudioPath && previousAudioPath !== details.audioPath) {
    scheduleAtisFileRemoval(previousAudioPath);
  }
  process.stdout.on('data', chunk => {
    if (autoAtisBroadcasts.get(details.station) !== current) return;
    current.buffer = Buffer.concat([current.buffer, chunk]);
    while (current.buffer.length >= testBroadcastFrameBytes) {
      const frame = current.buffer.subarray(0, testBroadcastFrameBytes);
      current.buffer = current.buffer.subarray(testBroadcastFrameBytes);
      forwardAudio(sender, {
        frequency:details.frequency, codec:'pcm16', sampleRate:16000,
        sequence:++current.sequence, payload:frame.toString('base64')
      });
    }
  });
  process.stderr.on('data', chunk => console.warn(`FFmpeg ATIS ${details.station}: ${chunk}`));
  process.on('error', error => {
    console.error(`Automatic ATIS ${details.station} failed:`, error);
    if (autoAtisBroadcasts.get(details.station) === current) autoAtisBroadcasts.delete(details.station);
  });
  process.on('close', () => {
    if (autoAtisBroadcasts.get(details.station) === current) autoAtisBroadcasts.delete(details.station);
  });
  console.log(`Automatic ATIS ${details.station} ${details.frequency} started (${details.letter}).`);
}

async function ensureAutoAtisSchema() {
  await pool.query(
    `CREATE TABLE IF NOT EXISTS auto_atis_broadcasts (
       airport_icao VARCHAR(12) NOT NULL,
       frequency VARCHAR(12) NOT NULL,
       info_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
       info_letter VARCHAR(16) NOT NULL DEFAULT 'Alpha',
       content_hash CHAR(64) NOT NULL DEFAULT '',
       metar_raw VARCHAR(255) NOT NULL DEFAULT '',
       active_runway VARCHAR(16) NOT NULL DEFAULT '',
       atis_text TEXT NOT NULL,
       is_active TINYINT(1) NOT NULL DEFAULT 1,
       updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (airport_icao)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
  );
  await pool.query(
    `CREATE TABLE IF NOT EXISTS atc_atis_overrides (
       airport_icao VARCHAR(12) NOT NULL,
       updated_by BIGINT UNSIGNED NOT NULL,
       arrival_runways VARCHAR(64) NOT NULL DEFAULT '',
       departure_runways VARCHAR(64) NOT NULL DEFAULT '',
       transition_level VARCHAR(16) NOT NULL DEFAULT '',
       transition_altitude VARCHAR(16) NOT NULL DEFAULT '',
       approach_type VARCHAR(64) NOT NULL DEFAULT '',
       remarks VARCHAR(500) NOT NULL DEFAULT '',
       is_active TINYINT(1) NOT NULL DEFAULT 1,
       updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (airport_icao), KEY idx_atc_atis_active (is_active)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
  );
  await pool.query(
    `CREATE TABLE IF NOT EXISTS atc_operational_events (
       id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
       airport_icao VARCHAR(12) NOT NULL,
       event_type VARCHAR(40) NOT NULL,
       old_value VARCHAR(160) NOT NULL DEFAULT '',
       new_value VARCHAR(160) NOT NULL DEFAULT '',
       created_by_user_id INT NULL,
       created_by_callsign VARCHAR(40) NOT NULL DEFAULT '',
       created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (id),
       KEY idx_atc_event_airport_id (airport_icao, id),
       KEY idx_atc_event_created (created_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
  );
}

async function reconcileAutoAtis() {
  if (autoAtisReconcileRunning) return;
  autoAtisReconcileRunning = true;
  try {
    await ensureAutoAtisSchema();
    const [rows] = await pool.query(
      `SELECT s.airport_icao AS station_code, MAX(s.airport_name) AS name,
              MAX(s.latitude) AS latitude_deg, MAX(s.longitude) AS longitude_deg,
              MAX(s.frequency) AS scoped_frequency
       FROM atc_session_atis_airports s
       INNER JOIN atc_sessions a ON a.id=s.session_id
       WHERE a.is_active=1 AND a.is_spectator=0
         AND a.last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
       GROUP BY s.airport_icao`
    );
    const activeStations = new Set(rows.map(row => String(row.station_code).toUpperCase()));
    for (const station of [...autoAtisBroadcasts.keys()]) {
      if (!activeStations.has(station)) stopAutoAtis(station, 'last ATC offline');
    }
    if (activeStations.size) {
      await pool.query(
        `UPDATE auto_atis_broadcasts SET is_active=0
         WHERE is_active=1 AND airport_icao NOT IN (?)`, [[...activeStations]]
      );
    } else await pool.query('UPDATE auto_atis_broadcasts SET is_active=0 WHERE is_active=1');
    for (const airport of rows) {
      const station = String(airport.station_code).toUpperCase();
      const frequency = normalizeFrequency(airport.scoped_frequency) || airportAtisFrequencies.get(station);
      if (!frequency) continue;
      let metar;
      try { metar = await fetchAirportMetar(station); }
      catch (error) { console.warn(`Automatic ATIS ${station}: ${error.message}`); continue; }
      const [overrideRows] = await pool.query(
        'SELECT * FROM atc_atis_overrides WHERE airport_icao=? AND is_active=1 LIMIT 1', [station]
      );
      const override = overrideRows[0] || null;
      const automaticRunway = chooseAtisRunway(station, metar.raw);
      const runway = String(override?.arrival_runways || override?.departure_runways || automaticRunway).trim();
      const overrideSignature = override ? [
        override.arrival_runways, override.departure_runways, override.transition_level,
        override.transition_altitude, override.approach_type, override.remarks
      ].join('|') : '';
      const contentHash = crypto.createHash('sha256')
        .update(`${metar.raw}|${automaticRunway}|${overrideSignature}`).digest('hex');
      const [existingRows] = await pool.query(
        'SELECT * FROM auto_atis_broadcasts WHERE airport_icao=? LIMIT 1', [station]
      );
      const existing = existingRows[0] || null;
      const unchanged = existing && existing.content_hash === contentHash;
      let infoIndex = unchanged ? Number(existing.info_index) :
        (existing && Number(existing.is_active) ? (Number(existing.info_index) + 1) % 26 : 0);
      const letter = atisAlphabet[infoIndex];
      const atisText = unchanged ? String(existing.atis_text) :
        buildAtisSpeech(station, String(airport.name || station), metar, automaticRunway, letter, override);
      const current = autoAtisBroadcasts.get(station);
      if (unchanged && current && current.frequency === frequency) continue;
      // FFmpeg keeps its input file open on Windows. Use a content-versioned
      // filename so a changed ATIS can be synthesized before the old process
      // is stopped, without trying to overwrite a locked MP3.
      const audioPath = path.join(
        config.testAudioDirectory,
        'atis',
        `${station}-${contentHash.slice(0, 16)}.mp3`
      );
      if (!unchanged || !fs.existsSync(audioPath)) await synthesizeAtis(atisText, audioPath);
      await pool.query(
        `INSERT INTO auto_atis_broadcasts
         (airport_icao,frequency,info_index,info_letter,content_hash,metar_raw,
          active_runway,atis_text,is_active,updated_at)
         VALUES (?,?,?,?,?,?,?,?,1,NOW())
         ON DUPLICATE KEY UPDATE frequency=VALUES(frequency),info_index=VALUES(info_index),
          info_letter=VALUES(info_letter),content_hash=VALUES(content_hash),
          metar_raw=VALUES(metar_raw),active_runway=VALUES(active_runway),
          atis_text=VALUES(atis_text),is_active=1,updated_at=NOW()`,
        [station,frequency,infoIndex,letter,contentHash,metar.raw,runway,atisText]
      );
      // Publish the operational event only after synthesis and the database
      // update completed. Every controller in whose ATIS scope this airport
      // lies receives the same event, independent of radio frequency.
      const previousRunway = String(existing?.active_runway || '').trim().toUpperCase();
      const publishedRunway = String(runway || '').trim().toUpperCase();
      if (existing && previousRunway && publishedRunway && previousRunway !== publishedRunway) {
        await pool.query(
          `INSERT INTO atc_operational_events
             (airport_icao,event_type,old_value,new_value,created_by_user_id,created_by_callsign)
           VALUES (?,'atis_runway_changed',?,?,NULL,'AUTO_ATIS')`,
          [station, previousRunway, publishedRunway]
        );
      }
      startAutoAtisAudio({
        station, frequency, letter, runway, contentHash, audioPath,
        latitude:Number(airport.latitude_deg), longitude:Number(airport.longitude_deg)
      });
    }
  } catch (error) { console.error('Automatic ATIS reconciliation failed:', error); }
  finally { autoAtisReconcileRunning = false; cleanupAtisAudioDirectory(); }
}

async function handleMonitor(client, payload) {
  let atcSession = null;
  if (client.userId) {
    const [rows] = await pool.query(
      `SELECT callsign, station_code, radar_boundary_code, is_spectator, can_transmit_voice
       FROM atc_sessions
       WHERE user_id = ? AND is_active = 1
       ORDER BY last_seen_at DESC, id DESC
       LIMIT 1`,
      [client.userId]
    );
    atcSession = rows[0] || null;
    if (atcSession) {
      client.callsign = String(atcSession.callsign || client.callsign).toUpperCase();
      client.spectator = Number(atcSession.is_spectator || 0) === 1 ||
        Number(atcSession.can_transmit_voice || 0) !== 1;
    }
  }
  client.monitorAuthorized = client.opPermission >= 1 || Boolean(atcSession);
  if (!client.monitorAuthorized) {
    send(client.ws, {
      type: 'error',
      message: 'No permission for voice monitoring.'
    });
    return;
  }

  client.monitor = true;
  client.monitorFrequency = normalizeFrequency(payload.frequency);
  if (!client.monitorFrequency) {
    throw new Error('Invalid monitor frequency.');
  }
  client.monitorGlobal = client.monitorFrequency === config.unicomFrequency &&
    config.unicomGlobal;
  client.monitorLatitude = NaN;
  client.monitorLongitude = NaN;
  client.monitorRangeNm = null;
  client.monitorLocationName = client.monitorGlobal ? 'UNICOM' : '';
  if (!client.monitorGlobal) {
    const rangeNm = Number(payload.rangeNm);
    if (![5, 10, 25, 50].includes(rangeNm)) {
      throw new Error('Invalid monitor range.');
    }
    const sessionLatitude = Number(payload.latitude);
    const sessionLongitude = Number(payload.longitude);
    if (payload.atcSessionReference === true && (atcSession || client.opPermission >= 1)) {
      if (!Number.isFinite(sessionLatitude) || !Number.isFinite(sessionLongitude)) {
        throw new Error('ATC radar reference is not ready. Please reconnect.');
      }
      client.monitorLatitude = sessionLatitude;
      client.monitorLongitude = sessionLongitude;
      client.monitorLocationName = String(
        atcSession?.callsign || atcSession?.radar_boundary_code ||
        atcSession?.station_code || payload.airportIcao || client.callsign || ''
      ).toUpperCase();
    } else {
      const airport = await resolveAirport(payload.airportIcao);
      client.monitorLatitude = Number(airport.latitude_deg);
      client.monitorLongitude = Number(airport.longitude_deg);
      client.monitorLocationName = String(airport.ident || '').toUpperCase();
    }
    client.monitorRangeNm = rangeNm;
    // A staff transmission from the browser originates at the configured
    // receiver site and participates in the same regional channel lock.
    client.latitude = client.monitorLatitude;
    client.longitude = client.monitorLongitude;
    client.station = client.monitorLocationName;
    client.rangeNm = rangeNm;
  }

  send(client.ws, {
    type: 'monitor',
    success: true,
    callsign: client.callsign,
    receiveOnly: client.spectator,
    frequency: client.monitorFrequency,
    global: client.monitorGlobal,
    airportIcao: client.monitorLocationName,
    rangeNm: client.monitorRangeNm
  });
}

function createHttpServer(handler) {
  if (config.tls.cert && config.tls.key) {
    return https.createServer({
      cert: fs.readFileSync(config.tls.cert),
      key: fs.readFileSync(config.tls.key)
    }, handler);
  }

  return http.createServer(handler);
}

const server = createHttpServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      success: true,
      clients: clients.size,
      testSource: testBroadcast ? {
        active: true,
        frequency: testBroadcast.frequency,
        sourceName: testBroadcast.sourceName,
        startedBy: testBroadcast.startedBy
      } : { active: false },
      connections: Array.from(clients.values()).map((client) => ({
        callsign: client.callsign,
        authenticated: client.authenticated,
        com1: client.com1,
        com2: client.com2,
        txCom: client.txCom,
        ptt: client.ptt === true,
        monitor: client.monitor,
        monitorFrequency: client.monitorFrequency,
        monitorGlobal: client.monitorGlobal,
        monitorLocationName: client.monitorLocationName,
        monitorRangeNm: client.monitorRangeNm,
        audioPacketsReceived: client.audioPacketsReceived,
        audioPacketsForwarded: client.audioPacketsForwarded,
        lastAudioAt: client.lastAudioAt,
        lastPttAt: client.lastPttAt
      }))
    }));
    return;
  }

  res.writeHead(404);
  res.end();
});

const wss = new WebSocket.Server({ server });

wss.on('connection', (ws) => {
  const id = cryptoRandomId();
  ws.isAlive = true;
  ws.on('pong', () => {
    ws.isAlive = true;
  });
  console.log(`Voice client connected: ${id}`);
  const client = {
    id,
    ws,
    authenticated: false,
    userId: null,
    callsign: '',
    opPermission: 0,
    spectator: false,
    com1: null,
    com2: null,
    txCom: 1,
    latitude: NaN,
    longitude: NaN,
    station: '',
    monitor: false,
    monitorAuthorized: false,
    monitorFrequency: null,
    monitorGlobal: false,
    monitorLatitude: NaN,
    monitorLongitude: NaN,
    monitorRangeNm: null,
    monitorLocationName: '',
    messageQueue: Promise.resolve(),
    audioPacketsReceived: 0,
    audioPacketsForwarded: 0,
    lastAudioAt: null,
    lastPttAt: null,
    lastBusyNoticeAt: null
  };

  clients.set(id, client);

  ws.on('message', (raw) => {
    client.messageQueue = client.messageQueue.then(async () => {
      const payload = parseJsonMessage(raw);

      if (!payload || typeof payload.type !== 'string') {
        send(ws, { type: 'error', message: 'Invalid message.' });
        return;
      }

      if (!client.authenticated) {
        if (payload.type !== 'hello' || typeof payload.token !== 'string') {
          console.warn(`Voice client sent ${payload.type} before hello: ${id}`);
          send(ws, { type: 'error', message: 'Authentication required.' });
          ws.close();
          return;
        }

        try {
          console.log(`Voice client authentication started: ${id}`);
          const session = await authenticate(payload.token);

          if (!session) {
            console.warn(`Voice client authentication rejected: ${id}`);
            send(ws, { type: 'error', message: 'Invalid or expired session.' });
            ws.close();
            return;
          }

          client.authenticated = true;
          client.userId = Number(session.user_id);
          client.callsign = String(session.callsign || payload.callsign || '').toUpperCase();
          client.opPermission = Number(session.op_permission || 0);
          client.spectator = Number(session.is_spectator || 0) === 1;
          updateClientState(client, payload);
          console.log(`Voice client authenticated: ${id}`);

          send(ws, {
            type: 'hello',
            success: true,
            callsign: client.callsign,
            opPermission: client.opPermission,
            receiveOnly: client.spectator
          });
        } catch (error) {
          console.error('Authentication failed:', error);
          send(ws, { type: 'error', message: 'Voice server authentication error.' });
          ws.close();
        }

        return;
      }

      if (payload.type === 'state') {
        updateClientState(client, payload);
        return;
      }

      if (payload.type === 'ptt') {
        if (payload.active === true) requestTransmission(client, payload);
        else {
          client.ptt = false;
          client.lastPttAt = new Date().toISOString();
          send(ws, { type: 'tx', active: false, frequency: transmittingFrequency(client, payload.frequency) });
        }
        return;
      }

      if (payload.type === 'audio') {
        if (client.spectator) {
          client.ptt = false;
          send(ws, {
            type: 'tx',
            active: false,
            receiveOnly: true
          });
          return;
        }
        client.audioPacketsReceived += 1;
        client.lastAudioAt = new Date().toISOString();

        // Recover a lost PTT control packet, but never bypass channel locking.
        if (payload.ptt === true && !client.ptt &&
            !requestTransmission(client, payload)) return;

        if (client.ptt) {
          forwardAudio(client, payload);
        }
        return;
      }

      if (payload.type === 'monitor') {
        await handleMonitor(client, payload);
        return;
      }

      if (payload.type === 'test_source_status') {
        if (client.opPermission < 5) throw new Error('No permission for test source.');
        send(client.ws, {
          type: 'test_source_status', active: Boolean(testBroadcast),
          frequency: testBroadcast ? testBroadcast.frequency : null,
          sourceName: testBroadcast ? testBroadcast.sourceName : null,
          loop: testBroadcast ? testBroadcast.loop : false,
          startedBy: testBroadcast ? testBroadcast.startedBy : null,
          locationName: testBroadcast ? testBroadcast.locationName : null,
          rangeNm: testBroadcast ? testBroadcast.rangeNm : null
        });
        return;
      }

      if (payload.type === 'test_source_start') {
        try { await startTestBroadcast(client, payload); }
        catch (error) { send(client.ws, { type: 'test_source_status', active: false, reason: 'error', message: error.message }); }
        return;
      }

      if (payload.type === 'test_source_stop') {
        if (client.opPermission < 5) throw new Error('No permission for test source.');
        stopTestBroadcast('stopped');
        return;
      }
    }).catch((error) => {
      console.error('Voice message processing failed:', error);
      send(ws, {
        type: 'error',
        message: error && error.message ? String(error.message) : 'Voice server message error.'
      });
    });
  });

  ws.on('close', () => {
    console.log(`Voice client disconnected: ${id}`);
    clients.delete(id);
  });
});

const heartbeatTimer = setInterval(() => {
  for (const ws of wss.clients) {
    if (ws.isAlive === false) {
      ws.terminate();
      continue;
    }

    ws.isAlive = false;
    ws.ping();
  }
}, 25000);

const autoAtisTimer = setInterval(() => {
  reconcileAutoAtis().catch(error => console.error('Automatic ATIS timer failed:', error));
}, 30000);
setTimeout(() => {
  reconcileAutoAtis().catch(error => console.error('Automatic ATIS startup failed:', error));
}, 2500);

wss.on('close', () => {
  clearInterval(heartbeatTimer);
  clearInterval(autoAtisTimer);
  for (const station of [...autoAtisBroadcasts.keys()]) stopAutoAtis(station, 'server shutdown');
});

server.listen(config.port, config.host, () => {
  const protocol = config.tls.cert && config.tls.key ? 'wss' : 'ws';
  console.log(`VFN voice service listening on ${protocol}://${config.host}:${config.port}`);
});

function cryptoRandomId() {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}
