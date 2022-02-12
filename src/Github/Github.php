<?php

namespace Borzo\Github;

use Borzo\Super;
use Exception;

class Github {
    private static array $affectedFilenamesCache = [];

    public static function markPullRequestIfMergeabilityChanged($owner, $repo, GithubPullRequest $pullRequest) {
        $isMergeable = self::isPullRequestMergeable($owner, $repo, $pullRequest->number);

        $hasLabel = $pullRequest->hasLabel(GithubLabels::REBASE_REQUIRED);

        if ($isMergeable == $hasLabel) {
            if (!$isMergeable) {
                Super::getLog()
                    ->info("Found unmergeable PR {$pullRequest->number} owner {$owner} repo {$repo}, setting label " . GithubLabels::REBASE_REQUIRED);
                $pullRequest->addLabels([GithubLabels::REBASE_REQUIRED]);
            } else {
                Super::getLog()
                    ->info("Found mergeable PR {$pullRequest->number} owner {$owner} repo {$repo}, removing label " . GithubLabels::REBASE_REQUIRED);
                $pullRequest->removeLabels([GithubLabels::REBASE_REQUIRED]);
            }
        }
    }

    private static function isPullRequestMergeable($owner, $repo, $pullRequestNumber, $attempts = 0): bool {
        $pullInfoJson = self::makeRequest('GET', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/pulls/' . urlencode($pullRequestNumber), []);

        $pullRequestInfo = json_decode($pullInfoJson, true);

        if ($pullRequestInfo['mergeable'] === null) {
            if ($attempts > 5) {
                Super::getLog()->info("Field 'mergeable' is NULL for pull request #$pullRequestNumber");
                return true;
            }

            sleep(1);
            return self::isPullRequestMergeable($owner, $repo, $pullRequestNumber, $attempts + 1);
        }
        return (bool) $pullRequestInfo['mergeable'];
    }

    /**
     * Возвращает имена всех изменённых в пулл-риквесте файлов.
     * Гитхаб измененные файлы отдает по 30 штук на страницу.
     * Поэтому будем постранично идти и проверять наличие данных пока не найдем файл или не пройдем по всем файлам
     *
     * @return string[]
     */
    public static function getAllAffectedFilenames(string $owner, string $repo, array $pullRequestPayload): array {
        $url = '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/pulls/' . urlencode($pullRequestPayload['number']) . '/files';

        if (isset(self::$affectedFilenamesCache[$url])) {
            return self::$affectedFilenamesCache[$url];
        }

        $page = 1;

        $allChangedFilenames = [];
        do {
            $pullRequestFilesJson = self::makeRequest(
                'GET',
                $url . '?page=' . urlencode($page),
                [],
                false   // don't log response body
            );

            $changedFiles = json_decode($pullRequestFilesJson, true);

            if (empty($changedFiles)) {
                break;
            }

            $changedFilenames    = array_column($changedFiles, 'filename');
            $allChangedFilenames = array_merge($allChangedFilenames, $changedFilenames);

            $page += 1;
        } while (true);

        self::$affectedFilenamesCache[$url] = $allChangedFilenames;

        return $allChangedFilenames;
    }

    public static function addRequestLabels($owner, $repo, $issueId, array $labels) {
        self::makeRequest('POST', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/issues/' . urlencode($issueId) . '/labels', $labels);
    }

    public static function makeRequest($method, $path, array $data, $isLogBody = true) {
        $url = 'https://api.github.com' . $path;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERPWD, Super::getConfig()->github_username . ':' . Super::getConfig()->github_token);
        curl_setopt($ch, CURLOPT_USERAGENT, 'verter/0.13');

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($isLogBody) {
            Super::getLog()->info("{$method} {$url} got {$status} body {$body}");
        } else {
            Super::getLog()->info("{$method} {$url} got {$status} body not logged");
        }

        if (!in_array($status, [200, 201])) {
            throw new Exception("Bad response to {$url}: {$status}\n{$body}");
        }
        return $body;
    }

    public static function addCommentToPullRequest(string $owner, string $repo, string $pullRequestId, string $comment) {
        self::makeRequest(
            'POST',
            '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/issues/' . urlencode($pullRequestId) . '/comments',
            [
                'body' => $comment,
            ]
        );
    }

    /**
     * Возвращает список комментариев к риквесту.
     * @return string[]
     */
    public static function getPullRequestComments(string $owner, string $repo, string $pullRequestId): array {
        $commentsJson = self::makeRequest(
            'GET',
            '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/issues/' . urlencode($pullRequestId) . '/comments',
            []
        );

        $comments = [];
        $list     = json_decode($commentsJson, true);
        if (is_array($list)) {
            foreach ($list as $item) {
                $comments[] = $item['body'];
            }
        }

        return $comments;
    }

    private static function markConflictPullRequestsInRepository($owner, $repo) {
        Super::getLog()
            ->info("Searching for merge conflicts in owner {$owner} repo {$repo}");

        $page = 1;
        while (true) {
            $pullRequestsJson = self::makeRequest('GET', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/pulls?page=' . urlencode($page), []);
            $pullRequests     = json_decode($pullRequestsJson, true);

            if (empty($pullRequests)) {
                break;
            }

            Super::getLog()
                ->info("Found " . count($pullRequests) . " PRs in page {$page} owner {$owner} repo {$repo}");

            foreach ($pullRequests as $pullRequestData) {
                $pullRequest = new GithubPullRequest($pullRequestData);
                self::markPullRequestIfMergeabilityChanged($owner, $repo, $pullRequest);
            }

            $page++;
        }
    }
}
