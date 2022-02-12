<?php

namespace Borzo\Gitlab;

use Borzo\Super;
use Exception;

class Gitlab {
    private static array $affectedFilenamesCache = [];

    private static function prepareRepositoryName(string $name): string {
        return urlencode(
            str_replace(' ', '-', $name)
        );
    }

    public static function getUserIdByUserName(string $userName): ?int {
        $userJson = self::makeRequest('GET', '/users?username=' . urlencode($userName), []);

        $user = json_decode($userJson, true);

        return $user[0]['id'] ?? null;
    }

    public static function addAssignees(string $repo, int $mergeRequestIid, string $assignee): void {
        $assigneeId = self::getUserIdByUserName($assignee);
        self::makeRequest('PUT', '/projects/' . self::prepareRepositoryName($repo) . '/merge_requests/' . urlencode($mergeRequestIid), [
            'assignee_id' => $assigneeId,
        ]);
    }

    public static function setRequestLabels(string $repo, int $mergeRequestIid, array $labels): void {
        self::makeRequest('PUT', '/projects/' . self::prepareRepositoryName($repo) . '/merge_requests/' . urlencode($mergeRequestIid), [
            'labels' => implode(',', $labels),
        ]);
    }

    public static function addCommentToMergeRequest(string $repo, int $mergeRequestIid, string $comment): void {
        self::makeRequest(
            'POST',
            '/projects/' . self::prepareRepositoryName($repo) . '/merge_requests/' . urlencode($mergeRequestIid) . '/notes',
            [
                'body' => $comment,
            ]
        );
    }

    public static function markMergeRequestIfMergeabilityChanged(string $repo, int $mergeRequestIid, array $currentLabels): void {
        $isMergeable = self::isMergeRequestMergeable($repo, $mergeRequestIid);

        $hasTag = in_array(GitlabLabels::REBASE_REQUIRED, $currentLabels);

        if ($isMergeable == $hasTag) {
            if (!$isMergeable) {
                Super::getLog()
                    ->info("Found unmergeable PR {$mergeRequestIid} repo {$repo}, setting label " . GitlabLabels::REBASE_REQUIRED);
                self::setRequestLabels(
                    $repo,
                    $mergeRequestIid,
                    array_merge($currentLabels, [GitlabLabels::REBASE_REQUIRED])
                );
            } else {
                Super::getLog()
                    ->info("Found mergeable PR {$mergeRequestIid} repo {$repo}, removing label " . GitlabLabels::REBASE_REQUIRED);
                self::setRequestLabels(
                    $repo,
                    $mergeRequestIid,
                    array_diff($currentLabels, [GitlabLabels::REBASE_REQUIRED])
                );
            }
        }
    }

    public static function isMergeRequestMergeable(string $repo, int $mergeRequestIid, int $attempts = 0): bool {
        $mergeRequestInfoJson = self::makeRequest('GET', '/projects/' . self::prepareRepositoryName($repo) . '/merge_requests/' . urlencode($mergeRequestIid), []);

        $mergeRequestInfo = json_decode($mergeRequestInfoJson, true);
        if (in_array($mergeRequestInfo['merge_status'], ['unchecked', 'checking'])) {
            if ($attempts > 5) {
                return true;
            }

            sleep(1);

            return self::isMergeRequestMergeable($repo, $mergeRequestIid, $attempts + 1);
        }

        return $mergeRequestInfo['merge_status'] == 'can_be_merged';
    }

    /**
     * Гитлаб в своих заголовках возвращает информацию о пагинации (сколько всего записей, текущая страница и т.д.)
     * Запрашиваем заголовки в том же запросе, и затем парсим параметры для последующей пагинации.
     */
    public static function getPipelines(string $repo, array $params = []): array {
        $path = '/projects/' . self::prepareRepositoryName($repo) . '/pipelines';
        if ($params) {
            $path .= '?' . http_build_query($params);
        }
        $pipelinesResponseWithHeaders  = self::makeRequest('GET', $path, [], true);
        [$headers, $pipelinesJson] = explode("\r\n\r\n", $pipelinesResponseWithHeaders);

        $headers   = self::parseHeaders($headers);
        $pipelines = json_decode($pipelinesJson, true) ?: [];

        return [
            'pipelines' => $pipelines,
            'limit'     => $headers['x-per-page'] ?? count($pipelines),
            'total'     => $headers['x-total'] ?? count($pipelines),
        ];
    }

    /**
     * @return string[]
     */
    private static function parseHeaders(string $headers): array {
        $headers = explode("\r\n", $headers);

        $pagination = [];
        foreach ($headers as $header) {
            if (!str_contains($header, ': ')) {
                continue;
            }
            [$headerKey, $headerValue] = explode(': ', $header);
            $pagination[$headerKey] = $headerValue;
        }
        return $pagination;
    }

    /**
     * @param string[] $countryCodes список кодов стран для запуска тестов
     * @return mixed
     */
    public static function runPipeline(string $repo, string $backendBranch, string $frontendBranch, array $countryCodes): array {
        $data = [
            'ref'       => 'master',
            'variables' => [
                [
                    'key'   => 'DST_AUTOTEST_BACKEND_CUSTOM_BRANCH',
                    'value' => $backendBranch,
                ],
                [
                    'key'   => 'DST_AUTOTEST_FRONTEND_CUSTOM_BRANCH',
                    'value' => $frontendBranch,
                ],
                [
                    'key'   => 'DST_AUTOTEST_COUNTRY_CODE',
                    'value' => implode(';', $countryCodes),
                ],
            ],
        ];

        Super::getLog()->info('Start pipeline with data: ' . json_encode($data));
        $pipelineJson = Gitlab::makeRequest(
            'POST',
            '/projects/' . self::prepareRepositoryName($repo) . '/pipeline',
            $data,
        );

        return json_decode($pipelineJson, true);
    }

    public static function makeRequest(string $method, string $path, array $data = [], bool $includeResponseHeaders = false): string {
        $url = 'https://gitlab.borzo.com/api/v4' . $path;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        if ($includeResponseHeaders) {
            curl_setopt($ch, CURLOPT_HEADER, true);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-type: application/json',
            'Private-Token: ' . Super::getConfig()->gitlab_token,
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'verter/0.13');

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        Super::getLog()->info("{$method} {$url} got {$status} body {$body}");

        if (!in_array($status, [200, 201])) {
            throw new Exception("Bad response to {$url}: {$status}\n{$body}");
        }

        return $body;
    }

    /**
     * Возвращает имена всех изменных в пулл-риквесте файлов.
     * Гитлаб измененные файлы отдает по 20 штук на страницу.
     * Поэтому будем постранично идти и проверять наличие данных пока не найдем файл или не пройдем по всем файлам
     *
     * @return string[]
     */
    public static function getAllAffectedFilenames(string $owner, string $repo, array $pullRequestPayload): array {

        $url = '/projects/' . self::prepareRepositoryName("{$owner}/{$repo}") . '/repository/commits/'. urlencode($pullRequestPayload['id']) . '/diff';

        if (isset(self::$affectedFilenamesCache[$url])) {
            return self::$affectedFilenamesCache[$url];
        }

        $page = 1;

        $allChangedFilenames = [];
        do {
            $pullRequestFilesJson = self::makeRequest('GET', $url . '?page=' . urlencode($page), []);

            $changedFiles = json_decode($pullRequestFilesJson, true);

            if (empty($changedFiles)) {
                break;
            }

            $changedFilenames    = array_column($changedFiles, 'old_path');
            $allChangedFilenames = array_merge($allChangedFilenames, $changedFilenames);

            $page += 1;
        } while (true);

        self::$affectedFilenamesCache[$url] = $allChangedFilenames;

        return $allChangedFilenames;
    }

    /**
     * Возвращает список комментариев к риквесту.
     *
     * @return string[]
     */
    public static function getPullRequestComments(string $owner, string $repo, string $pullRequestId): array {
        $comments = [];

        $url  = '/projects/' . self::prepareRepositoryName("{$owner}/{$repo}") . '/merge_requests/' . $pullRequestId . '/notes';
        $page = 1;

        do {
            $commentsJson = self::makeRequest('GET', $url . '?page=' . urlencode($page), []);

            $list = json_decode($commentsJson, true);

            if (empty($list)) {
                break;
            }

            if (is_array($list)) {
                foreach ($list as $item) {
                    $comments[] = $item['body'];
                }
            }

            $page += 1;
        } while (true);

        return $comments;
    }
}
