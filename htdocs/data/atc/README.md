# FIR/UIR boundary data

`fir-boundaries.geojson` was downloaded on 2026-07-29 from the
[VAT-Spy Client Data Update Project](https://github.com/vatsimnetwork/vatspy-data-project).

Source project: VATSIM Network contributors  
Source files: `Boundaries.geojson` and `VATSpy.dat`  
License: [Creative Commons Attribution-ShareAlike 4.0 International](https://creativecommons.org/licenses/by-sa/4.0/)

The file is stored locally for reliable and fast map loading. It has not been
geometrically modified by Virtual Flight Network. Display styling and the
interactive Leaflet presentation are VFN additions.
`VATSpy.dat` supplies the human-readable FIR and UIR names through
`execute/fir_names.php`; the browser combines them with the geometries.

## Detailed controller sectors

`sector-boundaries.ndjson` and `sector-boundaries.index.json` are generated
from the [VATGlasses Data Project](https://github.com/lennycolton/vatglasses-data)
using `scripts/build_vatglasses_sectors.js`. The source and the generated,
adapted dataset are licensed under
[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/).
They may only be used for non-commercial purposes and adaptations must remain
under the same license. VFN loads an exact active sector by indexed byte range;
the complete multi-megabyte dataset is never sent to every map visitor.

The source describes three-dimensional airspace. Every generated feature keeps
its `min_fl` and `max_fl` properties. The current two-dimensional map renders
the union of all altitude slices assigned to the selected primary controller;
it does not pretend that a single slice covers every altitude.

Import snapshot 2026-08-06 (source commit
`281e4f9a119c09eaf279e374f435447dc8930374`): 141 regional source files,
1,271 indexed CTR/FSS positions, 4,927 rendered GeoJSON features and 4,363
source altitude segments. All 223,405 generated coordinate pairs, closed rings,
height ranges and byte-index records passed the compiler validation. Of 1,169
VATSpy aliases, 174 currently have a directly matching detailed position; the
remaining aliases deliberately retain the visibly marked FIR/UIR fallback.
VATGlasses also supplies many detailed positions which are not aliases in
VATSpy and are now directly searchable in the ATC position selector.

`tracon-boundaries.geojson` is generated from the
[SimAware TRACON Project](https://github.com/vatsimnetwork/simaware-tracon-project)
and remains licensed under CC BY-SA 4.0.
