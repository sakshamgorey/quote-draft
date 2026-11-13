# SKU dictionary — sample excerpt

A small excerpt of the full dictionary used by the optional AI-suggestions feature (`DRAFT_AI_SUGGESTIONS`). The full file covers every code in the catalog and is kept out of this repository; this excerpt has the same structure so the parser, tests and feature run as-is.

## Rules for an AI consuming this dictionary

1. Find the exact SKU first. Retain brand, stored model, product title and any conflict flag. A broad product type alone is not enough.
2. For family lookup, use the brand plus the longest matching complete model/code. BA-PAC is shared by two brands. Fourteen stored-model mismatches are explicitly listed in the evidence companion.
3. Interpret exact hyphen-delimited segments using the tables below. Do not infer that HG inside RHG, PF inside PFI, or SS inside HSS has the meaning of the standalone token.
4. Apply embedded patterns only inside the named manufacturer/family in the embedded-code section. Keep the original SKU spelling, case, decimal values and zero-padding.
5. Treat a FAMILY_IDENTITY entry as a product lookup label, not a proven acronym expansion. For example, LXB identifies a balustrade; it does not authorize inventing words for L, X and B.
6. A CONTEXT_DEPENDENT entry requires its branch. Some entries have distinct word meanings; others reuse a model identifier or change the affected assembly component. Preserve those distinctions.
7. A UNRESOLVED_PART entry must stay unexpanded. The complete model still has a usable product identity in the register.
8. Do not generate a new SKU by recombining known codes. Confirm that the proposed exact SKU occurs in the catalog or is explicitly supported by a current ordering specification.
9. Do not derive certifications, fire ratings, load capacity, compatibility or insulation from a familiar substring alone. Follow the full SKU specification and any source conflict.
10. Numeric suffixes can mean opening dimensions, diameter, depth, ladder height or compatibility size. Some options follow dimensions, and some accessories have no numeric suffix. Use the examples and numeric inventory below.


### One supported attribute or variant meaning

| Code | Supported meaning / product identity | Brand and stored-model scope | SKU rows | Evidence |
|---|---|---|---:|---|
| `HS` | High security in Cendrex PFI-HS / FRI-HS. | Cendrex: `BA-FRI-HS`, `BA-PFI-HS` | 17 | DB |
| `SS` | Stainless steel in the observed material suffixes. The full specification determines which components and grade. | Acudor USA: `BA-ADWT-SS`, `BA-ARVB-SS`, `BA-AS-9000-SS`, `BA-ASVB-SS`, `BA-FB-5060-SS`, `BA-MS-7000-SS`, `BA-UF-5000-SS`; Cendrex: `BA-AHD-GYP-SS`, `BA-AHD-SS`, `BA-CTR-SS`, `BA-FRI-SS`, `BA-PFI-SS`, `BA-RAD-SS`, `BA-UAP-SS`, `BA-UHF-SS`; Karp Associates: `BA-KRP-150FR-SS`, `BA-KRP-350FR-SS` | 176 | DB |
| `GYP` | Gypsum/drywall finishing flange in Cendrex variants; catalog wording includes drywall bead and mud-in. **Source conflict:** `BA-AHD-GYP-24-36` disagree with their stored model; do not learn an alias from those rows. | Cendrex: `BA-AHA-GYP`, `BA-AHD-60`, `BA-AHD-GYP`, `BA-AHD-GYP-SS`, `BA-EDG-GYP`, `BA-HHD-GYP`, `BA-PFI-GYP`, `BA-PFN-GYP` | 68 | DB; [CENDREX](https://cendrex.com/wp-content/uploads/2026/01/2825_CendrexCatalog2025-EN-WEB.pdf) |
| `PLY` | Plaster bead flange in the Cendrex variants. Do not interpret as plywood. | Cendrex: `BA-AHA-PLY`, `BA-HHD-PLY`, `BA-PFI-PLY`, `BA-PFN-PLY` | 28 | DB; [CENDREX](https://cendrex.com/wp-content/uploads/2026/01/2825_CendrexCatalog2025-EN-WEB.pdf) |
| `UL` | Omni AA-...-UL: UL-listed Access Armor variant. Milcor UL-SEE/UL-SEEA/UL-SKY: part of the model name; the prefix's literal expansion is unresolved. Product certification must be read separately. | Milcor: `BA-UL-SEE`, `BA-UL-SEEA`, `BA-UL-SKY`; Omni Containment: `BA-AA` | 80 | DB; [OMNI](https://irp.cdn-website.com/21bbb717/files/uploaded/Access-Armor_2016-Specsheet.pdf) [MILCOR_UL](https://www.bestaccessdoors.com/content/BA-UL-SEEA.pdf) |

### One catalog family/product identity

| Code | Supported meaning / product identity | Brand and stored-model scope | SKU rows | Evidence |
|---|---|---|---:|---|
| `PFI` | Cendrex fire-rated insulated access-door family. Product meaning is established; do not split into PF + I. | Cendrex: `BA-PFI`, `BA-PFI-GYP`, `BA-PFI-HS`, `BA-PFI-PLY`, `BA-PFI-SS` | 75 | DB; [CENDREX](https://cendrex.com/wp-content/uploads/2026/01/2825_CendrexCatalog2025-EN-WEB.pdf) |
| `FRI` | Cendrex fire-rated insulated access-panel family. | Cendrex: `BA-FRI`, `BA-FRI-DF`, `BA-FRI-HS`, `BA-FRI-PF`, `BA-FRI-SS` | 65 | DB |
| `50TB` | BA-E-50TB: Thermally Broken Ladder Roof Hatch - Aluminum; BA-F-50TB: Thermally Broken Equipment Roof Hatch; BA-GS-50TB: Thermally Broken Skylight Roof Hatch - Aluminum; BA-L-50TB: Thermally Broken Service Stair Roof Hatch - Aluminum; BA-NB-50TB: Thermally Broken Ship Stair Roof Hatch - Aluminum; BA-S-50TB: Thermally Broken Ladder Roof Hatch - Aluminum | Bilco: `BA-E-50TB`, `BA-F-50TB`, `BA-GS-50TB`, `BA-L-50TB`, `BA-NB-50TB`, `BA-S-50TB` | 6 | DB |
| `GS` | BILCO skylight roof-hatch model family in GS-50TB. Do not infer galvanized steel: the observed product is aluminum. | Bilco: `BA-GS-50TB` | 1 | DB |
| `NB` | BILCO NB model/form-factor series, observed at 30 x 54 inches; suffix distinguishes roof hatch, smoke vent, construction and options. | Bilco: `BA-NB-20C`, `BA-NB-20HZ`, `BA-NB-20SV`, `BA-NB-20VM`, `BA-NB-40`, `BA-NB-40SV`, `BA-NB-50C`, `BA-NB-50HZ`, `BA-NB-50TB`, `BA-NB-50VM`, `BA-NB-90`, `BA-NB-90VM` | 12 | DB |

### Multiple meanings or context-dependent roles

| Code | Supported meaning / product identity | Brand and stored-model scope | SKU rows | Evidence |
|---|---|---|---:|---|
| `S` | Babcock Davis BRHES/BSVLA/BSVLB suffix: single door/leaf. Fakro DEF-S/FVC-S: solar operation. Acudor GFRC-S: square corner. BILCO S: roof-hatch/smoke-vent model series, generally the 36 x 30 format in these rows. | Acudor USA: `BA-GFRC-S`; Babcock Davis: `BA-BRHES-S`, `BA-BSVLA-S`, `BA-BSVLB-S`; Bilco: `BA-S-20VM`, `BA-S-40`, `BA-S-40SV`, `BA-S-50C`, `BA-S-50SVTB`, `BA-S-50TB`, `BA-S-50VM`, `BA-S-90`, `BA-S-90VM`; Fakro: `BA-DEF-S`, `BA-FVC-S`, `BA-FVC-S-L` | 53 | DB |
| `F` | Acudor CD-5080-F/HD-5070-F: fiberglass-duct application. BILCO F: equipment roof-hatch family. Milcor F-SEC: flush floor-door family. The model-family letters are not all independently expanded. | Acudor USA: `BA-CD-5080-F`, `BA-HD-5070-F`; Bilco: `BA-F-40`, `BA-F-50HZ`, `BA-F-50TB`; Milcor: `BA-F-SEC-DL`, `BA-F-SEC-SL` | 28 | DB |
| `L` | Fakro FV-L/FVE-L/FVS-L/FX-L/FXC-L/FVC-S-L: laminated glazing. Fakro FWU-L: left-side hinge. Fakro LWP-L: taller/longer-reach ladder variant. BILCO L: roof-hatch/smoke-vent model series, generally the 30 x 96 format in these rows. | Bilco: `BA-L-20HZ`, `BA-L-20VM`, `BA-L-40`, `BA-L-40SV`, `BA-L-50HZ`, `BA-L-50TB`, `BA-L-50VM`, `BA-L-90`, `BA-L-90VM`; Fakro: `BA-FV-L`, `BA-FVC-S-L`, `BA-FVE-L`, `BA-FVS-L`, `BA-FWU-L`, `BA-FX-L`, `BA-FXC-L`, `BA-LWP-L` | 77 | DB |
| `E` | BA-E-20C: Security Roof Hatch - Steel; BA-E-20HZ: Ladder Roof Hatch - Steel - MDA Approved; BA-E-40: Ladder Roof Hatch - Steel Curb & Alum. Cover; BA-E-50C: Security Roof Hatch - Aluminum; BA-E-50HZ: Ladder Roof Hatch - Aluminum - MDA Approved; BA-E-50TB: Thermally Broken Ladder Roof Hatch - Aluminum; BA-E-90: Ladder Roof Hatch - Stainless Steel | Bilco: `BA-E-20C`, `BA-E-20HZ`, `BA-E-40`, `BA-E-50C`, `BA-E-50HZ`, `BA-E-50TB`, `BA-E-90` | 7 | DB |

## Embedded codes and complete alphanumeric patterns

| Scope and pattern | Supported reading | Guardrail |
|---|---|---|
| BILCO `50TB` | Aluminum thermally broken roof-hatch variant. TB = thermally broken. | Keep the opening/form-factor prefix and full model. |
| BILCO `20SV`, `40SV`, `50SVTB` | Automatic smoke-vent variants: steel; steel curb/aluminum cover; aluminum with thermal break. | Read SV and TB inside this BILCO pattern only. |
