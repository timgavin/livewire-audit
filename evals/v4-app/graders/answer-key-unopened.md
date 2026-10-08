---
# A run can read the plugin folder. One that saw the answer key or the repo notes is void.
type: regex
target: trace
match: not_contains
pattern: 'MANIFEST\.md|AGENTS\.md'
---
