<?php
namespace App\Services;
use App\Services\Security\PublicHttp;
class HealthCheckEngine
{
    public function __construct(private PublicHttp $http) {}
    public function check(string $url): bool
    {
        foreach ([0, 2, 5] as $delay) {
            if ($delay) {
                sleep($delay);
            }
            try {
                if ($this->http->get($url)->status() === 200) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }
        return false;
    }
}
