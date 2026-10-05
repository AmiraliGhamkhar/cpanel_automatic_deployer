# GitHub setup

Repository input must be a canonical URL such as `https://github.com/owner/repository` or the same URL with `.git`. SSH URLs, embedded tokens, query strings, arbitrary hosts and local filesystem URLs are rejected. Branch names are constrained and Git arguments are quoted. A selected commit must be a full lowercase 40-character SHA.

The panel resolves “latest” through GitHub's commit API immediately before deployment. The target clones the selected branch, verifies the resolved SHA is an ancestor of the remote branch, and checks out that SHA detached. If the branch moves incompatibly between resolution and clone, the operation fails safely rather than substituting another commit.

An optional `GITHUB_TOKEN` supplied to the **control-plane process environment** increases metadata rate limits or allows metadata access. Never embed it in a repository URL. Keep it in your host's protected secret mechanism; do not commit a populated .env. Remote private-repository authentication is **not implemented**: private metadata access does not make target clones work. Deploy public repositories only for now.

Project detection checks root filenames in this order: `artisan`, `requirements.txt`, `pyproject.toml`, `package.json`, `index.html`. This is a heuristic, not a security boundary. Use the Detect project type action, then override manually if appropriate. Monorepo subdirectories and arbitrary build commands need reviewed extensions, not shell text in the UI.

Only deploy repositories you trust. Composer hooks, Python build hooks and npm lifecycle scripts are executable code with the hosting account's privileges. Shell-argument escaping prevents injection through configuration; it cannot sandbox intentionally executable project code. A compromised dependency can read all secrets available to the target account.
