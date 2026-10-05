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
    public function detect(string $repo, string $branch): string
    {
        Input::branch($branch);
        $files = $this->request(
            $repo,
            "/contents?ref=" . rawurlencode($branch),
        );
        $names = array_column($files, "name");
        foreach (
            [
                "artisan" => "laravel",
                "requirements.txt" => "python",
                "pyproject.toml" => "python",
                "package.json" => "node",
                "index.html" => "static",
            ]
            as $file => $type
        ) {
            if (in_array($file, $names, true)) {
                return $type;
            }
        }
        return "custom";
    }
}
