<?php

namespace Borzo\Github;

use Borzo\Super;

class GithubPullRequest {
    /** @var bool */
    public bool $isDraft;

    /** @var string|null */
    public ?string $body;

    /** @var string */
    public mixed $url;

    /** @var string */
    public mixed $title;

    /** @var int */
    public int $number;

    /** @var string */
    public string $authorLogin;

    /** @var string[] */
    public array $labels = [];

    /** @var array */
    public mixed $repositoryData = [];

    private array $assignees = [];

    public function __construct(array $data) {
        $this->url            = $data['html_url'];
        $this->title          = $data['title'];
        $this->number         = (int) $data['number'];
        $this->body           = self::stringOrNull($data['body'] ?? null);
        $this->isDraft        = (bool) ($data['draft'] ?? false);
        $this->authorLogin    = strtolower($data['user']['login']);
        $this->repositoryData = $data['head']['repo'];

        if (isset($data['assignees'])) {
            $this->assignees = (array) $data['assignees'];
        }

        if (!isset($this->repositoryData['owner']['login'], $this->repositoryData['name'])) {
            Super::getLog()
                ->err("Repo settings not found. Pull request data: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        foreach ($data['labels'] ?? [] as $labelInfo) {
            $label                = $labelInfo['name'];
            $this->labels[$label] = $label;
        }
    }

    public function hasLabel(string $label): bool {
        return isset($this->labels[$label]);
    }

    public function addLabels(array $labels): void {
        if (empty($labels)) {
            return;
        }

        $owner = $this->repositoryData['owner']['login'];
        $repo  = $this->repositoryData['name'];
        Github::addRequestLabels($owner, $repo, $this->number, $labels);

        foreach ($labels as $label) {
            $this->labels[$label] = $label;
        }
    }

    public function removeLabels(array $labels): void {
        if (empty($labels)) {
            return;
        }

        $owner = $this->repositoryData['owner']['login'];
        $repo  = $this->repositoryData['name'];

        foreach ($labels as $label) {
            Github::makeRequest('DELETE', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/issues/' . urlencode($this->number) . '/labels/' . rawurlencode($label), []);
            unset($this->labels[$label]);
        }
    }

    public function addAssignees(array $assignees): void {
        $owner = $this->repositoryData['owner']['login'];
        $repo  = $this->repositoryData['name'];

        Github::makeRequest('POST', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/issues/' . urlencode($this->number) . '/assignees', [
            'assignees' => $assignees,
        ]);
    }

    /**
     * Возвращает список логинов ревьюеров, назначенных на PR
     * @return string[]
     */
    public function getAssigneeLogins(): array {
        $logins = [];

        foreach ($this->assignees as $assignee) {
            $logins[] = strtolower($assignee['login']);
        }

        return $logins;
    }

    private static function stringOrNull($value): ?string {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return null;
    }
}
