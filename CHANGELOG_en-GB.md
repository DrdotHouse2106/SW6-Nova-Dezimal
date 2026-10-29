# 1.0.8
- Security: in sales channels with Nova Decimal disabled, decimal data from the browser is no longer taken into the order
- Security (weclapp correction): only orders from channels with Nova Decimal active; each position is checked against cart status, position price and product settings (maximum, piece size), implausible positions are not changed and marked `fehler`
- Security: minimum, maximum and step also apply when the decimal data of a position was removed (e.g. via API)
- weclapp: connection via `https://` only, no redirects; a missing quantity in the weclapp response no longer aborts the run
- weclapp: status and info on the order are only written when something changed (previously every 10 minutes for "wartet"/"test")

# 1.0.7
- Product page: input exactly as tall as the buy button

# 1.0.6
- Product page: input as one unit with a single border, no more clipped corners with rounded quantity fields in the theme

# 1.0.5
- Off-canvas cart: value can now be changed directly (Enter or "Apply", no page reload)
- Cart page: "Apply" is a separate button below the input instead of squeezed next to it

# 1.0.4
- Cart: notice when the changed value was adjusted to min/max/step
- Removed diagnostic HTML comment

# 1.0.3
- Cart: product data for decimal positions is loaded when missing, so validation and price run on every calculation

# 1.0.2
- Cart: position price is also set as price definition; status on the position for troubleshooting
- Product page: the main price shows the total for the chosen value
- New: "= x running metres" for rolls sold in m²

# 1.0.1
- New settings "field mapping", "additional payload key" and "not together with plugin" to reuse existing custom fields and mail templates

# 1.0.0
- First version: decimal values (e.g. 2.35 m²), boards with a fixed size per piece (price per m² × area), value editable in the cart
- Optional: correct quantity and unit price in the weclapp sales order (test mode, start date, allowed statuses)
