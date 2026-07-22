# VFN Voice Service

Realtime voice routing for the VFN pilot client.

The PHP backend stays responsible for login, sessions, permissions, positions and the website.
This service is only responsible for realtime voice transport and frequency/range routing.

## Start

```powershell
cd voice-service
npm install
copy .env.example .env
npm start
```

## Client Protocol

All messages are JSON over WebSocket, except encoded audio packets which are still JSON envelopes with a base64 payload for the first implementation phase.

### Authenticate

```json
{
  "type": "hello",
  "token": "session-token",
  "callsign": "DLH123",
  "com1": "122.800",
  "com2": "118.300",
  "txCom": 1,
  "latitude": 51.0,
  "longitude": 13.0
}
```

### State Update

```json
{
  "type": "state",
  "com1": "122.800",
  "com2": "118.300",
  "txCom": 1,
  "latitude": 51.0,
  "longitude": 13.0
}
```

### Push To Talk

```json
{
  "type": "ptt",
  "active": true,
  "frequency": "122.800",
  "txCom": 1
}
```

### Audio Packet

```json
{
  "type": "audio",
  "codec": "opus",
  "sequence": 1,
  "frequency": "122.800",
  "payload": "base64-opus-frame"
}
```

The server forwards valid packets to receivers on matching COM frequencies and inside range.

## Routing Rules

- UNICOM can be global while `UNICOM_GLOBAL=1`.
- Non-UNICOM voice is range-limited.
- The default range depends on the station suffix if present:
  - `_GND`: `RANGE_GND_NM`
  - `_TWR`: `RANGE_TWR_NM`
  - `_APP` / `_DEP`: `RANGE_APP_NM`
  - `_CTR` / `_FSS`: `RANGE_CTR_NM`
  - otherwise `RANGE_DEFAULT_NM`
- Admin monitor clients require `op_permission >= 1`.

## Next Plugin Phase

1. Add WebSocket client connection from the C++ plugin.
2. Add Opus encode/decode.
3. Add microphone capture and output device selection.
4. Map incoming audio to RX indicators and PTT audio to TX indicators.
