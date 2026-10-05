<?php
namespace App\Services\Remote;
use App\Services\Security\{Input, PublicHttp};
class GitHubService
{
    public function __construct(private PublicHttp $http) {}
    private function request(string $repository, string $suffix): array
    {
        Input::repository($repository);
        $path = preg_replace('/\.git$/', "", substr($repository, 19));
        $headers = [
            "Accept" => "application/vnd.github+json",
            "X-GitHub-Api-Version" => "2022-11-28",
            "User-Agent" => "Personal-cPanel-Control",
        ];
        if ($token = config("services.github.token")) {
            $headers["Authorization"] = "Bearer " . $token;
        }
        $r = $this->http->get(
            "https://api.github.com/repos/" . $path . $suffix,
            $headers,
        );
        if (!$r->successful()) {
            throw new \RuntimeException(
                "GitHub request failed: verify repository access, ref and API rate limit.",
            );
        }
        return $r->json();
    }
    public function commit(string $repo, string $branch, ?string $sha): string
    {
        Input::branch($branch);
        if ($sha) {
            Input::commit($sha);
        }
        $data = $this->request(
            $repo,
            "/commits/" . rawurlencode($sha ?: $branch),
        );
        return Input::commit($data["sha"] ?? "");
    }
    public function metadata(string $repo): array
    {
        $r = $this->request($repo, "");
        return array_intersect_key(
            $r,
            array_flip(["full_name", "default_branch", "private", "html_url"]),
        );
    }
    /**
     * Recent commits for the deployment picker.
     *
     * Cached briefly so a form reload cannot exhaust the anonymous API rate
     * limit. Callers must tolerate an empty list: the GitHub API may be
     * unreachable or rate limited, and that must not break the form.
     *
     * @return array<string,string> sha => "shortsha · message · date"
     */
    public function recentCommits(string $repo, string $branch, int $limit = 30): array
    {
        Input::branch($branch);
        $limit = max(1, min(50, $limit));
        $cacheKey = "gh-commits:" . sha1($repo . "|" . $branch . "|" . $limit);

        return (array) \Illuminate\Support\Facades\Cache::remember(
            $cacheKey,
            60,
            function () use ($repo, $branch, $limit) {
                $commits = $this->request(
                    $repo,
                    "/commits?sha=" . rawurlencode($branch) . "&per_page=" . $limit,
                );
                $options = [];
                foreach ($commits as $commit) {
                    $sha = (string) ($commit["sha"] ?? "");
                    if (!preg_match("/\\A[a-f0-9]{40}\\z/D", $sha)) {
                        continue;
                    }
                    $message = trim(
                        preg_split("/\\r?\\n/", (string) ($commit["commit"]["message"] ?? ""))[0] ?? "",
                    );
                    $options[$sha] = substr($sha, 0, 8) .
                        " · " .
                        mb_substr($message, 0, 60) .
                        " · " .
                        mb_substr((string) ($commit["commit"]["author"]["date"] ?? ""), 0, 10);
                }
                return $options;
            },
        );
    }

    /** Detect the project type from repository markers, most specific first. */
    public function detect(string $repo, string $branch): string
    {
        return $this->detectWithReason($repo, $branch)["type"];
    }

    /**
     * @return array{type:string,marker:string|null}
     */
    public function detectWithReason(string $repo, string $branch): array
    {
        Input::branch($branch);
        // The tree is more reliable than the root listing: markers may live in
        // a subdirectory of the application.
        $paths = $this->treePaths($repo, $branch);
        // Marker precedence follows the template order, most specific first.
        foreach (
            (array) config("deployment_templates.templates", []) as $type => $template
        ) {
            foreach ((array) ($template["markers"] ?? []) as $file) {
                foreach ($paths as $path) {
                    if ($path === $file || str_ends_with($path, "/" . $file)) {
                        return ["type" => (string) $type, "marker" => $path];
                    }
                }
            }
        }
        return ["type" => "custom", "marker" => null];
    }

    /**
     * Repository file paths, capped so a large tree cannot exhaust memory.
     *
     * @return list<string>
     */
    private function treePaths(string $repo, string $branch): array
    {
        $result = $this->request(
            $repo,
            "/git/trees/" . rawurlencode($branch) . "?recursive=1",
        );
        $paths = [];
        foreach (($result["tree"] ?? []) as $entry) {
            if (($entry["type"] ?? "") !== "blob" || !isset($entry["path"])) {
                continue;
            }
            $paths[] = (string) $entry["path"];
            if (count($paths) >= 2000) {
                break;
            }
        }
        return $paths;
    }
}
