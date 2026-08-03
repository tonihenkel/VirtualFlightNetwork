import bpy
import json
import os
import sys
from mathutils import Vector


def arg_after_separator(index=0):
    argv = sys.argv
    if "--" not in argv:
        raise RuntimeError("Expected arguments after --")
    return argv[argv.index("--") + 1 + index]


fbx_path = os.path.abspath(arg_after_separator(0))
report_path = os.path.abspath(arg_after_separator(1))

bpy.ops.wm.read_factory_settings(use_empty=True)
bpy.ops.import_scene.fbx(filepath=fbx_path, use_image_search=True)

objects = list(bpy.context.scene.objects)
meshes = [obj for obj in objects if obj.type == "MESH"]

world_points = []
for obj in meshes:
    world_points.extend(obj.matrix_world @ Vector(corner) for corner in obj.bound_box)

minimum = [min(point[axis] for point in world_points) for axis in range(3)]
maximum = [max(point[axis] for point in world_points) for axis in range(3)]

report = {
    "fbx": fbx_path,
    "object_count": len(objects),
    "mesh_count": len(meshes),
    "vertex_count": sum(len(obj.data.vertices) for obj in meshes),
    "polygon_count": sum(len(obj.data.polygons) for obj in meshes),
    "bounds": {
        "min": minimum,
        "max": maximum,
        "dimensions": [maximum[i] - minimum[i] for i in range(3)],
    },
    "objects": [
        {
            "name": obj.name,
            "type": obj.type,
            "dimensions": list(obj.dimensions),
            "location": list(obj.location),
            "world_bounds": {
                "min": [
                    min((obj.matrix_world @ Vector(corner))[axis] for corner in obj.bound_box)
                    for axis in range(3)
                ],
                "max": [
                    max((obj.matrix_world @ Vector(corner))[axis] for corner in obj.bound_box)
                    for axis in range(3)
                ],
            }
            if obj.type == "MESH"
            else None,
            "materials": [
                slot.material.name if slot.material else None
                for slot in obj.material_slots
            ],
        }
        for obj in objects
    ],
    "materials": [
        {
            "name": material.name,
            "images": sorted(
                {
                    node.image.filepath
                    for node in material.node_tree.nodes
                    if node.type == "TEX_IMAGE" and node.image
                }
            )
            if material.use_nodes and material.node_tree
            else [],
        }
        for material in bpy.data.materials
    ],
}

os.makedirs(os.path.dirname(report_path), exist_ok=True)
with open(report_path, "w", encoding="utf-8") as report_file:
    json.dump(report, report_file, indent=2)

print(json.dumps({key: report[key] for key in ("object_count", "mesh_count", "vertex_count", "polygon_count", "bounds")}, indent=2))
