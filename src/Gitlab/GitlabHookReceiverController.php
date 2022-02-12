<?php

namespace Borzo\Gitlab;

use Borzo\Conventions;
use Borzo\Fedecks\FedecksTelegramBot;
use Borzo\Super;
use Borzo\VCSHookReceiverControllerAbstract;
use Borzo\YouTrack\YouTrack;
use Borzo\YouTrack\YouTrackStates;
use Borzo\YouTrack\YouTrackTags;
use Exception;

class GitlabHookReceiverController extends VCSHookReceiverControllerAbstract {
    protected function getAllowedHostTypes(): array {
        return [static::HOST_FOR_INTERNAL_API];
    }

    /**
     * Сюда приходят все вебхуки от gitlab.
     * @see https://docs.gitlab.com/ee/user/project/integrations/webhooks.html
     */
    public function indexAction() {
        $request = Super::getHttpRequest();

        $type    = $request->header('X-Gitlab-Event');
        $body    = $request->bodyRaw();
        $payload = $request->bodyJson();

        Super::getLog()->info("Got hook with type {$type} and payload {$body}");

        $eventType = $payload['event_type'] ?? 'undefined';
        if ($eventType == 'merge_request') {
            $this->reactToMergeRequest($payload);
        }

        // Пересылаем сообщение в телеграм бот
        FedecksTelegramBot::sendGitlabPayloadToFedecks($body);
    }

    private function reactToMergeRequest(array $payload): void {
        Super::getLog()->info("Got merge request event");

        $assignees = Super::getProjectAssignees();

        $mobileAppProjects = [
            'Borzo/android.courier',
            'Borzo/android.clients',
            'Borzo/ios.courier',
            'Borzo/ios.clients',
            'Borzo/ios.market',
            'Borzo/ios.base',
        ];

        $issueId = Conventions::getYouTrackIssueIdFromBranch($payload['object_attributes']['source_branch']);

        if ($issueId === null) {
            $issueId = Conventions::getYouTrackIssueIdFromText($payload['object_attributes']['title']);
        }

        $issue = YouTrack::getIssue($issueId);

        $repoName = $payload['project']['path_with_namespace'] ?? null;
        if (empty($repoName)) {
            throw new Exception('Got merge request hook from unknown repository');
        }

        $requestAuthor   = strtolower($payload['user']['username']);
        $requestAssignee = $payload['object_attributes']['assignee_id'] ?? null;

        $mergeRequestUrl       = $payload['object_attributes']['url'];
        $mergeRequestNumber    = $payload['object_attributes']['iid'];
        $mergeRequestTitleHtml = htmlspecialchars($payload['object_attributes']['title']);

        $isNewCommit = false;
        $mentions    = [];

        switch ($payload['object_attributes']['action']) {
            case 'open':
                // Добавили новый реквест
                Super::getLog()->info("New merge request #{$mergeRequestNumber} detected in {$repoName} repository {$mergeRequestUrl}. Issue {$issueId}");

                $isNewCommit = true;

                // Особая логика для мобильных реп
                if (
                    in_array($repoName, $mobileAppProjects, true)
                    && !empty($assignees[$repoName]['*'])
                    && is_array($assignees[$repoName]['*'])
                ) {
                    $candidates = $assignees[$repoName]['*'];

                    // Первый человек в списке - обязательный человек, которого надо упомянуть (кроме случае, когда он создатель риквеста)
                    $username = array_shift($candidates);
                    if ($username !== $requestAuthor) {
                        $mentions[] = $username;
                    }

                    // и добавляем рандомных из оставшихся (не считая создателя риквеста)
                    // так, чтобы в $mentions в итоге оказалось два человека
                    $candidates = array_diff($candidates, [$requestAuthor]);
                    if (!empty($candidates)) {
                        shuffle($candidates);

                        $need = 2 - count($mentions);
                        if ($need > count($candidates)) {
                            $need = count($candidates);
                        }

                        for ($i = 0; $i < $need; $i++) {
                            $mentions[] = array_shift($candidates);
                        }
                    }
                }

                // Если реквест создан с уже назначенным ревьюером, то не выбираем нового ревьюера
                if (!$requestAssignee) {
                    $assignee = null;
                    if (!empty($assignees[$repoName][$requestAuthor])) {
                        $assignee = $assignees[$repoName][$requestAuthor];
                    } elseif (!empty($mentions)) {
                        $assignee = $mentions[0];
                    } elseif (!empty($assignees[$repoName]['*'])) {
                        if (is_array($assignees[$repoName]['*'])) {
                            $assignee = array_values($assignees[$repoName]['*'])[0];
                        } else {
                            $assignee = $assignees[$repoName]['*'];
                        }
                    }

                    if (!$assignee) {
                        // Если никто так и не назначился, то назначаем человека по умолчанию.
                        // Если его забыли указать в конфиге, то никого не назначаем.
                        $assignee = $assignees['gitlab_unknown_to']['*'] ?? null;
                    }

                    if ($assignee) {
                        Gitlab::addAssignees(
                            $payload['project']['path_with_namespace'],
                            $mergeRequestNumber,
                            $assignee
                        );
                    }
                }

                // При создании пулл-реквеста меняем статус задачи на Code Review
                YouTrack::setIssueState(
                    $issueId,
                    [
                        YouTrackStates::UNSTARTED,
                        YouTrackStates::STARTED,
                        YouTrackStates::REWORK,
                        YouTrackStates::FINISHED,
                    ],
                    YouTrackStates::CODE_REVIEW
                );
                YouTrack::addCommentToIssue($issueId, "A merge request was made: [{$mergeRequestTitleHtml}]({$mergeRequestUrl})");

                if ($issueId) {
                    $comment = 'YouTrack: [' . $issueId . '](' . YouTrack::YOUTRACK_URL . '/issue/' . urlencode($issueId) . ')';

                    if (!empty($mentions)) {
                        $comment .= " \nReviewers are: ";
                        foreach ($mentions as $mention) {
                            $comment .= '@' . $mention . ' ';
                        }
                    }

                    Gitlab::addCommentToMergeRequest(
                        $payload['project']['path_with_namespace'],
                        $mergeRequestNumber,
                        $comment
                    );
                }

                // Если задача с тегом Release To Test, то ставим метку 'выложить на тест' в gitlab
                if ($issue && $issue->hasTag(YouTrackTags::RELEASE_TO_TEST)) {
                    Gitlab::setRequestLabels(
                        $payload['project']['path_with_namespace'],
                        $mergeRequestNumber,
                        array_column($payload['labels'] ?? [], 'title') + [GitlabLabels::RELEASE_TO_TEST]
                    );
                }
                break;
            case 'merge':
                // Замержили реквест
                Super::getLog()->info("Merge request #{$mergeRequestNumber} was merged in {$repoName} repository {$mergeRequestUrl}. Issue {$issueId}");

                // Меняем статус задачи на Finished
                YouTrack::setIssueState(
                    $issueId,
                    [
                        YouTrackStates::STARTED,
                        YouTrackStates::REWORK,
                        YouTrackStates::CODE_REVIEW,
                        YouTrackStates::TESTING,
                    ],
                    YouTrackStates::FINISHED
                );

                // Пишем в задачу комментарий о том, что код поехал на бой
                $comment = "We deployed a bit of code: [{$mergeRequestTitleHtml}]({$mergeRequestUrl})";

                // Пишем в задачу комментарий от разработчика (Release notes)
                $releaseNotesRegexp = '(?:Report|Release notes?|RN)';
                if (preg_match('/(?:#{1,6}\s*' . $releaseNotesRegexp . '[.:]?|\*\*' . $releaseNotesRegexp . '[.:]?\*\*[.:]?)[\r\n\s]+(.+)/ius', $payload['object_attributes']['description'], $match)) {
                    $comment .= "\n\n**Release Notes**\n" . trim($match[1]);
                }

                YouTrack::addCommentToIssue($issueId, $comment);
                break;
            case 'update':
                $newLabels     = [];
                $removedLabels = [];
                $changedLabels = $payload['changes']['labels'] ?? [];
                if ($changedLabels) {
                    $newLabels     = $this->getNewLabels($changedLabels);
                    $removedLabels = $this->getRemovedLabels($changedLabels);
                }

                if ($newLabels) {
                    foreach ($newLabels as $label) {
                        Super::getLog()->info("Add new label {$label} to merge request #{$mergeRequestNumber} in {$repoName} repository {$mergeRequestUrl}. Issue {$issueId}");
                        if (mb_strtolower($label) == mb_strtolower(GitlabLabels::CHANGES_REQUIRED)) {
                            // Поставили реквесту «ждём изменений»
                            YouTrack::setIssueState(
                                $issueId,
                                [YouTrackStates::CODE_REVIEW],
                                YouTrackStates::REWORK
                            );
                        } else if (mb_strtolower($label) == mb_strtolower(GitlabLabels::MERGE_FORBIDDEN)) {
                            // Поставили реквесту «не мержить»
                            $comment = "Tag '" . YouTrackTags::WAITING . "' was added to issue because new label '" . GitlabLabels::MERGE_FORBIDDEN . "' was added to pull request [{$mergeRequestTitleHtml}]({$mergeRequestUrl}).";

                            // Тег проставляем, только если на этот момент его еще нет
                            if ($issue && !$issue->hasTag(YouTrackTags::WAITING)) {
                                YouTrack::addTagToIssue($issueId, YouTrackTags::WAITING, $comment);
                            }
                        }
                    }
                }

                if ($removedLabels) {
                    foreach ($removedLabels as $label) {
                        Super::getLog()->info("Remove label {$label} from merge request #{$mergeRequestNumber} in {$repoName} repository {$mergeRequestUrl}. Issue {$issueId}");
                        if (mb_strtolower($label) == mb_strtolower(GitlabLabels::CHANGES_REQUIRED)) {
                            // Убрали «ждём изменений»
                            YouTrack::setIssueState(
                                $issueId,
                                [
                                    YouTrackStates::UNSTARTED,
                                    YouTrackStates::STARTED,
                                    YouTrackStates::REWORK,
                                ],
                                YouTrackStates::CODE_REVIEW
                            );
                        } else if (mb_strtolower($label) == mb_strtolower(GitlabLabels::MERGE_FORBIDDEN)) {
                            // Убрали «не мержить»
                            $comment = "Tag '" . YouTrackTags::WAITING . "' was removed from issue because label '" . GitlabLabels::MERGE_FORBIDDEN . "' was removed from pull request [{$mergeRequestTitleHtml}]({$mergeRequestUrl}).";

                            // Тег удаляем, только если на этот момент тег еще присутствует
                            if ($issue && $issue->hasTag(YouTrackTags::WAITING)) {
                                YouTrack::removeTagFromIssue($issueId, YouTrackTags::WAITING, $comment);
                            }
                        }
                    }
                }

                // Новый коммит в ветке
                if (!empty($payload['object_attributes']['oldrev'])) {
                    $isNewCommit = true;
                    Gitlab::markMergeRequestIfMergeabilityChanged(
                        $payload['project']['path_with_namespace'],
                        $mergeRequestNumber,
                        array_column($payload['labels'] ?? [], 'title')
                    );
                }
                break;
        }

        // Тегнем в комментариях людей, подписавшихся на изменения
        if ($isNewCommit) {
            $affectedFiles = Gitlab::getAllAffectedFilenames(
                $payload['project']['namespace'],
                $payload['project']['name'],
                $payload['object_attributes']['last_commit']
            );

            $fileUrls = [];
            foreach ($affectedFiles as $filename) {
                $fileUrls[$filename] = "[{$filename}]" . '(https://gitlab.borzo.com/'. urlencode($payload['project']['namespace']) . '/' . urlencode($payload['project']['name'])
                    . '/-/merge_requests/' . urlencode($mergeRequestNumber)
                    . '/diffs#diff-content-' . hash('sha1', $filename) . ')';
            }

            $filesWatchedBy = $this->getFileWatchers($repoName, $affectedFiles);
            $watchedUsers   = array_keys($filesWatchedBy);

            // Если пользователь является автором пулл-риквеста или он и так назначен на пулл-риквест, то не надо упоминать его в комментарии
            $watchers = array_diff(
                $watchedUsers,
                array_merge(
                    [$requestAuthor],
                    $this->getAssigneeLogins($payload['assignees'] ?? []), // часть пейлоадов приходит без этого поля
                    $mentions
                )
            );

            if ($watchers) {
                $comments = Gitlab::getPullRequestComments(
                    $payload['project']['namespace'],
                    $payload['project']['name'],
                    $mergeRequestNumber
                );

                foreach ($watchers as $watcher) {
                    $notification = $this->getFileWatchersNotificationString(
                        $watcher,
                        $filesWatchedBy,
                        $fileUrls,
                        $comments,
                        "\n\n" // гитлаб игнорирует одиночные переносы строк, а двойные превращает в <p></p>
                    );

                    if ($notification) {
                        Gitlab::addCommentToMergeRequest(
                            $repoName,
                            $mergeRequestNumber,
                            $notification
                        );
                    }
                }
            }
        }
    }

    /**
     * Возвращает список логинов ревьюеров, назначенных на PR
     * @return string[]
     */
    private function getAssigneeLogins(array $assignees): array {
        $logins = [];

        foreach ($assignees as $assignee) {
            $logins[] = strtolower($assignee['username']);
        }

        return $logins;
    }

    /**
     * Возвращает массив новых тегов
     * @param array[] $changedLabels
     */
    private function getNewLabels(array $changedLabels): array {
        $newLabels = [];

        foreach ($changedLabels['current'] as $currentLabel) {
            $newLabels[$currentLabel['title']] = $currentLabel['title'];
        }

        foreach ($changedLabels['previous'] as $previousLabel) {
            unset($newLabels[$previousLabel['title']]);
        }

        return $newLabels;
    }

    /**
     * Возвращает массив удаленных тегов
     * @param array[] $changedLabels
     */
    private function getRemovedLabels(array $changedLabels): array {
        $removedLabels = [];

        foreach ($changedLabels['previous'] as $currentLabel) {
            $removedLabels[$currentLabel['title']] = $currentLabel['title'];
        }

        foreach ($changedLabels['current'] as $previousLabel) {
            unset($removedLabels[$previousLabel['title']]);
        }

        return $removedLabels;
    }

    protected function getVcsName(): string {
        return 'git_lab';
    }
}
