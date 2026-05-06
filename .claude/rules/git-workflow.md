<!-- craftcms-claude-skills -->
# Git Workflow

- Conventional commits: `feat(scope):`, `fix(scope):`, `refactor(scope):`, `docs:`, `test:`, `chore:`. Common scopes for this repo: `tools`, `transport`, `mcp`, `cp`, `config`, `migrations`.
- **Subject line only** for most commits. One line, under 72 characters, describes the what: `fix(transport): handle aborted SSE connections without leaking workers`.
- **Body only when the why isn't obvious** — a migration that drops a column, a security fix, an architectural decision. Even then, keep it to 3-5 lines max. If the commit message is longer than the diff, something is wrong.
- Never include: "Verification" sections, "How to undo" sections, "Follow-up" sections, file-by-file change lists, or test count reports. The diff shows what changed. The PR description (if any) covers the broader context. The commit message covers the why.
- `--amend` for fixes to the most recent unpushed commit. New commit once pushed.
- Run `ddev composer check-cs` and `ddev composer phpstan` before every commit.
- No AI attribution in commit messages. No "Co-Authored-By" lines referencing AI tools.
- All comments, commit messages, and documentation in English only.
- Use absolute paths in git commands. Never `cd path && git commit` — the target directory may have untrusted hooks.
- **Branching model:** active development happens on `develop-v5`. There is no `main`. A `v5` branch will be created later as the release branch — until then, `develop-v5` is the only long-lived branch. Open PRs against `develop-v5`. Never create or push a `main` branch.
