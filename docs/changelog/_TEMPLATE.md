<!--
  How to add a release (two steps):

  1. Copy this file:   cp docs/changelog/_TEMPLATE.md docs/changelog/vX.Y.Z.md
     Then replace the body below with the release's changes.
  2. Add an entry to the TOP of the "releases" array in docs/changelog/manifest.json:
       { "version": "X.Y.Z", "date": "YYYY-MM-DD", "title": "Short summary", "file": "vX.Y.Z.md" }

  Keep releases newest-first. This file is not listed in manifest.json, so it never
  renders on the page. Standard Markdown works: headings, lists, links and `code`.
-->

One-line summary of the release (optional).

### Added
- ...

### Changed
- ...

### Fixed
- ...
