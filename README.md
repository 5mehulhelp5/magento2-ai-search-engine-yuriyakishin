# Magento 2 Search Engine — Reliable Filters for AI-Powered Catalog Search

An infrastructure module: structured product search that talks directly
to the store's search engine (Elasticsearch or OpenSearch — whichever is
configured in Magento), bypassing Magento's standard search-query-building
layer. It doesn't show anything on the storefront by itself and needs no
admin configuration — **Yu_AiChat** (product search inside the chat
assistant) and **Yu_AiCatalogSearch** (AI-powered catalog search) are
built on top of it.

## Why it exists

Magento's own search layer (`SearchInterface`) behaves unreliably in some
scenarios — when called from a context other than the search results
page (for example, from an AI assistant's tool) — and doesn't always
honor every filter (in particular, price range). `Yu_AiSearchEngine`
works around this limitation by talking to the search index directly,
while still reading its connection settings (engine address, index name)
from Magento's standard configuration — so switching the search engine
in the admin panel doesn't require reconfiguring this module separately.

Another reason search goes through a dedicated module instead of being
assembled on the fly wherever it's needed: security. The call only
accepts plain data (a query string, attribute-value pairs), never a
ready-made search query as a whole. This rules out injecting arbitrary
code into the search engine, even when the search parameters come from
AI-generated text.

## Features

- **Keyword search** — free text across every searchable attribute at
  once.
- **Search by specific attributes** — e.g. "color: red" and "material:
  cotton" at the same time, including the ability to exclude a value
  ("not red").
- **Combined mode** — keywords and attribute filters in a single query,
  with facet counts computed at the same time (how many products of
  which other color/size exist in the current result set) — this is
  what powers the refine-your-search suggestions in AI catalog search.
- **Price, category and stock filters** — built into every search mode.
- **Automatic attribute discovery** — the module figures out on its own
  which product attributes the merchant has actually configured as
  searchable or usable in layered navigation, and which of those are
  actually populated in the catalog — nothing needs to be listed
  manually.

## Requirements

- PHP >= 8.1
- Magento 2.4.x with Elasticsearch/OpenSearch configured (Magento's
  standard search engine)

## Installation

```bash
composer require yu-dev/module-ai-search-engine
bin/magento module:enable Yu_AiSearchEngine
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

The module needs no admin configuration and doesn't do anything on the
storefront by itself — it's used by other modules (`Yu_AiChat`,
`Yu_AiCatalogSearch`), which are installed separately.

## Author

Yuriy Akishin:
- 📧 Email: yuriy.akishin@gmail.com
- 💼 LinkedIn: https://www.linkedin.com/in/yuriyakishin/
- 💻 GitHub: https://github.com/yuriyakishin

## License

[MIT](LICENSE)
