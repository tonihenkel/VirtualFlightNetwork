require('dotenv').config();

const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const net = require('net');
const dns = require('dns').promises;
const { spawn } = require('child_process');
const mysql = require('mysql2/promise');
const WebSocket = require('ws');

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
// Match the plugin's 2048-sample capture buffers. Tiny 20 ms frames caused
// older X-Plane clients to spend most of their receive loop synchronously
// opening/playing JSON-wrapped waveOut packets, producing gaps and backlog.
const testBroadcastFrameBytes = 2048 * 2;

fs.mkdirSync(config.testAudioDirectory, { recursive: true });

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

  if ([5, 10, 25, 50].includes(Number(overrideRangeNm))) {
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
    client.ptt = client.spectator ? false : payload.ptt;
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
    return receiver.opPermission >= 1 &&
      (receiver.monitorFrequency === frequency || receiver.monitorGlobal);
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

function handleMonitor(client, payload) {
  if (client.opPermission < 1) {
    send(client.ws, {
      type: 'error',
      message: 'No permission for voice monitoring.'
    });
    return;
  }

  client.monitor = true;
  client.monitorGlobal = payload.global === true;
  client.monitorFrequency = normalizeFrequency(payload.frequency);

  send(client.ws, {
    type: 'monitor',
    success: true,
    frequency: client.monitorFrequency,
    global: client.monitorGlobal
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
    monitorFrequency: null,
    monitorGlobal: false,
    messageQueue: Promise.resolve(),
    audioPacketsReceived: 0,
    audioPacketsForwarded: 0,
    lastAudioAt: null,
    lastPttAt: null
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
        client.ptt = client.spectator ? false : payload.active === true;
        client.lastPttAt = new Date().toISOString();
        client.txCom = Number(payload.txCom || client.txCom) === 2 ? 2 : 1;
        send(ws, {
          type: 'tx',
          active: client.ptt,
          frequency: normalizeFrequency(payload.frequency) || (client.txCom === 2 ? client.com2 : client.com1)
        });
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

        // Every plugin audio frame carries its current PTT state as well.
        // This makes transmission recover automatically if a standalone PTT
        // control packet was lost during connect/reconnect.
        if (payload.ptt === true) {
          client.ptt = true;
          client.txCom =
            Number(payload.txCom || client.txCom) === 2 ? 2 : 1;
        }

        if (client.ptt) {
          forwardAudio(client, payload);
        }
        return;
      }

      if (payload.type === 'monitor') {
        handleMonitor(client, payload);
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
      send(ws, { type: 'error', message: 'Voice server message error.' });
      ws.close();
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

wss.on('close', () => {
  clearInterval(heartbeatTimer);
});

server.listen(config.port, config.host, () => {
  const protocol = config.tls.cert && config.tls.key ? 'wss' : 'ws';
  console.log(`VFN voice service listening on ${protocol}://${config.host}:${config.port}`);
});

function cryptoRandomId() {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}
