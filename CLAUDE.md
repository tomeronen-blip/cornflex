# Working on this project

The owner works on this project from both the office computer and the Claude phone app, so every session must start and end in sync with GitHub.

- **Start of every session:** run `git pull` on `main` before doing anything else, so you work on the latest version.
- **End of every piece of work:** commit and push to `main` (not a separate branch), so the other device and the WordPress update pick it up. If the session started on another branch, merge it into `main` and push.
- **Language and pace:** talk to the owner in Hebrew and guide them slowly, one step at a time.
- The GitHub repo is public, by the owner's choice (decided 2026-10-07). Never commit API keys, passwords or tokens: they live only in the WordPress settings. If the repo is ever made private, first save a read-only GitHub token in WordPress (Cornflex → עדכון מ-GitHub), or the update button stops working.
