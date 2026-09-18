# Content translator filter (filter_contenttranslator)

Render-time filter for [local_contenttranslator](../../local/contenttranslator/README.md). It replaces
registered content with the stored translation for the current user's language.

* Never calls a translation engine; lookups are served from the MUC cache or one DB query.
* Language fallback: current language -> parent language -> source text.
* Honours the per-language visibility mode (show machine translations immediately or only after review)
  and the "show stale" setting.
* Adds `lang` attributes, an optional "machine translated" indicator (per block or one banner per page)
  and a "show original" toggle.
* Works for `format_text()` and `format_string()` output (set *Apply to* to *Content and headings*).

Enable the filter and move it to the top of the filter order so it sees the untouched source text.

GNU GPL v3 or later. Copyright 2026 Wunderbyte GmbH.
