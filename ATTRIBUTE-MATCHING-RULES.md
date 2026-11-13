# How product attributes should affect matching

Research supplement, 26 October 2026. No application changes or product-data writes. The comparisons below are proposed matching rules derived from manufacturer specifications and the current catalog. They assess individual attributes; passing one attribute does not establish that the complete product fits the request.

## Start with ladder weight limits

A ladder has a **rated carrying capacity**. A customer request such as “at least 350 lb capacity” supplies a **minimum acceptable rating**. Those are two different roles for the same quantity.

For that request, 300 lb fails; 350, 375, and 400 lb satisfy the capacity threshold if they describe the same kind of rated capacity. A 400 lb model does not automatically deserve a better overall match than a 350 lb model: both satisfy this requirement, and dimensions, height, material, fire protection, and requested configuration still matter.

“The person weighs 350 lb” is different. Clothing, tools, and materials carried onto the ladder contribute to the load. Preserve the stated person weight separately if total load is unknown; do not invent a universal tool allowance or increase the manufacturer's published rating using a presumed safety factor. Follow the manufacturer's boundary wording when assessing actual loading. [Werner duty-rating guidance](https://www.wernerco.com/docs/default-source/literature/ladder-safety-inspection-form.pdf?sfvrsn=ff6)

Likewise, “ladder weighs under 80 lb” is a maximum product-weight requirement, not an 80 lb carrying-capacity requirement. A rung/component test force, shipping weight, and overall working capacity must not share an undifferentiated pounds field.

Portable-ladder duty classes can help when explicitly stated, but should not be assigned to every attic or fixed ladder solely from its numeric rating. Use the classification and standard declared for the exact model.

## Concrete examples from this database

The following were read from stored product titles and specifications. They are catalog evidence, not newly independently verified ratings for every SKU.

| Product | Opening | Stored capacity | Stored thermal information | Stored fire information |
|---|---|---:|---|---|
| BA-LWP-30-54 | 30 × 54 in | 300 lb | Not included in this comparison | Non-rated |
| BA-LWP-L-30-54 | 30 × 54 in | 300 lb | Not included in this comparison | Non-rated |
| BA-LWT-30-54 | 30 × 54 in | 350 lb | R-12.5 | Non-rated |
| BA-LWF60-30-54 | 30 × 54 in | 375 lb | R-12 | 60 minutes in title |
| BA-LME-25-54 | 25 × 54 in | 400 lb | R-5.3 | Non-rated |

For “30 × 54 opening, capacity at least 350 lb, insulation at least R-12,” BA-LWT and BA-LWF60 satisfy these selected recorded attributes. BA-LME has the highest capacity but fails the requested opening and insulation threshold. BA-LWP and BA-LWP-L fail capacity.

Adding a required 60-minute fire rating rules out the recorded non-rated BA-LWT configuration. Adding a 10 ft 8 in ceiling then exposes a different problem: FAKRO's LWF60 sheet lists a maximum 10 ft 1 in room height for the 30 × 54 configuration. Capacity, insulation, and fire rating cannot compensate for that incompatibility. Confirm manufacturer-to-store SKU mapping before operational use. [FAKRO LWF60 technical specification](https://www.fakrousa.com/att/LOCAL/products/attic-ladders/lwf60/LWF60_technical_specification.pdf)

BA-LST-22.5-31.5 contains a different condition: its stored height wording is “Up to 10 feet 10 inches (with added steps).” That is an accessory-dependent capability, not the unmodified product's standard height. The manufacturer publishes different LST configurations by step count. [FAKRO LST dimensional sheet](https://www.fakrousa.com/att/LOCAL/fakro/LST-inch_cm.pdf)

## Five kinds of comparison

| Kind | Interpretation | Example |
|---|---|---|
| Minimum performance | Product rating must meet or exceed a stated minimum | At least 350 lb capacity |
| Maximum allowed value | Product value must not exceed the stated limit | U-factor no greater than 0.30 |
| Range compatibility | The installation condition must fall within the product's supported range | Actual ceiling height between supported minimum and maximum |
| Exact or compatible configuration | Same value, documented tolerance, or explicitly supported configuration | Door thickness, nominal duct diameter, actuator voltage |
| Conditional capability | The requirement is satisfied only with a named option, accessory, assembly, or mounting condition | Extended ladder height with compatible additional steps |

These rules are attribute-specific, not just unit-specific. Inches can mean an exact opening, a minimum usable depth, a maximum wall-cavity depth, or a ceiling height that must fall within a range.

## Apply the same reasoning to every family

### 1. Ladders

- **Capacity:** minimum rated carrying capacity; distinguish actual user/carried load from requested rating.
- **Ceiling/landing height:** actual height must fit the supported minimum and maximum for that size/configuration. A taller maximum alone does not establish fit for a very low ceiling.
- **Swing and landing space:** required product clearance must fit within available site clearance. Here a smaller required clearance can help.
- **Opening:** match the manufacturer's installation opening, not an unrelated outside-frame dimension.
- **Thermal/fire performance:** compare the correct rated component and test context.
- **Additional steps/extensions:** mark conditional and retain the exact compatible accessory.

[FAKRO's LTK sheet](https://www.fakrousa.com/att/LOCAL/architects/attic-ladders/ltk/LTK_technical_specification.pdf) demonstrates that the same family has opening-dependent height ranges as well as a separate maximum load.

### 2. Floor doors

- **Uniform live load:** a stated minimum PSF requirement can be met by an equal or higher documented uniform live-load rating, subject to the same size and installation basis.
- **Wheel/concentrated load:** separate from uniform PSF. A 300-PSF lid is not automatically suitable for a 300-lb wheel or point load, and multiplying PSF by total hatch area does not establish concentrated-load capacity.
- **H20:** preserve the loading designation and applicability. Do not reinterpret the number 20 as a pounds or PSF limit. [Acudor FC-H20](https://www.acudor.com/products/floor-doors/fc-h20) specifies wheel loading and the applicable use conditions.
- **Recess depth:** match the required flooring assembly thickness and available supported recess; a deeper recess is not automatically an acceptable substitute.
- **Flood performance:** compare documented water head/pressure and direction where available. A channel drain or weather gasket alone does not establish a flood rating.

### 3. Roof hatches

- **Downward live/snow load:** minimum capacity under the requested load definition.
- **Wind uplift:** minimum resistance to the specified upward pressure, with direction retained. Do not numerically compare signed downward pressure and uplift as if they were the same attribute.
- **Curb height:** exact, minimum, or maximum according to the request. A 12-inch replacement curb requirement is different from “at least 12 inches above the finished roof.” Preserve the height reference surface.
- **Access opening and hinge side:** geometric compatibility; rotating width and length may change the hinge/access arrangement.
- **Thermal break:** construction compatibility, not a substitute inferred from high R-value.

[BILCO Type S](https://www.bilco.com/ProductDetail/Type_20S_20Roof_20Hatch-Ladder_20Access-69) provides separate cover load, curb, and opening specifications. Their meanings should survive extraction independently.

### 4. Access doors and panels

- **Fire duration:** compare duration only after checking assembly, orientation, standard/listing, rated size, and configuration. A numerically longer rating for a wall does not satisfy a ceiling requirement.
- **Insulation R-value:** minimum thermal resistance, using the same unit convention and component/assembly basis. Higher R generally indicates more resistance to heat flow; panel-core and whole-assembly values cannot be substituted casually. [EIA R-value definition](https://www.eia.gov/tools/glossary/?id=R)
- **Acoustics:** compare STC with STC and OITC with OITC, normally against a stated minimum. Do not treat the two as interchangeable or equate either with arbitrary noise-level dB figures. [BILCO acoustic rating example](https://www.bilco.com/acdsv)
- **Duct pressure and leakage:** pressure capacity and maximum leakage form a paired specification. Leakage is meaningful at a specified pressure and test setup, not in isolation. Acudor lists both for [CD-5080-HP](https://www.acudor.com/products/duct-access-doors/cd-5080-hp).
- **Drywall/inlay thickness:** exact or supported range; 5/8-inch board is not automatically compatible with a 1/2-inch recess.

The [Acudor FW-5050](https://www.acudor.com/products/fire-rated-access-doors/fw-5050) illustrates why fire duration needs context: ceiling construction and size affect its rated use. A bare “fire rated” flag loses those distinctions.

### 5. Smoke vents

- **Opening-under-load capability:** minimum ability to open against the specified snow/wind load.
- **Closed-cover uplift resistance:** separate minimum resistance while the cover is closed.
- **Static downward cover rating:** yet another attribute, if documented.
- **Fusible-link temperature:** specified activation setting/approved configuration. A higher temperature is not a better match for a lower requested activation temperature.
- **Control voltage/interface:** compatibility, not “more is better.”

[BILCO's thermally broken smoke vent](https://www.bilco.com/ProductDetail/Thermally-Broken-Automatic-Smoke-Vents-198) documents 90 PSF closed-cover uplift resistance, opening against 10 PSF snow/wind load, and a 165°F fusible link. Those numbers describe three separate behaviors.

### 6. Skylights

- **U-factor:** for a stated maximum, lower or equal satisfies the numeric threshold when units and whole-unit/glazing basis match.
- **SHGC:** follow the specified maximum, minimum, or range. Lower means less admitted solar heat; it is not universally the desired outcome for every climate/project.
- **Visible transmittance:** minimum or range if specified; more light is not an automatic preference when glare/privacy constraints exist.
- **Roof pitch:** actual pitch must fall inside the supported range for both skylight and flashing.
- **Glazing/impact/walkable rating:** exact class or documented compatible capability; “tempered” alone does not establish every safety or loading requirement.

[ENERGY STAR explains the different rating directions](https://www.energystar.gov/independently-tested-and-certified-energy-performance). [VELUX FS specifications](https://pro.veluxusa.com/products/skylights/fs) tie installation to pitch and flashing, not solely size.

### 7. Safety rails

- **Load resistance:** minimum capacity with component, application point, and load direction retained. A whole-system claim cannot be inferred from one rail component.
- **Rail height:** required height or permitted range, not an unbounded preference for a taller rail.
- **Hatch/curb attachment:** verified compatibility with the exact mounting interface.
- **Gate function:** self-closing and self-latching are distinct requested functions; a chain should not be equated to such a gate by general “safety” wording.

[OSHA 1910.29](https://www.osha.gov/laws-regs/regulations/standardnumber/1910/1910.29) gives component- and direction-specific strength criteria. This supports retaining context rather than a generic pounds field; the research is not a site-specific compliance determination. [Bil-Guard](https://www.bilco.com/ProductDetail/Bil-Guard%C2%AE-2~0-Roof-Hatch-Safety-Railing-System-8) separately describes gate and attachment features.

### 8. Valve boxes

- **“6-inch-deep box”:** nominal dimension requirement, exact unless alternatives are allowed.
- **“Needs at least 6 inches clear depth”:** minimum usable internal depth, not nominal exterior depth.
- **“Wall cavity is only 6 inches deep”:** maximum installation envelope, accounting for the documented mounting geometry.
- **Valve clearance:** compare the needed three-dimensional space, not merely face opening.
- **Open back/window/penetrations:** exact construction or available configuration.

[Acudor ARVB](https://acudor.com/products/valve-boxes/arvb) identifies depth, enclosed/open-back construction, and penetrations as configurable characteristics. The comparison direction comes from the request's meaning.

### 9. Vent covers

- **Duct diameter/rectangular opening:** exact compatible size and shape. A 6-inch circle is not a 6 × 6 square.
- **Minimum open area:** compare actual open area for the specified size/configuration. Do not substitute face area or compare free-area percentages without their size basis.
- **Airflow:** evaluate the required airflow with the corresponding pressure-drop/performance data when available. Free area alone does not certify a requested CFM.
- **Pressure drop:** maximum allowed pressure loss at the requested airflow, not at an unrelated test point.
- **Supply/return/exhaust and damper:** functional compatibility.

[Greenheck's performance explanation](https://www.greenheck.com/resources/blog/louver-fundamentals--understanding-louver-performance-criteria) shows that free area and aerodynamic behavior both affect pressure drop. This is a general comparison principle, not a transfer of Greenheck ratings to this catalog's grilles.

### 10. Door louvers and vision-lite kits

- **Door thickness:** must fall within the frame's supported range or match its specified thickness.
- **Glazing thickness:** must fit the selected glazing pocket/frame. Thicker glass is not automatically compatible.
- **Cutout, glass, and visible opening:** keep separate dimensions. For some frames the required glass is smaller than the order/cutout size.
- **Fire rating:** requires compatible door, frame, glazing, size, and listing.
- **Louver free area:** minimum if requested, for the actual selected size; distinguish fixed blades from heat-activated closing blades.
- **Activation temperature:** exact approved setting, as for smoke vents.

[Activar's VSLH submittal](https://www.activarcpg.com/wp-content/uploads/Vision-Lite-VSLH-Submittal.pdf), inspected in the first research pass, distinguishes thickness ranges and dimension bases. The document's re-fetch returned HTTP 403 in this pass; no new claims are based on an unread revision. [Activar's louver submittal](https://www.activarcpg.com/wp-content/uploads/Louver-1900ASG-Submittal.pdf) separately identifies free area and door thickness.

## How this should affect match decisions

1. Establish the attribute's meaning before comparing numbers: requested minimum rating versus actual site condition versus exact product configuration.
2. Compare only compatible quantities, units, components, standards, and configurations.
3. Treat each required fit condition independently. Extra capacity cannot make up for an incompatible opening or insufficient fire rating.
4. Distinguish confirmed satisfaction, contradiction, missing evidence, conflicting evidence, and satisfaction requiring an option. Do not hide these behind a single similarity score.
5. Once a minimum is met, do not automatically reward excessive capacity. Use the remaining customer requirements and preferences to rank qualified candidates.
6. Preserve preferences such as “if possible” separately from requirements such as “must.”
7. Do not pick the highest number from contradictory sources. Resolve whether values refer to different revisions, sizes, options, or test contexts; otherwise mark the attribute unresolved.

The existing ladder capacity comparison already follows the basic minimum-rating idea. Its use of the largest discovered rating deserves scrutiny when evidence conflicts. Extending this approach should mean adding the correct comparison and evidence context for each attribute, not copying the same greater-than-or-equal rule everywhere.

## Practical first attributes to formalize later

The highest-value extensions are: ladder height range and capacity basis; distinct roof/floor/smoke-vent load types; contextual fire duration; R-value and U-factor with component/unit basis; round diameter and three-dimensional box sizing; mounting/pitch/thickness compatibility; and configuration-dependent capabilities. They can all produce explicit explanations such as “capacity sufficient, ceiling height unsupported” instead of vague feature similarity.
