---
# Audit mode creates exactly one file. Fails if any created path is not the report.
type: regex
target: files
match: not_contains
pattern: '^(?!(?:.*/)?livewire-audit-\d{4}-\d{2}-\d{2}\.md$).+$'
flags: m
---
