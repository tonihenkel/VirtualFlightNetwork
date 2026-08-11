# OpenStreetMap airport enrichments

These extracts supplement airport layouts where the X-Plane Scenery Gateway
contains incomplete taxiway names or parking positions. Existing Gateway
geometry is retained; only verifiable OSM `aeroway=taxiway` and
`aeroway=parking_position` data is added.

Run an enrichment again with:

```text
php scripts/enrich_airport_layout_from_osm.php data-sources/osm-airports/ETAR.osm ETAR htdocs/data/airport_layouts/ETAR.json
```

## Included extracts

- `ETAR.osm`: OpenStreetMap API 0.6 map extract, bounding box
  `7.56,49.42,7.64,49.46`, downloaded 2026-08-10. OpenStreetMap data is
  available under ODbL; attribution: OpenStreetMap contributors.

