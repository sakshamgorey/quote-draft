# Product matching research — 26 October 2026

Research only: no application code, dependencies, or product records changed. PostgreSQL inspection used read-only transactions. Recommendations below are a proposed matching design, not implemented behavior or a certification of any product.

## Main recommendation

Keep a shared set of product identifiers and dimensions, then add attributes specific to each product family. Prioritize attributes that distinguish compatible products: installation dimensions, subtype, rating context, and required configuration. A longer list of unstructured features alone will not solve these problems.

The catalog side needs the same attributes as the request side. Extracting a ceiling height or fire duration is useful only when the matcher can compare it with verified product specifications.

## What exists today

`app/Ai/Agents/QuoteExtractor.php` extracts product type, quantity, a two-dimensional opening size, fire-rated and insulated booleans, application, surface, material, flange, PSF load, pounds capacity, brand, model, features, and requested options. Values carry exact supporting quotations, which is worth preserving.

`app/Services/Drafts/ProductMatcher.php` filters by availability, type, size in either orientation, and fire-rated boolean. `ProductFit.php` evaluates other details using tags, selected page data, and options. Features are affirmative text matches or unknown, rather than verified numeric constraints.

Specific limitations:

- One size means width and height only. It cannot represent a diameter, box depth, ladder height, or the distinction between nominal size, rough opening, clear opening, and outside curb.
- One PSF value cannot represent downward live load and wind uplift separately. Its matcher currently reads only exact numeric PSF purpose tags, not page load specifications.
- `load_lbs` exists already. Ladder capacity matching reads names and selected spec labels, but does not capture ceiling-height compatibility.
- Fire rating is a boolean. It loses duration, test standard, assembly, orientation, and size restrictions.
- “Fire rated if possible” is explicitly extracted as true and then used as a hard filter. A preference becomes a requirement.
- `stainless` is an example under options even though material also has its own field. Gasket and latch concepts also overlap features/options. Define the requested property first; determine later whether the catalog provides it as standard or as an option.
- Existing feature aliases combine watertight, waterproof, and floodtight. These should retain their specific performance meaning and test conditions.
- Missing page insulation is sometimes treated as false. For new attributes, keep unstated, explicitly absent, and contradictory evidence separate.
- The existing `type_of_access` column mostly contains broad category names such as “Roof Hatches”; it is not currently a ladder/ship-stair/service-stair classification.

## Catalog evidence

Counts describe the database inspected on this date, including accessories and bundles under their current categories. Page presence does not establish that the specifications are complete or correct.

| Current product family | Products | Stored pages | Pages with a nonempty spec list |
|---|---:|---:|---:|
| Access door | 2,195 | 1,837 | 1,794 |
| Floor door | 315 | 232 | 227 |
| Smoke vent | 270 | 135 | 127 |
| Roof hatch | 224 | 159 | 152 |
| Skylight | 209 | 191 | 191 |
| Door louver / window kit | 152 | 152 | 152 |
| Ladder | 75 | 74 | 74 |
| Valve box | 34 | 26 | 26 |
| Vent cover | 29 | 29 | 29 |
| Safety rail | 14 | 14 | 14 |
| **Total** | **3,517** | **2,849** | **2,786** |

There are 668 products without stored pages. Raw specification labels vary considerably: the database has 847 distinct family/label combinations. Normalizing existing evidence is an immediate opportunity.

## 1. Ladders

**Add first:** ladder subtype; floor-to-ceiling or floor-to-landing height; supported minimum/maximum height on the catalog side; fixed ladder length where applicable; rough opening with dimension basis; capacity with units and rating basis; fire duration and standard; thermal R-value with rated component.

**Add next:** swing clearance, landing/projection space, folded clearance, ceiling/frame depth, operation (folding/scissor/fixed/electric), mounting/standoff, handrail provision, and compatible extension/step model.

Height is a range check: a 10 ft 8 in ceiling must fall within the model's supported range. “Under 10 ft” is an upper bound, not an exact height. A model reaching that height only with additional steps is a conditional match requiring those steps. Keep user load capacity separate from ladder shipping weight or a component's test load.

The local catalog already contains 48 pages with the exact label “Load Capacity”, 18 with “Ceiling Height”, and 43 with “Rough Opening”. It also includes ladder accessories such as BA-LXH handrails and BA-LXS feet; these need an accessory role, not just the ladder category.

[FAKRO’s selection guide](https://www.fakrousa.com/products/attic-ladders/) identifies opening, ceiling-height range, insulation, and capacity as selection factors. [Werner’s dimension guide](https://www.wernerco.com/us/products/featured-products/attic-ladders/know-your-dimensions) separately identifies swing clearance and landing space.

Example request: “30 × 54 rough opening, 10 ft 8 in ceiling, at least 350 lb, folding wood ladder.” Opening, installation height, capacity, mechanism, and material should each survive separately.

## 2. Roof hatches

**Add first:** access method (ladder, ship stair, service stair, equipment); curb height; curb/opening dimension basis; downward live load in PSF; wind-uplift resistance in PSF; thermally broken construction; single/double leaf.

**Add next:** curb and cover materials separately, insulation performance and component, hinge side, opening angle, manual/electric operation, actuator voltage, retrofit-curb compatibility, glazing/daylighting, and model-specific approval identifiers when requested.

Manufacturer data uses **PSF**, pounds per square foot, for distributed loading. Preserve ambiguous “LPF” wording and request clarification rather than silently converting it to PSF. A linear load, distributed area load, total capacity, and product weight are different measurements.

[BILCO Type S](https://www.bilco.com/ProductDetail/Type_20S_20Roof_20Hatch-Ladder_20Access-69) illustrates distinct live-load, curb, opening-angle, and material specifications. Its [thermally broken range](https://www.bilco.com/ProductDetail/Thermally_20Broken_20Roof_20Hatch-78/) also shows why insulation claims must identify the component: a glazed-cover variant does not share every opaque-cover thermal property.

The database has 72 roof-hatch pages with a “Curb” label and 23 with load-labeled specifications containing PSF or square-foot wording. An exploratory text search found load wording in 96 roof-hatch pages, versus only 15 products with PSF-like purpose tags. Text hits are enrichment candidates, not verified load ratings.

## 3. Floor doors

**Add first:** traffic/loading class; uniform live load PSF; wheel/point load separately; frame type; cast-in-place versus retrofit mounting; recess/infill depth; intended finish; single/double leaf; drainage provision; required water-resistance performance.

**Add next:** flood head or pressure and test basis, clear opening, lift assistance, hold-open, non-slip surface, odor/gas sealing, and fire duration/assembly where applicable.

H20 is a traffic loading designation, not a synonym for a PSF rating. [Acudor FC-H20](https://www.acudor.com/products/floor-doors/fc-h20) specifies wheel loading and installation/use conditions, and distinguishes drainage from watertightness. Do not turn “H20” into a generic pounds capacity or infer flood resistance from a channel drain.

The catalog already has H20, 300-PSF, recessed-finish, and flood-resistant families. Recess choices include 1/8, 1/4, 3/8, 1/2, 5/8, 11/16, and 3/4 inch across stored options. These are useful numeric configuration constraints.

Example: “36 × 36, occasional vehicle traffic, H20, cast into concrete, drained channel frame.” The H20 designation and drainage requirement must not disappear into general feature text.

## 4. Access doors and panels

**Add first:** fire duration in minutes; test/listing standard and jurisdiction when stated; installation orientation and assembly; nominal versus rough opening; drywall/infill thickness or recess depth; latch/lock function; panel subtype.

**Add next:** STC and OITC separately; temperature-rise requirement with duration and unit; pressure/leakage performance; removable versus hinged panel; hinge side; inside release/self-closing; stainless grade; finish; panel/frame gauge separately; security or anti-ligature requirements.

Subtype should distinguish general access, drywall inlay, tile-recessed, exterior, duct, grease-duct, chute, and security products where the catalog supports them. For duct doors, also capture duct construction, diameter for round ducts, operating temperature, and pressure units such as inches water gauge.

[Acudor FW-5050](https://www.acudor.com/products/fire-rated-access-doors/fw-5050) has different wall and ceiling ratings, ceiling-construction conditions, and maximum rated sizes. It also distinguishes nominal size from installation opening. A single boolean cannot establish that a requested assembly is covered. [FW-5050-ACF](https://www.acudor.com/products/fire-rated-access-doors/fw-5050-acf) separately publishes STC/OITC and vertical-only installation. [RD-5090](https://www.acudor.com/products/duct-access-doors/rd-5090) is specifically for round ducts.

Existing evidence includes 216 exact “Fire Rating” labels, 23 “Acoustic Rating” labels, 271 “Rough Opening” labels, and 67 drywall-size option groups. Counts omit synonymous labels. Twenty-nine products have wall-rating options distinguishing UL and ULC configurations; rating must follow the selected configuration.

## 5. Smoke vents

**Add first:** opening dimensions and basis; leaf count; listing/approval; activation method; fusible-link temperature; downward load; uplift resistance; opening-under-load capability; curb height.

**Add next:** alarm/electric-release interface and voltage, manual release, reset method, net/aerodynamic vent area when explicitly documented, opening angle, opaque/daylighting cover, thermal break, STC, OITC, and rain-noise metric.

[BILCO’s thermally broken smoke vent](https://www.bilco.com/ProductDetail/Thermally-Broken-Automatic-Smoke-Vents-198) distinguishes the load resisting opening from the load against which the vent can actually open, and names the fusible link and listing. These must not compete for one `load_psf` field. [The ACDSV range](https://www.bilco.com/ProductDetail/Type-ACDSV-Automatic-Smoke-Vent-191) documents separate acoustic metrics.

Only 135 of 270 smoke-vent products have stored pages. Recover missing evidence before interpreting absent technical values as incompatibility. Existing pages include 107 “Curb” labels and 17 “Performance Ratings” labels.

Example: “UL-listed double-leaf smoke vent with 165°F fusible link, opens under 10 PSF snow load.” The temperature is an activation setting, not a fire-duration rating.

## 6. Skylights

**Add first:** fixed/venting/access/walkable subtype; deck/curb/self-flashed mounting; roof pitch; roofing material and compatible flashing; dimension basis; glazing construction; manual/electric/solar operation.

**Add next:** unit U-factor, SHGC, visible transmittance, glazing-only versus whole-unit thermal basis, impact/snow/walk-on rating, curb height, glass safety type, pane count, rain sensor, screen/shade inclusion, and compatible accessory model.

Roof pitch should be a numeric angle or rise/run with the original wording retained. Check the product's supported range. U-factor needs units and whether it is for glazing or the whole unit; SHGC is not an insulation R-value.

[VELUX FS data](https://pro.veluxusa.com/products/skylights/fs) separates rough opening from outside frame and lists pitch/flashing compatibility. The [VELUX size chart](https://info.veluxusa.com/hubfs/Residential/VELUX%20USA/VELUX%20USA%20Website/Images/Catalog%20and%20Brochures/brochure-residential-skylight.pdf) uses outside-curb measurements for curb-mounted units. These examples demonstrate dimension semantics; their pitch limits must not be generalized to other manufacturers or flat-roof models.

Your pages already have 93 “Roof Pitch”, 86 “Glazing”, and 30 “Glazing U-Value” labels. Flashing kits and other accessories also appear in the skylight category. Store their role and compatibility separately.

## 7. Safety rails

**Add first:** standalone rail, gate, safety post, or hatch-and-rail assembly; compatible hatch model; hatch/curb dimensions and basis; mounting interface; self-closing/latching gate; entry side.

**Add next:** rail height, guard layout, top-rail/midrail load performance with direction and test basis, material/finish, retrofit suitability, and requested approval/standard.

[BILCO Bil-Guard 2.0](https://www.bilco.com/ProductDetail/Bil-Guard%C2%AE-2~0-Roof-Hatch-Safety-Railing-System-8) illustrates model sizing, non-penetrating capflashing attachment, and self-closing/latching gate requirements. Compatibility is more meaningful than merely matching “steel” and two dimensions.

All 14 catalog entries have pages, but they comprise two different families: BA-CORHSR standalone compression railing and BA-STRHSR hatch with railing. Elsewhere, BA-AGRHSR is a gate classified as roof_hatch, and BA-MSTR is a railing classified as ladder. These are taxonomy-review candidates.

## 8. Valve boxes

**Add first:** width × height × depth; recessed/surface-mounted; box versus removable valve panel; viewing window; lock type; material and stainless grade.

**Add next:** enclosed/open back, pipe/conduit penetrations, clear internal space, flange and wall interface, labeling/engraving, and handle-hole size where applicable.

[Acudor ARVB](https://acudor.com/products/valve-boxes/arvb) explicitly distinguishes depth, open-back construction, vision panels, and special holes. Depth affects whether the valve fits; it should not depend on a fuzzy option-text match alone.

The database already has 4-, 6-, and 8-inch depths in names, plus explicit depth options for four products. BA-AFVP is a flush valve panel, whereas other families are actual boxes. Preserve that distinction.

Example: “12 × 12 × 6 recessed stainless valve box with window.” Six inches is the box depth, not quantity, opening height, or an unrelated accessory.

## 9. Vent covers

**Add first:** supply/return/exhaust function; round/rectangular shape; diameter or width/height; duct-opening versus outer-face dimensions; wall/ceiling/floor installation; removable/permanent; damper or diffuser provision.

**Add next:** free/open area with units, airflow and pressure-drop condition when documented, filter provision, finish, slot pattern, flooring thickness/inlay, and walking-load performance for floor products.

The store’s [round supply-cover family](https://www.bestaccessdoors.com/products/access-doors-panels/model-number/ba-ig-rs5/) confirms that circular sizing is a real catalog requirement. Its [permanent return cover](https://www.bestaccessdoors.com/24-x-6-invisagrille-flush-permanent-cold-air-return-cover/) lists open area. These are direct evidence for the catalog offerings; validate technical performance against their submittals before using it as a firm constraint.

BA-IG-RS4-04, BA-IG-RS5-05, and BA-IG-RS6-06 have null nominal width and height. The current extractor intentionally returns null for one dimension, and the matcher then asks for width × height. Add a diameter route on both sides rather than storing a 6-inch circle as a 6 × 6 rectangle.

## 10. Door louvers and vision-lite kits

**Add first:** split into door-louver and vision-lite subtypes; door thickness; cutout/order size; material/finish; fire duration and listing context.

**Louver-specific:** blade design, fixed versus fusible-link closing, free area, sightproof/lightproof behavior when specified, screen, activation temperature, and security/impact performance.

**Vision-lite-specific:** frame-only versus frame/glass/tape kit, glazing product/code, glass thickness, safety/fire/impact classification, glass size versus visible opening versus door cutout, and frame profile.

[Activar’s VSLH submittal](https://www.activarcpg.com/wp-content/uploads/Vision-Lite-VSLH-Submittal.pdf) distinguishes door and glazing thickness, order/cutout/glass/visible dimensions, and conditions for a rated assembly. [NGP L-1900](https://ngp.com/ngp/cache/file/1F85D2CB-75D3-4C1A-BF5D2CBF9D0348ED.pdf) demonstrates louver-specific free area, fusible-link closure, and door-thickness compatibility.

All 152 catalog entries have spec lists. They include 36 “Glazing Thickness”, 79 “Fire Rating”, and 65 “Frame Depth” labels. A glass vision lite should never qualify as a door louver merely because type, dimensions, finish, and fire boolean happen to match.

## Catalog conflicts to resolve before trusting stronger matching

1. **Likely ladder unit mismatch:** BA-LML-70-130 is stored as 70 × 130 inches, with similar 70 × 120 and 60 × 120 records. [FAKRO’s LML sheet](https://docs.gofakro.com/files/Product-structure/fakro/technical-specifications/loft-ladders-and-doors/LM/LML%20EN.pdf) lists those sizes in centimeters. This is strong evidence of a unit-label issue, but verify the exact regional product and supplier SKU before correcting it. Do not automatically import all other ratings from a regional sheet.
2. **Malformed opening text:** the stored BA-LWF-22.5-54 page says `5" x 54"` under Rough Opening while the SKU/title say 22.5 × 54. Flag the conflict; do not let the malformed field overwrite the product size.
3. **Incorrect glazing expansion:** a stored BA-VLLPB-PPF page expands PPF as “Polymer-Polymer Film”. Activar’s VSLH ordering table identifies PPF as Pyran Platinum F. Retain the actual product code and verified identity instead of propagating an invented expansion.
4. **Mixed products, accessories, and bundles:** ladders include feet/handrails; skylights include flashing; roof_hatch includes a standalone gate; safety_rail includes a complete hatch assembly. Add product role and explicit compatible-with relationships.
5. **Water-resistance wording:** store titles and manufacturer descriptions can differ. A channel drain does not establish a floodtight rating. Keep the source, model/configuration, and exact evidence instead of combining these words as interchangeable synonyms.

## Shared extraction semantics

Each important requested attribute should preserve:

- The normalized attribute and value, original unit, and exact quote.
- Comparison: exact, minimum, maximum, or range.
- Strength: required, preferred, explicitly excluded, or unresolved.
- Scope: which item, component, assembly, or selected option it refers to.
- Measurement basis: rough opening, nominal/order size, outside curb, usable clearance, or another named basis.

On the catalog side, retain evidence source, relevant SKU/configuration, rated component, test/standard where applicable, verification status, and conditions. Represent supported ranges and conditional configurations explicitly.

For example: “at least 400 lb” is a minimum capacity; “maximum U-factor 0.30” is an upper bound; “6 inch diameter” is a circular duct dimension; “12 inch curb” is a component height. Do not compare values solely because they share a unit.

Preserve unknowns. Missing evidence is not a confirmed match or a confirmed failure. Explicit contradictions and unmet required compatibility deserve a different treatment from preferred finish or decorative style. An exact SKU can identify a product without proving it satisfies all separately stated requirements.

Keep stable material names separate from grades and finishes. The current material vocabulary also omits materials present in the catalog, such as reinforced cement products and glazing materials; extend it selectively with component scope rather than guessing a generic material from an accessory.

## Suggested implementation order for a later task

1. **Correct identity and dimension semantics:** subtype, product/accessory/bundle role, diameter, depth, size basis, original units, and verified unit-conflict handling.
2. **Capture essential compatibility:** ladder height range, valve depth, door thickness, roof/skylight mounting and pitch, rated fire duration with assembly/standard, and distinct load types.
3. **Normalize existing page and option evidence:** especially ladder capacity/height, skylight pitch/glazing, access-panel fire ratings/infill, and roof load data. Fill missing pages where needed.
4. **Add richer performance:** R/U values with basis, STC/OITC, pressure/leakage, free area, smoke-vent controls, and specialized approvals.
5. **Evaluate complete request-to-match behavior:** correct extraction alone is insufficient. Include missing-data, conflicting-source, accessory, option-dependent, and wrong-unit cases.

## Checks performed and proposed evaluation cases

Two read-only application checks reproduced current gaps:

- A vent-cover request without two-dimensional size returns `needs_info` and asks for width × height; it has no diameter path.
- BA-TRH-36-36 has page text containing 40 PSF but no PSF purpose tag. `ProductFit` returns unknown for a 40-PSF requirement.

Five synthetic extraction probes were attempted with the configured primary Gemini model: ladder height/R-value, round vent diameter, valve depth, roof live/uplift loads, and required/preferred fire ratings. Every attempt returned `ProviderOverloadedException`. One combined probe using the configured fallback also returned `ProviderOverloadedException`. No successful model outputs or extraction-quality score are claimed.

For the next evaluation, use these acceptance cases:

| Request detail | Expected preservation and matching behavior |
|---|---|
| 30 × 54 ladder opening, 10 ft 8 in ceiling, at least 350 lb | Separate opening, installation height, and minimum user capacity; check supported height range |
| 6-inch round supply vent | Diameter and supply subtype; do not demand rectangular dimensions |
| 12 × 12 × 6 valve box | Third dimension is depth; compare standard/configurable depth |
| Roof hatch: 40 PSF live load, 90 PSF uplift | Keep two load types; compare each with the corresponding rating |
| Smoke vent opens under 10 PSF snow load | Keep operating-under-load capability distinct from static cover strength |
| 2-hour access panel for a specific ceiling assembly | Check duration, orientation, assembly and rated size; boolean alone is insufficient |
| Fire rated if possible | Preference; preserve non-rated alternatives appropriately |
| 24 × 24 door louver versus 24 × 24 vision lite | Different subtypes despite shared parent category |
| Vision frame for 1-3/4-inch door, 1/4-inch glazing | Separate door and glass thickness; verify configuration |
| Skylight measured outside curb | Compare to outside-curb dimensions rather than assuming rough opening |
| Ladder height reached only with extra steps | Conditional fit with exact required compatible accessory |
| Ladder shipping weight 90 lb; user capacity 350 lb | Never turn shipping weight into required carrying capacity |
| Floor door H20 with drainage | Preserve traffic designation and drainage; do not infer floodtightness |
| Supply handrail for an existing ladder | Accessory request with compatibility, not a new complete ladder |

This research covers all 10 configured product families and representative subtypes. It is not an individual certification or complete manual review of all 3,517 SKUs.
