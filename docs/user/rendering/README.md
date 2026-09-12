[Back to parent section](../../README.md)

# Rendering

---

## Quick setup path

1. [/admin/filters.php](/admin/filters.php): set **Content translator** to *On* and move it to the top.
2. [/admin/search.php?query=filterall](/admin/search.php?query=filterall): enable *Filter all strings*.
3. Check *Reports → System status → Content translator setup*.

---

## Table of Contents

1. [Enabling the filter](#1-enabling-the-filter)
2. [What learners see](#2-what-learners-see)
3. [Performance](#3-performance)
4. [Known core gaps](#4-known-core-gaps)

---

## 1. Enabling the filter

The filter must run **first** so that it sees the untouched source text before multilang, glossary
auto-linking or emoticon filters change it. Course, section and activity names only pass through
filters when *Filter all strings* is on.

## 2. What learners see

For every filtered text the filter normalises the text, looks up its hash for the user's language
(then the parent language) and applies the visibility mode of the language / course:

| Situation | Output |
|---|---|
| visible translation exists | translation, wrapped with `lang="xx"` and (optionally) a "machine translated" badge |
| item known, no visible translation | source text, wrapped with the source `lang` for assistive technology |
| unknown text | untouched |
| user's language = source language | untouched |
| "Show original" active for the session | source text |

The page banner (default indicator mode) says that parts of the page were translated automatically and
offers the *Show original* / *Show translation* link (`?ctoriginal=1|0`, remembered in the session).

Translations of `format_string()` output (names) are returned as plain text without any markup.

## 3. Performance

Lookups are served from an application cache keyed by hash, language and tenant (negative results are
cached too); a cold cache costs one DB query per distinct text. No external service is ever called
while a page renders. Cheap pre-checks skip empty, numeric and letter-less strings.

## 4. Known core gaps

Moodle core does not run text filters everywhere. Not translated by design of core: global search
results (MDL-67847), calendar event titles in some views, grader report and profile pages
(MDL-89044–89048). The Moodle App receives translated content through web services because filters
run server-side.
