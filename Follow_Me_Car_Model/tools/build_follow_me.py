import math
import os
import sys

import bpy
from mathutils import Vector


ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SOURCE_FBX = os.path.join(ROOT, "source", "FINAL_MODEL.fbx")
BUILD_DIR = os.path.join(ROOT, "build")
TEXTURE_DIR = os.path.join(BUILD_DIR, "textures")


def material(name, color, metallic=0.0, roughness=0.45, emission=None, strength=0.0):
    mat = bpy.data.materials.get(name) or bpy.data.materials.new(name)
    mat.use_nodes = True
    bsdf = mat.node_tree.nodes.get("Principled BSDF")
    bsdf.inputs["Base Color"].default_value = (*color, 1.0)
    bsdf.inputs["Metallic"].default_value = metallic
    bsdf.inputs["Roughness"].default_value = roughness
    if emission is not None:
        bsdf.inputs["Emission Color"].default_value = (*emission, 1.0)
        bsdf.inputs["Emission Strength"].default_value = strength
    return mat


def cube(name, location, scale, mat, bevel=0.03, rotation=(0.0, 0.0, 0.0)):
    bpy.ops.mesh.primitive_cube_add(location=location, rotation=rotation)
    obj = bpy.context.object
    obj.name = name
    obj.scale = scale
    bpy.ops.object.transform_apply(location=False, rotation=False, scale=True)
    if bevel:
        modifier = obj.modifiers.new("Edge softening", "BEVEL")
        modifier.width = bevel
        modifier.segments = 3
    obj.data.materials.append(mat)
    return obj


def cylinder(name, location, radius, depth, mat, rotation=(0.0, 0.0, 0.0), vertices=48):
    bpy.ops.mesh.primitive_cylinder_add(
        vertices=vertices, radius=radius, depth=depth, location=location, rotation=rotation
    )
    obj = bpy.context.object
    obj.name = name
    obj.data.materials.append(mat)
    bevel = obj.modifiers.new("Edge softening", "BEVEL")
    bevel.width = min(radius, depth) * 0.12
    bevel.segments = 3
    return obj


def text_object(name, body, location, size, mat, rotation, align="CENTER", extrude=0.008):
    bpy.ops.object.text_add(location=location, rotation=rotation)
    obj = bpy.context.object
    obj.name = name
    obj.data.body = body
    obj.data.align_x = align
    obj.data.align_y = "CENTER"
    obj.data.size = size
    obj.data.extrude = extrude
    obj.data.bevel_depth = 0.002
    obj.data.materials.append(mat)
    return obj


os.makedirs(BUILD_DIR, exist_ok=True)
os.makedirs(TEXTURE_DIR, exist_ok=True)
bpy.ops.wm.read_factory_settings(use_empty=True)
bpy.ops.import_scene.fbx(filepath=SOURCE_FBX, use_image_search=True)

# The source FBX is authored at 1/1000 scale. A factor of 90 yields the real
# Amarok length of roughly 5.22 m while preserving the model's proportions.
for obj in bpy.context.scene.objects:
    if obj.parent is None:
        obj.scale *= 90.0

# Airport-yellow body paint. Keep all detailed glass, lamps, tyres and interior
# textures from the source asset.
airport_yellow = material("VFN Airport Yellow", (1.0, 0.58, 0.015), metallic=0.08, roughness=0.27)
for paint_name in ("phong5",):
    paint = bpy.data.materials.get(paint_name)
    if paint and paint.use_nodes:
        bsdf = paint.node_tree.nodes.get("Principled BSDF")
        for link in list(paint.node_tree.links):
            if link.to_node == bsdf and link.to_socket == bsdf.inputs["Base Color"]:
                paint.node_tree.links.remove(link)
        bsdf.inputs["Base Color"].default_value = (1.0, 0.58, 0.015, 1.0)
        bsdf.inputs["Metallic"].default_value = 0.08
        bsdf.inputs["Roughness"].default_value = 0.27

black = material("VFN Safety Black", (0.012, 0.016, 0.018), metallic=0.15, roughness=0.3)
dark_metal = material("VFN Powder-coated Frame", (0.025, 0.032, 0.035), metallic=0.75, roughness=0.28)
white = material("VFN White Marking", (0.92, 0.96, 1.0), roughness=0.32)
amber = material(
    "VFN Amber Lens", (1.0, 0.2, 0.0), roughness=0.2, emission=(1.0, 0.13, 0.0), strength=7.0
)
led_yellow = material(
    "VFN LED Yellow", (1.0, 0.62, 0.01), roughness=0.22, emission=(1.0, 0.28, 0.0), strength=11.0
)
red = material(
    "VFN Rear Red", (0.55, 0.01, 0.005), roughness=0.25, emission=(1.0, 0.005, 0.0), strength=4.0
)

# High-visibility checkerboard belt on both sides.
columns = 11
for side in (-1, 1):
    x = side * 1.035
    for row, z in enumerate((0.66, 0.93)):
        for col in range(columns):
            y = -1.88 + col * 0.37
            mat = black if (row + col) % 2 == 0 else airport_yellow
            cube(
                f"Livery_{'L' if side < 0 else 'R'}_{row}_{col}",
                (x, y, z),
                (0.012, 0.185, 0.135),
                mat,
                bevel=0.006,
            )

# Rear chevron panel for excellent visibility from the cockpit.
cube("Rear guidance panel", (0.0, 2.155, 0.64), (0.78, 0.018, 0.34), airport_yellow, bevel=0.035)
for index, x in enumerate((-0.58, -0.29, 0.0, 0.29, 0.58)):
    cube(
        f"Rear chevron {index + 1}",
        (x, 2.178, 0.64),
        (0.085, 0.012, 0.36),
        black,
        bevel=0.008,
        rotation=(0.0, math.radians(32), 0.0),
    )

# Rigid powder-coated rack and illuminated pilot guidance display.
for x in (-0.78, 0.78):
    cylinder(f"Sign upright {x}", (x, 1.10, 1.78), 0.035, 0.78, dark_metal)
cube("Sign lower crossbar", (0.0, 1.10, 1.51), (0.86, 0.045, 0.04), dark_metal, bevel=0.025)
cube("FOLLOW ME display housing", (0.0, 1.10, 2.08), (0.91, 0.115, 0.35), dark_metal, bevel=0.075)
cube("FOLLOW ME rear LED field", (0.0, 1.225, 2.08), (0.83, 0.012, 0.27), black, bevel=0.045)
cube("FOLLOW ME front LED field", (0.0, 0.975, 2.08), (0.83, 0.012, 0.27), black, bevel=0.045)
text_object(
    "FOLLOW ME rear text",
    "FOLLOW ME",
    (0.0, 1.242, 2.08),
    0.255,
    led_yellow,
    (math.radians(90), 0.0, 0.0),
    extrude=0.004,
)
text_object(
    "FOLLOW ME front text",
    "FOLLOW ME",
    (0.0, 0.958, 2.08),
    0.255,
    led_yellow,
    (math.radians(-90), 0.0, math.radians(180)),
    extrude=0.004,
)

# Dual ECE-style amber beacons, compact side marker lamps and antenna.
for x in (-0.76, 0.76):
    cylinder(f"Beacon base {x}", (x, 1.10, 2.48), 0.14, 0.055, black)
    cylinder(f"Amber beacon {x}", (x, 1.10, 2.59), 0.115, 0.18, amber)
    cylinder(f"Beacon cap {x}", (x, 1.10, 2.69), 0.09, 0.025, black)
for x in (-0.94, 0.94):
    cube(f"Amber side marker {x}", (x, 1.10, 2.08), (0.035, 0.135, 0.09), amber, bevel=0.025)
cylinder("Radio antenna base", (0.0, 0.92, 2.49), 0.055, 0.07, black)
cylinder(
    "Radio antenna",
    (0.0, 0.92, 2.85),
    0.012,
    0.72,
    dark_metal,
)

# VFN identity and unit numbering on both doors and display frame.
for side in (-1, 1):
    text_object(
        f"VFN side logo {'L' if side < 0 else 'R'}",
        "VFN",
        (side * 1.058, -0.58, 1.20),
        0.28,
        white,
        (math.radians(90), 0.0, math.radians(90 if side > 0 else -90)),
        extrude=0.004,
    )
    text_object(
        f"Unit number {'L' if side < 0 else 'R'}",
        "01",
        (side * 1.06, -0.16, 1.18),
        0.18,
        black,
        (math.radians(90), 0.0, math.radians(90 if side > 0 else -90)),
        extrude=0.004,
    )
text_object(
    "VFN rear identity",
    "VFN 01",
    (0.0, 2.205, 1.18),
    0.18,
    white,
    (math.radians(90), 0.0, 0.0),
    extrude=0.004,
)

# Small work lamps under the guidance board.
for x in (-0.48, 0.48):
    cube(f"Rear work lamp {x}", (x, 1.235, 1.62), (0.12, 0.025, 0.07), white, bevel=0.025)
    cube(f"Rear red marker {x}", (x, 1.24, 1.48), (0.08, 0.025, 0.035), red, bevel=0.018)

# Ground plane, studio lighting and camera for a reproducible preview.
ground = material("Preview Ground", (0.08, 0.09, 0.095), roughness=0.82)
cube("Preview ground", (0.0, 0.0, -0.87), (10.0, 10.0, 0.03), ground, bevel=0.0)

bpy.ops.object.light_add(type="AREA", location=(4.0, -4.0, 7.0))
key = bpy.context.object
key.name = "Key light"
key.data.energy = 1500
key.data.shape = "DISK"
key.data.size = 5.0
bpy.ops.object.light_add(type="AREA", location=(-4.0, 1.0, 4.0))
fill = bpy.context.object
fill.name = "Fill light"
fill.data.energy = 950
fill.data.size = 4.0
bpy.ops.object.light_add(type="AREA", location=(0.0, 5.0, 5.0))
rim = bpy.context.object
rim.name = "Rim light"
rim.data.energy = 1200
rim.data.size = 3.0

bpy.ops.object.camera_add(location=(6.9, 7.6, 4.4))
camera = bpy.context.object
camera.name = "Preview camera"
direction = Vector((0.0, 0.15, 0.85)) - camera.location
camera.rotation_euler = direction.to_track_quat("-Z", "Y").to_euler()
camera.data.lens = 58
bpy.context.scene.camera = camera

scene = bpy.context.scene
scene.render.engine = "BLENDER_WORKBENCH"
scene.render.resolution_x = 1280
scene.render.resolution_y = 720
scene.render.resolution_percentage = 100
scene.render.image_settings.file_format = "PNG"
scene.render.filepath = os.path.join(BUILD_DIR, "VFN_Follow_Me_Amarok_preview.png")
scene.render.film_transparent = False
if scene.world is None:
    scene.world = bpy.data.worlds.new("VFN Preview World")
scene.world.color = (0.025, 0.03, 0.04)
scene.view_settings.look = "AgX - Medium High Contrast"

# Save a fully editable master first, then interchange formats.
bpy.ops.wm.save_as_mainfile(filepath=os.path.join(BUILD_DIR, "VFN_Follow_Me_Amarok.blend"))

# Exclude preview-only objects from exported vehicle files.
for obj in (camera, key, fill, rim, bpy.data.objects.get("Preview ground")):
    if obj:
        obj.hide_viewport = True
        obj.hide_render = True
        obj.select_set(False)
for obj in bpy.context.scene.objects:
    if not obj.hide_viewport:
        obj.select_set(True)
bpy.context.view_layer.objects.active = next(
    obj for obj in bpy.context.selected_objects if obj.type == "MESH"
)

bpy.ops.export_scene.fbx(
    filepath=os.path.join(BUILD_DIR, "VFN_Follow_Me_Amarok.fbx"),
    use_selection=True,
    path_mode="COPY",
    embed_textures=False,
    apply_scale_options="FBX_SCALE_ALL",
)
bpy.ops.export_scene.gltf(
    filepath=os.path.join(BUILD_DIR, "VFN_Follow_Me_Amarok.glb"),
    export_format="GLB",
    use_selection=True,
)

# Preview rendering is opt-in because some headless Windows hosts do not expose
# a working OpenGL context. Run with VFN_RENDER_PREVIEW=1 on a graphics desktop.
if os.environ.get("VFN_RENDER_PREVIEW") == "1":
    bpy.ops.render.render(write_still=True)

print("Created VFN Follow Me Amarok assets in", BUILD_DIR)
