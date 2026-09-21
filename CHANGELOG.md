# Changelog

## 1.0.2
- Plugin Check fixes: direct access protection in every PHP file, no manual `load_plugin_textdomain()` call (bundled Turkish translation still loads through the `load_textdomain_mofile` filter).

## 1.0.1
- Plugin name shortened to "Qtyguard" so the directory slug matches the text domain; "Tested up to" updated.

## 1.0.0
- Minimum, maximum and "multiples of" rules per product, per variation, per category and store-wide.
- Precedence: variation, product, category, store-wide default.
- "Count variations together" option for variable products.
- Whole-order limits: minimum / maximum items and minimum order amount.
- Exempt user roles (for example wholesale).
- Editable messages with placeholders, optional rule note on the product page.
- Works with the classic and the block cart and checkout; HPOS compatible.
- Turkish translation included.
