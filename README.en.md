# Nova Decimal

Shopware 6 plugin (6.6 and 6.7) for selling products by metre, square metre or any other unit **with a decimal value** (e.g. 2.35 m²). It also sells **boards with a fixed size** per piece while the price is maintained per m². Optionally it **corrects the sales order in weclapp** (quantity and unit price), so it no longer has to be fixed by hand.

German (main documentation): [README.md](README.md)

## Features

- **Free amount** (rolls, by the metre):
  - The customer enters the value instead of a quantity; price = value × unit price.
  - Values of the same product are added up, and the value can be changed in the cart.
  - The server enforces min, max and step.
  - Listings show "Details" instead of "Add to cart".
  - Shipping weight = weight per unit × value.
- **Fixed size per piece** (boards):
  - The product price is per m² and is multiplied by the area per piece everywhere (detail page, listing, search, cart).
  - The reference price still shows the price per m².
- **weclapp correction** (optional):
  - Every 10 minutes the sales order position is set to the real amount (e.g. 2.35 m² or 2 × 1.5 = 3 m²). The unit price is converted so the position total stays the same.
  - Safety: test mode by default, a start date, allowed weclapp statuses only, and only positions that still have the Shopware quantity are touched. Only orders from sales channels with Nova Decimal active, only positions calculated by the cart and matching the product settings; connection via https only, no redirects.
  - The result is stored on the order (custom fields "Nova Decimal (weclapp)").
  - Console: `bin/console nova-dezimal:weclapp [--order=…] [--test|--write]`.

## Setup

Product → Custom fields → **Nova Decimal**:
- Mode, unit, label
- Min/max/step/default
- Area per piece (boards; empty = purchase unit)

The weclapp settings are in the plugin configuration.

## Limitations

- Stock counts positions or pieces, not m.
- Editing an order in the administration recalculates without the decimal value.
- Price sorting and filtering for boards uses the stored price per m².

## License

MIT
