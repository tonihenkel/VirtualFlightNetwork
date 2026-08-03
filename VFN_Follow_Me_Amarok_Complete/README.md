# VFN Follow-Me Amarok

This directory contains a realistic VFN airport guidance vehicle derived from
the supplied Volkswagen Amarok FBX.

## Deliverables

- `build/VFN_Follow_Me_Amarok.blend` — editable master scene
- `build/VFN_Follow_Me_Amarok.fbx` — interchange export with copied textures
- `build/VFN_Follow_Me_Amarok.glb` — self-contained real-time preview/export
- `build/export_report.json` — validation report for the exported FBX
- `tools/build_follow_me.py` — reproducible Blender build script

The exported vehicle is 5.224 m long. Its 3.21 m maximum height includes the
radio antenna. The visible body, interior, lights, wheels and engine retain the
detail of the source model. Added VFN equipment includes:

- yellow airport paint and black/yellow checkerboard side markings
- illuminated double-sided `FOLLOW ME` display
- powder-coated support rack
- dual amber rotating-beacon housings
- work lights, rear marker lamps and radio antenna
- high-visibility rear chevrons
- VFN identity, unit number `01` and door markings

## Rebuild

Run Blender in background mode:

```powershell
blender.exe --background --python tools/build_follow_me.py
```

Set `VFN_RENDER_PREVIEW=1` to render the configured preview on a workstation
with a working graphics context.

## X-Plane / XPMP2 integration

XPMP2 loads CSL aircraft in X-Plane OBJ8 format. FBX and GLB are intentionally
kept as the high-detail source outputs; they are not renamed to `.obj`, because
that would not produce a valid X-Plane object. A final CSL release therefore
requires an OBJ8 export pass (normally with the XPlane2Blender exporter), LOD
creation and a matching `xsb_aircraft.txt` entry for the network's chosen
vehicle type identifier.

## Source credit

The supplied texture atlas identifies the source as:

`Volkswagen Amarok V6 — Yellow1441 (Yelkant Modacı)`

Keep this attribution with redistributed derivatives and verify the original
asset's distribution terms before publishing the model outside the VFN
project.
