<?php

namespace Borzo\YouTrack;

use Borzo\Library\HttpClient\HttpException;
use Borzo\Super;
use Throwable;

/**
 * YouTrack REST API Client
 * https://www.jetbrains.com/help/youtrack/devportal/youtrack-rest-api.html
 */
class YouTrack {
    public const YOUTRACK_URL = 'https://youtrack.borzo.com';

    /**
     * @param string[] $fromStates
     */
    public static function setIssueState(?string $issueId, array $fromStates, string $toState): void {
        $issue = self::getIssue($issueId);
        if (!$issue) {
            return;
        }

        if (!in_array($issue->state, $fromStates)) {
            return;
        }

        Super::getLog()->info("Issue #{$issueId} got proper status {$issue->state}, modifying to {$toState}");
        self::executeIssueCommand($issueId, "state: {$toState}");

        // Если задача перешла в статус Code review, то увеличиваем счетчик CR iteration
        // Делаем это отдельным запросом, который может упасть, если в задаче нет такого поля
        if ($toState === YouTrackStates::CODE_REVIEW) {
            try {
                Super::getLog()->info("Issue #{$issueId} set CR iteration to " . ($issue->codeReviewIteration + 1));
                self::executeIssueCommand($issueId, 'CR iteration: ' . ($issue->codeReviewIteration + 1));
            } catch (Throwable) {
            }
        }
    }

    public static function addTagToIssue(string $issueId, string $tag, string $reasonComment): void {
        Super::getLog()->info("Add tag {$tag} to issue #{$issueId}");
        self::addCommentToIssue($issueId, $reasonComment);
        self::executeIssueCommand($issueId, "add tag {$tag}");
    }

    public static function removeTagFromIssue(string $issueId, string $tag, string $reasonComment): void {
        Super::getLog()->info("Remove tag {$tag} from issue #{$issueId}");
        self::addCommentToIssue($issueId, $reasonComment);
        self::executeIssueCommand($issueId, "remove tag {$tag}");
    }

    public static function addCommentToIssue(?string $issueId, string $comment): void {
        if (!$issueId) {
            return;
        }

        self::makeRequest('POST', '/api/commands', [
            'query'   => 'comment',
            'comment' => $comment,
            'issues'  => [
                ["idReadable" => $issueId],
            ],
        ]);
    }

    public static function getIssue(?string $issueId): ?YouTrackIssueModel {
        if (!$issueId) {
            return null;
        }

        $issue = new YouTrackIssueModel();

        $fieldsList = [
            'id',
            'summary',
            'comments',
            'tags(name)',
            'customFields(name,value(fullName,name))',
        ];

        $response = self::makeRequest(
            'GET',
            '/api/issues/' . urlencode($issueId) . '?fields=' . implode(',', $fieldsList)
        );
        foreach ($response['customFields'] as $field) {
            $name  = $field['name'];
            $value = $field['value'];
            if ($name == 'State') {
                $issue->state = (string) $value['name'];
            } elseif ($name == 'Priority') {
                $issue->priority = (string) $value['name'];
            } elseif ($name == 'CR iteration') {
                $issue->codeReviewIteration = (int) $value;
            }
        }

        $issueTags = $response['tags'] ?? [];
        foreach ($issueTags as $tag) {
            $issue->tags[] = (string) $tag['name'];
        }

        if ($issue->state !== null) {
            return $issue;
        }

        return null;
    }

    private static function executeIssueCommand(?string $issueId, string $command): void {
        if (!$issueId) {
            return;
        }

        self::makeRequest('POST', '/api/commands', [
            'query'  => $command,
            'issues' => [
                ['idReadable' => $issueId],
            ],
        ]);
    }

    private static function makeRequest(string $method, string $path, ?array $data = null): array {
        $url = static::YOUTRACK_URL . $path;

        $response = Super::createHttpClient()
            ->buildRequest(
                $method,
                $url,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            )
            ->header('Content-Type', 'application/json')
            ->header('Accept', 'application/json')
            ->send();

        Super::getLog()->info("{$method} {$url} got {$response->status} body {$response->body}");

        return $response->getJson();
    }
}
