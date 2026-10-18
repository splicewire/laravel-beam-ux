# EP-11: Block Style

ADR-0228 decision 4 owns `style.layers[{breakpoint?, state?, declarations}]` on the block. ADR-0003 owns literal-only `bdStyle`, content-hashed CSS classes, the layer after Tailwind utilities, and responsive tokens. Other object props remain opaque; classes remain authored values.

The PHP StyleRegistry declares the package defaults. Its exported JSON fixture is checked against regeneration in PHP and copied into Beam JS. The theme's `responsive.breakpoints` map overrides widths; `responsive.tokens.<breakpoint>` holds token values in the existing theme namespaces. No Tailwind config is read at runtime. Hosts keep these declared widths aligned with their Tailwind configuration. The editor envelope and reader artifact descriptor carry the resolved registry.

The artifact retains the style data, then invokes the runtime compiler when rendered. Identical rules share a class and one block-style sheet per rendered entry. Responsive theme tokens have a separate theme sheet. Ordinary object-literal `style` and other props are not interpreted by the Lens. Legacy string style attributes migrate on the next author write; the canonical block field is what history stores. A fragment cannot carry a style.

The CSS grammar accepts literal property/value pairs, rejects selector/HTML/at-rule breakouts, and admits only declared breakpoint/state names. States are hover, focus-visible and active. Variants, presets, Release, colour-scheme modes and component interface work are outside this slice.

Package proof includes the real PHP printer → shipped Node compiler → React render chain. No live-host proof or release attestation is claimed. PHP landing is held for EP-43; the JS/PHP contract must reach consumers together.
