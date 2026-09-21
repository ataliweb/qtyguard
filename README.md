# Qtyguard

**Quantity rules for WooCommerce.** Minimum, maximum and "sold in multiples of" limits per product, per variation, per category and for the whole order. Works with the classic and the block cart and checkout.

[![CI](https://github.com/ataliweb/qtyguard/actions/workflows/ci.yml/badge.svg)](https://github.com/ataliweb/qtyguard/actions/workflows/ci.yml)

## Features

- **Min / max / multiples of** on every product. Example: sold in boxes of 6, at least 12, at most 60.
- **Four levels of rules.** Variation, then product, then category, then store-wide default. Each field is picked separately, so a category can set the step while a product overrides only the maximum.
- **Count variations together.** For variable products the rule can apply to the combined quantity of all variations.
- **Whole-order limits.** Minimum / maximum items in the cart and a minimum order amount.
- **Exempt roles.** For example wholesale customers ignore every rule.
- **Your own wording.** All messages are editable with placeholders. Optional note ("Minimum: 3 · Multiples of 3") on the product page.
- **Enforced everywhere.** Quantity inputs get the right min/max/step, add to cart and cart updates are validated, and the cart/checkout is blocked until the order is valid, in the block checkout too.
- HPOS compatible, no custom tables, clean uninstall. English and Turkish included.

## Install

Download `qtyguard.zip` from [Releases](https://github.com/ataliweb/qtyguard/releases), then Plugins → Add New → Upload. Requires WooCommerce 7.0+, PHP 7.4+.

Set store-wide rules under **WooCommerce → Quantity Rules**. Per-product rules are on the product's **Inventory** tab, per-variation rules inside each variation, per-category rules on the category edit screen.

## How rules combine

| Situation | Result |
|---|---|
| Rule on variation and product | Each field comes from the variation if set, otherwise the product |
| Product in two categories | Larger minimum, smaller maximum, larger step |
| Step 6 and no minimum | Minimum becomes 6 |
| Minimum 4, step 6 | Minimum becomes 6 (rounded up to a multiple) |
| Minimum above maximum | Minimum wins, maximum is raised |

## Development

The rule engine is plain PHP with no WordPress calls, so it is tested on its own:

```bash
php tests/run-tests.php
```

CI lints every file on PHP 7.4, 8.1 and 8.3 and runs those tests. A tag like `v1.0.1` builds an installable zip and attaches it to a release.

Translations: `languages/qtyguard.pot` is the template; copy it to `qtyguard-xx_XX.po` and compile with Poedit or `msgfmt`.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
