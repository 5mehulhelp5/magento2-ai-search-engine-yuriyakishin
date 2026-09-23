# Magento 2 Search Engine - Keyword & Semantic (Vector) Product Search

<a href="https://yu.net.ua/" target="_blank" rel="noopener noreferrer"><img alt="Live demo" src="https://img.shields.io/badge/demo-yu.net.ua-2ea44f?style=for-the-badge"></a>

**🔗 Demo: <a href="https://yu.net.ua/" target="_blank" rel="noopener noreferrer">yu.net.ua</a>**

An infrastructure module: structured product search that talks directly
to the store's search engine (Elasticsearch or OpenSearch - whichever is
configured in Magento), bypassing Magento's standard search-query-building
layer, with optional semantic (meaning-based) matching blended in. It
doesn't show anything on the storefront by itself and needs no admin
configuration for keyword search - **Yu_AiChat** (product search inside
the chat assistant) and **Yu_AiCatalogSearch** (AI-powered catalog
search) are built on top of it.

## Why it exists

Magento's own search layer (`SearchInterface`) behaves unreliably in some
scenarios - when called from a context other than the search results
page (for example, from an AI assistant's tool) - and doesn't always
honor every filter (in particular, price range). `Yu_AiSearchEngine`
works around this limitation by talking to the search index directly,
while still reading its connection settings (engine address, index name)
from Magento's standard configuration - so switching the search engine
in the admin panel doesn't require reconfiguring this module separately.

Another reason search goes through a dedicated module instead of being
assembled on the fly wherever it's needed: security. The call only
accepts plain data (a query string, attribute-value pairs), never a
ready-made search query as a whole. This rules out injecting arbitrary
code into the search engine, even when the search parameters come from
AI-generated text.

## Features

- **Keyword search** - free text across every searchable attribute at
  once.
- **Search by specific attributes** - e.g. "color: red" and "material:
  cotton" at the same time, including the ability to exclude a value
  ("not red").
- **Combined mode** - keywords and attribute filters in a single query,
  with facet counts computed at the same time (how many products of
  which other color/size exist in the current result set) - this is
  what powers the refine-your-search suggestions in AI catalog search.
- **Price, category and stock filters** - built into every search mode.
- **Automatic attribute discovery** - the module figures out on its own
  which product attributes the merchant has actually configured as
  searchable or usable in layered navigation, and which of those are
  actually populated in the catalog - nothing needs to be listed
  manually.
- **Semantic (vector) search, blended with keyword search.** Free-text
  queries are matched both on literal words and on meaning - a search
  for "warm winter jacket" can find a product described only as
  "insulated parka", something keyword matching alone would miss. Off
  by default; when enabled, the two rankings are blended with
  configurable weights, and any embedding failure degrades gracefully
  back to today's keyword-only behavior rather than breaking search.

## Semantic (Vector) Search

![Semantic search matching a product by meaning, not just literal words](docs/images/ai-vector-search.png)

Vectors come from `Yu_AiLlm`'s OpenAI embeddings provider (see that
module's README) and live in a separate, per-store Elasticsearch index
this module owns and keeps in sync via a native Magento indexer, **AI
Search Vector** - visible in Index Management, supporting both "Update
on Save" and "Update by Schedule" like any other Magento indexer.

In "Update on Save" mode, a product's vector is refreshed right after
the product is saved or deleted in the admin panel, and after the mass
"Update Attributes" / "Update Websites" actions. Computing a vector
means a call to the embedding API, so a product save takes roughly a
second longer; on large catalogs or frequent bulk edits "Update by
Schedule" (done in the background by cron) is the better choice. Two
safety rules apply to "Update on Save":

- Nothing happens until the index has been fully built at least once
  (the indexer shows "Ready") - so a store that hasn't set up semantic
  search yet doesn't try to call the embedding API on every save.
- If refreshing the vector fails (the API is unreachable, the search
  engine is down), the error is written to the log and the product is
  still saved - the vector index never blocks catalog editing. The
  product's vector simply stays as it was until the next save or full
  reindex.

How the two result lists are combined: keyword search finds the
products that literally match the query and every attribute requirement
(e.g. "color: green"); vector search finds the products closest in
meaning. Vector matches are held to the same requirements - a product
that is semantically close but has a different color is dropped, not
blended in. A product that doesn't have the attribute at all is not
treated as contradicting it, exactly as on the keyword side.

Turning it on:

1. Configure and enable an embedding-capable LLM provider under
   **Stores → Configuration → AI → LLM AI** (currently OpenAI).
2. Run a full reindex of **AI Search Vector**
   (`bin/magento indexer:reindex yu_vector_search`, or via Index
   Management in the admin panel).
3. Enable semantic search under **Stores → Configuration → AI → Search
   Engine AI → Semantic Search** (see Configuration below).

Until step 2 has completed at least once, the vector index doesn't
exist and every hybrid search request quietly falls back to keyword
only - turning on Semantic Search before reindexing doesn't break
anything, it just doesn't do anything yet either.

### What a product's vector is built from

A vector (an "embedding") is a list of numbers an AI model computes
from a piece of text; two texts that mean similar things get similar
numbers, which is what lets "jacket for rainy weather" find a product
whose description never says "rainy". So the vector can only know
what's in the text it was computed from. For each product, that text
is assembled from, in this order:

1. **Name**
2. **Category paths** - the product's categories as breadcrumbs
   ("Parent > Child"), in the store view's language. Inactive
   categories are left out, and a parent category isn't repeated on its
   own when a deeper path already contains it.
3. **Attribute values** - one "Label: value, value" line per attribute,
   using the store view's labels (the raw option IDs stored in the
   database mean nothing to an AI model).
4. **Short description** and **description** - with HTML tags removed,
   so markup from the WYSIWYG editor or Page Builder doesn't pollute
   the vector.

The order matters: the embedding model accepts a limited amount of text,
so a very long product text is cut from the end - the description loses
its last paragraphs, while the name, categories and attributes always
make it in.

Which attributes are included is configurable (see Configuration). The
default, **Automatic**, takes the attributes you've marked *Use in
Search* or *Use in Layered Navigation* that have dropdown,
multiple-select or short text values. Yes/No attributes are left out by
default - a line like "Label: No" carries little meaning - but you can
pick them explicitly, in which case the product gets just the label,
and only when the value is Yes. Text values made only of digits or
codes are skipped, since they add noise rather than meaning.

A vector is a soft signal, not a filter: including a color attribute
makes "red dress" lean towards red products, but it doesn't guarantee
them. Exact requirements are enforced by the keyword side of the search
and its filters, exactly as without semantic search.

Changing these settings, or renaming categories, only affects vectors
after the next full reindex of **AI Search Vector**.

## Requirements

- PHP >= 8.1
- Magento 2.4.x with Elasticsearch/OpenSearch configured (Magento's
  standard search engine); Elasticsearch 7.x's lack of native
  approximate kNN is fine at typical catalog sizes - vector scoring
  runs as a brute-force `script_score` query
- For semantic search on OpenSearch: the k-NN plugin (`opensearch-knn`),
  which ships with the standard OpenSearch distribution and Docker image.
  Elasticsearch and OpenSearch store vectors differently (Elasticsearch
  calls the field type `dense_vector`, OpenSearch calls it `knn_vector`
  and provides it through that plugin); the module picks the right one
  from the search engine configured in Magento. After switching the store
  from one engine to the other, run a full reindex of
  `yu_vector_search` so the vector index is rebuilt in the new
  engine
- The `Yu_AiLlm` module (installed automatically as a dependency),
  needed only for semantic search - keyword search has no LLM
  dependency at all

## Installation

```bash
composer require yu-dev/module-ai-search-engine
bin/magento module:enable Yu_AiLlm Yu_AiSearchEngine
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Keyword search needs no admin configuration and works immediately. The
module doesn't do anything on the storefront by itself - it's used by
other modules (`Yu_AiChat`, `Yu_AiCatalogSearch`), which are installed
separately. Semantic search is opt-in - see the Semantic (Vector) Search
section above.

## Configuration

**Stores → Configuration → AI → Search Engine AI → Semantic Search**

- **Enabled** - the kill switch for hybrid search. Off (default) falls
  back to keyword-only everywhere.
- **Keyword Score Weight** / **Vector Score Weight** - blend weights,
  default 0.4 / 0.6. Each score is normalized to [0,1] independently
  before blending, so the two weights aren't required to sum to 1.

**Stores → Configuration → AI → Search Engine AI → Vector Index Content**

See "What a product's vector is built from" above.

- **Include Category Paths** - Yes (default) adds category breadcrumbs.
- **Include Attributes** - **Automatic** (default), **Selected
  attributes only** (pick them in the **Attributes** list that appears),
  or **None** (name, categories and descriptions only).

## Author

Yuriy Akishin:
- 📧 Email: yuriy.akishin@gmail.com
- 💼 LinkedIn: https://www.linkedin.com/in/yuriyakishin/
- 💻 GitHub: https://github.com/yuriyakishin

## License

[MIT](LICENSE)
