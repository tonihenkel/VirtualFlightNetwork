require('dotenv').config();

const http = require('http');
const mysql = require('mysql2/promise');
const WebSocket = require('ws');

const config = {
  host: process.env.VOICE_HOST || '0.0.0.0',
  port: Number(process.env.VOICE_PORT || 8090),
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
  const [rows] = await pool.execute(
    `SELECT
        s.user_id,
        UPPER(s.callsign) AS callsign,
        COALESCE(s.is_invisible, 0) AS is_invisible,
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

function getFrequencyRangeNm(frequency, station) {
  if (frequency === config.unicomFrequency && config.unicomGlobal) {
    return Infinity;
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

  const rangeNm = getFrequencyRangeNm(frequency, sender.station);

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
    return;
  }

  for (const receiver of clients.values()) {
    if (!canReceive(sender, receiver, frequency)) {
      continue;
    }

    send(receiver.ws, {
      type: 'audio',
      from: sender.callsign,
      frequency,
      codec: payload.codec || 'opus',
      sequence: Number(payload.sequence || 0),
      payload: payload.payload
    });

    send(receiver.ws, {
      type: 'rx',
      active: true,
      frequency,
      from: sender.callsign
    });
  }
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

const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      success: true,
      clients: clients.size
    }));
    return;
  }

  res.writeHead(404);
  res.end();
});

const wss = new WebSocket.Server({ server });

wss.on('connection', (ws) => {
  const id = cryptoRandomId();
  const client = {
    id,
    ws,
    authenticated: false,
    userId: null,
    callsign: '',
    opPermission: 0,
    com1: null,
    com2: null,
    txCom: 1,
    latitude: NaN,
    longitude: NaN,
    station: '',
    monitor: false,
    monitorFrequency: null,
    monitorGlobal: false
  };

  clients.set(id, client);

  ws.on('message', async (raw) => {
    const payload = parseJsonMessage(raw);

    if (!payload || typeof payload.type !== 'string') {
      send(ws, { type: 'error', message: 'Invalid message.' });
      return;
    }

    if (!client.authenticated) {
      if (payload.type !== 'hello' || typeof payload.token !== 'string') {
        send(ws, { type: 'error', message: 'Authentication required.' });
        ws.close();
        return;
      }

      try {
        const session = await authenticate(payload.token);

        if (!session) {
          send(ws, { type: 'error', message: 'Invalid or expired session.' });
          ws.close();
          return;
        }

        client.authenticated = true;
        client.userId = Number(session.user_id);
        client.callsign = String(session.callsign || payload.callsign || '').toUpperCase();
        client.opPermission = Number(session.op_permission || 0);
        updateClientState(client, payload);

        send(ws, {
          type: 'hello',
          success: true,
          callsign: client.callsign,
          opPermission: client.opPermission
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
      client.ptt = payload.active === true;
      client.txCom = Number(payload.txCom || client.txCom) === 2 ? 2 : 1;
      send(ws, {
        type: 'tx',
        active: client.ptt,
        frequency: normalizeFrequency(payload.frequency) || (client.txCom === 2 ? client.com2 : client.com1)
      });
      return;
    }

    if (payload.type === 'audio') {
      if (client.ptt) {
        forwardAudio(client, payload);
      }
      return;
    }

    if (payload.type === 'monitor') {
      handleMonitor(client, payload);
      return;
    }
  });

  ws.on('close', () => {
    clients.delete(id);
  });
});

server.listen(config.port, config.host, () => {
  console.log(`VFN voice service listening on ${config.host}:${config.port}`);
});

function cryptoRandomId() {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}
