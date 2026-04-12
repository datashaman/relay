# Design System Strategy: Agentic Precision & Tonal Depth

## 1. Overview & Creative North Star
**Creative North Star: "The Orchestrated Terminal"**
This design system moves away from the "SaaS-standard" look of cards and heavy borders. Instead, it adopts an aesthetic of **Information Density with Intentionality**. Think of it as a high-end IDE or a flight control system: every pixel serves a functional purpose, but the experience is softened by sophisticated tonal layering and editorial typography.

We break the "template" look through:
*   **Asymmetric Information Density:** Grouping technical data tightly while allowing generous "breathing room" (negative space) around high-level status indicators.
*   **Layered Surfaces:** Using depth and color shifts rather than lines to define boundaries.
*   **Monospace Accents:** Leveraging `JetBrains Mono` not just for code, but for tactical metadata and status labels to reinforce the developer-focused DNA.

---

## 2. Color & Surface Architecture
We prioritize a dark-mode-first environment where light is a premium resource used to guide the eye through the issue pipeline.

### The Pipeline Spectrum
These colors represent the "state of the machine" and should be used sparingly as indicators, not background fills.
*   **Preflight:** `#534AB7` (Purple) — The "Cold Start" phase.
*   **Implement:** `#BA7517` (Amber) — Active computation/work.
*   **Verify:** `#639922` (Green) — Validation and logic checks.
*   **Release:** `#1D9E75` (Teal) — The "Success" state.
*   **Stuck:** `#EF9F27` (Bright Amber) — Requires human intervention.

### The "No-Line" Rule & Surface Hierarchy
**Explicit Instruction:** Prohibit 1px solid borders for sectioning. To separate a code editor from a sidebar, use background shifts.
*   **Root Level:** `surface` (`#131315`)
*   **Secondary Content:** `surface_container_low` (`#1B1B1D`)
*   **Interactive Cards/Modules:** `surface_container_high` (`#2A2A2C`)
*   **Floating Modals/Popovers:** `surface_container_highest` (`#353437`)

**Glass & Gradient Rule:**
To provide "visual soul," primary actions should utilize a subtle linear gradient from `primary` (`#C5C0FF`) to `primary_container` (`#534AB7`) at 135 degrees. For floating terminal overlays, use **Glassmorphism**: `surface_container_highest` at 80% opacity with a `20px` backdrop-blur.

---

## 3. Typography
The system uses a high-contrast pairing of **Space Grotesk** (Headlines) for a modern, technical flair and **Inter** (Body) for maximum readability in dense data environments.

*   **Display/Headline (Space Grotesk):** Large, bold, and authoritative. Used for pipeline titles and major status updates.
*   **Body (Inter):** Optimized for long-form issue descriptions and logs.
*   **Labels (JetBrains Mono):** All technical metadata (e.g., Commit IDs, Timestamp, "Stuck" status) must use monospace to signify "System Output."

| Level | Font | Size | Weight | Case |
| :--- | :--- | :--- | :--- | :--- |
| **Headline-LG** | Space Grotesk | 2.0rem | 700 | Sentence |
| **Title-SM** | Inter | 1.0rem | 600 | Sentence |
| **Body-MD** | Inter | 0.875rem | 400 | Sentence |
| **Label-MD** | JetBrains Mono | 0.75rem | 500 | All Caps |

---

## 4. Elevation & Depth
Depth is a functional tool, not a stylistic flourish. We convey hierarchy through **Tonal Layering**.

*   **The Layering Principle:** Instead of a shadow, an inner "Input Area" should be `surface_container_lowest` (`#0E0E10`) nested inside a `surface_container` (`#201F21`) dashboard.
*   **Ambient Shadows:** If an element must "float" (e.g., a critical "Stuck" alert), use a shadow with a `40px` blur at 8% opacity, using the `primary` color as the shadow tint rather than black.
*   **The "Ghost Border" Fallback:** For the "Stuck" state, use a `1px` border of `outline_variant` (`#474553`) at 40% opacity to provide a structural container without breaking the tonal flow.

---

## 5. Components

### Code Editors & Terminal Outputs
*   **Background:** `surface_container_lowest`.
*   **Typography:** `JetBrains Mono` 0.875rem.
*   **Interaction:** No borders. Highlight active lines with a `surface_bright` subtle background wash.

### Status Badges (The Pipeline Stages)
*   **Visual Style:** Small, uppercase `Label-SM` text.
*   **Colors:** Use the stage-specific colors.
*   **State:** For "Stuck," apply a subtle pulse animation using the Amber shadow.

### Buttons (The "Tactical" Primary)
*   **Primary:** Background gradient (`primary` to `primary_container`), `on_primary` text. No border. Radius: `md` (`0.375rem`).
*   **Secondary/Ghost:** No background. `outline` text. On hover, shift background to `surface_variant`.

### Structured Clarification Forms (Radio Groups)
*   **Layout:** Vertical stacks. Forbid divider lines.
*   **Selection:** The selected state is indicated by a shift from `surface_container_low` to `surface_container_highest` with a `secondary` (`#68DBAE`) left-edge accent (2px wide).

### Diff Views
*   **Additions:** `on_secondary_fixed_variant` background with `secondary` text.
*   **Deletions:** `error_container` background with `error` text.
*   **Layout:** Information-dense; use `0px` radius between lines to create a solid block of code.

---

## 6. Do's and Don'ts

### Do
*   **Do** use `surface_container` tiers to create hierarchy.
*   **Do** use `JetBrains Mono` for any text that is "generated" by the agent.
*   **Do** allow code blocks to take up 70% of the horizontal layout; prioritize technical content over white space.
*   **Do** use the "Stuck" Amber sparingly to ensure it demands immediate attention.

### Don't
*   **Don't** use 1px solid borders to separate sidebar/main content/header. Use tonal shifts.
*   **Don't** use pure black `#000000` or pure white `#FFFFFF`. Stick to the defined surface and "on-surface" tokens.
*   **Don't** use standard "drop shadows." Use tonal layering or ultra-diffused ambient glows.
*   **Don't** use Inter for code or terminal output; it lacks the necessary character alignment for technical data.

---

## 7. Token Reference

### Named colors (dark mode)
```
background              #131315
surface                 #131315
surface_dim             #131315
surface_bright          #39393b
surface_container_lowest #0e0e10
surface_container_low   #1b1b1d
surface_container       #201f21
surface_container_high  #2a2a2c
surface_container_highest #353437
surface_variant         #353437
surface_tint            #c5c0ff

on_background           #e5e1e4
on_surface              #e5e1e4
on_surface_variant      #c8c4d5
inverse_surface         #e5e1e4
inverse_on_surface      #303032

primary                 #c5c0ff
primary_container       #534ab7
on_primary              #28188c
on_primary_container    #d1ccff
primary_fixed           #e3dfff
primary_fixed_dim       #c5c0ff
on_primary_fixed        #140067
on_primary_fixed_variant #3f35a3
inverse_primary         #584fbc

secondary               #68dbae
secondary_container     #26a37a
on_secondary            #003827
on_secondary_container  #003121
secondary_fixed         #86f8c9
secondary_fixed_dim     #68dbae
on_secondary_fixed      #002115
on_secondary_fixed_variant #00513a

tertiary                #ffb869
tertiary_container      #824e00
on_tertiary             #482900
on_tertiary_container   #ffc78c
tertiary_fixed          #ffdcbb
tertiary_fixed_dim      #ffb869
on_tertiary_fixed       #2b1700
on_tertiary_fixed_variant #673d00

error                   #ffb4ab
error_container         #93000a
on_error                #690005
on_error_container      #ffdad6

outline                 #928f9e
outline_variant         #474553
```

### Theme overrides
```
primary_override        #534AB7
secondary_override      #1D9E75
tertiary_override       #BA7517
neutral_override        #121214
roundness               ROUND_FOUR
spacing_scale           1
color_mode              DARK
color_variant           FIDELITY
```
